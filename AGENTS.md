# AGENTS.md — KakraCards

Notes for an AI agent picking up work in this repo. Read this before making changes; update it when architecture shifts.

## What this app is

KakraCards digitizes handwritten Kakra Gull ringing cards (scanned images/PDFs) using
LLM vision decoding, lets a human review/reconcile results from multiple models, saves
the corrected record to MySQL, and tracks which models are most accurate/cheap/fast so
the best one(s) can be chosen going forward.

Stack: PHP (no framework, procedural, `public/bootstrap.php` is the shared library) +
Python (decoding workers, CLI) + MySQL. No JS build step; vanilla JS inline in the PHP
pages.

## Repo map

```
public/
  bootstrap.php        # ~1650 lines: env loading, PDO, model lists, job CRUD,
                        # PDF conversion, subprocess helpers, quality-tracking, HTML helpers
  index.php            # list/open saved records
  upload.php           # upload UI, model checkboxes, job queue table, review/compare UI
  edit.php             # record editor with image pan/zoom preview
  settings.php         # prompt file editor + preferred-model checkboxes
  stats.php            # per-model token/time/accuracy analytics
  decode_job_worker.php  # CLI-only: PHP-side job runner, shells out to python/decode_cards.py
  image.php            # serves source images with auth/path checks

python/
  decode_cards.py        # single source of truth for calling the LLM (currently Gemini only)
                          # + optional direct-to-MySQL insert (used by CLI only, not by the job workers)
  decode_job_worker.py    # standalone Python job runner (used by CLI enqueue path)
  enqueue_decode_jobs.py  # CLI: glob inputs, convert PDFs, create decode_jobs rows,
                          # spawn one decode_job_worker per (image, model) pair

db/migrations/*.sql   # run in order, no migration framework
prompts/*.md          # prompt templates; active one tracked in app_settings.active_prompt_file
uploads/, logs/       # gitignored runtime dirs
.env / .env-sample    # config; .env is gitignored — safe to hold real secrets
```

## Two parallel job-runner implementations (important)

There are **two independent code paths that both end up calling `python/decode_cards.py`**:

1. **Browser upload flow**: `upload.php` creates `decode_jobs` rows via
   `createDecodeJob()` (bootstrap.php:1000) and spawns `public/decode_job_worker.php`
   as a background CLI process (`startDecodeJobWorker`, bootstrap.php:1214). That PHP
   worker calls `decodeImageWithPythonPrompt()` (bootstrap.php:574), which shells out to
   `python3 python/decode_cards.py --image ... --prompt-file ... --model ... --no-db --json`
   and parses the JSON on stdout.
2. **CLI enqueue flow**: `python/enqueue_decode_jobs.py` creates the same `decode_jobs`
   rows directly in Python and spawns `python/decode_job_worker.py --job-id N`, which
   calls `decode_cards.call_gemini()` **in-process** (no subprocess).

Both paths read/write the same `decode_jobs` table and both bottleneck through
`decode_cards.py`'s model-calling function. **Any provider/model change must be made in
`decode_cards.py` once** — both PHP and Python job runners pick it up automatically
(PHP via subprocess, Python via import). Do not duplicate provider logic into
`decode_job_worker.php`.

## Model selection today (Gemini-only, as of this writing)

- `python/decode_cards.py::call_gemini()` is the only place that talks to an LLM. It
  reads `GEMINI_API_KEY`/`GEMINI_MODEL`/`GEMINI_TIMEOUT_SEC` from `.env`, POSTs to
  `generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` with
  `responseMimeType: application/json`, temperature 0, and returns
  `{decoded, usageMetadata, model}`. `usageMetadata` uses Gemini's field names
  (`totalTokenCount`, `promptTokenCount`, `candidatesTokenCount`, `thoughtsTokenCount`,
  `promptTokenCountDetails.{text,image}TokenCount`).
- `public/bootstrap.php::geminiAvailableModels()` (line 87) calls Gemini's `/models`
  list endpoint live to populate the settings/upload checkboxes; falls back to a
  hardcoded list of 4 if that fails.
- `app_settings` table (key/value) stores `preferred_gemini_models` (JSON array) and
  `active_prompt_file`. Preferred models are the checkbox defaults on the upload page
  and the fallback model list for CLI enqueue when `--model` isn't passed.
