# kakracards

KakraCards is a web application for digitizing Kakra Gull ringing cards from scanned images and PDFs.
It combines OCR and LLM-assisted decoding, then lets you review, compare, and correct extracted values before saving them to a structured database.

## What the application does

- Upload one or many card images or PDF files from the browser.
- Queue background decoding jobs so large batches do not block the UI.
- Run the same card through multiple Gemini models in parallel for comparison.
- Review conflicting values side by side and accept the final result.
- Save decoded records into normalized tables for header, content rows, and recovery rows.
- Track decode job status, retry failed jobs, and archive failed items from the job list.
- View model/token/time quality analytics in a dedicated Statistics page.
- Edit saved records with an image preview that supports zoom and pan.

## Main workflow

1. Open Upload and Decode and submit images or PDFs.
2. Monitor decode jobs in the jobs table while workers process files in background.
3. Open a finished job for review and resolve model differences where needed.
4. Save the final record and continue editing in the record editor.

## UI and editing highlights

- Unified top navigation is available across index, upload, settings, stats, and edit pages.
- Pages use a consistent wide layout and dimmed beige background for easier cross-page scanning.
- Upload review and edit pages include zoomable + pannable image previews (scroll to zoom, drag to pan when zoomed).
- Date fields in editor/review support calendar popup behavior for ringing and recovery dates.
- Content and recovery rows support add, insert, and delete controls directly in table UI.

## Quality tracking, benchmarking and statistics

- Every saved record is human-verified ground truth. Saving a reviewed record scores
  each model's output against it, field by field, into `decode_field_quality`.
- **Benchmark re-runs** (`python/benchmark.py`) decode already-reviewed cards again with
  any model or prompt version and score them automatically — no manual review needed.
  This is how new models and prompt versions are evaluated:

  ```bash
  python3 python/benchmark.py --model anthropic/claude-opus-5 --model x-ai/grok-4.6 --dry-run   # plan + cost estimate
  python3 python/benchmark.py --model anthropic/claude-opus-5 --model x-ai/grok-4.6             # run
  python3 python/benchmark.py --prompt prompts/paigutus_v2.md --model gemini-3.1-pro-preview   # try a prompt version
  ```

- Every job records the prompt version (sha256, text kept in `prompt_versions`), the
  measured or estimated cost in USD, and prompt/answer/reasoning token counts.
- The Statistics page compares models on a common card set (paired mode) and shows
  accuracy, unique-error accuracy, row recall/precision, omission vs hallucination,
  null-field accuracy, character error rate, calibration of the model's own OK/CHECK
  flags, cost per card, a per-field heatmap, a confusion report of the most frequent
  misreadings (the input for prompt tuning) and accuracy per prompt version.
- `php public/rescore_quality.php --all` rescores historical rows after scoring changes.

Migrations related to these features:

- `db/migrations/004_decode_field_quality.sql`
- `db/migrations/005_decode_field_quality_manual_corrections.sql`
- `db/migrations/006_cost_prompt_provenance_benchmark.sql`

## Architecture at a glance

- PHP frontend and backend endpoints in public.
- MySQL storage with SQL migrations in db/migrations.
- Python decoding workers and OCR pipeline in python.
- Prompt templates in prompts.

## Notes

- Background workers are started from the web layer and process decode jobs asynchronously.
- Preferred models and prompt selection are configurable from the settings page.
- Source images are preserved so saved records remain traceable to the original card image.
- Gemini is called directly; any other provider's model (Claude, GPT, Qwen, etc.) is
  reached through [OpenRouter](https://openrouter.ai) using a curated model allow-list.
  See `INSTALL.md` → "Adding OpenRouter models". A model string containing a `/` is
  routed to OpenRouter; comparison, review, and the Statistics page work the same
  regardless of which provider produced a result.

## CLI enqueue (phase 1)

You can enqueue decode jobs from CLI and keep using the browser for live progress and review.

Example with explicit models, mixing Gemini and OpenRouter:

```bash
python3 python/enqueue_decode_jobs.py uploads/card1.jpg uploads/card2*.jpg \
	--model=gemini-2.5-flash \
	--model=gemini-3.1-pro \
	--model=anthropic/claude-sonnet-4.5
```

Behavior:

- defaults prompt file to active prompt from DB settings (`app_settings.active_prompt_file`)
- you can override the prompt with `--prompt=prompts/default_prompt.md` or another relative/absolute path
- defaults model list to preferred models from DB settings if `--model` is omitted
- accepts files and glob patterns
- creates `decode_jobs` entries like browser upload flow
- starts background workers immediately
- writes logs to `logs/decode_cli.log`
- with `-v`, monitors only jobs created in current invocation and prints status/elapsed/error updates
- `-v` monitor timeout uses `GEMINI_TIMEOUT_SEC` from `.env`

See `INSTALL.md` for setup and usage.
