<?php

declare(strict_types=1);

const ROOT_DIR = __DIR__ . '/..';

function loadEnvFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
        if (getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}

function envValue(string $name, ?string $default = null): ?string
{
    return $_ENV[$name] ?? getenv($name) ?: $default;
}

loadEnvFile(ROOT_DIR . '/.env');

function resolveProjectPath(string $path): string
{
    if ($path === '') {
        return ROOT_DIR;
    }
    if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return $path;
    }

    return ROOT_DIR . '/' . ltrim($path, './');
}

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        envValue('MYSQL_HOST', '127.0.0.1'),
        envValue('MYSQL_PORT', '3306'),
        envValue('MYSQL_DATABASE', 'kakracards')
    );

    $pdo = new PDO($dsn, envValue('MYSQL_USER', 'root'), envValue('MYSQL_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function allowedGeminiModels(): array
{
    $models = geminiAvailableModels();
    if (!empty($models)) {
        return $models;
    }

    return [
        'gemini-2.5-flash',
        'gemini-2.5-pro',
        'gemini-3-flash-preview',
        'gemini-3-pro-preview',
    ];
}

function allowedOpenRouterModels(): array
{
    $apiKey = trim((string) envValue('OPENROUTER_API_KEY', ''));
    if ($apiKey === '') {
        return [];
    }

    $configured = trim((string) envValue('OPENROUTER_MODELS', ''));
    if ($configured === '') {
        return [];
    }

    $models = array_values(array_filter(
        array_map('trim', explode(',', $configured)),
        static fn($value) => $value !== ''
    ));

    return array_values(array_unique($models));
}

/**
 * Combined model allow-list across every configured provider (Gemini + OpenRouter).
 * Use this — not allowedGeminiModels() — anywhere a selection is validated or the
 * full pickable list is shown, so non-Gemini models aren't silently rejected.
 */
function allowedModels(): array
{
    return array_values(array_unique(array_merge(allowedGeminiModels(), allowedOpenRouterModels())));
}

function geminiAvailableModels(): array
{
    $apiKey = trim((string) envValue('GEMINI_API_KEY', ''));
    if ($apiKey === '' || !function_exists('curl_init')) {
        return [];
    }

    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body) || $status < 200 || $status >= 300) {
        return [];
    }

    $json = json_decode($body, true);
    $models = [];
    foreach (($json['models'] ?? []) as $model) {
        if (!is_array($model)) {
            continue;
        }
        $name = (string) ($model['name'] ?? '');
        $methods = is_array($model['supportedGenerationMethods'] ?? null) ? $model['supportedGenerationMethods'] : [];
        if ($name === '' || !in_array('generateContent', $methods, true)) {
            continue;
        }
        $models[] = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
    }
    sort($models, SORT_NATURAL | SORT_FLAG_CASE);

    return array_values(array_unique($models));
}

function preferredGeminiModels(): array
{
    $stmt = pdo()->prepare('SELECT `value` FROM app_settings WHERE `key` = :key LIMIT 1');
    $stmt->execute(['key' => 'preferred_gemini_models']);
    $row = $stmt->fetch();
    $decoded = json_decode((string) ($row['value'] ?? ''), true);
    if (is_array($decoded)) {
        $models = array_values(array_filter(array_map('strval', $decoded), static fn($v) => trim($v) !== ''));
        if (!empty($models)) {
            return $models;
        }
    }

    return [currentGeminiModel()];
}

function savePreferredGeminiModels(array $models): void
{
    $available = allowedModels();
    $selected = [];
    foreach ($models as $model) {
        $model = trim((string) $model);
        if ($model !== '' && in_array($model, $available, true)) {
            $selected[] = $model;
        }
    }
    $selected = array_values(array_unique($selected));
    if (empty($selected)) {
        throw new RuntimeException('Select at least one model');
    }

    $stmt = pdo()->prepare(
        'INSERT INTO app_settings (`key`, `value`) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE `value` = :value_update, updated_at = CURRENT_TIMESTAMP'
    );
    $json = json_encode($selected, JSON_UNESCAPED_UNICODE);
    $stmt->execute([
        'key' => 'preferred_gemini_models',
        'value' => $json,
        'value_update' => $json,
    ]);

    // GEMINI_MODEL is only a last-resort CLI fallback for bare Gemini calls, so only
    // update it when at least one selected model is actually a Gemini model — an
    // OpenRouter-only selection should not touch it.
    $firstGeminiModel = null;
    foreach ($selected as $model) {
        if (in_array($model, allowedGeminiModels(), true)) {
            $firstGeminiModel = $model;
            break;
        }
    }
    if ($firstGeminiModel !== null) {
        saveGeminiModel($firstGeminiModel);
    }
}

function currentGeminiModel(): string
{
    $model = trim((string) envValue('GEMINI_MODEL', 'gemini-2.5-flash'));
    if ($model === '') {
        return 'gemini-2.5-flash';
    }

    return $model;
}

