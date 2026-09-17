#!/usr/bin/env python3
import argparse
import datetime as dt
import glob
import hashlib
import json
import logging
import os
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import Iterable

import decode_cards

ROOT = Path(__file__).resolve().parents[1]
ALLOWED_EXTENSIONS = {"jpg", "jpeg", "png", "gif", "webp", "pdf"}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Enqueue decode jobs from CLI and start background workers"
    )
    parser.add_argument(
        "inputs",
        nargs="+",
        help="Input images/PDFs and/or glob patterns, e.g. uploads/card*.jpg",
    )
    parser.add_argument(
        "--model",
        action="append",
        default=[],
        help="Model to use; can be repeated. Gemini model id (e.g. gemini-2.5-flash) "
        "or OpenRouter model id containing a slash (e.g. anthropic/claude-sonnet-4.5)",
    )
    parser.add_argument(
        "--prompt",
        "--prompt-file",
        dest="prompt_file",
        default="",
        help="Prompt file path. Defaults to active prompt in DB settings.",
    )
    parser.add_argument(
        "-v",
        "--verbose",
        action="store_true",
        help="Print detailed enqueue and worker spawn output",
    )
    parser.add_argument(
        "--log-file",
        default=str(ROOT / "logs" / "decode_cli.log"),
        help="Path to log file",
    )
    return parser.parse_args()


def db_connection():
    try:
        import mysql.connector
    except ModuleNotFoundError as exc:
        raise RuntimeError(
            "mysql-connector-python is required for CLI enqueue; install python/requirements.txt"
        ) from exc

    return mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "127.0.0.1"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        database=os.getenv("MYSQL_DATABASE", "kakracards"),
        user=os.getenv("MYSQL_USER", "root"),
        password=os.getenv("MYSQL_PASSWORD", ""),
    )


def setup_logger(log_file: str, verbose: bool) -> logging.Logger:
    log_path = Path(log_file)
    log_path.parent.mkdir(parents=True, exist_ok=True)

    logger = logging.getLogger("decode_cli")
    logger.setLevel(logging.INFO)
    logger.handlers.clear()

    formatter = logging.Formatter("%(asctime)s %(levelname)s %(message)s")

    file_handler = logging.FileHandler(log_path, encoding="utf-8")
    file_handler.setFormatter(formatter)
    logger.addHandler(file_handler)

    if verbose:
        stream_handler = logging.StreamHandler(sys.stdout)
        stream_handler.setFormatter(formatter)
        logger.addHandler(stream_handler)

    return logger


def has_glob_chars(value: str) -> bool:
    return any(ch in value for ch in "*?[]")


def expand_inputs(inputs: Iterable[str]) -> list[Path]:
    expanded: list[Path] = []
    seen: set[str] = set()

    for value in inputs:
        candidates: list[str]
        if has_glob_chars(value):
            candidates = glob.glob(value)
        else:
            candidates = [value]

        for candidate in candidates:
            path = Path(candidate).expanduser().resolve()
            path_key = str(path)
            if path_key in seen:
                continue
            seen.add(path_key)
            if path.is_file():
                expanded.append(path)

    return expanded


def filter_allowed_files(paths: list[Path]) -> list[Path]:
    allowed: list[Path] = []
    for path in paths:
        ext = path.suffix.lower().lstrip(".")
        if ext in ALLOWED_EXTENSIONS:
            allowed.append(path)
    return allowed


def command_exists(name: str) -> bool:
    return shutil.which(name) is not None


def convert_pdf_to_images(pdf_path: Path, name_prefix: str) -> list[Path]:
    if not command_exists("pdftoppm"):
        raise RuntimeError("pdftoppm is required for PDF conversion (install poppler-utils)")

    uploads_dir = Path(os.getenv("UPLOAD_DIR", "./uploads"))
    if not uploads_dir.is_absolute():
        uploads_dir = (ROOT / uploads_dir).resolve()
    uploads_dir.mkdir(parents=True, exist_ok=True)

    safe_prefix = "".join(ch if ch.isalnum() or ch in "_-" else "_" for ch in name_prefix)
    output_prefix = uploads_dir / safe_prefix

    cmd = ["pdftoppm", "-jpeg", "-r", "300", str(pdf_path), str(output_prefix)]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        detail = proc.stderr.strip() or proc.stdout.strip()
        raise RuntimeError(f"PDF conversion failed for {pdf_path.name}: {detail}")

    images = sorted(output_prefix.parent.glob(output_prefix.name + "-*.jpg"))
    if not images:
        raise RuntimeError(f"No images generated from PDF {pdf_path.name}")

    combined: list[Path] = []
    pair_index = 1
    for idx in range(0, len(images), 2):
        img1 = images[idx]
        img2 = images[idx + 1] if idx + 1 < len(images) else None

        if img2 is not None and command_exists("convert"):
            combined_path = output_prefix.parent / f"{output_prefix.name}_pair_{pair_index}.jpg"
            proc = subprocess.run(
                ["convert", "-append", str(img1), str(img2), str(combined_path)],
                capture_output=True,
                text=True,
            )
            if proc.returncode == 0 and combined_path.is_file():
                combined.append(combined_path)
                try:
                    img1.unlink()
                    img2.unlink()
                except OSError:
                    pass
            else:
                combined.append(img1)
                combined.append(img2)
        else:
            combined.append(img1)
            if img2 is not None:
                combined.append(img2)

        pair_index += 1

    try:
        pdf_path.unlink()
    except OSError:
        pass

    return combined


