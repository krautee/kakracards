#!/usr/bin/env python3
import argparse
import base64
import json
import mimetypes
import os
import sys
from pathlib import Path

import mysql.connector
import requests
from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parents[1]


def load_env() -> None:
    load_dotenv(ROOT / ".env")


def read_prompt(prompt_file: str | None) -> str:
    path = Path(prompt_file or os.getenv("PROMPT_FILE", ROOT / "prompts/default_prompt.md"))
    return path.read_text(encoding="utf-8")


def call_gemini(image_path: Path, prompt_text: str) -> dict:
    api_key = os.getenv("GEMINI_API_KEY", "").strip()
    if not api_key:
        raise RuntimeError("GEMINI_API_KEY is required")

    model = os.getenv("GEMINI_MODEL", "gemini-2.5-flash")
    mime_type = mimetypes.guess_type(image_path.name)[0] or "image/jpeg"
    image_b64 = base64.b64encode(image_path.read_bytes()).decode("ascii")

    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
    payload = {
        "contents": [
            {
                "parts": [
                    {"text": prompt_text},
                    {"inline_data": {"mime_type": mime_type, "data": image_b64}},
                ]
            }
        ],
        "generationConfig": {
            "temperature": 0,
            "responseMimeType": "application/json",
        },
    }
    response = requests.post(url, params={"key": api_key}, json=payload, timeout=180)
    response.raise_for_status()
    data = response.json()

    parts = data.get("candidates", [{}])[0].get("content", {}).get("parts", [{}])
    text = parts[0].get("text", "{}").strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[-1].rsplit("```", 1)[0].strip()
    parsed = json.loads(text)
    if not isinstance(parsed, dict):
        raise RuntimeError("Gemini response is not a JSON object")
    return parsed


def db_connection():
    return mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "127.0.0.1"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        database=os.getenv("MYSQL_DATABASE", "kakracards"),
        user=os.getenv("MYSQL_USER", "root"),
        password=os.getenv("MYSQL_PASSWORD", ""),
    )


def insert_decoding(conn, decoded: dict, image_path: Path) -> int:
    header = decoded.get("header", {}) if isinstance(decoded.get("header"), dict) else {}
    content_rows = decoded.get("content") if isinstance(decoded.get("content"), list) else []
    recovery_rows = decoded.get("recovery") if isinstance(decoded.get("recovery"), list) else []
    uncertainties = decoded.get("uncertainties") if isinstance(decoded.get("uncertainties"), list) else []

    bird_id = str(header.get("bird_id", "")).strip()

    cur = conn.cursor()
    cur.execute(
        """
        INSERT INTO cards_header (
            bird_id, card_code, sex, ring_position, ring_number, ringing_age,
            ringing_date, ringing_nest, scull_length, scull_repeat,
            source_image_filename, source_image_path, uncertainties, raw_response_json
        ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """,
        (
            bird_id,
            header.get("card_code"),
            header.get("sex"),
            header.get("ring_position"),
            header.get("ring_number"),
            header.get("ringing_age"),
            header.get("ringing_date"),
            header.get("ringing_nest"),
            header.get("scull_length"),
            header.get("scull_repeat"),
            image_path.name,
            str(image_path),
            "\n".join(str(x) for x in uncertainties),
            json.dumps(decoded, ensure_ascii=False),
        ),
    )
    header_id = cur.lastrowid

    for idx, row in enumerate(content_rows, start=1):
        if not isinstance(row, dict):
            continue
        cur.execute(
            """
            INSERT INTO cards_content (
                header_id, bird_id, row_no, ring_position, ring_number,
                obs_status, obs_year, obs_nest, obs_notes, decoding_status
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                header_id,
                bird_id,
                idx,
                row.get("ring_position"),
                row.get("ring_number"),
                row.get("obs_status"),
                row.get("obs_year"),
                row.get("obs_nest"),
                row.get("obs_notes"),
                row.get("decoding_status"),
            ),
        )

    for idx, row in enumerate(recovery_rows, start=1):
        if not isinstance(row, dict):
            continue
        cur.execute(
            """
            INSERT INTO cards_recovery (
                header_id, bird_id, row_no, ring_number, recovery_status,
                recovery_date, recovery_location, recovery_person, recovery_notes
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                header_id,
                bird_id,
                idx,
                row.get("ring_number"),
                row.get("recovery_status"),
                row.get("recovery_date"),
                row.get("recovery_location"),
                row.get("recovery_person"),
                row.get("recovery_notes"),
            ),
        )

    conn.commit()
    return header_id


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Decode Kakra cards with Gemini API")
    parser.add_argument("--image", nargs="+", required=True, help="Path(s) to card image(s)")
    parser.add_argument("--prompt-file", help="Prompt markdown file path")
    parser.add_argument("--no-db", action="store_true", help="Do not insert results to MySQL")
    parser.add_argument("--json", action="store_true", help="Print JSON output")
    return parser.parse_args()


def main() -> int:
    load_env()
    args = parse_args()

    prompt_text = read_prompt(args.prompt_file)
    conn = None if args.no_db else db_connection()

    try:
        results = []
        for img in args.image:
            image_path = Path(img).resolve()
            decoded = call_gemini(image_path, prompt_text)
            entry = {
                "image": str(image_path),
                "decoded": decoded,
            }
            if conn is not None:
                header_id = insert_decoding(conn, decoded, image_path)
                entry["header_id"] = header_id
            results.append(entry)

        if args.json:
            print(json.dumps(results, ensure_ascii=False, indent=2))
        else:
            for result in results:
                msg = f"Decoded: {result['image']}"
                if "header_id" in result:
                    msg += f" -> cards_header.id={result['header_id']}"
                print(msg)

    finally:
        if conn is not None and conn.is_connected():
            conn.close()

    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except requests.HTTPError as exc:
        body = exc.response.text if exc.response is not None else str(exc)
        print(f"Gemini API error: {body}", file=sys.stderr)
        raise SystemExit(2)
    except Exception as exc:
        print(f"Error: {exc}", file=sys.stderr)
        raise SystemExit(1)
