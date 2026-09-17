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
  stats.php            # evaluation UI: paired comparison, metrics, heatmap, confusion report
  decode_job_worker.php  # CLI-only: PHP-side job runner, shells out to python/decode_cards.py
  score_benchmark_job.php # CLI-only: score one benchmark job against its saved record
  rescore_quality.php  # CLI-only maintenance: cost backfill, rescore all quality rows
  image.php            # serves source images with auth/path checks

python/
  decode_cards.py        # single source of truth for calling the LLM (Gemini + OpenRouter)
                          # + optional direct-to-MySQL insert (used by CLI only, not by the job workers)
  decode_job_worker.py    # standalone Python job runner (CLI enqueue + benchmark paths)
  enqueue_decode_jobs.py  # CLI: glob inputs, convert PDFs, create decode_jobs rows,
                          # spawn one decode_job_worker per (image, model) pair
  benchmark.py            # re-run reviewed cards through models/prompts, auto-score
  refresh_model_pricing.py / model_pricing.json  # USD per 1M tokens (OpenRouter catalog)

db/migrations/*.sql   # run in order, no migration framework
prompts/*.md          # prompt templates; active one tracked in app_settings.active_prompt_file
uploads/, logs/       # gitignored runtime dirs
.env / .env-sample    # config; .env is gitignored — safe to hold real secrets
```

## Two parallel job-runner implementations (important)

There are **two independent code paths that both end up calling `python/decode_cards.py`**:

1. **Browser upload flow**: `upload.php` creates `decode_jobs` rows via
   `createDecodeJob()` and spawns `public/decode_job_worker.php`
   as a background CLI process (`startDecodeJobWorker`). That PHP
   worker calls `decodeImageWithPythonPrompt()`, which shells out to
   `python3 python/decode_cards.py --image ... --prompt-file ... --model ... --no-db --json`
   and parses the JSON on stdout.
2. **CLI enqueue flow**: `python/enqueue_decode_jobs.py` creates the same `decode_jobs`
   rows directly in Python and spawns `python/decode_job_worker.py --job-id N`, which
   calls `decode_cards.call_model()` **in-process** (no subprocess). `python/benchmark.py`
   uses the same worker.

Both paths read/write the same `decode_jobs` table and both bottleneck through
`decode_cards.py`'s model-calling function. **Any provider/model change must be made in
`decode_cards.py` once** — both PHP and Python job runners pick it up automatically
(PHP via subprocess, Python via import). Do not duplicate provider logic into
`decode_job_worker.php`.

## Model selection (Gemini direct + OpenRouter)

- `python/decode_cards.py::call_model()` is the only place that talks to an LLM. A model
  id containing `/` (OpenRouter's `vendor/name`) goes to `call_openrouter()`, anything else
  to `call_gemini()`. Both return `{decoded, usageMetadata, model, resolvedModel}` where
  `usageMetadata` uses Gemini's key names plus `costUsd` (measured by OpenRouter via
  `usage.include`, estimated for Gemini from `python/model_pricing.json`, refreshed by
  `python/refresh_model_pricing.py`) and `thoughtsTokenCount` for reasoning tokens.
  `OPENROUTER_REASONING_EFFORT` (default low) caps reasoning spend; `_post_json()` retries
  DNS/timeout/429/5xx failures (`LLM_HTTP_RETRIES`).
- `resolvedModel` is the concrete version the provider reports (Gemini `modelVersion`),
  stored in `decode_jobs.decoding_model`, so alias jobs (`gemini-flash-latest`) show up
  under their real version in stats.
- `public/bootstrap.php::geminiAvailableModels()` (line 87) calls Gemini's `/models`
  list endpoint live to populate the settings/upload checkboxes; falls back to a
  hardcoded list of 4 if that fails.
- `app_settings` table (key/value) stores `preferred_gemini_models` (JSON array) and
  `active_prompt_file`. Preferred models are the checkbox defaults on the upload page
  and the fallback model list for CLI enqueue when `--model` isn't passed.
- `decode_jobs.requested_model` / `decode_jobs.decoding_model` are free-text
  `VARCHAR(128)`; OpenRouter ids like `anthropic/claude-sonnet-4.5` are stored as-is.
- Comparison/review UI groups jobs by `comparison_group` (same source image, N models,
  one job per model) — see `buildComparisonData()`.
- Quality tracking (`decode_field_quality`) and the Statistics page key everything off
  the free-text `model` column, `cost_usd` and the token/time columns — provider-agnostic.

## Prompt contract

`prompts/*.md` instructs the model to return **strict JSON only** (no markdown fence)
matching a fixed schema: `header` (object), `content` (array of rows), `recovery`
(array of rows), plus an `uncertainties` list. All downstream code (insert into
`cards_header`/`cards_content`/`cards_recovery`, comparison UI, field-quality scoring)
depends on this exact shape regardless of which model produced it. Any new provider
must be prompted the same way and must return parseable JSON (strip fences defensively,
as `call_gemini` already does for backtick-fenced responses).

## Ground truth, scoring and benchmarking (the evaluation core)

- Every saved record (`cards_header` + `cards_content` + `cards_recovery`) is human-verified
  ground truth. `computeFieldQualityRows($decoded, $final)` in bootstrap.php compares one
  model output with it and returns one row per field; `insertFieldQualityRows()` writes
  them to `decode_field_quality`. **Both** the manual "Accept and Save" path
  (`saveDecodeFieldQuality`) and benchmark re-runs (`scoreBenchmarkJob`) use this one
  function — never fork the scoring logic.
- Scoring details worth knowing: rows are aligned to final rows by content
  (`decodeQualityAlignRows`, weighted similarity + monotone DP), not by position; a
  final row the model did not produce yields `error_type='missing_row'` on every field,
  a predicted row with no counterpart `extra_row`. Blank rows on either side are ignored.
  `char_distance` is a UTF-8 Levenshtein on normalized values. `inherited_from_previous`
  marks content-row ring number/position equal to the previous predicted row (or header),
  so stats can count a propagated misread once. `predicted_decoding_status` keeps the
  model's own OK/CHECK/MESS. `decodeQualityNormalizeValue` canonicalizes dates (d.m.yyyy,
  2-digit years expanded), sex symbols, arrows and whitespace.
- **Benchmark mode**: `python/benchmark.py --model ... [--prompt file] [--headers ids]`
  creates `decode_jobs` with `benchmark_header_id` set, runs `decode_job_worker.py` with a
  concurrency limit, and each worker calls `public/score_benchmark_job.php` after decoding.
  Scored jobs get status `benchmarked` and are hidden from the upload page. A
  (card, model, prompt sha) triple already benchmarked is skipped unless `--force`.
  `--dry-run` prints the plan and cost estimate.
- **Prompt provenance**: every job stores `prompt_name` + `prompt_sha256`; the text lives in
  `prompt_versions`. Retrying a job re-materializes the same version. The stats page's
  prompt-version panel and its prompt filter key off the sha.
- `public/rescore_quality.php --all` backfills cost/token splits for old jobs and rescores
  all quality rows with the current scoring code (keeps `manually_corrected`). Run it after
  changing anything in the scoring/normalization functions.
- `public/stats.php` is the analysis UI: paired comparison (only cards all models share),
  benchmark/review/prompt filters, accuracy, unique-error accuracy, row recall/precision,
  omission vs hallucination, null-field accuracy, CER, OK/CHECK calibration, cost per
  card, per-field heatmap, and a confusion report (most frequent predicted→final pairs)
  which is the input for prompt tuning.

## Prompt tuning loop

1. Look at the confusion report for a model/prompt on stats.php; repeated pairs across
   cards are conventions the prompt does not state (not handwriting problems).
2. Write a new prompt file in `prompts/` (keep old versions; they are cheap and the sha
   makes results comparable). `prompts/paigutus_v2.md` documents the card layout and the
   field vocabularies derived from the ground truth.
3. `python3 python/benchmark.py --prompt prompts/<new>.md --model ...` on the reviewed cards.
4. Compare in the "Prompt versions" panel; switch the active prompt in Settings when it wins.

## Adding a new model provider

Add a branch in `call_model()` and a `call_<provider>()` that keeps the return contract
above (normalize usage into the Gemini-shaped keys + `costUsd`). Add the models to the
allow-list mechanism (`allowedOpenRouterModels()` reads `OPENROUTER_MODELS`; a new
provider needs its own list merged in `allowedModels()`). No DB migration is needed:
model columns are free text.

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

## Data quality notes

- Ground truth is only as good as the review: blank template rows have been saved before
  (now filtered on save and in scoring), two records carry `ringing_date = 19.09.2026`
  (date-picker default), sex is sometimes ♀/♂ instead of F/M, and some dates are ISO.
  Scoring normalizes what it can; fix the records in the editor when you find them.
- Gemini thinking tokens dominate some models' cost (`gemini-flash-latest` was 2x the
  cost of `gemini-3.1-pro` per card). Compare on `cost_usd`, never on token counts.