function updateEnvFileValue(string $name, string $value): void
{
    $envPath = ROOT_DIR . '/.env';
    $line = $name . '=' . $value;

    if (!is_file($envPath)) {
        if (file_put_contents($envPath, $line . PHP_EOL) === false) {
            throw new RuntimeException('Failed to write .env');
        }

        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Failed to read .env');
    }

    $updated = false;
    foreach ($lines as $index => $existingLine) {
        if (preg_match('/^\s*' . preg_quote($name, '/') . '\s*=/', $existingLine) === 1) {
            $lines[$index] = $line;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $lines[] = $line;
    }

    $contents = implode(PHP_EOL, $lines) . PHP_EOL;
    if (file_put_contents($envPath, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write .env');
    }
}

function promptDir(): string
{
    $dir = ROOT_DIR . '/prompts';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function sanitizePromptFilename(string $filename): string
{
    $base = basename(trim($filename));
    $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base);
    if ($base === '' || $base === '.' || $base === '..') {
        $base = 'default_prompt.md';
    }
    if (!str_ends_with(strtolower($base), '.md')) {
        $base .= '.md';
    }

    return $base;
}

function promptRelativePathFromFilename(string $filename): string
{
    return './prompts/' . sanitizePromptFilename($filename);
}

function listPromptFiles(): array
{
    $files = glob(promptDir() . '/*.md') ?: [];
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    $relative = [];
    foreach ($files as $file) {
        $relative[] = './prompts/' . basename($file);
    }

    if (empty($relative)) {
        $default = './prompts/default_prompt.md';
        @file_put_contents(resolveProjectPath($default), "");
        $relative[] = $default;
    }

    return $relative;
}

function isAllowedPromptRelativePath(string $relativePath): bool
{
    return in_array($relativePath, listPromptFiles(), true);
}

function getCurrentPromptFileRelativePath(): string
{
    $stmt = pdo()->prepare('SELECT `value` FROM app_settings WHERE `key` = :key LIMIT 1');
    $stmt->execute(['key' => 'active_prompt_file']);
    $row = $stmt->fetch();
    $fromDb = trim((string) ($row['value'] ?? ''));
    if ($fromDb !== '' && is_file(resolveProjectPath($fromDb))) {
        return $fromDb;
    }

    $fromEnv = trim((string) envValue('PROMPT_FILE', './prompts/default_prompt.md'));
    if ($fromEnv === '') {
        $fromEnv = './prompts/default_prompt.md';
    }

    return $fromEnv;
}

function setCurrentPromptFileRelativePath(string $relativePath): void
{
    $stmt = pdo()->prepare(
        'INSERT INTO app_settings (`key`, `value`) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE `value` = :value_update, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'key' => 'active_prompt_file',
        'value' => $relativePath,
        'value_update' => $relativePath,
    ]);

    updateEnvFileValue('PROMPT_FILE', $relativePath);
    $_ENV['PROMPT_FILE'] = $relativePath;
    putenv('PROMPT_FILE=' . $relativePath);
}

function getPromptTextFromRelativePath(string $relativePath): string
{
    $path = resolveProjectPath($relativePath);
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function getPromptText(): string
{
    return getPromptTextFromRelativePath(getCurrentPromptFileRelativePath());
}

function savePromptText(string $text): void
{
    savePromptTextToFile($text, basename(getCurrentPromptFileRelativePath()), true);
}

function savePromptTextToFile(string $text, string $filename, bool $setActive = true): string
{
    $safeFilename = sanitizePromptFilename($filename);
    $relativePath = './prompts/' . $safeFilename;
    $fullPath = resolveProjectPath($relativePath);

    if (file_put_contents($fullPath, $text) === false) {
        throw new RuntimeException('Failed to write prompt file');
    }

    if ($setActive) {
        setCurrentPromptFileRelativePath($relativePath);
    }

    return $relativePath;
}

function saveGeminiModel(string $model): void
{
    $model = trim($model);
    if ($model === '' || !in_array($model, allowedGeminiModels(), true)) {
        throw new RuntimeException('Invalid Gemini model selection');
    }

    updateEnvFileValue('GEMINI_MODEL', $model);
    $_ENV['GEMINI_MODEL'] = $model;
    putenv('GEMINI_MODEL=' . $model);
}

function normalizedModelSelection(array|string|null $selection): array
{
    $values = is_array($selection) ? $selection : [$selection];
    $available = allowedModels();
    $models = [];
    foreach ($values as $model) {
        $model = trim((string) $model);
        if ($model !== '' && in_array($model, $available, true)) {
            $models[] = $model;
        }
    }
    $models = array_values(array_unique($models));

    return empty($models) ? preferredGeminiModels() : $models;
}

function uploadDir(): string
{
    $dir = resolveProjectPath((string) envValue('UPLOAD_DIR', './uploads'));
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function commandExists(string $name): bool
{
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $name)) {
        return false;
    }

    $process = proc_open(
        ['which', $name],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return false;
    }

    $stdout = trim(stream_get_contents($pipes[1]) ?: '');
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    return $status === 0 && $stdout !== '';
}

function convertPdfToImages(string $pdfPath, string $namePrefix): array
{
    if (!commandExists('pdftoppm')) {
        throw new RuntimeException('pdftoppm is required for PDF conversion (install poppler-utils)');
    }
    if (!is_file($pdfPath)) {
        throw new RuntimeException('PDF source file not found');
    }

    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $namePrefix);
    $outputPrefix = rtrim(uploadDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safePrefix;

    $process = proc_open(
        ['pdftoppm', '-jpeg', '-r', '300', $pdfPath, $outputPrefix],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start PDF conversion');
    }

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('PDF conversion failed: ' . trim($stderr !== '' ? $stderr : $stdout));
    }

    $files = glob($outputPrefix . '-*.jpg') ?: [];
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    if (empty($files)) {
        throw new RuntimeException('No images generated from PDF');
    }

    $combinedFiles = [];
    $pairIndex = 1;
    for ($i = 0; $i < count($files); $i += 2) {
        $img1 = $files[$i];
        $img2 = $files[$i + 1] ?? null;

        if ($img2 !== null && commandExists('convert')) {
            $combinedPath = $outputPrefix . '_pair_' . $pairIndex . '.jpg';
            $process = proc_open(
                ['convert', '-append', $img1, $img2, $combinedPath],
                [1 => ['pipe', 'w']],
                $p,
                null,
                null,
                ['bypass_shell' => true]
            );
            if (is_resource($process)) {
                fclose($p[1]);
                proc_close($process);
                if (is_file($combinedPath)) {
                    $combinedFiles[] = $combinedPath;
                    @unlink($img1);
                    @unlink($img2);
                } else {
                    $combinedFiles[] = $img1;
                    $combinedFiles[] = $img2;
                }
            } else {
                $combinedFiles[] = $img1;
                $combinedFiles[] = $img2;
            }
        } else {
            $combinedFiles[] = $img1;
            if ($img2 !== null) {
                $combinedFiles[] = $img2;
            }
        }
        $pairIndex++;
    }

    @unlink($pdfPath);

    return $combinedFiles;
}

function pythonBin(): string
{
    return envValue('PYTHON_BIN', 'python3');
}

function phpBin(): string
{
    return envValue('PHP_BIN', 'php');
}

function workerSpawnDiagnostics(): array
{
    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    $execExists = function_exists('exec');
    $execDisabled = in_array('exec', $disabled, true);

    return [
        'exec_exists' => $execExists,
        'exec_disabled' => $execDisabled,
        'can_spawn' => $execExists && !$execDisabled,
        'disable_functions' => implode(',', $disabled),
    ];
}

function safePythonBin(): string
{
    $bin = pythonBin();
    if (!preg_match('/^[a-zA-Z0-9_\\-]+$/', $bin)) {
        throw new RuntimeException('Invalid PYTHON_BIN value');
    }
    $process = proc_open(
        ['which', $bin],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to validate PYTHON_BIN');
    }
    $stdout = trim(stream_get_contents($pipes[1]) ?: '');
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $stdout === '' || !is_executable($stdout)) {
        throw new RuntimeException('PYTHON_BIN is not executable');
    }

    return $stdout;
}

function safePhpBin(): string
{
    $bin = phpBin();
    if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $bin)) {
        throw new RuntimeException('Invalid PHP_BIN value');
    }

    if (str_contains($bin, '/')) {
        if (!is_executable($bin)) {
            throw new RuntimeException('PHP_BIN is not executable');
        }
        return $bin;
    }

    $process = proc_open(
        ['which', $bin],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to validate PHP_BIN');
    }
    $stdout = trim(stream_get_contents($pipes[1]) ?: '');
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $stdout === '' || !is_executable($stdout)) {
        throw new RuntimeException('PHP_BIN is not executable');
    }

    return $stdout;
}

