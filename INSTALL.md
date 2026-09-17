# Installation and Setup

## 1) Prepare environment file

```bash
cd /path/to/kakracards
cp .env-sample .env
```

Update `.env` with:
- `GEMINI_API_KEY`
- `MYSQL_*` connection values
- optional `GEMINI_TIMEOUT_SEC` (seconds, default `180`)
- optional `PHP_BIN` (CLI binary, default `php`)
- optional `GEMINI_MODEL` and paths
- optional `OPENROUTER_API_KEY` + `OPENROUTER_MODELS` to also compare OpenRouter models
  (e.g. Claude, GPT, Qwen) alongside Gemini — see "Adding OpenRouter models" below
- optional `OPENROUTER_REASONING_EFFORT` (default `low`) to cap hidden reasoning tokens
  on reasoning-capable models, and `LLM_HTTP_RETRIES` (default 3) for transient errors

## 2) Create MySQL schema

```bash
for f in db/migrations/*.sql; do mysql -h <host> -u <user> -p <database> < "$f"; done
```

(The PHP side also adds missing columns on first use, but running the migrations is the
documented path.)

## 3) Python CLI environment

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r python/requirements.txt
```

The decoder itself uses only the Python standard library for HTTP and `.env`
loading. `mysql-connector-python` is only needed if you want to write decoded
results to MySQL from the CLI.

Decode one image and insert into DB:

```bash
python python/decode_cards.py --image /absolute/path/to/card.jpg
```

Decode multiple images at once with custom prompt file:

```bash
python python/decode_cards.py \
  --image /abs/path/card1.jpg /abs/path/card2.jpg \
  --prompt-file /abs/path/prompt.md
```

Preview JSON only (no DB insert):

```bash
python python/decode_cards.py --image /abs/path/card.jpg --no-db --json
```

## 4) Run PHP UI

```bash
php -S 127.0.0.1:8000 -t public
```

Then open:
- `http://127.0.0.1:8000/index.php` list/open records
- `http://127.0.0.1:8000/upload.php` queue/decode/review/save
- `http://127.0.0.1:8000/settings.php` edit Gemini prompt used by UI

If you will upload PDF scans, install Poppler tools (`pdftoppm`) so PDF pages can
be converted to images during queueing.

## Token-use optimization included

- Gemini response constrained to `application/json`
- shared stable prompt from file/settings
- temperature set to `0` for deterministic compact output

## Adding OpenRouter models

Gemini is called directly; any other model (Claude, GPT, Qwen, etc.) goes through
[OpenRouter](https://openrouter.ai), which exposes them behind one OpenAI-compatible API.

1. Set `OPENROUTER_API_KEY` in `.env`.
2. Set `OPENROUTER_MODELS` to a comma-separated list of OpenRouter model ids (each is
   always `<vendor>/<name>`, e.g. `anthropic/claude-sonnet-4.5`). This is a deliberate
   allow-list, not OpenRouter's full catalog — every model you add here is one more
   paid API call per card whenever it's selected for a comparison run.
3. Reopen Settings — the listed models now appear as checkboxes next to Gemini's, and
   checking them adds them to the preferred/default model set used on the Upload page.
4. From the CLI, use the same `--model` flag as Gemini:
   ```bash
   python3 python/enqueue_decode_jobs.py uploads/card1.jpg --model=anthropic/claude-sonnet-4.5
   ```

The model string itself decides routing: anything containing a `/` goes to OpenRouter,
anything without one is treated as a bare Gemini model id. Everything downstream —
job status, the model-comparison review UI, and the Statistics page's token/accuracy
tracking — works the same regardless of provider, since it's all keyed off that string.

## Benchmarking models and prompts

Reviewed records are ground truth, so a model or prompt can be evaluated without any
manual work:

```bash
python3 python/benchmark.py --model openai/gpt-6-astra --model deepseek/deepseek-v4.1-flash --dry-run
python3 python/benchmark.py --model openai/gpt-6-astra --model deepseek/deepseek-v4.1-flash -y --concurrency 3
python3 python/benchmark.py --prompt prompts/paigutus_v2.md --model gemini-3.1-pro-preview
```

Results appear on `stats.php` (choose "Benchmark re-runs only" and a prompt version).
Cost per card is measured by OpenRouter; for Gemini it is estimated from
`python/model_pricing.json`, which `python3 python/refresh_model_pricing.py` regenerates
from OpenRouter's public catalog.
