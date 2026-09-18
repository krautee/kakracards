<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

ensureDecodeFieldQualityTable();
ensureDecodeJobAnalysisColumns();

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$source = (string) ($_GET['source'] ?? 'all');            // all | benchmark | review
if (!in_array($source, ['all', 'benchmark', 'review'], true)) {
    $source = 'all';
}
$promptSha = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['prompt'] ?? '')) ?? '';
$paired = isset($_GET['paired']) ? (string) $_GET['paired'] === '1' : true;
$minCards = max(1, (int) ($_GET['min_cards'] ?? 3));
$focusModel = trim((string) ($_GET['model'] ?? ''));
$focusField = preg_replace('/[^a-z_]/', '', (string) ($_GET['field'] ?? '')) ?? '';

$textFields = ['obs_notes', 'recovery_location', 'recovery_person', 'recovery_notes'];
$textFieldList = "'" . implode("','", $textFields) . "'";

$baseWhere = ['1=1'];
$baseParams = [];
if ($source === 'benchmark') {
    $baseWhere[] = 'q.is_benchmark = 1';
} elseif ($source === 'review') {
    $baseWhere[] = 'q.is_benchmark = 0';
}
if ($promptSha !== '') {
    $baseWhere[] = 'q.prompt_sha256 = :prompt_sha';
    $baseParams['prompt_sha'] = $promptSha;
}

