-- Free-text changelog per prompt version (what changed and why), shown on prompts.php.
ALTER TABLE prompt_versions ADD COLUMN notes TEXT NULL AFTER prompt_text;
