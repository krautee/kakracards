<?php

declare(strict_types=1);

// CLI-only maintenance: bring historical data in line with the current scoring code.
//
//   php public/rescore_quality.php --backfill-cost   estimate cost_usd/token splits for
//                                                    old jobs from usage_metadata_json
//   php public/rescore_quality.php --rescore         recompute all non-benchmark quality
//                                                    rows (row alignment, char distance,
//                                                    inherited flag, date canonicalization),
//                                                    keeping manually_corrected flags
//   php public/rescore_quality.php --rescore-benchmark  re-score finished benchmark jobs
//   php public/rescore_quality.php --all             everything
//
// Safe to run repeatedly.

require __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

$doCost = in_array('--backfill-cost', $argv, true) || in_array('--all', $argv, true);
$doRescore = in_array('--rescore', $argv, true) || in_array('--all', $argv, true);
$doBench = in_array('--rescore-benchmark', $argv, true) || in_array('--all', $argv, true);
if (!$doCost && !$doRescore && !$doBench) {
    fwrite(STDERR, "Usage: php public/rescore_quality.php [--backfill-cost] [--rescore] [--rescore-benchmark] [--all]\n");
    exit(1);
}

$pricing = json_decode((string) @file_get_contents(ROOT_DIR . '/python/model_pricing.json'), true) ?: [];

if ($doCost) {
    $rows = pdo()->query(
        "SELECT id, COALESCE(NULLIF(decoding_model, ''), requested_model) AS model, usage_metadata_json
         FROM decode_jobs
         WHERE usage_metadata_json IS NOT NULL AND cost_usd IS NULL"
    )->fetchAll();
    $upd = pdo()->prepare(
        'UPDATE decode_jobs SET prompt_token_count=:p, completion_token_count=:c, reasoning_token_count=:r, cost_usd=:cost WHERE id=:id'
    );
    $done = 0;
    $unknown = [];
    foreach ($rows as $row) {
        $usage = json_decode((string) $row['usage_metadata_json'], true);
        if (!is_array($usage)) {
            continue;
        }
        $model = (string) $row['model'];
        $p = (int) ($usage['promptTokenCount'] ?? 0);
        $c = (int) ($usage['candidatesTokenCount'] ?? 0);
        $r = (int) ($usage['thoughtsTokenCount'] ?? 0);
        $cost = null;
        if (isset($usage['costUsd']) && is_numeric($usage['costUsd'])) {
            $cost = (float) $usage['costUsd'];
        } elseif (isset($pricing[$model])) {
            $cost = ($p * (float) $pricing[$model]['input_per_m'] + ($c + $r) * (float) $pricing[$model]['output_per_m']) / 1_000_000;
        } else {
            $unknown[$model] = true;
        }
        $upd->execute(['id' => $row['id'], 'p' => $p, 'c' => $c, 'r' => $r, 'cost' => $cost]);
        $done++;
    }
    echo "Cost backfill: {$done} jobs updated";
    if ($unknown) {
        echo '; no price for: ' . implode(', ', array_keys($unknown));
    }
    echo PHP_EOL;
}

if ($doRescore) {
    ensureDecodeFieldQualityTable();
    $jobIds = pdo()->query(
        'SELECT DISTINCT decode_job_id FROM decode_field_quality WHERE is_benchmark = 0 ORDER BY decode_job_id'
    )->fetchAll(PDO::FETCH_COLUMN);
    $rescored = 0;
    $skipped = 0;
    foreach ($jobIds as $jobId) {
        $jobId = (int) $jobId;
        $job = getDecodeJob($jobId);
        $headerId = $job ? (int) ($job['saved_header_id'] ?? 0) : 0;
        if ($headerId <= 0) {
            $stmt = pdo()->prepare('SELECT header_id FROM decode_field_quality WHERE decode_job_id = :id LIMIT 1');
            $stmt->execute(['id' => $jobId]);
            $headerId = (int) $stmt->fetchColumn();
        }
        $decoded = $job ? json_decode((string) ($job['decoded_json'] ?? ''), true) : null;
        $final = $headerId > 0 ? loadSavedRecordAsDecoded($headerId) : null;
        if (!$job || !is_array($decoded) || $final === null) {
            $skipped++;
            continue;
        }

        // Keep the manual-correction flags: they record that the reviewer changed the
        // value after saving, which a recomputation cannot know.
        $flagStmt = pdo()->prepare(
            'SELECT section, row_no, field_name, manually_corrected FROM decode_field_quality WHERE decode_job_id = :id AND manually_corrected = 1'
        );
        $flagStmt->execute(['id' => $jobId]);
        $flags = [];
        foreach ($flagStmt->fetchAll() as $f) {
            $flags[$f['section'] . '|' . ($f['row_no'] ?? '') . '|' . $f['field_name']] = true;
        }

        $pdo = pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM decode_field_quality WHERE decode_job_id = :id')->execute(['id' => $jobId]);
            insertFieldQualityRows($headerId, $job, computeFieldQualityRows($decoded, $final), false);
            if ($flags) {
                $mark = $pdo->prepare(
                    'UPDATE decode_field_quality SET manually_corrected = 1
                     WHERE decode_job_id = :id AND section = :s AND COALESCE(row_no, 0) = :r AND field_name = :f'
                );
                foreach (array_keys($flags) as $key) {
                    [$s, $r, $f] = explode('|', $key, 3);
                    $mark->execute(['id' => $jobId, 's' => $s, 'r' => (int) $r, 'f' => $f]);
                }
            }
            $pdo->commit();
            $rescored++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, "job {$jobId}: " . $e->getMessage() . PHP_EOL);
            $skipped++;
        }
    }
    echo "Rescore: {$rescored} jobs rescored, {$skipped} skipped" . PHP_EOL;
}

if ($doBench) {
    $ids = pdo()->query("SELECT id FROM decode_jobs WHERE benchmark_header_id IS NOT NULL AND status = 'benchmarked' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0;
    $bad = 0;
    foreach ($ids as $id) {
        try {
            scoreBenchmarkJob((int) $id);
            $ok++;
        } catch (Throwable $e) {
            $bad++;
            fwrite(STDERR, "benchmark job {$id}: " . $e->getMessage() . PHP_EOL);
        }
    }
    echo "Benchmark rescore: {$ok} jobs rescored, {$bad} failed" . PHP_EOL;
}