- `decode_jobs.requested_model` / `decode_jobs.decoding_model` are free-text
  `VARCHAR(128)` — **not constrained to Gemini**, so provider-prefixed model IDs like
  `openrouter/anthropic/claude-sonnet-4.5` fit without a schema change.
- Comparison/review UI groups jobs by `comparison_group` (same source image, N models,
  one job per model) — see `buildComparisonData()` (bootstrap.php:1088).
- Quality tracking (`decode_field_quality` table, `saveDecodeFieldQuality()`
  bootstrap.php:1395) and the Statistics page (`public/stats.php`) key everything off
  the free-text `model` column and `total_token_count`/`started_at`/`finished_at` —
  **fully provider-agnostic already**. Adding new model strings requires no changes to
  stats/quality code, as long as the decoder normalizes usage into the existing
  `total_token_count` shape.

## Prompt contract

`prompts/*.md` instructs the model to return **strict JSON only** (no markdown fence)
matching a fixed schema: `header` (object), `content` (array of rows), `recovery`
(array of rows), plus an `uncertainties` list. All downstream code (insert into
`cards_header`/`cards_content`/`cards_recovery`, comparison UI, field-quality scoring)
depends on this exact shape regardless of which model produced it. Any new provider
must be prompted the same way and must return parseable JSON (strip fences defensively,
as `call_gemini` already does for backtick-fenced responses).

## Adding a new model provider — where to change things

1. `python/decode_cards.py`: generalize `call_gemini(image_path, prompt_text, model_override)`
   into a dispatcher (e.g. `call_model()`) that routes by a model-string prefix/convention
   (e.g. `openrouter/<vendor>/<name>` vs a bare Gemini model id) to a provider-specific
   function. Keep the return contract identical: `{"decoded": dict, "usageMetadata": dict, "model": str}`,
   with `usageMetadata` normalized to the existing Gemini-shaped keys so `total_token_count`
   and stats keep working unchanged.
2. `public/bootstrap.php`: `allowedGeminiModels()` / `geminiAvailableModels()` are
   Gemini-specific names but are the only place the UI sources its checkbox list from.
   Either rename to a provider-agnostic `allowedModels()` that merges Gemini + configured
   OpenRouter models, or add a parallel `allowedOpenRouterModels()` and merge at the
   call sites (`settings.php`, `upload.php`). `savePreferredGeminiModels()` /
   `preferredGeminiModels()` similarly validate against `allowedGeminiModels()` — that
   allow-list check must include new provider models or saving them will be rejected.
3. `.env` / `.env-sample`: add `OPENROUTER_API_KEY` (and optionally
   `OPENROUTER_TIMEOUT_SEC`, a curated `OPENROUTER_MODELS` list to avoid querying
   OpenRouter's full model catalog live).
4. No DB migration needed — `requested_model`/`decoding_model` columns already accept
   arbitrary strings up to 128 chars, and `decode_field_quality.model` is unconstrained too.
5. Test both job-runner paths (browser upload spawn, and `enqueue_decode_jobs.py --model=...`)
   since they independently invoke the decoder.

## Conventions / gotchas

- `.env` is gitignored — real secrets belong there, never in `.env-sample` or committed files.
- PHP subprocess spawns always validate the binary via `which` first
  (`safePythonBin()`, `safePhpBin()`) and use `bypass_shell => true` — keep that pattern
  for any new subprocess calls to avoid shell injection.
- Uploaded prompt files are written as one-shot temp snapshot files per job
  (`create_prompt_snapshot` in Python, `tempnam()` in PHP) and deleted after the job
  finishes — so a job always decodes with the exact prompt text active at enqueue time,
  even if the active prompt is edited later.
- Background workers are literally `Popen`/`proc_open` with `start_new_session=True` /
  no wait — there is no job queue daemon, no retry backoff, no concurrency limit. Many
  simultaneous jobs means many simultaneous LLM API calls; be mindful of this when
  fanning out to multiple paid models per image.
- Model display color-coding in `upload.php`'s JS is just a hash of the model string —
  no special-casing needed for new model names.

## Current cost/ops model

Only Gemini is paid for today; OpenRouter introduces per-token costs that vary wildly
by model. There is currently no cost-tracking column — only token counts and elapsed
time are recorded per job. If OpenRouter models are added, consider whether to persist
per-model $/1K-token pricing (even as a static lookup table) so the Statistics page can
show estimated cost, not just token count, for real model-selection decisions.
