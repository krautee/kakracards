#!/usr/bin/env python3
import argparse
import base64
import json
import mimetypes
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load_env() -> None:
    env_path = ROOT / ".env"
    if not env_path.is_file():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        name, value = line.split("=", 1)
        name = name.strip()
        value = value.strip()
        if name and name not in os.environ:
            os.environ[name] = value


def read_prompt(prompt_file: str | None) -> str:
    path = Path(prompt_file or os.getenv("PROMPT_FILE", ROOT / "prompts/default_prompt.md"))
    return path.read_text(encoding="utf-8")


def _parse_json_object(text: str, provider: str) -> dict:
    text = text.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[-1].rsplit("```", 1)[0].strip()
    parsed = json.loads(text)
    if not isinstance(parsed, dict):
        raise RuntimeError(f"{provider} response is not a JSON object")
    return parsed


def call_model(image_path: Path, prompt_text: str, model_override: str | None = None) -> dict:
    """Dispatch a decode request to the right provider based on the model name.

    OpenRouter model ids are always "<vendor>/<name>" (e.g. "anthropic/claude-sonnet-4.5",
    "qwen/qwen2.5-vl-72b-instruct"), while Gemini's own model ids never contain a slash
    (e.g. "gemini-2.5-flash"). That distinction is used to route the call without needing
    an extra prefix convention.
    """
    model = (model_override or os.getenv("GEMINI_MODEL", "gemini-2.5-flash")).strip()
    if "/" in model:
        return call_openrouter(image_path, prompt_text, model)
    return call_gemini(image_path, prompt_text, model)


def call_gemini(image_path: Path, prompt_text: str, model_override: str | None = None) -> dict:
    api_key = os.getenv("GEMINI_API_KEY", "").strip()
    if not api_key:
        raise RuntimeError("GEMINI_API_KEY is required")

    model = (model_override or os.getenv("GEMINI_MODEL", "gemini-2.5-flash")).strip()
    timeout_sec = int(os.getenv("GEMINI_TIMEOUT_SEC", "180"))
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
    request = urllib.request.Request(
        f"{url}?{urllib.parse.urlencode({'key': api_key})}",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=timeout_sec) as response:
            data = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Gemini API error: {body}") from exc
    except TimeoutError as exc:
        raise RuntimeError(f"Gemini read timed out after {timeout_sec}s") from exc

    parts = data.get("candidates", [{}])[0].get("content", {}).get("parts", [{}])
    text = parts[0].get("text", "{}")
    parsed = _parse_json_object(text, "Gemini")

    usage_metadata = data.get("usageMetadata")
    if not isinstance(usage_metadata, dict):
        usage_metadata = {}

    return {
        "decoded": parsed,
        "usageMetadata": usage_metadata,
        "model": model,
    }


def call_openrouter(image_path: Path, prompt_text: str, model: str) -> dict:
    api_key = os.getenv("OPENROUTER_API_KEY", "").strip()
    if not api_key:
        raise RuntimeError("OPENROUTER_API_KEY is required")

    timeout_sec = int(os.getenv("OPENROUTER_TIMEOUT_SEC", os.getenv("GEMINI_TIMEOUT_SEC", "180")))
    mime_type = mimetypes.guess_type(image_path.name)[0] or "image/jpeg"
    image_b64 = base64.b64encode(image_path.read_bytes()).decode("ascii")

    payload = {
        "model": model,
        "temperature": 0,
        "messages": [
            {
                "role": "user",
                "content": [
                    {"type": "text", "text": prompt_text},
                    {
                        "type": "image_url",
                        "image_url": {"url": f"data:{mime_type};base64,{image_b64}"},
                    },
                ],
            }
        ],
    }
    request = urllib.request.Request(
        "https://openrouter.ai/api/v1/chat/completions",
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {api_key}",
            "HTTP-Referer": os.getenv("APP_BASE_URL", "http://localhost"),
            "X-Title": "KakraCards",
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=timeout_sec) as response:
            data = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"OpenRouter API error: {body}") from exc
    except TimeoutError as exc:
        raise RuntimeError(f"OpenRouter read timed out after {timeout_sec}s") from exc

    if isinstance(data.get("error"), dict):
        raise RuntimeError(f"OpenRouter API error: {data['error']}")

    choices = data.get("choices") or [{}]
    message = choices[0].get("message", {}) if isinstance(choices[0], dict) else {}
    content = message.get("content", "{}")
    if isinstance(content, list):
        content = "".join(
            str(part.get("text", "")) for part in content if isinstance(part, dict)
        )
    parsed = _parse_json_object(str(content), "OpenRouter")

    usage = data.get("usage")
    usage_metadata = {}
    if isinstance(usage, dict):
        usage_metadata = {
            "totalTokenCount": int(usage.get("total_tokens") or 0),
            "promptTokenCount": int(usage.get("prompt_tokens") or 0),
            "candidatesTokenCount": int(usage.get("completion_tokens") or 0),
        }

    return {
        "decoded": parsed,
        "usageMetadata": usage_metadata,
        "model": str(data.get("model") or model),
    }


def db_connection():
    try:
        import mysql.connector
    except ModuleNotFoundError as exc:
        raise RuntimeError(
            "mysql-connector-python is required only when saving to MySQL; install python/requirements.txt or run with --no-db"
        ) from exc

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
    parser.add_argument(
        "--model",
        help="Model to use for this run: a Gemini model id (e.g. gemini-2.5-flash) "
        "or an OpenRouter model id containing a slash (e.g. anthropic/claude-sonnet-4.5)",
    )
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
            result = call_model(image_path, prompt_text, args.model)
            decoded = result["decoded"]
            entry = {
                "image": str(image_path),
                "decoded": decoded,
                "usageMetadata": result["usageMetadata"],
                "model": result["model"],
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
    except Exception as exc:
        print(f"Error: {exc}", file=sys.stderr)
        raise SystemExit(1)