function decodeImageWithPythonPrompt(string $imagePath, string $promptFilePath, ?string $model = null): array
{
    $script = ROOT_DIR . '/python/decode_cards.py';
    $cmd = [
        safePythonBin(),
        $script,
        '--image',
        $imagePath,
        '--prompt-file',
        $promptFilePath,
        '--no-db',
        '--json',
    ];
    if ($model !== null && trim($model) !== '') {
        $cmd[] = '--model';
        $cmd[] = trim($model);
    }
    $process = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Failed to execute decoder process');
    }

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException("Decode failed: " . trim($stderr !== '' ? $stderr : $stdout));
    }

    $json = json_decode($stdout, true);
    if (!is_array($json) || !isset($json[0]['decoded']) || !is_array($json[0]['decoded'])) {
        throw new RuntimeException('Unexpected decode output');
    }

    return $json[0];
}

function decodeImageWithPython(string $imagePath): array
{
    $tmpPrompt = tempnam(sys_get_temp_dir(), 'kakra_prompt_');
    if ($tmpPrompt === false) {
        throw new RuntimeException('Failed to create prompt temp file');
    }
    chmod($tmpPrompt, 0600);
    file_put_contents($tmpPrompt, getPromptText());

    try {
        return decodeImageWithPythonPrompt($imagePath, $tmpPrompt);
    } finally {
        @unlink($tmpPrompt);
    }
}

function formatUsageMetadata(array $metadata): string
{
    $total = (int) ($metadata['totalTokenCount'] ?? 0);
    $prompt = (int) ($metadata['promptTokenCount'] ?? 0);
    $candidates = (int) ($metadata['candidatesTokenCount'] ?? 0);
    $thoughts = (int) ($metadata['thoughtsTokenCount'] ?? 0);
    $text = (int) ($metadata['promptTokenCountDetails']['textTokenCount'] ?? 0);
    $image = (int) ($metadata['promptTokenCountDetails']['imageTokenCount'] ?? 0);

    if ($total === 0) {
        return '';
    }

    $parts = ["Total tokens $total:"];
    $parts[] = "prompt $prompt";
    if ($text > 0 || $image > 0) {
        $parts[] = "(text $text + image $image)";
    }
    if ($candidates > 0) {
        $parts[] = ", candidates $candidates";
    }
    if ($thoughts > 0) {
        $parts[] = ", thoughts $thoughts";
    }

    return implode(' ', $parts);
}

function usageMetadataHumanLines(array $metadata): array
{
    $lines = [];
    $summary = formatUsageMetadata($metadata);
    if ($summary !== '') {
        $lines[] = $summary;
    }

    $flat = [];
    $flatten = static function (array $value, string $prefix = '') use (&$flatten, &$flat): void {
        foreach ($value as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $flatten($v, $key);
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $flat[$key] = (string) $v;
            }
        }
    };
    $flatten($metadata);

    $skip = [
        'totalTokenCount',
        'promptTokenCount',
        'candidatesTokenCount',
        'thoughtsTokenCount',
        'promptTokenCountDetails.textTokenCount',
        'promptTokenCountDetails.imageTokenCount',
    ];
    foreach ($flat as $key => $value) {
        if ($value === '' || in_array($key, $skip, true)) {
            continue;
        }
        $lines[] = $key . ': ' . $value;
    }

    return $lines;
}

function renderContentRowsTable(array $rows): string
{
    $columns = ['ring_position', 'ring_number', 'obs_status', 'obs_year', 'obs_nest', 'obs_notes'];
    $headers = ['Position', 'Number', 'Status', 'Year', 'Nest', 'Notes'];

    $html = '<div style="overflow-x:auto;">';
    $html .= '<table style="width:100%;border-collapse:collapse;margin-top:8px;" class="editable-table content-rows">';
    $html .= '<thead><tr style="background:#f6f9fc;">';
    foreach ($headers as $h) {
        $html .= '<th style="border:1px solid #d7dce2;padding:8px;text-align:left;font-weight:700;font-size:0.85rem;">' . h($h) . '</th>';
    }
    $html .= '<th style="border:1px solid #d7dce2;padding:8px;text-align:center;font-weight:700;font-size:0.85rem;width:46px;">Ins</th>';
    $html .= '<th style="border:1px solid #d7dce2;padding:8px;text-align:center;font-weight:700;font-size:0.85rem;width:40px;">Del</th>';
    $html .= '</tr></thead><tbody>';

    if (empty($rows)) {
        $html .= '<tr><td colspan="' . (count($columns) + 2) . '" style="border:1px solid #d7dce2;padding:16px;text-align:center;color:#999;font-style:italic;">No content rows</td></tr>';
    } else {
        foreach ($rows as $i => $row) {
            $html .= '<tr style="' . ($i % 2 ? 'background:#f9fafb;' : '') . '">';
            foreach ($columns as $col) {
                $val = $row[$col] ?? '';
                $html .= '<td style="border:1px solid #d7dce2;padding:6px;"><input type="text" class="col-' . $col . '" value="' . h((string) $val) . '" style="width:100%;padding:4px;border:1px solid #ddd;border-radius:4px;"></td>';
            }
            $html .= '<td style="border:1px solid #d7dce2;padding:6px;text-align:center;"><button type="button" class="btn-insert-row" style="padding:4px 8px;background:#ecfdf5;border:1px solid #86efac;cursor:pointer;font-size:0.8rem;" title="Insert row below">+</button></td>';
            $html .= '<td style="border:1px solid #d7dce2;padding:6px;text-align:center;"><button type="button" class="btn-del-row" style="padding:4px 8px;background:#f5f5f5;border:1px solid #ddd;cursor:pointer;font-size:0.8rem;">×</button></td>';
            $html .= '</tr>';
        }
    }

    $html .= '</tbody></table></div>';
    $html .= '<button type="button" class="btn-add-content-row" style="margin-top:8px;padding:6px 12px;background:#e8f0f7;border:1px solid #c7ced6;border-radius:6px;cursor:pointer;font-size:0.9rem;">+ Add row</button>';
    return $html;
}

