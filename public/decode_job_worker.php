<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

$jobId = 0;
foreach ($argv as $i => $arg) {
    if ($arg === '--job-id' && isset($argv[$i + 1])) {
        $jobId = (int) $argv[$i + 1];
        break;
    }
}

if ($jobId <= 0) {
    fwrite(STDERR, "Missing --job-id\n");
    exit(1);
}

try {
    $job = getDecodeJob($jobId);
    if ($job === null) {
        throw new RuntimeException("decode_jobs.id={$jobId} not found");
    }

    $status = (string) ($job['status'] ?? 'queued');
    if (in_array($status, ['succeeded', 'saved', 'running'], true)) {
        exit(0);
    }

    $stmt = pdo()->prepare(
        'UPDATE decode_jobs
         SET status=:status, started_at=NOW(), finished_at=NULL, error_message=NULL, updated_at=CURRENT_TIMESTAMP
         WHERE id=:id'
    );
    $stmt->execute(['id' => $jobId, 'status' => 'running']);

    $sourceImagePath = (string) ($job['source_image_path'] ?? '');
    $promptFilePath = (string) ($job['prompt_file_path'] ?? '');
    if ($sourceImagePath === '' || !is_file($sourceImagePath)) {
        throw new RuntimeException('Source image not found for queued job');
    }
    if ($promptFilePath === '' || !is_file($promptFilePath)) {
        throw new RuntimeException('Prompt snapshot file not found for queued job');
    }

    $requestedModel = trim((string) ($job['requested_model'] ?? ''));
    $result = decodeImageWithPythonPrompt($sourceImagePath, $promptFilePath, $requestedModel !== '' ? $requestedModel : null);
    $decoded = is_array($result['decoded'] ?? null) ? $result['decoded'] : [];
    $usage = is_array($result['usageMetadata'] ?? null) ? $result['usageMetadata'] : [];
    $model = trim((string) ($result['model'] ?? $requestedModel ?: getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash'));
    $totalTokenCount = isset($usage['totalTokenCount']) ? (int) $usage['totalTokenCount'] : null;

    $doneStmt = pdo()->prepare(
        'UPDATE decode_jobs
         SET status=:status,
              decoded_json=:decoded_json,
              usage_metadata_json=:usage_metadata_json,
              decoding_model=:decoding_model,
              total_token_count=:total_token_count,
              finished_at=NOW(),
             updated_at=CURRENT_TIMESTAMP
         WHERE id=:id'
    );
    $doneStmt->execute([
        'id' => $jobId,
        'status' => 'succeeded',
        'decoded_json' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
        'usage_metadata_json' => json_encode($usage, JSON_UNESCAPED_UNICODE),
        'decoding_model' => $model,
        'total_token_count' => $totalTokenCount,
    ]);
    @unlink($promptFilePath);
    exit(0);
} catch (Throwable $e) {
    try {
        $failStmt = pdo()->prepare(
            'UPDATE decode_jobs
             SET status=:status, error_message=:error_message, finished_at=NOW(), updated_at=CURRENT_TIMESTAMP
             WHERE id=:id'
        );
        $failStmt->execute([
            'id' => $jobId,
            'status' => 'failed',
            'error_message' => substr($e->getMessage(), 0, 2000),
        ]);
    } catch (Throwable) {
    }

    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
