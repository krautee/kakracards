-- Cost tracking, prompt provenance and benchmark re-run support.
--
-- decode_jobs.prompt_file_path is a throwaway /tmp snapshot that the worker deletes,
-- so the prompt that produced a result was previously untraceable. Every job (and
-- every quality row) now carries the prompt's sha256, and prompt_versions keeps the
-- text for each hash so any past run can be reproduced or compared per prompt version.

CREATE TABLE IF NOT EXISTS prompt_versions (
    sha256 CHAR(64) PRIMARY KEY,
    prompt_name VARCHAR(255) NOT NULL,
    prompt_text MEDIUMTEXT NOT NULL,
    first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE decode_jobs
    ADD COLUMN prompt_name VARCHAR(255) NULL AFTER prompt_file_path,
    ADD COLUMN prompt_sha256 CHAR(64) NULL AFTER prompt_name,
    ADD COLUMN prompt_token_count INT UNSIGNED NULL AFTER total_token_count,
    ADD COLUMN completion_token_count INT UNSIGNED NULL AFTER prompt_token_count,
    ADD COLUMN reasoning_token_count INT UNSIGNED NULL AFTER completion_token_count,
    ADD COLUMN cost_usd DECIMAL(10, 6) NULL AFTER reasoning_token_count,
    ADD COLUMN benchmark_header_id BIGINT UNSIGNED NULL AFTER saved_header_id,
    ADD INDEX idx_decode_jobs_prompt (prompt_sha256),
    ADD INDEX idx_decode_jobs_benchmark (benchmark_header_id);

ALTER TABLE decode_field_quality
    ADD COLUMN prompt_sha256 CHAR(64) NULL AFTER prompt_file_path,
    ADD COLUMN char_distance INT UNSIGNED NULL AFTER normalized_match,
    ADD COLUMN inherited_from_previous TINYINT(1) NOT NULL DEFAULT 0 AFTER char_distance,
    ADD COLUMN predicted_decoding_status VARCHAR(16) NULL AFTER inherited_from_previous,
    ADD COLUMN is_benchmark TINYINT(1) NOT NULL DEFAULT 0 AFTER manually_corrected,
    ADD INDEX idx_dfq_prompt (prompt_sha256),
    ADD INDEX idx_dfq_benchmark (is_benchmark);