def ensure_prompt_path(conn, prompt_arg: str) -> Path:
    if prompt_arg.strip():
        path = Path(prompt_arg).expanduser()
        if not path.is_absolute():
            path = (ROOT / path).resolve()
        if not path.is_file():
            raise RuntimeError(f"Prompt file not found: {path}")
        return path

    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(
            "SELECT `value` FROM app_settings WHERE `key`=%s LIMIT 1",
            ("active_prompt_file",),
        )
        row = cur.fetchone()
    finally:
        cur.close()

    relative = str((row or {}).get("value") or "").strip()
    if not relative:
        relative = os.getenv("PROMPT_FILE", "./prompts/default_prompt.md")

    path = Path(relative)
    if not path.is_absolute():
        path = (ROOT / path).resolve()
    if not path.is_file():
        raise RuntimeError(f"Active prompt file not found: {path}")
    return path


def resolve_models(conn, requested_models: list[str]) -> list[str]:
    models = [m.strip() for m in requested_models if m and m.strip()]
    models = list(dict.fromkeys(models))
    if models:
        return models

    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(
            "SELECT `value` FROM app_settings WHERE `key`=%s LIMIT 1",
            ("preferred_gemini_models",),
        )
        row = cur.fetchone()
    finally:
        cur.close()

    decoded = None
    if row and row.get("value"):
        try:
            decoded = json.loads(str(row["value"]))
        except json.JSONDecodeError:
            decoded = None

    if isinstance(decoded, list):
        models = [str(v).strip() for v in decoded if str(v).strip()]
        models = list(dict.fromkeys(models))
        if models:
            return models

    fallback = os.getenv("GEMINI_MODEL", "gemini-2.5-flash").strip() or "gemini-2.5-flash"
    return [fallback]


def create_prompt_snapshot(prompt_text: str) -> str:
    with tempfile.NamedTemporaryFile(prefix="kakra_prompt_job_", delete=False) as tmp:
        tmp.write(prompt_text.encode("utf-8"))
        os.chmod(tmp.name, 0o644)
        return tmp.name


def create_decode_job(cur, source_path: Path, prompt_snapshot: str, model: str, comparison_group: str) -> int:
    cur.execute(
        """
        INSERT INTO decode_jobs
        (source_image_filename, source_image_path, prompt_file_path, requested_model, comparison_group, status, attempt_count)
        VALUES (%s, %s, %s, %s, %s, 'queued', 1)
        """,
        (
            source_path.name,
            str(source_path),
            prompt_snapshot,
            model,
            comparison_group,
        ),
    )
    return int(cur.lastrowid)


def resolve_python_bin() -> str:
    configured = os.getenv("PYTHON_BIN", "python3").strip() or "python3"
    if "/" in configured:
        if not os.access(configured, os.X_OK):
            raise RuntimeError(f"PYTHON_BIN is not executable: {configured}")
        return configured

    found = shutil.which(configured)
    if not found:
        raise RuntimeError(f"PYTHON_BIN not found on PATH: {configured}")
    return found


