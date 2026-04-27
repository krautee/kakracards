#!/usr/bin/env python3
import argparse
import json
import os
import sys
from pathlib import Path

import mysql.connector

import decode_cards


def db_connection():
    return mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "127.0.0.1"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        database=os.getenv("MYSQL_DATABASE", "kakracards"),
        user=os.getenv("MYSQL_USER", "root"),
        password=os.getenv("MYSQL_PASSWORD", ""),
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Decode one queued job")
    parser.add_argument("--job-id", type=int, required=True, help="Decode job id")
    return parser.parse_args()


def load_job(cur, job_id: int):
    cur.execute(
        """
        SELECT id, source_image_path, prompt_file_path, requested_model, status
        FROM decode_jobs
        WHERE id=%s
        LIMIT 1
        """,
        (job_id,),
    )
    return cur.fetchone()


def main() -> int:
    decode_cards.load_env()
    args = parse_args()

    conn = db_connection()
    cur = conn.cursor(dictionary=True)

    try:
        job = load_job(cur, args.job_id)
        if not job:
            raise RuntimeError(f"decode_jobs.id={args.job_id} not found")

        if job["status"] in {"succeeded", "saved", "running"}:
            return 0

        cur.execute(
            """
            UPDATE decode_jobs
            SET status='running', started_at=NOW(), finished_at=NULL, error_message=NULL, updated_at=NOW()
            WHERE id=%s
            """,
            (args.job_id,),
        )
        conn.commit()

        prompt_file = str(job.get("prompt_file_path") or "")
        prompt_text = decode_cards.read_prompt(prompt_file)
        requested_model = str(job.get("requested_model") or "").strip() or None
        result = decode_cards.call_gemini(
            Path(str(job["source_image_path"])).resolve(),
            prompt_text,
            requested_model,
        )
        decoded = result["decoded"]
        usage_metadata = result["usageMetadata"]
        model = str(result.get("model") or requested_model or os.getenv("GEMINI_MODEL", "gemini-2.5-flash")).strip()
        total_token_count = int(usage_metadata.get("totalTokenCount") or 0)

        cur.execute(
            """
            UPDATE decode_jobs
            SET status='succeeded',
                decoded_json=%s,
                usage_metadata_json=%s,
                decoding_model=%s,
                total_token_count=%s,
                finished_at=NOW(),
                updated_at=NOW()
            WHERE id=%s
            """,
            (
                json.dumps(decoded, ensure_ascii=False),
                json.dumps(usage_metadata, ensure_ascii=False),
                model,
                total_token_count,
                args.job_id,
            ),
        )
        conn.commit()
        if prompt_file:
            try:
                os.unlink(prompt_file)
            except OSError:
                pass
        return 0

    except Exception as exc:
        cur.execute(
            """
            UPDATE decode_jobs
            SET status='failed', error_message=%s, finished_at=NOW(), updated_at=NOW()
            WHERE id=%s
            """,
            (str(exc)[:2000], args.job_id),
        )
        conn.commit()
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    finally:
        cur.close()
        conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
