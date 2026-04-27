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

## Quality tracking and statistics

- Saving reviewed records stores per-field quality metrics in `decode_field_quality`.
- Manual corrections are tracked and reflected in stats.
- Statistics page summarizes:
	- per-model token/time performance
	- normalized/exact match rates
	- manual correction rates
	- weakest model/field pairs for prompt tuning

Migrations related to these features:

- `db/migrations/004_decode_field_quality.sql`
- `db/migrations/005_decode_field_quality_manual_corrections.sql`

## Architecture at a glance

- PHP frontend and backend endpoints in public.
- MySQL storage with SQL migrations in db/migrations.
- Python decoding workers and OCR pipeline in python.
- Prompt templates in prompts.

## Notes

- Background workers are started from the web layer and process decode jobs asynchronously.
- Preferred Gemini models and prompt selection are configurable from the settings page.
- Source images are preserved so saved records remain traceable to the original card image.

## CLI enqueue (phase 1)

You can enqueue decode jobs from CLI and keep using the browser for live progress and review.

Example with explicit models:

```bash
python3 python/enqueue_decode_jobs.py uploads/card1.jpg uploads/card2*.jpg \
	--model=gemini-2.5-flash \
	--model=gemini-3.1-pro
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
