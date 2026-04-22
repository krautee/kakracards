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

function getPromptText(): string
{
    $stmt = pdo()->prepare('SELECT `value` FROM app_settings WHERE `key` = :key LIMIT 1');
    $stmt->execute(['key' => 'prompt_text']);
    $row = $stmt->fetch();
    if ($row && trim((string) $row['value']) !== '') {
        return (string) $row['value'];
    }

    $path = resolveProjectPath((string) envValue('PROMPT_FILE', './prompts/default_prompt.md'));
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function savePromptText(string $text): void
{
    $stmt = pdo()->prepare(
        'INSERT INTO app_settings (`key`, `value`) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE `value` = :value_update, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['key' => 'prompt_text', 'value' => $text, 'value_update' => $text]);
}

function uploadDir(): string
{
    $dir = resolveProjectPath((string) envValue('UPLOAD_DIR', './uploads'));
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function pythonBin(): string
{
    return envValue('PYTHON_BIN', 'python3');
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

function decodeImageWithPython(string $imagePath): array
{
    $tmpPrompt = tempnam(sys_get_temp_dir(), 'kakra_prompt_');
    chmod($tmpPrompt, 0600);
    file_put_contents($tmpPrompt, getPromptText());

    $script = ROOT_DIR . '/python/decode_cards.py';
    $cmd = [
        safePythonBin(),
        $script,
        '--image',
        $imagePath,
        '--prompt-file',
        $tmpPrompt,
        '--no-db',
        '--json',
    ];
    $process = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        @unlink($tmpPrompt);
        throw new RuntimeException('Failed to execute decoder process');
    }

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($tmpPrompt);

    if ($exitCode !== 0) {
        throw new RuntimeException("Decode failed: " . trim($stderr !== '' ? $stderr : $stdout));
    }

    $json = json_decode($stdout, true);
    if (!is_array($json) || !isset($json[0]['decoded']) || !is_array($json[0]['decoded'])) {
        throw new RuntimeException('Unexpected decode output');
    }

    return $json[0]['decoded'];
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            'decoding_status' => $row['decoding_status'] ?? null,
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
            'decoding_status' => $row['decoding_status'] ?? null,
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
