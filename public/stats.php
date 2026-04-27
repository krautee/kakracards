<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$rows = pdo()->query(
    "SELECT
        COALESCE(NULLIF(decoding_model, ''), NULLIF(requested_model, ''), 'unknown') AS model,
        COUNT(*) AS jobs_total,
        SUM(CASE WHEN status = 'saved' THEN 1 ELSE 0 END) AS jobs_saved,
        SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS jobs_succeeded,
        MIN(total_token_count) AS token_min,
        AVG(total_token_count) AS token_avg,
        MAX(total_token_count) AS token_max,
        MIN(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS sec_min,
        AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS sec_avg,
        MAX(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS sec_max
     FROM decode_jobs
     WHERE status IN ('succeeded', 'saved')
       AND started_at IS NOT NULL
       AND finished_at IS NOT NULL
     GROUP BY model
     ORDER BY (token_avg IS NULL) ASC, token_avg ASC, sec_avg ASC, model ASC"
)->fetchAll();

$overall = pdo()->query(
    "SELECT
        COUNT(*) AS jobs_total,
        SUM(CASE WHEN status = 'saved' THEN 1 ELSE 0 END) AS jobs_saved,
        SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS jobs_succeeded,
        MIN(total_token_count) AS token_min,
        AVG(total_token_count) AS token_avg,
        MAX(total_token_count) AS token_max,
        MIN(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS sec_min,
        AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS sec_avg,
        MAX(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS sec_max
     FROM decode_jobs
     WHERE status IN ('succeeded', 'saved')
       AND started_at IS NOT NULL
       AND finished_at IS NOT NULL"
)->fetch() ?: [];

$qualityByModel = [];
$qualityByField = [];
$qualityByHeaderField = [];
try {
  $qualityByModel = pdo()->query(
    "SELECT
      model,
      COUNT(*) AS fields_total,
      AVG(exact_match) * 100 AS exact_match_pct,
      AVG(normalized_match) * 100 AS normalized_match_pct,
      SUM(manually_corrected) AS manually_corrected_fields,
      AVG(manually_corrected) * 100 AS manual_correction_pct,
      SUM(CASE WHEN error_type = 'missing' THEN 1 ELSE 0 END) AS missing_count,
      SUM(CASE WHEN error_type = 'mismatch' THEN 1 ELSE 0 END) AS mismatch_count,
      SUM(CASE WHEN error_type = 'format_mismatch' THEN 1 ELSE 0 END) AS format_mismatch_count
     FROM decode_field_quality
     GROUP BY model
     ORDER BY normalized_match_pct DESC, exact_match_pct DESC, model ASC"
  )->fetchAll();

  $qualityByField = pdo()->query(
    "SELECT
      model,
      section,
      field_name,
      COUNT(*) AS fields_total,
      AVG(normalized_match) * 100 AS normalized_match_pct,
      AVG(exact_match) * 100 AS exact_match_pct
     FROM decode_field_quality
     GROUP BY model, section, field_name
     HAVING COUNT(*) >= 3
     ORDER BY normalized_match_pct ASC, fields_total DESC
     LIMIT 40"
  )->fetchAll();

  $qualityByHeaderField = pdo()->query(
    "SELECT
      field_name,
      COUNT(*) AS fields_total,
      AVG(normalized_match) * 100 AS normalized_match_pct,
      AVG(exact_match) * 100 AS exact_match_pct
     FROM decode_field_quality
     WHERE section = 'header'
     GROUP BY field_name
     HAVING COUNT(*) >= 3
     ORDER BY normalized_match_pct ASC, fields_total DESC"
  )->fetchAll();
} catch (Throwable) {
  $qualityByModel = [];
  $qualityByField = [];
  $qualityByHeaderField = [];
}

$maxAvgTokens = 0.0;
$maxAvgSeconds = 0.0;
foreach ($rows as $row) {
    $tokenAvg = isset($row['token_avg']) ? (float) $row['token_avg'] : 0.0;
    $secAvg = isset($row['sec_avg']) ? (float) $row['sec_avg'] : 0.0;
    $maxAvgTokens = max($maxAvgTokens, $tokenAvg);
    $maxAvgSeconds = max($maxAvgSeconds, $secAvg);
}

function fmtNumber(mixed $value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return number_format((float) $value, $decimals, '.', ' ');
}

function fmtInt(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return number_format((int) $value, 0, '.', ' ');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Model Statistics</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:1700px;margin:18px auto;padding:0 16px;background:#f6f4ef;color:#1f2933}
    h1{margin:8px 0 14px}
    .nav a{display:inline-block;padding:6px 10px;background:#1155cc;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin:10px 0 16px}
    .card{background:#fff;border:1px solid #dbe2ea;border-radius:12px;padding:12px}
    .card .label{color:#64748b;font-size:.85rem}
    .card .value{font-weight:700;font-size:1.1rem;margin-top:4px}
    .panel{background:#fff;border:1px solid #dbe2ea;border-radius:12px;padding:12px;margin-bottom:14px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #dbe2ea;padding:8px;vertical-align:middle}
    th{background:#f1f5f9;text-align:left}
    .mono{font-family:ui-monospace, Menlo, Monaco, Consolas, monospace}
    .bar-row{display:grid;grid-template-columns:minmax(180px,280px) minmax(260px,1fr) 120px;gap:10px;align-items:center;margin:8px 0}
    .track{height:16px;background:#e2e8f0;border-radius:999px;overflow:hidden}
    .fill-tokens{height:100%;background:linear-gradient(90deg,#2563eb,#3b82f6)}
    .fill-time{height:100%;background:linear-gradient(90deg,#059669,#10b981)}
    .muted{color:#6b7280}
  </style>
</head>
<body>
<p class="nav">
  <a href="index.php">Home</a>
  <a href="upload.php">Upload &amp; Decode</a>
  <a href="settings.php">Settings (Prompt)</a>
  <a href="stats.php">Statistics</a>
</p>

<h1>Model Statistics</h1>
<p class="muted">Data source: decode jobs with status succeeded or saved, where both start and finish timestamps exist.</p>

<div class="cards">
  <div class="card">
    <div class="label">Total completed jobs</div>
    <div class="value"><?= fmtInt($overall['jobs_total'] ?? null) ?></div>
  </div>
  <div class="card">
    <div class="label">Saved jobs</div>
    <div class="value"><?= fmtInt($overall['jobs_saved'] ?? null) ?></div>
  </div>
  <div class="card">
    <div class="label">Succeeded not yet saved</div>
    <div class="value"><?= fmtInt($overall['jobs_succeeded'] ?? null) ?></div>
  </div>
  <div class="card">
    <div class="label">Overall avg tokens / avg seconds</div>
    <div class="value"><?= fmtNumber($overall['token_avg'] ?? null, 1) ?> / <?= fmtNumber($overall['sec_avg'] ?? null, 1) ?>s</div>
  </div>
</div>

<?php if (empty($rows)): ?>
  <div class="panel">
    <p>No successful or saved decode jobs found yet.</p>
  </div>
<?php else: ?>
  <div class="panel">
    <h2 style="margin:4px 0 10px;">By Model</h2>
    <table>
      <thead>
        <tr>
          <th>Model</th>
          <th>Total</th>
          <th>Saved</th>
          <th>Succeeded</th>
          <th>Tokens Min</th>
          <th>Tokens Avg</th>
          <th>Tokens Max</th>
          <th>Time Min (s)</th>
          <th>Time Avg (s)</th>
          <th>Time Max (s)</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="mono"><?= h((string) ($row['model'] ?? 'unknown')) ?></td>
          <td><?= fmtInt($row['jobs_total'] ?? null) ?></td>
          <td><?= fmtInt($row['jobs_saved'] ?? null) ?></td>
          <td><?= fmtInt($row['jobs_succeeded'] ?? null) ?></td>
          <td><?= fmtInt($row['token_min'] ?? null) ?></td>
          <td><?= fmtNumber($row['token_avg'] ?? null, 1) ?></td>
          <td><?= fmtInt($row['token_max'] ?? null) ?></td>
          <td><?= fmtInt($row['sec_min'] ?? null) ?></td>
          <td><?= fmtNumber($row['sec_avg'] ?? null, 2) ?></td>
          <td><?= fmtInt($row['sec_max'] ?? null) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel">
    <h2 style="margin:4px 0 10px;">Average Token Count</h2>
    <?php foreach ($rows as $row): ?>
      <?php
        $avg = isset($row['token_avg']) ? (float) $row['token_avg'] : 0.0;
        $percent = $maxAvgTokens > 0 ? min(100.0, ($avg / $maxAvgTokens) * 100.0) : 0.0;
      ?>
      <div class="bar-row">
        <div class="mono"><?= h((string) ($row['model'] ?? 'unknown')) ?></div>
        <div class="track"><div class="fill-tokens" style="width: <?= number_format($percent, 2, '.', '') ?>%;"></div></div>
        <div><?= fmtNumber($row['token_avg'] ?? null, 1) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2 style="margin:4px 0 10px;">Average Execution Time (seconds)</h2>
    <?php foreach ($rows as $row): ?>
      <?php
        $avg = isset($row['sec_avg']) ? (float) $row['sec_avg'] : 0.0;
        $percent = $maxAvgSeconds > 0 ? min(100.0, ($avg / $maxAvgSeconds) * 100.0) : 0.0;
      ?>
      <div class="bar-row">
        <div class="mono"><?= h((string) ($row['model'] ?? 'unknown')) ?></div>
        <div class="track"><div class="fill-time" style="width: <?= number_format($percent, 2, '.', '') ?>%;"></div></div>
        <div><?= fmtNumber($row['sec_avg'] ?? null, 2) ?>s</div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2 style="margin:4px 0 10px;">Quality By Model</h2>
    <?php if (empty($qualityByModel)): ?>
      <p class="muted">No quality data yet. Save reviewed records from Upload page to start collecting per-field quality metrics.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Model</th>
            <th>Fields Compared</th>
            <th>Normalized Match %</th>
            <th>Exact Match %</th>
            <th>Manual Correction %</th>
            <th>Manual Corrected Fields</th>
            <th>Missing</th>
            <th>Mismatch</th>
            <th>Format Mismatch</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($qualityByModel as $row): ?>
          <tr>
            <td class="mono"><?= h((string) ($row['model'] ?? 'unknown')) ?></td>
            <td><?= fmtInt($row['fields_total'] ?? null) ?></td>
            <td><?= fmtNumber($row['normalized_match_pct'] ?? null, 2) ?></td>
            <td><?= fmtNumber($row['exact_match_pct'] ?? null, 2) ?></td>
            <td><?= fmtNumber($row['manual_correction_pct'] ?? null, 2) ?></td>
            <td><?= fmtInt($row['manually_corrected_fields'] ?? null) ?></td>
            <td><?= fmtInt($row['missing_count'] ?? null) ?></td>
            <td><?= fmtInt($row['mismatch_count'] ?? null) ?></td>
            <td><?= fmtInt($row['format_mismatch_count'] ?? null) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2 style="margin:4px 0 10px;">Header Field Quality (includes scull fields)</h2>
    <?php if (empty($qualityByHeaderField)): ?>
      <p class="muted">Not enough header quality samples yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Header Field</th>
            <th>Fields Compared</th>
            <th>Normalized Match %</th>
            <th>Exact Match %</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($qualityByHeaderField as $row): ?>
          <tr>
            <td class="mono"><?= h((string) ($row['field_name'] ?? '')) ?></td>
            <td><?= fmtInt($row['fields_total'] ?? null) ?></td>
            <td><?= fmtNumber($row['normalized_match_pct'] ?? null, 2) ?></td>
            <td><?= fmtNumber($row['exact_match_pct'] ?? null, 2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2 style="margin:4px 0 10px;">Weakest Model/Field Pairs (Prompt Tuning Targets)</h2>
    <?php if (empty($qualityByField)): ?>
      <p class="muted">Not enough field-level quality samples yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Model</th>
            <th>Section</th>
            <th>Field</th>
            <th>Fields Compared</th>
            <th>Normalized Match %</th>
            <th>Exact Match %</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($qualityByField as $row): ?>
          <tr>
            <td class="mono"><?= h((string) ($row['model'] ?? 'unknown')) ?></td>
            <td><?= h((string) ($row['section'] ?? '')) ?></td>
            <td class="mono"><?= h((string) ($row['field_name'] ?? '')) ?></td>
            <td><?= fmtInt($row['fields_total'] ?? null) ?></td>
            <td><?= fmtNumber($row['normalized_match_pct'] ?? null, 2) ?></td>
            <td><?= fmtNumber($row['exact_match_pct'] ?? null, 2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
</body>
</html>
