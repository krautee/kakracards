#!/usr/bin/env python3
"""Benchmark re-run: decode already-reviewed cards again and score automatically.

Every saved record in cards_header is human-verified ground truth. This tool re-runs
their source images through the models you name and scores each result against the
saved record (decode_field_quality rows with is_benchmark=1), with no manual review.
That makes trying a new model or a new prompt version a single command.

Examples:
  # Everything reviewed so far, three models, current active prompt
  python3 python/benchmark.py --model anthropic/claude-opus-5 --model x-ai/grok-4.6 --model qwen/qwen3-vl-32b-instruct

  # Only some cards, a candidate prompt, 2 parallel workers
  python3 python/benchmark.py --headers 39,40,41 --prompt prompts/v2.md --model gemini-3.1-pro-preview --concurrency 2

  # Show what would run and what it would roughly cost, without spending anything
  python3 python/benchmark.py --model openai/gpt-6-astra --dry-run

A (card, model, prompt version) triple that already has a benchmark result is skipped
unless --force is given, so re-running the same command never double-spends.
"""
import argparse
import hashlib
import subprocess
import sys
import time
from pathlib import Path

import decode_cards
import enqueue_decode_jobs as enq

ROOT = Path(__file__).resolve().parents[1]

# Typical per-card token use observed in this project, for the pre-run cost estimate.
TYPICAL_PROMPT_TOKENS = 3000
TYPICAL_OUTPUT_TOKENS = 700
TYPICAL_REASONING_TOKENS_LOW = 1500


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Re-run reviewed cards through models and score against ground truth")
    parser.add_argument("--model", action="append", default=[], help="Model id; repeatable. Defaults to preferred models.")
    parser.add_argument("--headers", default="", help="Comma-separated cards_header ids. Default: all saved records with an image on disk.")
    parser.add_argument("--limit", type=int, default=0, help="Use at most N cards (newest first)")
    parser.add_argument("--prompt", "--prompt-file", dest="prompt_file", default="", help="Prompt file. Default: active prompt.")
    parser.add_argument("--concurrency", type=int, default=3, help="Parallel workers (default 3; keep low to avoid rate limits)")
    parser.add_argument("--force", action="store_true", help="Re-run even if this card/model/prompt was benchmarked already")
    parser.add_argument("--dry-run", action="store_true", help="List planned jobs and estimated cost; do not create or run anything")
    parser.add_argument("--yes", "-y", action="store_true", help="Skip the cost confirmation prompt")
    return parser.parse_args()


def select_headers(conn, header_arg: str, limit: int) -> list[dict]:
    cur = conn.cursor(dictionary=True)
    try:
        if header_arg.strip():
            ids = [int(x) for x in header_arg.split(",") if x.strip().isdigit()]
            if not ids:
                raise RuntimeError("--headers contains no valid ids")
            placeholders = ", ".join(["%s"] * len(ids))
            cur.execute(
                f"SELECT id, bird_id, source_image_path FROM cards_header WHERE id IN ({placeholders}) ORDER BY id DESC",
                tuple(ids),
            )
        else:
            cur.execute("SELECT id, bird_id, source_image_path FROM cards_header ORDER BY id DESC")
        rows = cur.fetchall() or []
    finally:
        cur.close()

    usable = []
    for row in rows:
        path = Path(str(row.get("source_image_path") or ""))
        if not path.is_absolute():
            path = (ROOT / path).resolve()
        if path.is_file():
            row["image_path"] = path
            usable.append(row)
        else:
            print(f"skip header {row['id']} (bird {row.get('bird_id')}): image missing: {path}")
    if limit > 0:
        usable = usable[:limit]
    return usable


def existing_benchmarks(conn, prompt_sha: str) -> set[tuple[int, str]]:
    cur = conn.cursor()
    try:
        cur.execute(
            """
            SELECT benchmark_header_id, requested_model
            FROM decode_jobs
            WHERE benchmark_header_id IS NOT NULL
              AND prompt_sha256 = %s
              AND status IN ('queued', 'running', 'succeeded', 'benchmarked')
            """,
            (prompt_sha,),
        )
        return {(int(h), str(m)) for h, m in cur.fetchall()}
    finally:
        cur.close()


def estimate_model_cost(model: str) -> float | None:
    pricing = decode_cards.load_pricing().get(model)
    if not pricing:
        return None
    out_tokens = TYPICAL_OUTPUT_TOKENS + TYPICAL_REASONING_TOKENS_LOW
    return decode_cards.estimate_cost_usd(model, TYPICAL_PROMPT_TOKENS, out_tokens)


def run_workers(job_ids: list[int], concurrency: int) -> dict[int, int]:
    """Run decode_job_worker.py for each job, at most `concurrency` at a time."""
    python_bin = enq.resolve_python_bin()
    worker = ROOT / "python" / "decode_job_worker.py"
    pending = list(job_ids)
    running: dict[int, subprocess.Popen] = {}
    results: dict[int, int] = {}
    started = time.time()
    while pending or running:
        while pending and len(running) < max(1, concurrency):
            job_id = pending.pop(0)
            running[job_id] = subprocess.Popen(
                [python_bin, str(worker), "--job-id", str(job_id)],
                cwd=str(ROOT),
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
                text=True,
            )
        for job_id, proc in list(running.items()):
            code = proc.poll()
            if code is None:
                continue
            _, err = proc.communicate()
            results[job_id] = code
            del running[job_id]
            status = "ok" if code == 0 else f"FAILED: {(err or '').strip()[:300]}"
            print(f"  job {job_id}: {status}   [{len(results)}/{len(job_ids)} done, {int(time.time() - started)}s]")
        time.sleep(1)
    return results


