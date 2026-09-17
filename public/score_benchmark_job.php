<?php

declare(strict_types=1);

// CLI-only: score one finished benchmark decode job against the saved record it was
// re-run for. Called by python/decode_job_worker.py after a successful decode, and by
// public/decode_job_worker.php in-process. Usage: php public/score_benchmark_job.php --job-id N

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
    scoreBenchmarkJob($jobId);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