def start_worker(job_id: int, logger: logging.Logger) -> None:
    python_bin = resolve_python_bin()
    worker_script = ROOT / "python" / "decode_job_worker.py"
    if not worker_script.is_file():
        raise RuntimeError(f"Worker script not found: {worker_script}")

    subprocess.Popen(
        [python_bin, str(worker_script), "--job-id", str(job_id)],
        cwd=str(ROOT),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
    logger.info("Started worker for job_id=%s", job_id)


def fetch_jobs(conn, job_ids: list[int]) -> list[dict]:
    if not job_ids:
        return []

    cur = conn.cursor(dictionary=True)
    placeholders = ", ".join(["%s"] * len(job_ids))
    try:
        cur.execute(
            f"""
            SELECT id, source_image_filename, requested_model, status, error_message,
                   created_at, started_at, finished_at
            FROM decode_jobs
            WHERE id IN ({placeholders})
            ORDER BY id ASC
            """,
            tuple(job_ids),
        )
        rows = cur.fetchall() or []
    finally:
        cur.close()
    return rows


def elapsed_seconds(row: dict) -> int:
    status = str(row.get("status") or "queued")
    created = row.get("created_at")
    started = row.get("started_at") or created
    finished = row.get("finished_at") or dt.datetime.now(dt.timezone.utc).replace(tzinfo=None)

    if started is None:
        return 0

    if status == "queued":
        if created is None:
            return 0
        return max(0, int((dt.datetime.now() - created).total_seconds()))

    return max(0, int((finished - started).total_seconds()))


def monitor_created_jobs(conn, job_ids: list[int], logger: logging.Logger) -> int:
    if not job_ids:
        return 0

    timeout_sec = int(os.getenv("GEMINI_TIMEOUT_SEC", "180"))
    timeout_sec = max(10, timeout_sec)
    poll_sec = 2
    deadline = time.time() + timeout_sec
    last_status: dict[int, str] = {}
    terminal = {"succeeded", "failed", "saved", "rejected"}

    print(f"Verbose monitor enabled for {len(job_ids)} job(s). Timeout: {timeout_sec}s")
    logger.info("Verbose monitor start job_ids=%s timeout=%ss", job_ids, timeout_sec)

    while True:
        rows = fetch_jobs(conn, job_ids)
        if not rows:
            print("No jobs found while monitoring")
            logger.warning("No rows returned while monitoring job_ids=%s", job_ids)
            return 1

        all_done = True
        has_failed = False
        for row in rows:
            job_id = int(row.get("id") or 0)
            status = str(row.get("status") or "queued")
            if status not in terminal:
                all_done = False
            if status == "failed":
                has_failed = True

            if last_status.get(job_id) != status:
                err = str(row.get("error_message") or "").strip()
                elapsed = elapsed_seconds(row)
                model = str(row.get("requested_model") or "")
                file_name = str(row.get("source_image_filename") or "")
                msg = f"job {job_id} [{model}] {file_name}: {status} ({elapsed}s)"
                if err:
                    msg += f" error={err}"
                print(msg)
                logger.info(msg)
                last_status[job_id] = status

        if all_done:
            summary = f"All monitored jobs finished. failed={has_failed}"
            print(summary)
            logger.info(summary)
            return 1 if has_failed else 0

        if time.time() >= deadline:
            print(f"Monitoring timeout reached ({timeout_sec}s).")
            logger.warning("Monitoring timeout reached for job_ids=%s", job_ids)
            return 2

        time.sleep(poll_sec)


def expand_to_work_items(paths: list[Path], logger: logging.Logger) -> list[Path]:
    items: list[Path] = []
    for path in paths:
        if path.suffix.lower() == ".pdf":
            prefix = hashlib.md5(f"{path}-{os.getpid()}".encode("utf-8")).hexdigest()[:16]
            converted = convert_pdf_to_images(path, prefix)
            logger.info("Converted PDF %s into %s image(s)", path.name, len(converted))
            items.extend(converted)
        else:
            items.append(path)
    return items


def main() -> int:
    decode_cards.load_env()
    args = parse_args()
    logger = setup_logger(args.log_file, args.verbose)

    conn = db_connection()
    cur = conn.cursor()

    try:
        resolved = expand_inputs(args.inputs)
        allowed = filter_allowed_files(resolved)
        if not allowed:
            raise RuntimeError("No valid input files found. Allowed: jpg, jpeg, png, gif, webp, pdf")

        prompt_path = ensure_prompt_path(conn, args.prompt_file)
        prompt_text = prompt_path.read_text(encoding="utf-8")
        models = resolve_models(conn, args.model)

        work_items = expand_to_work_items(allowed, logger)
        if not work_items:
            raise RuntimeError("No work items to enqueue after preprocessing")

        print("Enqueueing decode jobs")
        print("Prompt:", prompt_path)
        print("Models:", ", ".join(models))
        print("Images:")
        for item in work_items:
            print(" -", item)

        logger.info("CLI enqueue start prompt=%s models=%s items=%s", prompt_path, models, [str(x) for x in work_items])

        created_jobs: list[int] = []
        for item in work_items:
            comparison_group = hashlib.md5(f"{item}-{os.getpid()}".encode("utf-8")).hexdigest()
            for model in models:
                snapshot = create_prompt_snapshot(prompt_text)
                job_id = create_decode_job(cur, item, snapshot, model, comparison_group)
                created_jobs.append(job_id)
                logger.info("Queued job_id=%s file=%s model=%s group=%s", job_id, item, model, comparison_group)
                if args.verbose:
                    print(f"queued job_id={job_id} model={model} file={item}")

        conn.commit()

        for job_id in created_jobs:
            start_worker(job_id, logger)

        print(f"Queued {len(created_jobs)} jobs for {len(work_items)} image(s) across {len(models)} model(s).")
        print(f"Log file: {Path(args.log_file).resolve()}")
        logger.info("CLI enqueue complete jobs=%s", created_jobs)
        if args.verbose:
            return monitor_created_jobs(conn, created_jobs, logger)
        return 0

    finally:
        cur.close()
        conn.close()


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:  # noqa: BLE001
        print(f"Error: {exc}", file=sys.stderr)
        raise SystemExit(1)