function renderRecoveryRowsTable(array $rows): string
{
    $columns = ['ring_number', 'recovery_status', 'recovery_date', 'recovery_location', 'recovery_person', 'recovery_notes'];
    $headers = ['Number', 'Status', 'Date', 'Location', 'Person', 'Notes'];

    $html = '<div style="overflow-x:auto;">';
    $html .= '<table style="width:100%;border-collapse:collapse;margin-top:8px;" class="editable-table recovery-rows">';
    $html .= '<thead><tr style="background:#f6f9fc;">';
    foreach ($headers as $h) {
        $html .= '<th style="border:1px solid #d7dce2;padding:8px;text-align:left;font-weight:700;font-size:0.85rem;">' . h($h) . '</th>';
    }
    $html .= '<th style="border:1px solid #d7dce2;padding:8px;text-align:center;font-weight:700;font-size:0.85rem;width:46px;">Ins</th>';
    $html .= '<th style="border:1px solid #d7dce2;padding:8px;text-align:center;font-weight:700;font-size:0.85rem;width:40px;">Del</th>';
    $html .= '</tr></thead><tbody>';

    if (empty($rows)) {
        $html .= '<tr><td colspan="' . (count($columns) + 2) . '" style="border:1px solid #d7dce2;padding:16px;text-align:center;color:#999;font-style:italic;">No recovery rows</td></tr>';
    } else {
        foreach ($rows as $i => $row) {
            $html .= '<tr style="' . ($i % 2 ? 'background:#f9fafb;' : '') . '">';
            foreach ($columns as $col) {
                $val = $row[$col] ?? '';
                if ($col === 'recovery_date') {
                    $val = formatDateForEditor((string) $val);
                }
                $dateClass = $col === 'recovery_date' ? ' datepicker' : '';
                $html .= '<td style="border:1px solid #d7dce2;padding:6px;"><input type="text" class="col-' . $col . $dateClass . '" value="' . h((string) $val) . '" style="width:100%;padding:4px;border:1px solid #ddd;border-radius:4px;"></td>';
            }
            $html .= '<td style="border:1px solid #d7dce2;padding:6px;text-align:center;"><button type="button" class="btn-insert-row" style="padding:4px 8px;background:#ecfdf5;border:1px solid #86efac;cursor:pointer;font-size:0.8rem;" title="Insert row below">+</button></td>';
            $html .= '<td style="border:1px solid #d7dce2;padding:6px;text-align:center;"><button type="button" class="btn-del-row" style="padding:4px 8px;background:#f5f5f5;border:1px solid #ddd;cursor:pointer;font-size:0.8rem;">×</button></td>';
            $html .= '</tr>';
        }
    }

    $html .= '</tbody></table></div>';
    $html .= '<button type="button" class="btn-add-recovery-row" style="margin-top:8px;padding:6px 12px;background:#e8f0f7;border:1px solid #c7ced6;border-radius:6px;cursor:pointer;font-size:0.9rem;">+ Add row</button>';
    return $html;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatDateForEditor(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $trimmed) === 1) {
        return $trimmed;
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmed, $m) === 1) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }

    if (preg_match('/^(\d{2})[\/.\-](\d{2})[\/.\-](\d{4})$/', $trimmed, $m) === 1) {
        return $m[1] . '.' . $m[2] . '.' . $m[3];
    }

    return $trimmed;
}

function normalizeRows(string $jsonText): array
{
    $rows = json_decode($jsonText, true);
    return is_array($rows) ? array_values(array_filter($rows, static fn($row) => is_array($row))) : [];
}

function insertDecodedRecord(array $decoded, string $sourceImagePath): int
{
    $header = is_array($decoded['header'] ?? null) ? $decoded['header'] : [];
    $contentRows = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
    $recoveryRows = is_array($decoded['recovery'] ?? null) ? $decoded['recovery'] : [];
    $uncertainties = is_array($decoded['uncertainties'] ?? null) ? $decoded['uncertainties'] : [];

    $birdId = trim((string) ($header['bird_id'] ?? ''));

    $pdo = pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO cards_header
        (bird_id, card_code, sex, ring_position, ring_number, ringing_age, ringing_date, ringing_nest, scull_length, scull_repeat, source_image_filename, source_image_path, uncertainties, raw_response_json)
        VALUES
        (:bird_id, :card_code, :sex, :ring_position, :ring_number, :ringing_age, :ringing_date, :ringing_nest, :scull_length, :scull_repeat, :source_image_filename, :source_image_path, :uncertainties, :raw_response_json)'
    );
    $stmt->execute([
        'bird_id' => $birdId,
        'card_code' => $header['card_code'] ?? null,
        'sex' => $header['sex'] ?? null,
        'ring_position' => $header['ring_position'] ?? null,
        'ring_number' => $header['ring_number'] ?? null,
        'ringing_age' => $header['ringing_age'] ?? null,
        'ringing_date' => $header['ringing_date'] ?? null,
        'ringing_nest' => $header['ringing_nest'] ?? null,
        'scull_length' => $header['scull_length'] ?? null,
        'scull_repeat' => $header['scull_repeat'] ?? null,
        'source_image_filename' => basename($sourceImagePath),
        'source_image_path' => $sourceImagePath,
        'uncertainties' => implode("\n", array_map('strval', $uncertainties)),
        'raw_response_json' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
    ]);

    $headerId = (int) $pdo->lastInsertId();

    $contentStmt = $pdo->prepare(
        'INSERT INTO cards_content (header_id, bird_id, row_no, ring_position, ring_number, obs_status, obs_year, obs_nest, obs_notes, decoding_status)
        VALUES (:header_id, :bird_id, :row_no, :ring_position, :ring_number, :obs_status, :obs_year, :obs_nest, :obs_notes, :decoding_status)'
    );
    foreach ($contentRows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $contentStmt->execute([
            'header_id' => $headerId,
            'bird_id' => $birdId,
            'row_no' => $i + 1,
            'ring_position' => $row['ring_position'] ?? null,
            'ring_number' => $row['ring_number'] ?? null,
            'obs_status' => $row['obs_status'] ?? null,
            'obs_year' => $row['obs_year'] ?? null,
            'obs_nest' => $row['obs_nest'] ?? null,
            'obs_notes' => $row['obs_notes'] ?? null,
            'decoding_status' => null,
        ]);
    }

    $recoveryStmt = $pdo->prepare(
        'INSERT INTO cards_recovery (header_id, bird_id, row_no, ring_number, recovery_status, recovery_date, recovery_location, recovery_person, recovery_notes)
        VALUES (:header_id, :bird_id, :row_no, :ring_number, :recovery_status, :recovery_date, :recovery_location, :recovery_person, :recovery_notes)'
    );
    foreach ($recoveryRows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $recoveryStmt->execute([
            'header_id' => $headerId,
            'bird_id' => $birdId,
            'row_no' => $i + 1,
            'ring_number' => $row['ring_number'] ?? null,
            'recovery_status' => $row['recovery_status'] ?? null,
            'recovery_date' => $row['recovery_date'] ?? null,
            'recovery_location' => $row['recovery_location'] ?? null,
            'recovery_person' => $row['recovery_person'] ?? null,
            'recovery_notes' => $row['recovery_notes'] ?? null,
        ]);
    }

    $pdo->commit();

    return $headerId;
}

