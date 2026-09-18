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
    $html .= '<table style="width:100%;border-collapse:collapse;margin-top:8px;" class="editable-table content-rows no-sort">';
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
    $html .= '<table style="width:100%;border-collapse:collapse;margin-top:8px;" class="editable-table recovery-rows no-sort">';
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

/** True when every value in the row is blank (an untouched "+ Add row" template). */
function isBlankRow(mixed $row, ?array $keys = null): bool
{
    if (!is_array($row)) {
        return true;
    }
    foreach ($keys ?? array_keys($row) as $key) {
        if (trim((string) ($row[$key] ?? '')) !== '') {
            return false;
        }
    }
    return true;
}

function normalizeRows(string $jsonText): array
{
    $rows = json_decode($jsonText, true);
    if (!is_array($rows)) {
        return [];
    }
    // Drop rows the reviewer added but never filled; saving them pollutes the ground
    // truth and every later benchmark would be penalised for not producing blank rows.
    return array_values(array_filter($rows, static fn($row) => is_array($row) && !isBlankRow($row)));
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

/**
 * Register a prompt text in prompt_versions (keyed by sha256) so every job/quality row
 * can point at the exact prompt that produced it. Returns the sha256.
 */
function registerPromptVersion(string $promptName, string $promptText): string
{
    $sha = hash('sha256', $promptText);
    $stmt = pdo()->prepare(
        'INSERT INTO prompt_versions (sha256, prompt_name, prompt_text) VALUES (:sha, :name, :text)
         ON DUPLICATE KEY UPDATE prompt_name = prompt_name'
    );
    $stmt->execute(['sha' => $sha, 'name' => $promptName, 'text' => $promptText]);

    return $sha;
}

function getPromptVersionText(string $sha256): ?string
{
    $stmt = pdo()->prepare('SELECT prompt_text FROM prompt_versions WHERE sha256 = :sha LIMIT 1');
    $stmt->execute(['sha' => $sha256]);
    $row = $stmt->fetch();

    return $row ? (string) $row['prompt_text'] : null;
}

function getPromptVersion(string $sha256): ?array
{
    $stmt = pdo()->prepare('SELECT sha256, prompt_name, prompt_text, notes, first_seen_at FROM prompt_versions WHERE sha256 = :sha LIMIT 1');
    $stmt->execute(['sha' => $sha256]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function setPromptVersionNotes(string $sha256, string $notes): void
{
    $stmt = pdo()->prepare('UPDATE prompt_versions SET notes = :notes WHERE sha256 = :sha');
    $stmt->execute(['sha' => $sha256, 'notes' => trim($notes) === '' ? null : trim($notes)]);
}

/**
 * Make sure every prompts/*.md file has a registered version, so a prompt is comparable
 * and diffable before it has ever been run. Returns [relative path => sha256].
 */
function registerAllPromptFiles(): array
{
    ensureDecodeJobAnalysisColumns();
    $map = [];
    foreach (listPromptFiles() as $relative) {
        $text = getPromptTextFromRelativePath($relative);
        if ($text === '') {
            continue;
        }
        $map[$relative] = registerPromptVersion($relative, $text);
    }
    return $map;
}

/**
 * All prompt versions with usage and accuracy summary, newest first.
 * Each row: sha256, prompt_name, notes, first_seen_at, is_current_file (text equals the
 * file on disk right now), jobs, cards, models, best_model, best_acc, mean_acc.
 */
function listPromptVersionsWithStats(): array
{
    $current = registerAllPromptFiles();
    $currentByHash = array_flip($current);
    $versions = pdo()->query(
        'SELECT p.sha256, p.prompt_name, p.notes, p.first_seen_at,
                (SELECT COUNT(*) FROM decode_jobs j WHERE j.prompt_sha256 = p.sha256 AND j.status IN (\'saved\',\'succeeded\',\'benchmarked\')) AS jobs,
                (SELECT COUNT(DISTINCT q.header_id) FROM decode_field_quality q WHERE q.prompt_sha256 = p.sha256) AS cards,
                (SELECT COUNT(DISTINCT q.model) FROM decode_field_quality q WHERE q.prompt_sha256 = p.sha256) AS models
         FROM prompt_versions p ORDER BY p.first_seen_at DESC, p.prompt_name'
    )->fetchAll();
    $acc = pdo()->query(
        "SELECT prompt_sha256, model,
                100 * SUM(error_type <> 'both_empty' AND normalized_match = 1) / NULLIF(SUM(error_type <> 'both_empty'), 0) AS acc
         FROM decode_field_quality WHERE prompt_sha256 IS NOT NULL
         GROUP BY prompt_sha256, model"
    )->fetchAll();
    $accByPrompt = [];
    foreach ($acc as $a) {
        if ($a['acc'] !== null) {
            $accByPrompt[(string) $a['prompt_sha256']][(string) $a['model']] = (float) $a['acc'];
        }
    }
    foreach ($versions as &$v) {
        $sha = (string) $v['sha256'];
        $v['is_current_file'] = isset($currentByHash[$sha]) ? $currentByHash[$sha] : null;
        $models = $accByPrompt[$sha] ?? [];
        arsort($models);
        $v['best_model'] = $models ? (string) array_key_first($models) : null;
        $v['best_acc'] = $models ? (float) reset($models) : null;
        $v['mean_acc'] = $models ? array_sum($models) / count($models) : null;
        $v['per_model'] = $models;
    }
    unset($v);
    return $versions;
}

/**
 * Line diff (LCS) between two texts. Returns [['type' => ' '|'-'|'+', 'text' => ...], ...].
 */
function lineDiff(string $old, string $new): array
{
    $a = preg_split('/\R/', $old) ?: [];
    $b = preg_split('/\R/', $new) ?: [];
    $n = count($a);
    $m = count($b);
    $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
        }
    }
    $out = [];
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) {
            $out[] = ['type' => ' ', 'text' => $a[$i]];
            $i++;
            $j++;
        } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
            $out[] = ['type' => '-', 'text' => $a[$i]];
            $i++;
        } else {
            $out[] = ['type' => '+', 'text' => $b[$j]];
            $j++;
        }
    }
    for (; $i < $n; $i++) {
        $out[] = ['type' => '-', 'text' => $a[$i]];
    }
    for (; $j < $m; $j++) {
        $out[] = ['type' => '+', 'text' => $b[$j]];
    }
    return $out;
}

