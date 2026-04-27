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

## Main workflow

1. Open Upload and Decode and submit images or PDFs.
2. Monitor decode jobs in the jobs table while workers process files in background.
3. Open a finished job for review and resolve model differences where needed.
4. Save the final record and continue editing in the record editor.

## Architecture at a glance

- PHP frontend and backend endpoints in public.
- MySQL storage with SQL migrations in db/migrations.
- Python decoding workers and OCR pipeline in python.
- Prompt templates in prompts.

## Notes

- Background workers are started from the web layer and process decode jobs asynchronously.
- Preferred Gemini models and prompt selection are configurable from the settings page.
- Source images are preserved so saved records remain traceable to the original card image.

See `INSTALL.md` for setup and usage.