def print_summary(conn, job_ids: list[int]) -> None:
    if not job_ids:
        return
    cur = conn.cursor(dictionary=True)
    placeholders = ", ".join(["%s"] * len(job_ids))
    try:
        cur.execute(
            f"""
            SELECT j.requested_model AS model,
                   COUNT(*) AS jobs,
                   SUM(j.status = 'benchmarked') AS scored,
                   SUM(j.status = 'failed') AS failed,
                   ROUND(SUM(j.cost_usd), 4) AS cost_usd,
                   ROUND(AVG(TIMESTAMPDIFF(SECOND, j.started_at, j.finished_at)), 1) AS avg_sec,
                   ROUND(AVG(q.acc) * 100, 1) AS norm_acc_pct
            FROM decode_jobs j
            LEFT JOIN (
                SELECT decode_job_id, AVG(normalized_match) AS acc
                FROM decode_field_quality
                WHERE error_type <> 'both_empty'
                GROUP BY decode_job_id
            ) q ON q.decode_job_id = j.id
            WHERE j.id IN ({placeholders})
            GROUP BY j.requested_model
            ORDER BY norm_acc_pct DESC
            """,
            tuple(job_ids),
        )
        rows = cur.fetchall() or []
    finally:
        cur.close()
    print("\nSummary (normalized field accuracy excludes fields empty on both sides):")
    print(f"{'model':45s} {'jobs':>4s} {'scored':>6s} {'failed':>6s} {'cost $':>8s} {'avg s':>6s} {'acc %':>6s}")
    for r in rows:
        print(
            f"{str(r['model']):45s} {int(r['jobs']):4d} {int(r['scored'] or 0):6d} {int(r['failed'] or 0):6d} "
            f"{float(r['cost_usd'] or 0):8.4f} {float(r['avg_sec'] or 0):6.1f} {float(r['norm_acc_pct'] or 0):6.1f}"
        )
    print("Full breakdown: public/stats.php")


def main() -> int:
    decode_cards.load_env()
    args = parse_args()
    conn = enq.db_connection()
    cur = conn.cursor()
    try:
        prompt_path = enq.ensure_prompt_path(conn, args.prompt_file)
        prompt_text = prompt_path.read_text(encoding="utf-8")
        prompt_name = enq.prompt_display_name(prompt_path)
        prompt_sha = hashlib.sha256(prompt_text.encode("utf-8")).hexdigest()
        models = enq.resolve_models(conn, args.model)
        headers = select_headers(conn, args.headers, args.limit)
        if not headers:
            raise RuntimeError("No reviewed cards with images found to benchmark")

        done = set() if args.force else existing_benchmarks(conn, prompt_sha)
        plan = [(h, m) for h in headers for m in models if (int(h["id"]), m) not in done]
        skipped = len(headers) * len(models) - len(plan)

        print(f"Prompt: {prompt_name} (sha {prompt_sha[:12]})")
        print(f"Cards: {len(headers)}   Models: {', '.join(models)}")
        print(f"Planned jobs: {len(plan)}   Already benchmarked (skipped): {skipped}")
        total_est = 0.0
        for model in models:
            n = sum(1 for _, m in plan if m == model)
            est = estimate_model_cost(model)
            if est is None:
                print(f"  {model:45s} {n:3d} jobs   cost: unknown (not in model_pricing.json)")
            else:
                total_est += est * n
                print(f"  {model:45s} {n:3d} jobs   ~${est:.4f}/card  ~${est * n:.2f}")
        print(f"Estimated total: ~${total_est:.2f} (rough; reasoning models can exceed this)")

        if args.dry_run or not plan:
            return 0
        if not args.yes:
            answer = input("Proceed? [y/N] ").strip().lower()
            if answer not in {"y", "yes"}:
                print("Aborted.")
                return 1

        prompt_sha = enq.register_prompt_version(cur, prompt_name, prompt_text)
        job_ids: list[int] = []
        for header, model in plan:
            snapshot = enq.create_prompt_snapshot(prompt_text)
            group = f"bench-{header['id']}-{prompt_sha[:8]}"
            job_id = enq.create_decode_job(
                cur, header["image_path"], snapshot, model, group, prompt_name, prompt_sha, int(header["id"])
            )
            job_ids.append(job_id)
        conn.commit()
        print(f"Created {len(job_ids)} benchmark jobs; running with concurrency {args.concurrency}...")

        results = run_workers(job_ids, args.concurrency)
        failed = [j for j, code in results.items() if code != 0]
        print(f"Finished: {len(results) - len(failed)} ok, {len(failed)} failed")
        conn.commit()
        print_summary(conn, job_ids)
        return 1 if failed else 0
    finally:
        cur.close()
        conn.close()


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:  # noqa: BLE001
        print(f"Error: {exc}", file=sys.stderr)
        raise SystemExit(1)