function writePromptSnapshotFile(string $promptText): string
{
    $tmpPrompt = tempnam(sys_get_temp_dir(), 'kakra_prompt_job_');
    if ($tmpPrompt === false) {
        throw new RuntimeException('Unable to allocate prompt snapshot file');
    }
    chmod($tmpPrompt, 0644);
    if (file_put_contents($tmpPrompt, $promptText) === false) {
        @unlink($tmpPrompt);
        throw new RuntimeException('Unable to write prompt snapshot file');
    }

    return $tmpPrompt;
}

/**
 * Snapshot the prompt for one job. Returns [tmp_path, prompt_name, sha256].
 */
function createPromptSnapshot(?string $promptRelativePath = null): array
{
    $promptName = $promptRelativePath ?? getCurrentPromptFileRelativePath();
    $promptText = getPromptTextFromRelativePath($promptName);
    $sha = registerPromptVersion($promptName, $promptText);

    return [writePromptSnapshotFile($promptText), $promptName, $sha];
}

function createPromptSnapshotFile(?string $promptRelativePath = null): string
{
    return createPromptSnapshot($promptRelativePath)[0];
}

function createDecodeJob(
    string $sourceImagePath,
    ?string $promptRelativePath = null,
    ?string $requestedModel = null,
    ?string $comparisonGroup = null,
    ?int $benchmarkHeaderId = null
): int {
    ensureDecodeJobAnalysisColumns();
    [$promptSnapshot, $promptName, $promptSha] = createPromptSnapshot($promptRelativePath);
    $stmt = pdo()->prepare(
        'INSERT INTO decode_jobs
         (source_image_filename, source_image_path, prompt_file_path, prompt_name, prompt_sha256,
          requested_model, comparison_group, benchmark_header_id, status, attempt_count)
         VALUES (:source_image_filename, :source_image_path, :prompt_file_path, :prompt_name, :prompt_sha256,
          :requested_model, :comparison_group, :benchmark_header_id, :status, :attempt_count)'
    );
    $stmt->execute([
        'source_image_filename' => basename($sourceImagePath),
        'source_image_path' => $sourceImagePath,
        'prompt_file_path' => $promptSnapshot,
        'prompt_name' => $promptName,
        'prompt_sha256' => $promptSha,
        'requested_model' => $requestedModel,
        'comparison_group' => $comparisonGroup,
        'benchmark_header_id' => $benchmarkHeaderId,
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

    // A retry must re-run the *same* prompt version the job was created with, so its
    // result stays comparable with the sibling jobs in its comparison group. Only fall
    // back to the current active prompt when the job predates prompt provenance.
    $existingSha = trim((string) ($job['prompt_sha256'] ?? ''));
    $existingText = $existingSha !== '' && $promptRelativePath === null ? getPromptVersionText($existingSha) : null;
    if ($existingText !== null) {
        $promptSnapshot = writePromptSnapshotFile($existingText);
        $promptName = (string) ($job['prompt_name'] ?? '');
        $promptSha = $existingSha;
    } else {
        [$promptSnapshot, $promptName, $promptSha] = createPromptSnapshot($promptRelativePath);
    }

    $stmt = pdo()->prepare(
        'UPDATE decode_jobs SET
         prompt_file_path = :prompt_file_path,
         prompt_name = :prompt_name,
         prompt_sha256 = :prompt_sha256,
         status = :status,
          error_message = NULL,
          usage_metadata_json = NULL,
          decoding_model = NULL,
          total_token_count = NULL,
          prompt_token_count = NULL,
          completion_token_count = NULL,
          reasoning_token_count = NULL,
          cost_usd = NULL,
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
        'prompt_name' => $promptName,
        'prompt_sha256' => $promptSha,
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
    // Benchmark re-runs are scored automatically and never need manual review, so keep
    // them out of the job queue view entirely (they are visible on the Statistics page).
    $where = $includeSaved
        ? 'WHERE archived = 0 AND benchmark_header_id IS NULL'
        : "WHERE status NOT IN ('saved', 'rejected', 'benchmarked') AND archived = 0 AND benchmark_header_id IS NULL";
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
    $stmt = pdo()->query("SELECT COUNT(*) FROM decode_jobs WHERE status NOT IN ('saved', 'rejected', 'benchmarked') AND archived = 0 AND benchmark_header_id IS NULL");
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
        // 006_cost_prompt_provenance_benchmark.sql
        $additions = [
            'prompt_sha256' => 'CHAR(64) NULL AFTER prompt_file_path',
            'char_distance' => 'INT UNSIGNED NULL AFTER normalized_match',
            'inherited_from_previous' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER char_distance',
            'predicted_decoding_status' => 'VARCHAR(16) NULL AFTER inherited_from_previous',
            'is_benchmark' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER manually_corrected',
        ];
        foreach ($additions as $column => $definition) {
            if (!isset($columns[$column])) {
                pdo()->exec("ALTER TABLE decode_field_quality ADD COLUMN {$column} {$definition}");
            }
        }
    } catch (Throwable) {
    }

    $done = true;
}

function decodeQualityNormalizeValue(string $field, mixed $value): string
{
    $text = strtolower(trim((string) $value));
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    // Glyph variants that mean the same thing on these cards.
    $text = str_replace(['→', '−', '–'], ['->', '-', '-'], $text);

    if ($field === 'sex') {
        $text = str_replace(['♀', '♂'], ['f', 'm'], $text);
    }
    if ($field === 'scull_repeat') {
        // Reviewers wrote both "ns-" and "ns -" for the same mark.
        $text = preg_replace('/\s*-\s*/', '-', $text) ?? $text;
    }

    if (in_array($field, ['ringing_date', 'recovery_date'], true)) {
        // Two-digit years as written on the cards: 21 -> 2021, 98 -> 1998 (same rule as the prompt).
        $text = preg_replace_callback(
            '/^(\d{1,2}[.\/-]\d{1,2}[.\/-])(\d{2})$/',
            static fn(array $m) => $m[1] . ((int) $m[2] < 30 ? '20' : '19') . $m[2],
            $text
        ) ?? $text;
        $text = str_replace(['/', '-', ',', ' '], '.', $text);
        $text = preg_replace('/\.+/', '.', $text) ?? $text;
        $text = trim($text, '.');
        // Canonical d.m.Y without zero padding, so "2011-05-29", "29.05.2011" and
        // "29.5.2011" all compare equal (a format difference, not a reading error).
        if (preg_match('/^(\d{4})\.(\d{1,2})\.(\d{1,2})$/', $text, $m) === 1) {
            $text = sprintf('%d.%d.%d', (int) $m[3], (int) $m[2], (int) $m[1]);
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $text, $m) === 1) {
            $text = sprintf('%d.%d.%d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
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

const QUALITY_HEADER_KEYS = ['bird_id', 'card_code', 'sex', 'ring_position', 'ring_number', 'ringing_age', 'ringing_date', 'ringing_nest', 'scull_length', 'scull_repeat'];
const QUALITY_CONTENT_KEYS = ['ring_position', 'ring_number', 'obs_status', 'obs_year', 'obs_nest', 'obs_notes'];
const QUALITY_RECOVERY_KEYS = ['ring_number', 'recovery_status', 'recovery_date', 'recovery_location', 'recovery_person', 'recovery_notes'];

/** Fields that identify a row when aligning predicted rows to final rows (weight 1); the rest weigh 0.25. */
const QUALITY_ROW_KEY_FIELDS = [
    'content' => ['obs_year', 'obs_nest', 'obs_status', 'obs_notes'],
    'recovery' => ['recovery_date', 'recovery_location', 'recovery_person', 'recovery_notes'],
];

function decodeQualityLevenshtein(string $a, string $b): int
{
    if ($a === $b) {
        return 0;
    }
    $aChars = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $bChars = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $n = count($aChars);
    $m = count($bChars);
    if ($n === 0) {
        return $m;
    }
    if ($m === 0) {
        return $n;
    }
    $prev = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
        $curr = [$i];
        for ($j = 1; $j <= $m; $j++) {
            $cost = $aChars[$i - 1] === $bChars[$j - 1] ? 0 : 1;
            $curr[$j] = min($prev[$j] + 1, $curr[$j - 1] + 1, $prev[$j - 1] + $cost);
        }
        $prev = $curr;
    }

    return $prev[$m];
}

/**
 * Similarity in [0,1] between a predicted and a final row: weighted share of fields whose
 * normalized values match, counting only fields that are non-empty on at least one side.
 */
function decodeQualityRowSimilarity(array $predRow, array $finalRow, array $keys, array $keyFields): float
{
    $score = 0.0;
    $weightTotal = 0.0;
    foreach ($keys as $field) {
        $pred = decodeQualityNormalizeValue($field, (string) ($predRow[$field] ?? ''));
        $final = decodeQualityNormalizeValue($field, (string) ($finalRow[$field] ?? ''));
        if ($pred === '' && $final === '') {
            continue;
        }
        $weight = in_array($field, $keyFields, true) ? 1.0 : 0.25;
        $weightTotal += $weight;
        if ($pred === $final) {
            $score += $weight;
        } elseif ($pred !== '' && $final !== '') {
            // Partial credit for near-misses so a one-glyph error still anchors the row.
            $dist = decodeQualityLevenshtein($pred, $final);
            $len = max(mb_strlen($pred), mb_strlen($final));
            if ($len > 0 && $dist / $len <= 0.34) {
                $score += $weight * 0.5;
            }
        }
    }

    return $weightTotal > 0 ? $score / $weightTotal : 0.0;
}

/**
 * Align predicted rows to final rows in order (monotone alignment, like diff) maximizing
 * total similarity. Returns [[predIndex|null, finalIndex|null], ...]. A predicted row with
 * no counterpart is an extra row; a final row with no counterpart is a missing row.
 * Positional pairing (the old behaviour) turned one skipped row into a cascade of
 * mismatches for every row after it.
 */
function decodeQualityAlignRows(array $predRows, array $finalRows, array $keys, array $keyFields): array
{
    $n = count($predRows);
    $m = count($finalRows);
    $minSim = 0.2;
    $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0.0));
    $back = array_fill(0, $n + 1, array_fill(0, $m + 1, ''));
    for ($i = 1; $i <= $n; $i++) {
        $back[$i][0] = 'up';
    }
    for ($j = 1; $j <= $m; $j++) {
        $back[0][$j] = 'left';
    }
    for ($i = 1; $i <= $n; $i++) {
        for ($j = 1; $j <= $m; $j++) {
            $sim = decodeQualityRowSimilarity($predRows[$i - 1], $finalRows[$j - 1], $keys, $keyFields);
            $best = $dp[$i - 1][$j];
            $dir = 'up';
            if ($dp[$i][$j - 1] > $best) {
                $best = $dp[$i][$j - 1];
                $dir = 'left';
            }
            if ($sim >= $minSim && $dp[$i - 1][$j - 1] + $sim > $best) {
                $best = $dp[$i - 1][$j - 1] + $sim;
                $dir = 'diag';
            }
            $dp[$i][$j] = $best;
            $back[$i][$j] = $dir;
        }
    }

    $pairs = [];
    $i = $n;
    $j = $m;
    while ($i > 0 || $j > 0) {
        $dir = $back[$i][$j];
        if ($dir === 'diag') {
            $pairs[] = [$i - 1, $j - 1];
            $i--;
            $j--;
        } elseif ($dir === 'up' || $j === 0) {
            $pairs[] = [$i - 1, null];
            $i--;
        } else {
            $pairs[] = [null, $j - 1];
            $j--;
        }
    }

    return array_reverse($pairs);
}

function decodeQualityFieldRow(string $section, ?int $rowNo, string $field, string $predValue, string $finalValue, ?string $rowError = null): array
{
    $predValue = trim($predValue);
    $finalValue = trim($finalValue);
    $predNorm = decodeQualityNormalizeValue($field, $predValue);
    $finalNorm = decodeQualityNormalizeValue($field, $finalValue);
    $normMatch = $predNorm === $finalNorm;
    $errorType = $rowError ?? decodeQualityErrorType($predNorm, $finalNorm, $normMatch);
    $charDistance = ($predNorm === '' && $finalNorm === '') ? null : decodeQualityLevenshtein($predNorm, $finalNorm);

    return [
        'section' => $section,
        'row_no' => $rowNo,
        'field_name' => $field,
        'predicted_value' => $predValue,
        'final_value' => $finalValue,
        'exact_match' => (int) ($predValue === $finalValue),
        'normalized_match' => (int) $normMatch,
        'char_distance' => $charDistance,
        'inherited_from_previous' => 0,
        'predicted_decoding_status' => null,
        'error_type' => $errorType,
    ];
}

/**
 * Compare one job's prediction against a final (reviewed) record and return one quality
 * row per field. Shared by the manual "Accept and Save" flow and by benchmark re-runs.
 *
 * Row-level structure errors are reported as error_type 'missing_row' (final row the
 * model did not produce) and 'extra_row' (predicted row with no counterpart); their field
 * rows are still emitted so per-field counts stay comparable, but stats can separate them.
 * inherited_from_previous marks ring_number/ring_position values a model carried over from
 * the previous row (or the header for row 1), so one misread is not counted N times.
 */
function computeFieldQualityRows(array $decoded, array $finalDecoded): array
{
    // Fully blank rows carry no information on either side (an unfilled template row in
    // the saved record, or a model echoing the empty example row), so they are ignored.
    $nonBlank = static fn($rows, array $keys) => is_array($rows)
        ? array_values(array_filter($rows, static fn($r) => !isBlankRow($r, $keys)))
        : [];
    $finalHeader = is_array($finalDecoded['header'] ?? null) ? $finalDecoded['header'] : [];
    $finalContent = $nonBlank($finalDecoded['content'] ?? null, QUALITY_CONTENT_KEYS);
    $finalRecovery = $nonBlank($finalDecoded['recovery'] ?? null, QUALITY_RECOVERY_KEYS);
    $predHeader = is_array($decoded['header'] ?? null) ? $decoded['header'] : [];
    $predContent = $nonBlank($decoded['content'] ?? null, QUALITY_CONTENT_KEYS);
    $predRecovery = $nonBlank($decoded['recovery'] ?? null, QUALITY_RECOVERY_KEYS);

    $rows = [];
    foreach (QUALITY_HEADER_KEYS as $field) {
        $rows[] = decodeQualityFieldRow('header', null, $field, (string) ($predHeader[$field] ?? ''), (string) ($finalHeader[$field] ?? ''));
    }

    $sections = [
        'content' => [$predContent, $finalContent, QUALITY_CONTENT_KEYS],
        'recovery' => [$predRecovery, $finalRecovery, QUALITY_RECOVERY_KEYS],
    ];
    foreach ($sections as $section => [$predRows, $finalRows, $keys]) {
        $pairs = decodeQualityAlignRows($predRows, $finalRows, $keys, QUALITY_ROW_KEY_FIELDS[$section]);
        $extraRowNo = count($finalRows);
        $prevPred = $section === 'content' ? $predHeader : null;
        foreach ($pairs as [$pi, $fi]) {
            $predRow = $pi === null ? [] : $predRows[$pi];
            $finalRow = $fi === null ? [] : $finalRows[$fi];
            $rowError = null;
            if ($pi === null) {
                $rowError = 'missing_row';
            } elseif ($fi === null) {
                $rowError = 'extra_row';
            }
            // Extra predicted rows get row numbers after the final rows so they stay unique per job.
            $rowNo = $fi !== null ? $fi + 1 : ++$extraRowNo;
            $status = $pi === null ? null : trim((string) ($predRow['decoding_status'] ?? ''));
            foreach ($keys as $field) {
                $row = decodeQualityFieldRow($section, $rowNo, $field, (string) ($predRow[$field] ?? ''), (string) ($finalRow[$field] ?? ''), $rowError);
                if ($status !== null && $status !== '') {
                    $row['predicted_decoding_status'] = mb_substr($status, 0, 16);
                }
                if ($pi !== null && $prevPred !== null && in_array($field, ['ring_number', 'ring_position'], true)) {
                    $predNorm = decodeQualityNormalizeValue($field, (string) ($predRow[$field] ?? ''));
                    $prevNorm = decodeQualityNormalizeValue($field, (string) ($prevPred[$field] ?? ''));
                    if ($predNorm !== '' && $predNorm === $prevNorm) {
                        $row['inherited_from_previous'] = 1;
                    }
                }
                $rows[] = $row;
            }
            if ($pi !== null) {
                $prevPred = $predRow;
            }
        }
    }

    return $rows;
}

function insertFieldQualityRows(int $headerId, array $job, array $rows, bool $isBenchmark): void
{
    ensureDecodeFieldQualityTable();
    $jobId = (int) ($job['id'] ?? 0);
    $model = trim((string) ($job['decoding_model'] ?? ''));
    if ($model === '') {
        $model = trim((string) ($job['requested_model'] ?? ''));
    }
    if ($model === '') {
        $model = 'unknown';
    }

    $insert = pdo()->prepare(
        'INSERT INTO decode_field_quality
            (header_id, decode_job_id, model, prompt_file_path, prompt_sha256, section, row_no, field_name,
             predicted_value, final_value, exact_match, normalized_match, char_distance, inherited_from_previous,
             predicted_decoding_status, manually_corrected, is_benchmark, error_type)
         VALUES
            (:header_id, :decode_job_id, :model, :prompt_file_path, :prompt_sha256, :section, :row_no, :field_name,
             :predicted_value, :final_value, :exact_match, :normalized_match, :char_distance, :inherited_from_previous,
             :predicted_decoding_status, 0, :is_benchmark, :error_type)'
    );
    foreach ($rows as $row) {
        $insert->execute([
            'header_id' => $headerId,
            'decode_job_id' => $jobId,
            'model' => $model,
            'prompt_file_path' => (string) ($job['prompt_name'] ?? $job['prompt_file_path'] ?? ''),
            'prompt_sha256' => $job['prompt_sha256'] ?? null,
            'section' => $row['section'],
            'row_no' => $row['row_no'],
            'field_name' => $row['field_name'],
            'predicted_value' => $row['predicted_value'],
            'final_value' => $row['final_value'],
            'exact_match' => $row['exact_match'],
            'normalized_match' => $row['normalized_match'],
            'char_distance' => $row['char_distance'],
            'inherited_from_previous' => $row['inherited_from_previous'],
            'predicted_decoding_status' => $row['predicted_decoding_status'],
            'is_benchmark' => (int) $isBenchmark,
            'error_type' => $row['error_type'],
        ]);
    }
}

function saveDecodeFieldQuality(int $headerId, array $reviewJobIds, array $finalDecoded): void
{
    if (empty($reviewJobIds)) {
        return;
    }

    foreach ($reviewJobIds as $rawJobId) {
        $jobId = (int) $rawJobId;
        if ($jobId <= 0) {
            continue;
        }
        $job = getDecodeJob($jobId);
        if ($job === null) {
            continue;
        }
        $decoded = json_decode((string) ($job['decoded_json'] ?? ''), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        insertFieldQualityRows($headerId, $job, computeFieldQualityRows($decoded, $finalDecoded), false);
    }
}

/** Load a saved record in the same {header, content, recovery} shape the models produce. */
function loadSavedRecordAsDecoded(int $headerId): ?array
{
    $stmt = pdo()->prepare('SELECT * FROM cards_header WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $headerId]);
    $header = $stmt->fetch();
    if (!$header) {
        return null;
    }
    $content = pdo()->prepare('SELECT ' . implode(', ', QUALITY_CONTENT_KEYS) . ' FROM cards_content WHERE header_id = :id ORDER BY row_no');
    $content->execute(['id' => $headerId]);
    $recovery = pdo()->prepare('SELECT ' . implode(', ', QUALITY_RECOVERY_KEYS) . ' FROM cards_recovery WHERE header_id = :id ORDER BY row_no');
    $recovery->execute(['id' => $headerId]);

    $headerOut = [];
    foreach (QUALITY_HEADER_KEYS as $field) {
        $headerOut[$field] = (string) ($header[$field] ?? '');
    }

    return [
        'header' => $headerOut,
        'content' => $content->fetchAll() ?: [],
        'recovery' => $recovery->fetchAll() ?: [],
        'source_image_path' => (string) ($header['source_image_path'] ?? ''),
    ];
}

/**
 * Score a finished benchmark job against the saved record it was re-run for, then mark
 * the job 'benchmarked' so it never shows up for manual review. Idempotent: earlier
 * quality rows for the same job are replaced.
 */
function scoreBenchmarkJob(int $jobId): void
{
    $job = getDecodeJob($jobId);
    if ($job === null) {
        throw new RuntimeException("decode_jobs.id={$jobId} not found");
    }
    $headerId = (int) ($job['benchmark_header_id'] ?? 0);
    if ($headerId <= 0) {
        throw new RuntimeException("Job {$jobId} is not a benchmark job");
    }
    $final = loadSavedRecordAsDecoded($headerId);
    if ($final === null) {
        throw new RuntimeException("cards_header.id={$headerId} not found for benchmark job {$jobId}");
    }
    $decoded = json_decode((string) ($job['decoded_json'] ?? ''), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Job {$jobId} has no decoded JSON to score");
    }

    ensureDecodeFieldQualityTable();
    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM decode_field_quality WHERE decode_job_id = :id');
        $del->execute(['id' => $jobId]);
        insertFieldQualityRows($headerId, $job, computeFieldQualityRows($decoded, $final), true);
        $upd = $pdo->prepare("UPDATE decode_jobs SET status = 'benchmarked', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $upd->execute(['id' => $jobId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function refreshDecodeFieldQualityForHeader(int $headerId, array $finalDecoded): void
{
    ensureDecodeFieldQualityTable();

    $finalHeader = is_array($finalDecoded['header'] ?? null) ? $finalDecoded['header'] : [];
    $finalContent = is_array($finalDecoded['content'] ?? null) ? array_values($finalDecoded['content']) : [];
    $finalRecovery = is_array($finalDecoded['recovery'] ?? null) ? array_values($finalDecoded['recovery']) : [];

    $rowsStmt = pdo()->prepare(
        'SELECT id, section, row_no, field_name, predicted_value, final_value, manually_corrected, error_type
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
             char_distance=:char_distance,
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
        $oldErrorType = (string) ($row['error_type'] ?? '');
        if ($oldErrorType === 'extra_row') {
            // Predicted row with no counterpart in the saved record: nothing to refresh.
            continue;
        }

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
        $errorType = $oldErrorType === 'missing_row'
            ? 'missing_row'
            : decodeQualityErrorType($predNorm, $newFinalNorm, (bool) $normMatch);

        $update->execute([
            'id' => (int) ($row['id'] ?? 0),
            'final_value' => $newFinalValue,
            'exact_match' => $exactMatch,
            'normalized_match' => $normMatch,
            'char_distance' => ($predNorm === '' && $newFinalNorm === '') ? null : decodeQualityLevenshtein($predNorm, $newFinalNorm),
            'manually_corrected' => $manualCorrected,
            'error_type' => $errorType,
        ]);
    }
}

/**
 * Benchmark jobs that were run for one saved record, newest first, with the per-job
 * accuracy (normalized match on populated fields) and cost.
 */
function listBenchmarkJobsForHeader(int $headerId): array
{
    ensureDecodeJobAnalysisColumns();
    $stmt = pdo()->prepare(
        "SELECT j.id, j.status, j.requested_model, j.decoding_model, j.prompt_name, j.prompt_sha256,
                j.cost_usd, j.reasoning_token_count, j.total_token_count, j.error_message, j.created_at,
                TIMESTAMPDIFF(SECOND, j.started_at, j.finished_at) AS seconds,
                q.populated, q.ok, q.errors
         FROM decode_jobs j
         LEFT JOIN (
            SELECT decode_job_id,
                   SUM(error_type <> 'both_empty') AS populated,
                   SUM(error_type <> 'both_empty' AND normalized_match = 1) AS ok,
                   SUM(error_type <> 'both_empty' AND normalized_match = 0) AS errors
            FROM decode_field_quality WHERE header_id = :hq GROUP BY decode_job_id
         ) q ON q.decode_job_id = j.id
         WHERE j.benchmark_header_id = :h AND j.archived = 0
         ORDER BY j.id DESC"
    );
    $stmt->execute(['h' => $headerId, 'hq' => $headerId]);
    $jobs = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $populated = (int) ($row['populated'] ?? 0);
        $row['accuracy'] = $populated > 0 ? 100 * (int) $row['ok'] / $populated : null;
        $row['model'] = trim((string) ($row['decoding_model'] ?? '')) ?: (string) $row['requested_model'];
        $jobs[] = $row;
    }
    return $jobs;
}

/**
 * Field-by-field comparison of several benchmark jobs against the saved record.
 * Returns ['rows' => [[section,row_no,field,final,cells{jobId => [value,error_type,match]}]...]]
 * in display order: header fields, content rows, recovery rows; rows the models predicted
 * but the record does not have appear at the end of their section as "extra".
 */
function benchmarkMatrixForHeader(int $headerId, array $jobIds): array
{
    $jobIds = array_values(array_filter(array_map('intval', $jobIds)));
    if (!$jobIds) {
        return ['rows' => []];
    }
    $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
    $stmt = pdo()->prepare(
        "SELECT decode_job_id, section, row_no, field_name, predicted_value, final_value, normalized_match, error_type
         FROM decode_field_quality
         WHERE header_id = ? AND decode_job_id IN ($placeholders)
         ORDER BY id"
    );
    $stmt->execute(array_merge([$headerId], $jobIds));

    $sectionOrder = ['header' => 0, 'content' => 1, 'recovery' => 2];
    $fieldOrder = [
        'header' => array_flip(QUALITY_HEADER_KEYS),
        'content' => array_flip(QUALITY_CONTENT_KEYS),
        'recovery' => array_flip(QUALITY_RECOVERY_KEYS),
    ];
    $rows = [];
    foreach ($stmt->fetchAll() ?: [] as $q) {
        $section = (string) $q['section'];
        $rowNo = $q['row_no'] === null ? 0 : (int) $q['row_no'];
        $field = (string) $q['field_name'];
        $key = $section . '|' . $rowNo . '|' . $field;
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'section' => $section,
                'row_no' => $rowNo,
                'field' => $field,
                'final' => (string) $q['final_value'],
                'extra' => (string) $q['error_type'] === 'extra_row',
                'cells' => [],
            ];
        }
        if ((string) $q['error_type'] !== 'extra_row' && $rows[$key]['final'] === '' && (string) $q['final_value'] !== '') {
            $rows[$key]['final'] = (string) $q['final_value'];
        }
        $rows[$key]['cells'][(int) $q['decode_job_id']] = [
            'value' => (string) $q['predicted_value'],
            'error_type' => (string) $q['error_type'],
            'match' => (int) $q['normalized_match'] === 1,
        ];
    }
    uasort($rows, static function (array $a, array $b) use ($sectionOrder, $fieldOrder) {
        return [$sectionOrder[$a['section']] ?? 9, $a['row_no'], $fieldOrder[$a['section']][$a['field']] ?? 99]
            <=> [$sectionOrder[$b['section']] ?? 9, $b['row_no'], $fieldOrder[$b['section']][$b['field']] ?? 99];
    });

    return ['rows' => array_values($rows)];
}

/**
 * Queue benchmark jobs for one saved record from the UI and start their workers.
 * The saved record is never modified; results land in decode_field_quality (is_benchmark=1).
 */
function startBenchmarkForHeader(int $headerId, array $models, ?string $promptRelativePath): array
{
    $record = loadSavedRecordAsDecoded($headerId);
    if ($record === null) {
        throw new RuntimeException('Record not found');
    }
    $imagePath = (string) ($record['source_image_path'] ?? '');
    if ($imagePath === '' || !is_file($imagePath)) {
        throw new RuntimeException('Source image for this record is not on disk, cannot benchmark');
    }
    $allowed = allowedModels();
    $models = array_values(array_unique(array_filter(array_map('trim', $models), static fn($m) => $m !== '' && in_array($m, $allowed, true))));
    if (!$models) {
        throw new RuntimeException('Select at least one model');
    }
    if ($promptRelativePath !== null && !isAllowedPromptRelativePath($promptRelativePath)) {
        throw new RuntimeException('Unknown prompt file');
    }

    $group = 'bench-ui-' . $headerId . '-' . substr(md5((string) microtime(true)), 0, 8);
    $jobIds = [];
    foreach ($models as $model) {
        $jobIds[] = createDecodeJob($imagePath, $promptRelativePath, $model, $group, $headerId);
    }
    foreach ($jobIds as $jobId) {
        try {
            startDecodeJobWorker($jobId);
        } catch (Throwable $e) {
            markDecodeJobFailed($jobId, $e->getMessage());
        }
    }
    return $jobIds;
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
        // 006_cost_prompt_provenance_benchmark.sql
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS prompt_versions (
                sha256 CHAR(64) PRIMARY KEY,
                prompt_name VARCHAR(255) NOT NULL,
                prompt_text MEDIUMTEXT NOT NULL,
                first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $additions = [
            'prompt_name' => 'VARCHAR(255) NULL AFTER prompt_file_path',
            'prompt_sha256' => 'CHAR(64) NULL AFTER prompt_name',
            'prompt_token_count' => 'INT UNSIGNED NULL AFTER total_token_count',
            'completion_token_count' => 'INT UNSIGNED NULL AFTER prompt_token_count',
            'reasoning_token_count' => 'INT UNSIGNED NULL AFTER completion_token_count',
            'cost_usd' => 'DECIMAL(10, 6) NULL AFTER reasoning_token_count',
            'benchmark_header_id' => 'BIGINT UNSIGNED NULL AFTER saved_header_id',
        ];
        foreach ($additions as $column => $definition) {
            if (!isset($columns[$column])) {
                $pdo->exec("ALTER TABLE decode_jobs ADD COLUMN {$column} {$definition}");
            }
        }
        // 007_prompt_version_notes.sql
        $promptColumns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM prompt_versions')->fetchAll() as $row) {
            $promptColumns[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($promptColumns['notes'])) {
            $pdo->exec('ALTER TABLE prompt_versions ADD COLUMN notes TEXT NULL AFTER prompt_text');
        }
    } catch (Throwable) {
    }

    $done = true;
}