function updateDecodedRecord(int $id, array $decoded): void
{
    $header = is_array($decoded['header'] ?? null) ? $decoded['header'] : [];
    $contentRows = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
    $recoveryRows = is_array($decoded['recovery'] ?? null) ? $decoded['recovery'] : [];
    $uncertainties = is_array($decoded['uncertainties'] ?? null) ? $decoded['uncertainties'] : [];

    $birdId = trim((string) ($header['bird_id'] ?? ''));

    $pdo = pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'UPDATE cards_header SET
         bird_id=:bird_id, card_code=:card_code, sex=:sex, ring_position=:ring_position,
         ring_number=:ring_number, ringing_age=:ringing_age, ringing_date=:ringing_date,
         ringing_nest=:ringing_nest, scull_length=:scull_length, scull_repeat=:scull_repeat,
         uncertainties=:uncertainties, raw_response_json=:raw_response_json, updated_at=CURRENT_TIMESTAMP
         WHERE id=:id'
    );
    $stmt->execute([
        'id' => $id,
        'bird_id' => $birdId,
        'card_code' => $header['card_code'] ?? null,
        'sex' => $header['sex'] ?? null,
        'ring_position' => $header['ring_position'] ?? null,
        'ring_number' => $header['ring_number'] ?? null,
        'ringing_age' => $header['ringing_age'] ?? null,
        'ringing_date' => $header['ringing_date'] ?? null,
        'ringing_nest' => $header['ringing_nest'] ?? null,
        'scull_length' => $header['scull_length'] ?? null,
        'scull_repeat' => $header['scull_repeat'] ?? null,
        'uncertainties' => implode("\n", array_map('strval', $uncertainties)),
        'raw_response_json' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
    ]);

    $pdo->prepare('DELETE FROM cards_content WHERE header_id=:id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM cards_recovery WHERE header_id=:id')->execute(['id' => $id]);

    $contentStmt = $pdo->prepare(
        'INSERT INTO cards_content (header_id, bird_id, row_no, ring_position, ring_number, obs_status, obs_year, obs_nest, obs_notes, decoding_status)
        VALUES (:header_id, :bird_id, :row_no, :ring_position, :ring_number, :obs_status, :obs_year, :obs_nest, :obs_notes, :decoding_status)'
    );
    foreach ($contentRows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $contentStmt->execute([
            'header_id' => $id,
            'bird_id' => $birdId,
            'row_no' => $i + 1,
            'ring_position' => $row['ring_position'] ?? null,
            'ring_number' => $row['ring_number'] ?? null,
            'obs_status' => $row['obs_status'] ?? null,
            'obs_year' => $row['obs_year'] ?? null,
            'obs_nest' => $row['obs_nest'] ?? null,
            'obs_notes' => $row['obs_notes'] ?? null,
            'decoding_status' => null,
        ]);
    }

    $recoveryStmt = $pdo->prepare(
        'INSERT INTO cards_recovery (header_id, bird_id, row_no, ring_number, recovery_status, recovery_date, recovery_location, recovery_person, recovery_notes)
        VALUES (:header_id, :bird_id, :row_no, :ring_number, :recovery_status, :recovery_date, :recovery_location, :recovery_person, :recovery_notes)'
    );
    foreach ($recoveryRows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $recoveryStmt->execute([
            'header_id' => $id,
            'bird_id' => $birdId,
            'row_no' => $i + 1,
            'ring_number' => $row['ring_number'] ?? null,
            'recovery_status' => $row['recovery_status'] ?? null,
            'recovery_date' => $row['recovery_date'] ?? null,
            'recovery_location' => $row['recovery_location'] ?? null,
            'recovery_person' => $row['recovery_person'] ?? null,
            'recovery_notes' => $row['recovery_notes'] ?? null,
        ]);
    }

    $pdo->commit();
}

function createPromptSnapshotFile(?string $promptRelativePath = null): string
{
    $tmpPrompt = tempnam(sys_get_temp_dir(), 'kakra_prompt_job_');
    if ($tmpPrompt === false) {
        throw new RuntimeException('Unable to allocate prompt snapshot file');
    }
    chmod($tmpPrompt, 0644);
    $promptText = $promptRelativePath === null
        ? getPromptText()
        : getPromptTextFromRelativePath($promptRelativePath);
    if (file_put_contents($tmpPrompt, $promptText) === false) {
        @unlink($tmpPrompt);
        throw new RuntimeException('Unable to write prompt snapshot file');
    }

    return $tmpPrompt;
}

function createDecodeJob(string $sourceImagePath, ?string $promptRelativePath = null, ?string $requestedModel = null, ?string $comparisonGroup = null): int
{
    ensureDecodeJobAnalysisColumns();
    $promptSnapshot = createPromptSnapshotFile($promptRelativePath);
    $stmt = pdo()->prepare(
        'INSERT INTO decode_jobs
         (source_image_filename, source_image_path, prompt_file_path, requested_model, comparison_group, status, attempt_count)
         VALUES (:source_image_filename, :source_image_path, :prompt_file_path, :requested_model, :comparison_group, :status, :attempt_count)'
    );
    $stmt->execute([
        'source_image_filename' => basename($sourceImagePath),
        'source_image_path' => $sourceImagePath,
        'prompt_file_path' => $promptSnapshot,
        'requested_model' => $requestedModel,
        'comparison_group' => $comparisonGroup,
        'status' => 'queued',
        'attempt_count' => 1,
    ]);

    return (int) pdo()->lastInsertId();
}

function retryDecodeJob(int $jobId, ?string $promptRelativePath = null): void
{
    ensureDecodeJobAnalysisColumns();
    $job = getDecodeJob($jobId);
    if ($job === null) {
        throw new RuntimeException('Decode job not found');
    }

    $promptSnapshot = createPromptSnapshotFile($promptRelativePath);
    $stmt = pdo()->prepare(
        'UPDATE decode_jobs SET
         prompt_file_path = :prompt_file_path,
         status = :status,
          error_message = NULL,
          usage_metadata_json = NULL,
          decoding_model = NULL,
          total_token_count = NULL,
          decoded_json = NULL,
         started_at = NULL,
         finished_at = NULL,
         saved_header_id = NULL,
         attempt_count = attempt_count + 1,
         updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        'id' => $jobId,
        'prompt_file_path' => $promptSnapshot,
        'status' => 'queued',
    ]);
}