function statsQuery(string $sql, array $params): array
{
    $stmt = pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

// Paired mode: restrict to cards that every model (with at least min_cards cards) has seen,
// so models are compared on identical inputs instead of on whatever subset each ran on.
$pairedCardIds = [];
$pairedModelCount = 0;
if ($paired) {
    $coverage = statsQuery(
        'SELECT q.model, q.header_id FROM decode_field_quality q WHERE ' . implode(' AND ', $baseWhere) . ' GROUP BY q.model, q.header_id',
        $baseParams
    );
    $byModel = [];
    foreach ($coverage as $row) {
        $byModel[(string) $row['model']][] = (int) $row['header_id'];
    }
    $sets = array_filter($byModel, static fn(array $ids) => count($ids) >= $minCards);
    $pairedModelCount = count($sets);
    if ($sets) {
        $common = null;
        foreach ($sets as $ids) {
            $common = $common === null ? $ids : array_intersect($common, $ids);
        }
        $pairedCardIds = array_values(array_map('intval', $common ?: []));
    }
    if ($pairedCardIds) {
        $baseWhere[] = 'q.header_id IN (' . implode(',', $pairedCardIds) . ')';
    } else {
        $baseWhere[] = '1=0';
    }
}
$whereSql = implode(' AND ', $baseWhere);

// ---------------------------------------------------------------------------
// Per-model metrics
// ---------------------------------------------------------------------------
$modelRows = statsQuery(
    "SELECT q.model,
        COUNT(DISTINCT q.header_id) AS cards,
        COUNT(DISTINCT q.decode_job_id) AS jobs,
        COUNT(*) AS all_fields,
        SUM(q.normalized_match = 1) AS all_ok,
        SUM(q.error_type <> 'both_empty') AS populated,
        SUM(q.error_type <> 'both_empty' AND q.normalized_match = 1) AS populated_ok,
        SUM(q.error_type <> 'both_empty' AND q.exact_match = 1) AS exact_ok,
        SUM(q.error_type = 'missing') AS missing_cnt,
        SUM(q.error_type = 'extra') AS extra_cnt,
        SUM(q.error_type = 'mismatch') AS mismatch_cnt,
        SUM(q.error_type = 'format_mismatch') AS format_cnt,
        SUM(q.error_type = 'missing_row') AS missing_row_fields,
        SUM(q.error_type = 'extra_row') AS extra_row_fields,
        SUM(q.final_value = '' AND q.error_type <> 'extra_row') AS final_empty,
        SUM(q.final_value = '' AND q.predicted_value = '' AND q.error_type <> 'extra_row') AS null_ok,
        SUM(q.error_type <> 'both_empty' AND q.inherited_from_previous = 0) AS unique_populated,
        SUM(q.error_type <> 'both_empty' AND q.inherited_from_previous = 0 AND q.normalized_match = 1) AS unique_ok,
        SUM(CASE WHEN q.field_name IN ($textFieldList) AND q.final_value <> '' AND q.error_type NOT IN ('missing_row','extra_row') THEN COALESCE(q.char_distance, 0) ELSE 0 END) AS text_dist,
        SUM(CASE WHEN q.field_name IN ($textFieldList) AND q.final_value <> '' AND q.error_type NOT IN ('missing_row','extra_row') THEN CHAR_LENGTH(q.final_value) ELSE 0 END) AS text_len,
        SUM(q.section = 'content' AND q.predicted_decoding_status = 'OK' AND q.error_type <> 'both_empty') AS ok_fields,
        SUM(q.section = 'content' AND q.predicted_decoding_status = 'OK' AND q.error_type <> 'both_empty' AND q.normalized_match = 0) AS ok_wrong,
        SUM(q.section = 'content' AND q.predicted_decoding_status IS NOT NULL AND q.predicted_decoding_status NOT IN ('', 'OK') AND q.error_type <> 'both_empty') AS flagged_fields,
        SUM(q.section = 'content' AND q.predicted_decoding_status IS NOT NULL AND q.predicted_decoding_status NOT IN ('', 'OK') AND q.error_type <> 'both_empty' AND q.normalized_match = 0) AS flagged_wrong,
        SUM(q.manually_corrected) AS manually_corrected
     FROM decode_field_quality q
     WHERE $whereSql
     GROUP BY q.model",
    $baseParams
);

$rowLevel = statsQuery(
    "SELECT model, SUM(is_missing) AS missing_rows, SUM(is_extra) AS extra_rows, COUNT(*) AS rows_total
     FROM (
        SELECT q.model, q.decode_job_id, q.section, q.row_no,
               MAX(q.error_type = 'missing_row') AS is_missing,
               MAX(q.error_type = 'extra_row') AS is_extra
        FROM decode_field_quality q
        WHERE q.section IN ('content', 'recovery') AND $whereSql
        GROUP BY q.model, q.decode_job_id, q.section, q.row_no
     ) r
     GROUP BY model",
    $baseParams
);
$rowLevelByModel = [];
foreach ($rowLevel as $r) {
    $rowLevelByModel[(string) $r['model']] = $r;
}

$jobStats = statsQuery(
    "SELECT q.model,
        AVG(j.cost_usd) AS cost_avg,
        SUM(j.cost_usd) AS cost_sum,
        SUM(j.cost_usd IS NULL) AS cost_unknown,
        AVG(TIMESTAMPDIFF(SECOND, j.started_at, j.finished_at)) AS sec_avg,
        AVG(j.total_token_count) AS tokens_avg,
        AVG(j.reasoning_token_count) AS reasoning_avg
     FROM (SELECT DISTINCT q.model, q.decode_job_id FROM decode_field_quality q WHERE $whereSql) q
     JOIN decode_jobs j ON j.id = q.decode_job_id
     GROUP BY q.model",
    $baseParams
);
$jobStatsByModel = [];
foreach ($jobStats as $r) {
    $jobStatsByModel[(string) $r['model']] = $r;
}

$models = [];
foreach ($modelRows as $r) {
    $m = (string) $r['model'];
    $populated = (int) $r['populated'];
    $populatedOk = (int) $r['populated_ok'];
    $rl = $rowLevelByModel[$m] ?? ['missing_rows' => 0, 'extra_rows' => 0, 'rows_total' => 0];
    $finalRows = (int) $rl['rows_total'] - (int) $rl['extra_rows'];
    $predRows = (int) $rl['rows_total'] - (int) $rl['missing_rows'];
    $js = $jobStatsByModel[$m] ?? [];
    $costSum = isset($js['cost_sum']) ? (float) $js['cost_sum'] : null;
    $finalEmpty = (int) $r['final_empty'];
    $errors = $populated - $populatedOk;
    $models[$m] = [
        'model' => $m,
        'cards' => (int) $r['cards'],
        'jobs' => (int) $r['jobs'],
        'populated' => $populated,
        'acc' => $populated > 0 ? 100 * $populatedOk / $populated : null,
        'acc_all' => (int) $r['all_fields'] > 0 ? 100 * (int) $r['all_ok'] / (int) $r['all_fields'] : null,
        'exact' => $populated > 0 ? 100 * (int) $r['exact_ok'] / $populated : null,
        'unique_acc' => (int) $r['unique_populated'] > 0 ? 100 * (int) $r['unique_ok'] / (int) $r['unique_populated'] : null,
        'omission' => $populated > 0 ? 100 * ((int) $r['missing_cnt'] + (int) $r['missing_row_fields']) / $populated : null,
        'hallucination' => $populated > 0 ? 100 * ((int) $r['extra_cnt'] + (int) $r['mismatch_cnt'] + (int) $r['extra_row_fields']) / $populated : null,
        'format' => $populated > 0 ? 100 * (int) $r['format_cnt'] / $populated : null,
        'null_acc' => $finalEmpty > 0 ? 100 * (int) $r['null_ok'] / $finalEmpty : null,
        'row_recall' => $finalRows > 0 ? 100 * ($finalRows - (int) $rl['missing_rows']) / $finalRows : null,
        'row_precision' => $predRows > 0 ? 100 * ($predRows - (int) $rl['extra_rows']) / $predRows : null,
        'cer' => (int) $r['text_len'] > 0 ? 100 * (int) $r['text_dist'] / (int) $r['text_len'] : null,
        'ok_wrong' => (int) $r['ok_fields'] > 0 ? 100 * (int) $r['ok_wrong'] / (int) $r['ok_fields'] : null,
        'flag_rate' => ((int) $r['ok_fields'] + (int) $r['flagged_fields']) > 0 ? 100 * (int) $r['flagged_fields'] / ((int) $r['ok_fields'] + (int) $r['flagged_fields']) : null,
        'flagged_wrong' => (int) $r['flagged_fields'] > 0 ? 100 * (int) $r['flagged_wrong'] / (int) $r['flagged_fields'] : null,
        'cost_avg' => isset($js['cost_avg']) && $js['cost_avg'] !== null ? (float) $js['cost_avg'] : null,
        'cost_sum' => $costSum,
        'cost_unknown' => (int) ($js['cost_unknown'] ?? 0),
        'cost_per_error' => ($costSum !== null && (int) $r['cards'] > 0) ? $costSum / max(1, $errors) : null,
        'errors_per_card' => (int) $r['cards'] > 0 ? $errors / (int) $r['cards'] : null,
        'sec_avg' => isset($js['sec_avg']) && $js['sec_avg'] !== null ? (float) $js['sec_avg'] : null,
        'tokens_avg' => isset($js['tokens_avg']) && $js['tokens_avg'] !== null ? (float) $js['tokens_avg'] : null,
        'reasoning_avg' => isset($js['reasoning_avg']) && $js['reasoning_avg'] !== null ? (float) $js['reasoning_avg'] : null,
        'manually_corrected' => (int) $r['manually_corrected'],
    ];
}
uasort($models, static fn(array $a, array $b) => ($b['acc'] ?? -1) <=> ($a['acc'] ?? -1));

// ---------------------------------------------------------------------------
// Per-field heatmap (populated-field accuracy)
// ---------------------------------------------------------------------------
$fieldOrder = [
    'header' => QUALITY_HEADER_KEYS,
    'content' => QUALITY_CONTENT_KEYS,
    'recovery' => QUALITY_RECOVERY_KEYS,
];
$heat = statsQuery(
    "SELECT q.model, q.section, q.field_name,
        SUM(q.error_type <> 'both_empty') AS populated,
        SUM(q.error_type <> 'both_empty' AND q.normalized_match = 1) AS ok
     FROM decode_field_quality q
     WHERE $whereSql
     GROUP BY q.model, q.section, q.field_name",
    $baseParams
);
$heatMap = [];
foreach ($heat as $r) {
    $heatMap[(string) $r['model']][(string) $r['section'] . '.' . (string) $r['field_name']] = [
        'populated' => (int) $r['populated'],
        'ok' => (int) $r['ok'],
    ];
}

// ---------------------------------------------------------------------------
// Confusion report: most frequent predicted -> final pairs (prompt tuning input)
// ---------------------------------------------------------------------------
$confusionWhere = $baseWhere;
$confusionParams = $baseParams;
$confusionWhere[] = "q.normalized_match = 0 AND q.error_type <> 'both_empty'";
if ($focusModel !== '') {
    $confusionWhere[] = 'q.model = :focus_model';
    $confusionParams['focus_model'] = $focusModel;
}
if ($focusField !== '') {
    $confusionWhere[] = 'q.field_name = :focus_field';
    $confusionParams['focus_field'] = $focusField;
}
$confusion = statsQuery(
    'SELECT q.section, q.field_name, q.predicted_value, q.final_value, q.error_type,
            COUNT(*) AS n, COUNT(DISTINCT q.header_id) AS cards, COUNT(DISTINCT q.model) AS models_n,
            SUM(q.inherited_from_previous) AS inherited
     FROM decode_field_quality q
     WHERE ' . implode(' AND ', $confusionWhere) . '
     GROUP BY q.section, q.field_name, q.predicted_value, q.final_value, q.error_type
     ORDER BY n DESC, cards DESC
     LIMIT 60',
    $confusionParams
);
$fieldErrorTotals = statsQuery(
    'SELECT q.section, q.field_name, q.error_type, COUNT(*) AS n
     FROM decode_field_quality q
     WHERE ' . implode(' AND ', $confusionWhere) . '
     GROUP BY q.section, q.field_name, q.error_type
     ORDER BY n DESC',
    $confusionParams
);

// ---------------------------------------------------------------------------
// Prompt versions
// ---------------------------------------------------------------------------
$promptVersions = statsQuery(
    'SELECT p.sha256, p.prompt_name, p.first_seen_at,
            (SELECT COUNT(*) FROM decode_jobs j WHERE j.prompt_sha256 = p.sha256) AS jobs,
            (SELECT COUNT(DISTINCT q.header_id) FROM decode_field_quality q WHERE q.prompt_sha256 = p.sha256) AS cards
     FROM prompt_versions p ORDER BY p.first_seen_at DESC',
    []
);
$promptCompare = statsQuery(
    "SELECT q.prompt_sha256, q.model,
        COUNT(DISTINCT q.header_id) AS cards,
        SUM(q.error_type <> 'both_empty') AS populated,
        SUM(q.error_type <> 'both_empty' AND q.normalized_match = 1) AS ok
     FROM decode_field_quality q
     WHERE q.prompt_sha256 IS NOT NULL" . ($source === 'benchmark' ? ' AND q.is_benchmark = 1' : ($source === 'review' ? ' AND q.is_benchmark = 0' : '')) . "
     GROUP BY q.prompt_sha256, q.model",
    []
);
$promptCompareMap = [];
foreach ($promptCompare as $r) {
    $promptCompareMap[(string) $r['model']][(string) $r['prompt_sha256']] = $r;
}
$promptNames = [];
foreach ($promptVersions as $p) {
    $promptNames[(string) $p['sha256']] = (string) $p['prompt_name'];
}

$totals = statsQuery(
    'SELECT COUNT(*) AS n, SUM(cost_usd) AS cost, SUM(benchmark_header_id IS NOT NULL) AS bench
     FROM decode_jobs WHERE status IN (\'saved\', \'succeeded\', \'benchmarked\')',
    []
)[0] ?? [];
$groundTruthCards = (int) (statsQuery('SELECT COUNT(*) AS n FROM cards_header', [])[0]['n'] ?? 0);
$pendingBench = (int) (statsQuery("SELECT COUNT(*) AS n FROM decode_jobs WHERE benchmark_header_id IS NOT NULL AND status IN ('queued','running')", [])[0]['n'] ?? 0);

function pct(?float $v, int $d = 1): string
{
    return $v === null ? '-' : number_format($v, $d, '.', '') . '%';
}

function money(?float $v): string
{
    return $v === null ? '-' : '$' . number_format($v, 4, '.', '');
}

function num(?float $v, int $d = 1): string
{
    return $v === null ? '-' : number_format($v, $d, '.', ' ');
}

function heatColor(?float $pct): string
{
    if ($pct === null) {
        return '#f1f5f9';
    }
    // 50% -> red, 75% -> amber, 100% -> green
    $t = max(0.0, min(1.0, ($pct - 50) / 50));
    $hue = (int) round(120 * $t);
    return "hsl({$hue}, 70%, 82%)";
}

function shortModel(string $m): string
{
    return strlen($m) > 34 ? substr($m, 0, 32) . '…' : $m;
}

function qs(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    return 'stats.php?' . http_build_query($params);
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
    h2{margin:4px 0 10px;font-size:1.15rem}
    .nav a{display:inline-block;padding:6px 10px;background:#1155cc;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:10px 0 16px}
    .card{background:#fff;border:1px solid #dbe2ea;border-radius:12px;padding:12px}
    .card .label{color:#64748b;font-size:.85rem}
    .card .value{font-weight:700;font-size:1.1rem;margin-top:4px}
    .panel{background:#fff;border:1px solid #dbe2ea;border-radius:12px;padding:12px;margin-bottom:14px}
    .scroll{overflow-x:auto}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #dbe2ea;padding:6px 8px;vertical-align:middle;font-size:.9rem;white-space:nowrap}
    th{background:#f1f5f9;text-align:left;position:sticky;top:0}
    td.num{text-align:right;font-variant-numeric:tabular-nums}
    .mono{font-family:ui-monospace,Menlo,Monaco,Consolas,monospace;font-size:.85rem}
    .muted{color:#6b7280}
    .filters{display:flex;flex-wrap:wrap;gap:14px;align-items:end;background:#fff;border:1px solid #dbe2ea;border-radius:12px;padding:12px;margin-bottom:14px}
    .filters label{display:flex;flex-direction:column;gap:4px;font-size:.85rem;color:#374151}
    .filters select,.filters input{padding:6px 8px;border:1px solid #c7ced6;border-radius:8px;font:inherit}
    .btn{padding:8px 14px;border:0;border-radius:999px;background:#184d8d;color:#fff;font-weight:700;cursor:pointer}
    .best{font-weight:700;color:#166534}
    .heat td{text-align:center;min-width:52px}
    .heat th.rot{height:120px;white-space:nowrap;vertical-align:bottom;padding:0}
    .heat th.rot > div{transform:rotate(-60deg);transform-origin:left bottom;width:24px;margin-left:14px;font-weight:600;font-size:.78rem}
    .pill{display:inline-block;padding:1px 7px;border-radius:999px;font-size:.75rem;font-weight:700;background:#eef2ff;color:#3730a3}
    .legend{font-size:.8rem;color:#6b7280;margin-top:6px}
    details summary{cursor:pointer;font-weight:700}
    .defs dt{font-weight:700;margin-top:6px}
    .defs dd{margin:0 0 4px 0;color:#374151}
    th.sortable{cursor:pointer;user-select:none}
    th.sortable:hover{background:#e2e8f0}
    th.sortable::after{content:' \2195';color:#94a3b8;font-size:.75rem}
    th.sorted-asc::after{content:' \2191';color:#1d4ed8}
    th.sorted-desc::after{content:' \2193';color:#1d4ed8}
  </style>
</head>
<body>
<p class="nav">
  <a href="index.php">Home</a>
  <a href="upload.php">Upload &amp; Decode</a>
  <a href="settings.php">Settings (Prompt)</a>
  <a href="prompts.php">Prompts</a>
  <a href="stats.php">Statistics</a>
</p>

<h1>Model Statistics</h1>

<div class="cards">
  <div class="card"><div class="label">Ground-truth cards (reviewed &amp; saved)</div><div class="value"><?= $groundTruthCards ?></div></div>
  <div class="card"><div class="label">Models in this view</div><div class="value"><?= count($models) ?></div></div>
  <div class="card"><div class="label">Cards in this view<?= $paired ? ' (paired)' : '' ?></div><div class="value"><?= $paired ? count($pairedCardIds) : (int) max(array_map(static fn($m) => $m['cards'], $models) ?: [0]) ?></div></div>
  <div class="card"><div class="label">Completed decode jobs / benchmark jobs</div><div class="value"><?= (int) ($totals['n'] ?? 0) ?> / <?= (int) ($totals['bench'] ?? 0) ?><?= $pendingBench > 0 ? ' <span class="pill">' . $pendingBench . ' running</span>' : '' ?></div></div>
  <div class="card"><div class="label">Total spend recorded (all jobs)</div><div class="value"><?= money(isset($totals['cost']) ? (float) $totals['cost'] : null) ?></div></div>
</div>

<form class="filters" method="get">
  <label>Data source
    <select name="source">
      <option value="all" <?= $source === 'all' ? 'selected' : '' ?>>All (manual review + benchmark)</option>
      <option value="benchmark" <?= $source === 'benchmark' ? 'selected' : '' ?>>Benchmark re-runs only</option>
      <option value="review" <?= $source === 'review' ? 'selected' : '' ?>>Manual review sessions only</option>
    </select>
  </label>
  <label>Prompt version
    <select name="prompt">
      <option value="">Any</option>
      <?php foreach ($promptVersions as $p): ?>
        <option value="<?= h((string) $p['sha256']) ?>" <?= $promptSha === (string) $p['sha256'] ? 'selected' : '' ?>><?= h(basename((string) $p['prompt_name'])) ?> · <?= h(substr((string) $p['sha256'], 0, 8)) ?> · <?= (int) $p['cards'] ?> cards</option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Paired comparison
    <select name="paired">
      <option value="1" <?= $paired ? 'selected' : '' ?>>Only cards every model has seen</option>
      <option value="0" <?= !$paired ? 'selected' : '' ?>>Each model on all its cards</option>
    </select>
  </label>
  <label>Min cards for a model to count
    <input type="number" name="min_cards" min="1" value="<?= $minCards ?>" style="width:80px">
  </label>
  <button class="btn" type="submit">Apply</button>
</form>

<?php if ($paired): ?>
  <p class="muted">Paired mode: <?= count($pairedCardIds) ?> cards are shared by all <?= $pairedModelCount ?> models that have at least <?= $minCards ?> cards. Models with fewer cards are excluded from the intersection.</p>
<?php endif; ?>

<?php if (empty($models)): ?>
  <div class="panel"><p>No quality data for this filter. Save reviewed records, or run <span class="mono">python3 python/benchmark.py</span>.</p></div>
<?php else: ?>

<div class="panel">
  <h2>Accuracy, errors and cost by model</h2>
  <div class="scroll">
  <table>
    <thead>
      <tr>
        <th>Model</th>
        <th>Cards</th>
        <th title="Normalized match on fields that are non-empty on at least one side (the paper's accuracy w/o nulls)">Accuracy</th>
        <th title="Same, but each ring number / position a model carried over from the previous row is counted once">Unique-error acc.</th>
        <th title="Byte-exact match">Exact</th>
        <th title="Share of final rows the model produced at all">Row recall</th>
        <th title="Share of predicted rows that exist in the final record">Row precision</th>
        <th title="Field empty or row absent where the truth has a value">Omission</th>
        <th title="Value present but wrong, or a row that does not exist">Hallucination</th>
        <th title="Same content, different formatting (e.g. date format)">Format only</th>
        <th title="Accuracy on fields that should be empty">Null-field acc.</th>
        <th title="Character error rate over notes/location/person fields">CER (text)</th>
        <th title="Of the content-row fields the model marked OK, how many were wrong">Wrong when 'OK'</th>
        <th title="Share of content-row fields the model marked CHECK/MESS">Flag rate</th>
        <th>Cost / card</th>
        <th>Errors / card</th>
        <th>Avg s</th>
        <th>Avg tokens</th>
        <th>Reasoning tok.</th>
      </tr>
    </thead>
    <tbody>
    <?php $best = reset($models); foreach ($models as $m): ?>
      <tr>
        <td class="mono" title="<?= h($m['model']) ?>"><a href="<?= h(qs(['model' => $m['model']])) ?>#confusion" style="color:inherit"><?= h(shortModel($m['model'])) ?></a></td>
        <td class="num"><?= $m['cards'] ?></td>
        <td class="num <?= $m === $best ? 'best' : '' ?>"><?= pct($m['acc']) ?></td>
        <td class="num"><?= pct($m['unique_acc']) ?></td>
        <td class="num"><?= pct($m['exact']) ?></td>
        <td class="num"><?= pct($m['row_recall']) ?></td>
        <td class="num"><?= pct($m['row_precision']) ?></td>
        <td class="num"><?= pct($m['omission']) ?></td>
        <td class="num"><?= pct($m['hallucination']) ?></td>
        <td class="num"><?= pct($m['format']) ?></td>
        <td class="num"><?= pct($m['null_acc']) ?></td>
        <td class="num"><?= pct($m['cer']) ?></td>
        <td class="num"><?= pct($m['ok_wrong']) ?></td>
        <td class="num"><?= pct($m['flag_rate']) ?></td>
        <td class="num"><?= money($m['cost_avg']) ?><?= $m['cost_unknown'] > 0 ? '<span class="muted">*</span>' : '' ?></td>
        <td class="num"><?= num($m['errors_per_card'], 1) ?></td>
        <td class="num"><?= num($m['sec_avg'], 1) ?></td>
        <td class="num"><?= num($m['tokens_avg'], 0) ?></td>
        <td class="num"><?= num($m['reasoning_avg'], 0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="legend">Accuracy excludes fields that are empty in both prediction and truth. Row-level structure errors (missing / extra rows) are included in omission and hallucination, so a model that skips a row is penalised once per field of that row. * = some jobs have no recorded cost (older runs without a price entry).</p>
  <details style="margin-top:8px"><summary>Metric definitions</summary>
    <dl class="defs">
      <dt>Accuracy</dt><dd>Normalized value match (case, whitespace, date format ignored) on fields non-empty on at least one side.</dd>
      <dt>Unique-error accuracy</dt><dd>As above, but content-row ring numbers / positions the model inherited from the previous row are not counted. One misread header ring number otherwise counts once per row.</dd>
      <dt>Row recall / precision</dt><dd>Predicted rows are aligned to final rows by content, not by position. Recall: final rows the model produced; precision: predicted rows that really exist.</dd>
      <dt>Omission / hallucination</dt><dd>Omission: empty where truth has a value, or a whole row missing. Hallucination: a value present but wrong, or a row that does not exist. Together with format-only differences they sum to 100% minus accuracy.</dd>
      <dt>Null-field accuracy</dt><dd>On fields whose true value is empty, how often the model also left them empty (the opposite failure is inventing values).</dd>
      <dt>CER</dt><dd>Character error rate (edit distance / true length) on free-text fields (notes, recovery location, person) where the truth is non-empty; can exceed 100% when the model writes much more than the truth.</dd>
      <dt>Wrong when 'OK' / flag rate</dt><dd>Calibration of the model's own per-row decoding_status. A model whose OK rows are still often wrong cannot be trusted to route only flagged rows to a human.</dd>
      <dt>Cost / card</dt><dd>Measured by OpenRouter per request; for Gemini estimated from list prices (python/model_pricing.json). Reasoning tokens are billed as output.</dd>
    </dl>
  </details>
</div>

<div class="panel">
  <h2>Per-field accuracy heatmap</h2>
  <div class="scroll">
  <table class="heat">
    <thead>
      <tr>
        <th>Model</th>
        <?php foreach ($fieldOrder as $section => $fields): foreach ($fields as $f): ?>
          <th class="rot"><div><?= h(($section === 'header' ? 'H·' : ($section === 'content' ? 'C·' : 'R·')) . $f) ?></div></th>
        <?php endforeach; endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($models as $m): ?>
      <tr>
        <td class="mono" title="<?= h($m['model']) ?>"><?= h(shortModel($m['model'])) ?></td>
        <?php foreach ($fieldOrder as $section => $fields): foreach ($fields as $f):
          $cell = $heatMap[$m['model']][$section . '.' . $f] ?? null;
          $p = ($cell && $cell['populated'] > 0) ? 100 * $cell['ok'] / $cell['populated'] : null;
        ?>
          <td style="background:<?= heatColor($p) ?>" title="<?= h($section . '.' . $f) ?>: <?= $cell ? $cell['ok'] . '/' . $cell['populated'] : 'n/a' ?>"><?= $p === null ? '·' : (int) round($p) ?></td>
        <?php endforeach; endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="legend">H = header, C = content rows, R = recovery rows. Cell = normalized accuracy % on populated fields. Hover for counts.</p>
</div>

<div class="panel" id="confusion">
  <h2>Confusion report: what gets misread<?= $focusModel !== '' ? ' — ' . h($focusModel) : ' — all models' ?></h2>
  <form method="get" action="stats.php#confusion" class="filters" style="margin-bottom:10px">
    <?php foreach (['source', 'prompt', 'paired', 'min_cards'] as $keep): ?>
      <input type="hidden" name="<?= $keep ?>" value="<?= h((string) ($_GET[$keep] ?? ($keep === 'paired' ? '1' : ($keep === 'min_cards' ? '3' : '')))) ?>">
    <?php endforeach; ?>
    <label>Model
      <select name="model">
        <option value="">All models</option>
        <?php foreach ($models as $m): ?>
          <option value="<?= h($m['model']) ?>" <?= $focusModel === $m['model'] ? 'selected' : '' ?>><?= h($m['model']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Field
      <select name="field">
        <option value="">All fields</option>
        <?php foreach ($fieldOrder as $section => $fields): foreach ($fields as $f): ?>
          <option value="<?= h($f) ?>" <?= $focusField === $f ? 'selected' : '' ?>><?= h($section . '.' . $f) ?></option>
        <?php endforeach; endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Show</button>
  </form>
  <?php if (empty($confusion)): ?>
    <p class="muted">No errors for this selection.</p>
  <?php else: ?>
    <div class="scroll" style="max-height:520px;overflow-y:auto">
    <table>
      <thead><tr><th>Section</th><th>Field</th><th>Model wrote</th><th>Truth</th><th>Type</th><th>Times</th><th>Cards</th><th>Models</th><th>Inherited</th></tr></thead>
      <tbody>
      <?php foreach ($confusion as $c): ?>
        <tr>
          <td><?= h((string) $c['section']) ?></td>
          <td class="mono"><?= h((string) $c['field_name']) ?></td>
          <td class="mono" style="white-space:normal;max-width:320px"><?= $c['predicted_value'] === '' ? '<span class="muted">(empty)</span>' : h((string) $c['predicted_value']) ?></td>
          <td class="mono" style="white-space:normal;max-width:320px"><?= $c['final_value'] === '' ? '<span class="muted">(empty)</span>' : h((string) $c['final_value']) ?></td>
          <td><?= h((string) $c['error_type']) ?></td>
          <td class="num"><?= (int) $c['n'] ?></td>
          <td class="num"><?= (int) $c['cards'] ?></td>
          <td class="num"><?= (int) $c['models_n'] ?></td>
          <td class="num"><?= (int) $c['inherited'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="legend">Read this as a list of candidate prompt rules: a pair that repeats across cards (and models) is a convention the prompt does not state, not a handwriting problem. "Inherited" counts repetitions caused by the carry-over rule.</p>
  <?php endif; ?>
  <?php if (!empty($fieldErrorTotals)): ?>
    <details style="margin-top:8px"><summary>Error counts by field and type for this selection</summary>
      <div class="scroll"><table style="width:auto;margin-top:8px">
        <thead><tr><th>Section</th><th>Field</th><th>Type</th><th>Count</th></tr></thead>
        <tbody>
        <?php foreach ($fieldErrorTotals as $t): ?>
          <tr><td><?= h((string) $t['section']) ?></td><td class="mono"><?= h((string) $t['field_name']) ?></td><td><?= h((string) $t['error_type']) ?></td><td class="num"><?= (int) $t['n'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </details>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Prompt versions</h2>
  <?php if (empty($promptVersions)): ?>
    <p class="muted">No prompt versions recorded yet (jobs created before provenance tracking have none).</p>
  <?php else: ?>
    <div class="scroll">
    <table>
      <thead>
        <tr><th>Model</th>
        <?php foreach ($promptVersions as $p): ?>
          <th title="<?= h((string) $p['prompt_name']) ?>"><?= h(basename((string) $p['prompt_name'])) ?><br><span class="mono muted"><?= h(substr((string) $p['sha256'], 0, 8)) ?></span><br><span class="muted"><?= h(substr((string) $p['first_seen_at'], 0, 10)) ?> · <?= (int) $p['cards'] ?> cards</span></th>
        <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach (array_keys($promptCompareMap) as $modelName): ?>
        <tr>
          <td class="mono" title="<?= h((string) $modelName) ?>"><?= h(shortModel((string) $modelName)) ?></td>
          <?php foreach ($promptVersions as $p):
            $cell = $promptCompareMap[$modelName][(string) $p['sha256']] ?? null;
            $acc = ($cell && (int) $cell['populated'] > 0) ? 100 * (int) $cell['ok'] / (int) $cell['populated'] : null;
          ?>
            <td class="num" style="background:<?= heatColor($acc) ?>"><?= $acc === null ? '·' : pct($acc) . ' <span class="muted">(' . (int) $cell['cards'] . ')</span>' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="legend">Accuracy per (model, prompt version), with the number of cards in parentheses. Not restricted by the paired filter above. Re-run a prompt with <span class="mono">python3 python/benchmark.py --prompt prompts/&lt;file&gt;.md --model ...</span>.</p>
  <?php endif; ?>
</div>

<?php endif; ?>
<script src="sortable.js"></script>
</body>
</html>