function getDecodeJob(int $jobId): ?array
{
    ensureDecodeJobAnalysisColumns();
    $stmt = pdo()->prepare('SELECT * FROM decode_jobs WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function listDecodeJobs(int $limit = 100, bool $includeSaved = false): array
{
    ensureDecodeJobAnalysisColumns();
    $limit = max(1, min($limit, 500));
    $where = $includeSaved ? 'WHERE archived = 0' : "WHERE status NOT IN ('saved', 'rejected') AND archived = 0";
    $stmt = pdo()->query(
        'SELECT id, source_image_filename, source_image_path, status, error_message,
            attempt_count, saved_header_id, requested_model, comparison_group, decoding_model, total_token_count,
                created_at, started_at, finished_at
         FROM decode_jobs
         ' . $where . '
         ORDER BY id DESC
         LIMIT ' . $limit
    );

    return $stmt->fetchAll();
}

function countPendingDecodeJobs(): int
{
    ensureDecodeJobAnalysisColumns();
    $stmt = pdo()->query("SELECT COUNT(*) FROM decode_jobs WHERE status NOT IN ('saved', 'rejected') AND archived = 0");
    return (int) $stmt->fetchColumn();
}

function buildComparisonData(array $jobs): array
{
    $data = [
        'jobIds' => [],
        'models' => [],
        'jobModelMap' => [],
        'header' => [],
        'content' => [],
        'recovery' => [],
        'uncertainties' => [],
        'uncertaintiesByJob' => [],
    ];
    $headerKeys = ['bird_id', 'card_code', 'sex', 'ring_position', 'ring_number', 'ringing_age', 'ringing_date', 'ringing_nest', 'scull_length', 'scull_repeat'];
    $contentKeys = ['ring_position', 'ring_number', 'obs_status', 'obs_year', 'obs_nest', 'obs_notes'];
    $recoveryKeys = ['ring_number', 'recovery_status', 'recovery_date', 'recovery_location', 'recovery_person', 'recovery_notes'];

    $maxContentRows = 0;
    $maxRecoveryRows = 0;
    
    foreach ($jobs as $job) {
        $jobId = (int) $job['id'];
        $data['jobIds'][] = $jobId;
        $model = (string) ($job['decoding_model'] ?? 'unknown');
        if (!in_array($model, $data['models'], true)) {
            $data['models'][] = $model;
        }
        $data['jobModelMap'][(string) $jobId] = $model;
        $decoded = json_decode((string) ($job['decoded_json'] ?? ''), true);
        if (!is_array($decoded)) {
            continue;
        }

        $h = is_array($decoded['header'] ?? null) ? $decoded['header'] : [];
        foreach ($headerKeys as $k) {
            $val = trim((string) ($h[$k] ?? ''));
            if ($k === 'ringing_date') {
                $val = formatDateForEditor($val);
            }
            $data['header'][$k][] = ['value' => $val, 'model' => $model, 'jobId' => $jobId];
        }

        $c = is_array($decoded['content'] ?? null) ? array_values($decoded['content']) : [];
        $maxContentRows = max($maxContentRows, count($c));
        foreach ($c as $i => $row) {
            if (!is_array($row)) continue;
            foreach ($contentKeys as $k) {
                $val = trim((string) ($row[$k] ?? ''));
                $data['content'][$i][$k][] = ['value' => $val, 'model' => $model, 'jobId' => $jobId];
            }
        }

        $r = is_array($decoded['recovery'] ?? null) ? array_values($decoded['recovery']) : [];
        $maxRecoveryRows = max($maxRecoveryRows, count($r));
        foreach ($r as $i => $row) {
            if (!is_array($row)) continue;
            foreach ($recoveryKeys as $k) {
                $val = trim((string) ($row[$k] ?? ''));
                if ($k === 'recovery_date') {
                    $val = formatDateForEditor($val);
                }
                $data['recovery'][$i][$k][] = ['value' => $val, 'model' => $model, 'jobId' => $jobId];
            }
        }
        
        $u = is_array($decoded['uncertainties'] ?? null) ? $decoded['uncertainties'] : [];
        $uLines = array_values(array_filter(array_map('trim', $u), static fn($v) => $v !== ''));
        $uText = implode("\n", $uLines);
        $data['uncertainties'][] = ['value' => $uText, 'model' => $model, 'jobId' => $jobId];
        $data['uncertaintiesByJob'][(string) $jobId] = [
            'model' => $model,
            'text' => $uText,
            'lines' => $uLines,
        ];
    }

    // Fill missing rows for alignment
    for ($i = 0; $i < $maxContentRows; $i++) {
        if (!isset($data['content'][$i])) $data['content'][$i] = [];
        foreach ($contentKeys as $k) {
            if (!isset($data['content'][$i][$k])) $data['content'][$i][$k] = [];
            $foundModels = array_column($data['content'][$i][$k], 'model');
            foreach ($jobs as $job) {
                $m = (string) ($job['decoding_model'] ?? 'unknown');
                if (!in_array($m, $foundModels, true)) {
                    $data['content'][$i][$k][] = ['value' => '', 'model' => $m, 'jobId' => (int) $job['id']];
                }
            }
        }
    }
    
    for ($i = 0; $i < $maxRecoveryRows; $i++) {
        if (!isset($data['recovery'][$i])) $data['recovery'][$i] = [];
        foreach ($recoveryKeys as $k) {
            if (!isset($data['recovery'][$i][$k])) $data['recovery'][$i][$k] = [];
            $foundModels = array_column($data['recovery'][$i][$k], 'model');
            foreach ($jobs as $job) {
                $m = (string) ($job['decoding_model'] ?? 'unknown');
                if (!in_array($m, $foundModels, true)) {
                    $data['recovery'][$i][$k][] = ['value' => '', 'model' => $m, 'jobId' => (int) $job['id']];
                }
            }
        }
    }

    return $data;
}

function getDecodeJobsByComparisonGroup(string $comparisonGroup): array
{

    ensureDecodeJobAnalysisColumns();
    $stmt = pdo()->prepare(
        'SELECT * FROM decode_jobs WHERE comparison_group=:comparison_group ORDER BY id ASC'
    );
    $stmt->execute(['comparison_group' => $comparisonGroup]);

    return $stmt->fetchAll();
}

function markDecodeJobsSaved(array $jobIds, int $headerId): void
{
    foreach ($jobIds as $jobId) {
        markDecodeJobSaved((int) $jobId, $headerId);
    }
}

function startDecodeJobWorker(int $jobId): void
{
    $diag = workerSpawnDiagnostics();
    if (!(bool) $diag['can_spawn']) {
        throw new RuntimeException('Worker spawn unavailable: exec disabled in web PHP runtime');
    }

    $php = safePhpBin();
    $script = ROOT_DIR . '/public/decode_job_worker.php';
    if (!is_file($script)) {
        throw new RuntimeException('Decode job worker script is missing');
    }

    $command = escapeshellarg($php)
        . ' ' . escapeshellarg($script)
        . ' --job-id ' . (int) $jobId
        . ' > /dev/null 2>&1 &';
    exec($command);
}

function markDecodeJobFailed(int $jobId, string $errorMessage): void
{
    $stmt = pdo()->prepare(
        'UPDATE decode_jobs
         SET status=:status, error_message=:error_message, finished_at=NOW(), updated_at=CURRENT_TIMESTAMP
         WHERE id=:id'
    );
    $stmt->execute([
        'id' => $jobId,
        'status' => 'failed',
        'error_message' => substr($errorMessage, 0, 2000),
    ]);
}

function markDecodeJobSaved(int $jobId, int $headerId): void
{
    $stmt = pdo()->prepare(
        'UPDATE decode_jobs
         SET status=:status, saved_header_id=:saved_header_id, updated_at=CURRENT_TIMESTAMP
         WHERE id=:id'
    );
    $stmt->execute([
        'id' => $jobId,
        'status' => 'saved',
        'saved_header_id' => $headerId,
    ]);
}

function markDecodeJobRejected(int $jobId): void
{
    $stmt = pdo()->prepare(
        'UPDATE decode_jobs
         SET status=:status, updated_at=CURRENT_TIMESTAMP
         WHERE id=:id'
    );
    $stmt->execute([
        'id' => $jobId,
        'status' => 'rejected',
    ]);
}

function setDecodeJobUncertainties(int $jobId, array $uncertainties): void
{
    $job = getDecodeJob($jobId);
    if ($job === null) {
        return;
    }

    $decoded = json_decode((string) ($job['decoded_json'] ?? ''), true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $decoded['uncertainties'] = array_values(array_filter(array_map(
        static fn($line): string => trim((string) $line),
        $uncertainties
    ), static fn(string $line): bool => $line !== ''));

    $stmt = pdo()->prepare(
        'UPDATE decode_jobs SET decoded_json=:decoded_json, updated_at=CURRENT_TIMESTAMP WHERE id=:id'
    );
    $stmt->execute([
        'id' => $jobId,
        'decoded_json' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
    ]);
}

function ensureDecodeFieldQualityTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        pdo()->exec(
            'CREATE TABLE IF NOT EXISTS decode_field_quality (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                header_id BIGINT UNSIGNED NOT NULL,
                decode_job_id BIGINT UNSIGNED NOT NULL,
                model VARCHAR(128) NOT NULL,
                prompt_file_path VARCHAR(1024) NULL,
                section VARCHAR(16) NOT NULL,
                row_no INT UNSIGNED NULL,
                field_name VARCHAR(64) NOT NULL,
                predicted_value TEXT NULL,
                final_value TEXT NULL,
                exact_match TINYINT(1) NOT NULL DEFAULT 0,
                normalized_match TINYINT(1) NOT NULL DEFAULT 0,
                manually_corrected TINYINT(1) NOT NULL DEFAULT 0,
                error_type VARCHAR(32) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_dfq_model (model),
                INDEX idx_dfq_header (header_id),
                INDEX idx_dfq_job (decode_job_id),
                INDEX idx_dfq_field (section, field_name)
            )'
        );

        $columns = [];
        $stmt = pdo()->query('SHOW COLUMNS FROM decode_field_quality');
        foreach (($stmt?->fetchAll() ?: []) as $row) {
            $columns[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($columns['manually_corrected'])) {
            pdo()->exec('ALTER TABLE decode_field_quality ADD COLUMN manually_corrected TINYINT(1) NOT NULL DEFAULT 0 AFTER normalized_match');
        }
        if (!isset($columns['updated_at'])) {
            pdo()->exec('ALTER TABLE decode_field_quality ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
        }
    } catch (Throwable) {
    }

    $done = true;
}

function decodeQualityNormalizeValue(string $field, mixed $value): string
{
    $text = strtolower(trim((string) $value));
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    if (in_array($field, ['ringing_date', 'recovery_date'], true)) {
        $text = str_replace(['/', '-', ',', ' '], '.', $text);
        $text = preg_replace('/\.+/', '.', $text) ?? $text;
        $text = trim($text, '.');
    }

    return $text;
}

function decodeQualityLooseCanonical(string $value): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? strtolower($value);
}

function decodeQualityErrorType(string $predictedNorm, string $finalNorm, bool $normalizedMatch): string
{
    if ($normalizedMatch) {
        if ($predictedNorm === '' && $finalNorm === '') {
            return 'both_empty';
        }
        return 'match';
    }

    if ($predictedNorm === '' && $finalNorm !== '') {
        return 'missing';
    }
    if ($predictedNorm !== '' && $finalNorm === '') {
        return 'extra';
    }

    $predLoose = decodeQualityLooseCanonical($predictedNorm);
    $finalLoose = decodeQualityLooseCanonical($finalNorm);
    if ($predLoose !== '' && $predLoose === $finalLoose) {
        return 'format_mismatch';
    }

    return 'mismatch';
}

function saveDecodeFieldQuality(int $headerId, array $reviewJobIds, array $finalDecoded): void
{
    if (empty($reviewJobIds)) {
        return;
    }

    ensureDecodeFieldQualityTable();

    $headerKeys = ['bird_id', 'card_code', 'sex', 'ring_position', 'ring_number', 'ringing_age', 'ringing_date', 'ringing_nest', 'scull_length', 'scull_repeat'];
    $contentKeys = ['ring_position', 'ring_number', 'obs_status', 'obs_year', 'obs_nest', 'obs_notes'];
    $recoveryKeys = ['ring_number', 'recovery_status', 'recovery_date', 'recovery_location', 'recovery_person', 'recovery_notes'];

    $finalHeader = is_array($finalDecoded['header'] ?? null) ? $finalDecoded['header'] : [];
    $finalContent = is_array($finalDecoded['content'] ?? null) ? array_values($finalDecoded['content']) : [];
    $finalRecovery = is_array($finalDecoded['recovery'] ?? null) ? array_values($finalDecoded['recovery']) : [];

    $insert = pdo()->prepare(
        'INSERT INTO decode_field_quality
            (header_id, decode_job_id, model, prompt_file_path, section, row_no, field_name, predicted_value, final_value, exact_match, normalized_match, manually_corrected, error_type)
         VALUES
            (:header_id, :decode_job_id, :model, :prompt_file_path, :section, :row_no, :field_name, :predicted_value, :final_value, :exact_match, :normalized_match, :manually_corrected, :error_type)'
    );

    foreach ($reviewJobIds as $rawJobId) {
        $jobId = (int) $rawJobId;
        if ($jobId <= 0) {
            continue;
        }

        $job = getDecodeJob($jobId);
        if ($job === null) {
            continue;
        }

        $model = trim((string) ($job['decoding_model'] ?? ''));
        if ($model === '') {
            $model = trim((string) ($job['requested_model'] ?? ''));
        }
        if ($model === '') {
            $model = 'unknown';
        }

        $decoded = json_decode((string) ($job['decoded_json'] ?? ''), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $predHeader = is_array($decoded['header'] ?? null) ? $decoded['header'] : [];
        $predContent = is_array($decoded['content'] ?? null) ? array_values($decoded['content']) : [];
        $predRecovery = is_array($decoded['recovery'] ?? null) ? array_values($decoded['recovery']) : [];

        foreach ($headerKeys as $field) {
            $predValue = trim((string) ($predHeader[$field] ?? ''));
            $finalValue = trim((string) ($finalHeader[$field] ?? ''));
            $predNorm = decodeQualityNormalizeValue($field, $predValue);
            $finalNorm = decodeQualityNormalizeValue($field, $finalValue);
            $exactMatch = (int) ($predValue === $finalValue);
            $normMatch = (int) ($predNorm === $finalNorm);
            $insert->execute([
                'header_id' => $headerId,
                'decode_job_id' => $jobId,
                'model' => $model,
                'prompt_file_path' => (string) ($job['prompt_file_path'] ?? ''),
                'section' => 'header',
                'row_no' => null,
                'field_name' => $field,
                'predicted_value' => $predValue,
                'final_value' => $finalValue,
                'exact_match' => $exactMatch,
                'normalized_match' => $normMatch,
                'manually_corrected' => 0,
                'error_type' => decodeQualityErrorType($predNorm, $finalNorm, (bool) $normMatch),
            ]);
        }

        $contentRowCount = max(count($predContent), count($finalContent));
        for ($i = 0; $i < $contentRowCount; $i++) {
            $predRow = is_array($predContent[$i] ?? null) ? $predContent[$i] : [];
            $finalRow = is_array($finalContent[$i] ?? null) ? $finalContent[$i] : [];
            foreach ($contentKeys as $field) {
                $predValue = trim((string) ($predRow[$field] ?? ''));
                $finalValue = trim((string) ($finalRow[$field] ?? ''));
                $predNorm = decodeQualityNormalizeValue($field, $predValue);
                $finalNorm = decodeQualityNormalizeValue($field, $finalValue);
                $exactMatch = (int) ($predValue === $finalValue);
                $normMatch = (int) ($predNorm === $finalNorm);
                $insert->execute([
                    'header_id' => $headerId,
                    'decode_job_id' => $jobId,
                    'model' => $model,
                    'prompt_file_path' => (string) ($job['prompt_file_path'] ?? ''),
                    'section' => 'content',
                    'row_no' => $i + 1,
                    'field_name' => $field,
                    'predicted_value' => $predValue,
                    'final_value' => $finalValue,
                    'exact_match' => $exactMatch,
                    'normalized_match' => $normMatch,
                    'manually_corrected' => 0,
                    'error_type' => decodeQualityErrorType($predNorm, $finalNorm, (bool) $normMatch),
                ]);
            }
        }

        $recoveryRowCount = max(count($predRecovery), count($finalRecovery));
        for ($i = 0; $i < $recoveryRowCount; $i++) {
            $predRow = is_array($predRecovery[$i] ?? null) ? $predRecovery[$i] : [];
            $finalRow = is_array($finalRecovery[$i] ?? null) ? $finalRecovery[$i] : [];
            foreach ($recoveryKeys as $field) {
                $predValue = trim((string) ($predRow[$field] ?? ''));
                $finalValue = trim((string) ($finalRow[$field] ?? ''));
                $predNorm = decodeQualityNormalizeValue($field, $predValue);
                $finalNorm = decodeQualityNormalizeValue($field, $finalValue);
                $exactMatch = (int) ($predValue === $finalValue);
                $normMatch = (int) ($predNorm === $finalNorm);
                $insert->execute([
                    'header_id' => $headerId,
                    'decode_job_id' => $jobId,
                    'model' => $model,
                    'prompt_file_path' => (string) ($job['prompt_file_path'] ?? ''),
                    'section' => 'recovery',
                    'row_no' => $i + 1,
                    'field_name' => $field,
                    'predicted_value' => $predValue,
                    'final_value' => $finalValue,
                    'exact_match' => $exactMatch,
                    'normalized_match' => $normMatch,
                    'manually_corrected' => 0,
                    'error_type' => decodeQualityErrorType($predNorm, $finalNorm, (bool) $normMatch),
                ]);
            }
        }
    }
}

function refreshDecodeFieldQualityForHeader(int $headerId, array $finalDecoded): void
{
    ensureDecodeFieldQualityTable();

    $finalHeader = is_array($finalDecoded['header'] ?? null) ? $finalDecoded['header'] : [];
    $finalContent = is_array($finalDecoded['content'] ?? null) ? array_values($finalDecoded['content']) : [];
    $finalRecovery = is_array($finalDecoded['recovery'] ?? null) ? array_values($finalDecoded['recovery']) : [];

    $rowsStmt = pdo()->prepare(
        'SELECT id, section, row_no, field_name, predicted_value, final_value, manually_corrected
         FROM decode_field_quality
         WHERE header_id=:header_id'
    );
    $rowsStmt->execute(['header_id' => $headerId]);
    $rows = $rowsStmt->fetchAll() ?: [];
    if (empty($rows)) {
        return;
    }

    $update = pdo()->prepare(
        'UPDATE decode_field_quality
         SET final_value=:final_value,
             exact_match=:exact_match,
             normalized_match=:normalized_match,
             manually_corrected=:manually_corrected,
             error_type=:error_type,
             updated_at=CURRENT_TIMESTAMP
         WHERE id=:id'
    );

    foreach ($rows as $row) {
        $section = (string) ($row['section'] ?? '');
        $field = (string) ($row['field_name'] ?? '');
        $rowNo = isset($row['row_no']) ? (int) $row['row_no'] : null;
        $predValue = trim((string) ($row['predicted_value'] ?? ''));
        $oldFinalValue = trim((string) ($row['final_value'] ?? ''));

        $newFinalValue = '';
        if ($section === 'header') {
            $newFinalValue = trim((string) ($finalHeader[$field] ?? ''));
        } elseif ($section === 'content') {
            $idx = max(0, (int) $rowNo - 1);
            $target = is_array($finalContent[$idx] ?? null) ? $finalContent[$idx] : [];
            $newFinalValue = trim((string) ($target[$field] ?? ''));
        } elseif ($section === 'recovery') {
            $idx = max(0, (int) $rowNo - 1);
            $target = is_array($finalRecovery[$idx] ?? null) ? $finalRecovery[$idx] : [];
            $newFinalValue = trim((string) ($target[$field] ?? ''));
        }

        $predNorm = decodeQualityNormalizeValue($field, $predValue);
        $newFinalNorm = decodeQualityNormalizeValue($field, $newFinalValue);
        $exactMatch = (int) ($predValue === $newFinalValue);
        $normMatch = (int) ($predNorm === $newFinalNorm);

        $valueChanged = $oldFinalValue !== $newFinalValue;
        $manualCorrected = ((int) ($row['manually_corrected'] ?? 0) === 1 || $valueChanged) ? 1 : 0;

        $update->execute([
            'id' => (int) ($row['id'] ?? 0),
            'final_value' => $newFinalValue,
            'exact_match' => $exactMatch,
            'normalized_match' => $normMatch,
            'manually_corrected' => $manualCorrected,
            'error_type' => decodeQualityErrorType($predNorm, $newFinalNorm, (bool) $normMatch),
        ]);
    }
}

function deleteDecodeJob(int $jobId): void
{
    $stmt = pdo()->prepare(
        'UPDATE decode_jobs SET archived=1, updated_at=CURRENT_TIMESTAMP WHERE id=:id'
    );
    $stmt->execute(['id' => $jobId]);
}

function ensureDecodeJobAnalysisColumns(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo = pdo();
    try {
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM decode_jobs');
        foreach ($stmt->fetchAll() as $row) {
            $columns[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($columns['decoding_model'])) {
            $pdo->exec('ALTER TABLE decode_jobs ADD COLUMN decoding_model VARCHAR(128) NULL AFTER usage_metadata_json');
        }
        if (!isset($columns['total_token_count'])) {
            $pdo->exec('ALTER TABLE decode_jobs ADD COLUMN total_token_count INT UNSIGNED NULL AFTER decoding_model');
        }
        if (!isset($columns['requested_model'])) {
            $pdo->exec('ALTER TABLE decode_jobs ADD COLUMN requested_model VARCHAR(128) NULL AFTER prompt_file_path');
        }
        if (!isset($columns['comparison_group'])) {
            $pdo->exec('ALTER TABLE decode_jobs ADD COLUMN comparison_group VARCHAR(64) NULL AFTER requested_model');
        }
        if (!isset($columns['archived'])) {
            $pdo->exec('ALTER TABLE decode_jobs ADD COLUMN archived BOOLEAN NOT NULL DEFAULT 0');
        }
    } catch (Throwable) {
    }

    $done = true;
}
