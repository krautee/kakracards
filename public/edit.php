<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Missing id';
    exit;
}

$message = '';
$error = '';

if ((string) ($_GET['action'] ?? '') === 'benchmark_status') {
    $pending = 0;
    foreach (listBenchmarkJobsForHeader($id) as $job) {
        if (in_array((string) $job['status'], ['queued', 'running', 'succeeded'], true)) {
            $pending++;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['pending' => $pending]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['benchmark_run'])) {
    try {
        $promptChoice = trim((string) ($_POST['benchmark_prompt'] ?? ''));
        $jobIds = startBenchmarkForHeader($id, (array) ($_POST['benchmark_models'] ?? []), $promptChoice !== '' ? $promptChoice : null);
        header('Location: edit.php?id=' . $id . '&bench=' . count($jobIds) . '#benchmark');
        exit;
    } catch (Throwable $e) {
        $error = 'Benchmark not started: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['benchmark_retry'])) {
    $retryId = (int) ($_POST['benchmark_retry'] ?? 0);
    try {
        $job = getDecodeJob($retryId);
        if ($job === null || (int) ($job['benchmark_header_id'] ?? 0) !== $id) {
            throw new RuntimeException('Unknown benchmark job');
        }
        retryDecodeJob($retryId);
        startDecodeJobWorker($retryId);
        header('Location: edit.php?id=' . $id . '#benchmark');
        exit;
    } catch (Throwable $e) {
        $error = 'Retry failed: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['benchmark_run']) && !isset($_POST['benchmark_retry'])) {
    $decoded = [
        'header' => [
            'bird_id' => trim((string) ($_POST['bird_id'] ?? '')),
            'card_code' => trim((string) ($_POST['card_code'] ?? '')),
            'sex' => trim((string) ($_POST['sex'] ?? '')),
            'ring_position' => trim((string) ($_POST['ring_position'] ?? '')),
            'ring_number' => trim((string) ($_POST['ring_number'] ?? '')),
            'ringing_age' => trim((string) ($_POST['ringing_age'] ?? '')),
            'ringing_date' => trim((string) ($_POST['ringing_date'] ?? '')),
            'ringing_nest' => trim((string) ($_POST['ringing_nest'] ?? '')),
            'scull_length' => trim((string) ($_POST['scull_length'] ?? '')),
            'scull_repeat' => trim((string) ($_POST['scull_repeat'] ?? '')),
        ],
        'content' => normalizeRows((string) ($_POST['content_json'] ?? '[]')),
        'recovery' => normalizeRows((string) ($_POST['recovery_json'] ?? '[]')),
        'uncertainties' => array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['uncertainties'] ?? ''))))),
    ];

    try {
        updateDecodedRecord($id, $decoded);
      refreshDecodeFieldQualityForHeader($id, $decoded);
        $message = 'Saved.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = pdo()->prepare('SELECT * FROM cards_header WHERE id=:id');
$stmt->execute(['id' => $id]);
$header = $stmt->fetch();
if (!$header) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$contentStmt = pdo()->prepare('SELECT ring_position, ring_number, obs_status, obs_year, obs_nest, obs_notes FROM cards_content WHERE header_id=:id ORDER BY row_no');
$contentStmt->execute(['id' => $id]);
$contentRows = $contentStmt->fetchAll();

$recoveryStmt = pdo()->prepare('SELECT ring_number, recovery_status, recovery_date, recovery_location, recovery_person, recovery_notes FROM cards_recovery WHERE header_id=:id ORDER BY row_no');
$recoveryStmt->execute(['id' => $id]);
$recoveryRows = $recoveryStmt->fetchAll();
$uncertaintiesText = (string) ($header['uncertainties'] ?? '');
$ringingDateValue = formatDateForEditor((string) ($header['ringing_date'] ?? ''));
$ringingDateHasFullDate = preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $ringingDateValue) === 1;

if ((int) ($_GET['bench'] ?? 0) > 0) {
    $message = 'Benchmark started for ' . (int) $_GET['bench'] . ' model(s). Results appear below when the workers finish; the saved values are not changed.';
}

$benchmarkJobs = listBenchmarkJobsForHeader($id);
$benchmarkPending = count(array_filter($benchmarkJobs, static fn($j) => in_array((string) $j['status'], ['queued', 'running', 'succeeded'], true)));
$benchmarkScoredIds = array_map(static fn($j) => (int) $j['id'], array_filter($benchmarkJobs, static fn($j) => (string) $j['status'] === 'benchmarked'));
usort($benchmarkJobs, static fn($a, $b) => (($b['accuracy'] ?? -1) <=> ($a['accuracy'] ?? -1)) ?: ((int) $b['id'] <=> (int) $a['id']));
$benchmarkMatrix = benchmarkMatrixForHeader($id, $benchmarkScoredIds);
$benchmarkModels = preferredGeminiModels();
$promptFiles = listPromptFiles();
$activePromptFile = getCurrentPromptFileRelativePath();
$imageOnDisk = is_file((string) ($header['source_image_path'] ?? ''));

function benchCellStyle(?array $cell): string
{
    if ($cell === null) {
        return 'background:#f8fafc;color:#94a3b8';
    }
    if ($cell['match']) {
        return $cell['error_type'] === 'both_empty' ? 'background:#f8fafc;color:#94a3b8' : 'background:#dcfce7';
    }
    return match ($cell['error_type']) {
        'format_mismatch' => 'background:#fef9c3',
        'missing', 'missing_row' => 'background:#fee2e2;color:#991b1b',
        default => 'background:#fecaca',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Record #<?= $id ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>
    :root{color-scheme:light}
    body{font-family:Arial,sans-serif;max-width:1700px;margin:18px auto;padding:0 16px;background:#f6f4ef;color:#1f2933}
    a{color:#184d8d}
    .nav a{display:inline-block;padding:6px 10px;background:#1155cc;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}
    h1{margin:8px 0 16px}
    input[type=text],input[type=date],textarea,select{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #c7ced6;border-radius:10px;background:#fff;font:inherit}
    textarea{min-height:120px;resize:vertical}
    fieldset{margin:0;border:1px solid #d7dce2;border-radius:16px;background:#fff;padding:16px}
    legend{padding:0 8px;font-weight:700}
    .panel-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,0.8fr);gap:20px;align-items:start}
    .header-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}
    .header-field{display:flex;flex-direction:column;gap:4px}
    .header-field label{font-weight:700;font-size:0.9rem;color:#1f2933}
    .header-field input{padding:6px 8px;border:1px solid #c7ced6;border-radius:8px;font-size:0.88rem}
    .section{margin-bottom:16px}
    .preview{position:sticky;top:16px;display:flex;flex-direction:column;gap:12px}
    .preview-frame{padding:14px;border:1px solid #d7dce2;border-radius:20px;background:linear-gradient(180deg,#ffffff,#f3f0e8);box-shadow:0 12px 30px rgba(15,23,42,.08)}
    .preview-frame img{display:block;width:100%;height:auto;border-radius:14px;background:#fff}
    .preview-viewport{max-height:calc(100vh - 72px);min-height:540px;overflow:auto;border-radius:14px;background:#fff;cursor:zoom-in;overscroll-behavior:contain}
    .preview-viewport img{display:block;width:100%;height:auto;max-width:none;transform-origin:top left;user-select:none;-webkit-user-drag:none}
    .preview-help{margin:8px 2px 0;color:#6b7280;font-size:.85rem}
    .empty-preview{display:grid;place-items:center;min-height:300px;border:1px dashed #c7ced6;border-radius:14px;color:#6b7280;background:#f9fafb;text-align:center;padding:20px}
    .actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
    .actions button{padding:10px 16px;border:0;border-radius:999px;background:#184d8d;color:#fff;font-weight:700;cursor:pointer}
    .actions button:hover{background:#123e72}
    .editable-table input{margin:0}
    .btn-del-row:hover{background:#ffe0e0 !important}
    .bench-form{display:flex;flex-wrap:wrap;gap:14px;align-items:end}
    .bench-models{display:flex;flex-wrap:wrap;gap:6px 14px}
    .bench-models label{font-size:.9rem;display:flex;align-items:center;gap:6px}
    .bench-form select{width:auto;min-width:260px}
    .bench-table{border-collapse:collapse;width:100%;font-size:.85rem}
    .bench-table th,.bench-table td{border:1px solid #dbe2ea;padding:5px 7px;vertical-align:top;white-space:nowrap}
    .bench-table th{background:#f1f5f9;text-align:left;position:sticky;top:0}
    .bench-table td.val{white-space:normal;min-width:110px;max-width:260px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.8rem}
    .bench-table tr.section-head td{background:#eef2f7;font-weight:700}
    .scroll{overflow-x:auto;max-height:70vh;overflow-y:auto}
    .mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.82rem}
    .muted{color:#6b7280}
    .tiny-btn{padding:4px 9px;border:1px solid #c6d1df;border-radius:8px;background:#fff;cursor:pointer;font-size:.8rem}
    .pill{display:inline-block;padding:1px 7px;border-radius:999px;font-size:.75rem;font-weight:700}
    @media (max-width: 980px){.panel-grid{grid-template-columns:1fr}.preview{position:static}.header-grid{grid-template-columns:repeat(2,1fr)}}
  </style>
</head>
<body>
<p class="nav">
  <a href="index.php">Home</a>
  <a href="upload.php">Upload &amp; Decode</a>
  <a href="settings.php">Settings (Prompt)</a>
  <a href="stats.php">Statistics</a>
</p>
<h1>Edit Record #<?= $id ?></h1>
<?php if ($message !== ''): ?><p style="color:#060;"><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($error !== ''): ?><p style="color:#a00;"><strong><?= h($error) ?></strong></p><?php endif; ?>

<?php $imagePath = (string) $header['source_image_path']; ?>
<?php $previewDataUri = ''; ?>
<?php if ($imagePath !== '' && is_file($imagePath)): ?>
  <?php $mimeType = mime_content_type($imagePath) ?: 'image/jpeg'; ?>
  <?php $previewDataUri = 'data:' . $mimeType . ';base64,' . base64_encode((string) file_get_contents($imagePath)); ?>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="id" value="<?= $id ?>">
    <div class="panel-grid">
    <div class="editor">
      <?php if (trim($uncertaintiesText) !== ''): ?>
        <div class="section">
          <fieldset>
            <legend>Decoding uncertainties</legend>
            <div style="white-space:pre-wrap;color:#374151;"><?= h($uncertaintiesText) ?></div>
            <input type="hidden" name="uncertainties" value="<?= h($uncertaintiesText) ?>">
          </fieldset>
        </div>
      <?php else: ?>
        <input type="hidden" name="uncertainties" value="">
      <?php endif; ?>
      <div class="section">
        <fieldset>
          <legend>Header</legend>
          <div class="header-grid">
            <div class="header-field">
              <label for="bird_id">BirdID</label>
              <input id="bird_id" type="text" name="bird_id" value="<?= h($header['bird_id']) ?>">
            </div>
            <div class="header-field">
              <label for="card_code">cardCode</label>
              <input id="card_code" type="text" name="card_code" value="<?= h($header['card_code']) ?>">
            </div>
            <div class="header-field">
              <label for="sex">Sex</label>
              <input id="sex" type="text" name="sex" value="<?= h($header['sex']) ?>">
            </div>
            <div class="header-field">
              <label for="ring_position">ringPosition</label>
              <input id="ring_position" type="text" name="ring_position" value="<?= h($header['ring_position']) ?>">
            </div>
            <div class="header-field">
              <label for="ring_number">ringNumber</label>
              <input id="ring_number" type="text" name="ring_number" value="<?= h($header['ring_number']) ?>">
            </div>
            <div class="header-field">
              <label for="ringing_age">ringingAge</label>
              <input id="ringing_age" type="text" name="ringing_age" value="<?= h($header['ringing_age']) ?>">
            </div>
            <div class="header-field">
              <label for="ringing_date">ringingDate</label>
              <input id="ringing_date" type="text" class="<?= $ringingDateHasFullDate ? 'datepicker' : '' ?>" name="ringing_date" value="<?= h($ringingDateValue) ?>">
            </div>
            <div class="header-field">
              <label for="ringing_nest">ringingNest</label>
              <input id="ringing_nest" type="text" name="ringing_nest" value="<?= h($header['ringing_nest']) ?>">
            </div>
            <div class="header-field">
              <label for="scull_length">scullLength</label>
              <input id="scull_length" type="text" name="scull_length" value="<?= h($header['scull_length']) ?>">
            </div>
            <div class="header-field">
              <label for="scull_repeat">scullRepeat</label>
              <input id="scull_repeat" type="text" name="scull_repeat" value="<?= h($header['scull_repeat']) ?>">
            </div>
          </div>
        </fieldset>
      </div>

      <div class="section">
        <fieldset>
          <legend>Content rows</legend>
          <?= renderContentRowsTable($contentRows) ?>
          <textarea name="content_json" style="display:none;"><?= h(json_encode($contentRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
        </fieldset>
      </div>

      <div class="section">
        <fieldset>
          <legend>Recovery rows</legend>
          <?= renderRecoveryRowsTable($recoveryRows) ?>
          <textarea name="recovery_json" style="display:none;"><?= h(json_encode($recoveryRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
        </fieldset>
      </div>

      <p class="actions"><button type="submit">Save changes</button></p>
    </div>

    <aside class="preview">
      <?php if ($previewDataUri !== ''): ?>
        <div class="preview-frame">
          <div class="preview-viewport" id="preview-viewport">
            <img id="preview-image" src="<?= h($previewDataUri) ?>" alt="Uploaded card image">
          </div>
          <p class="preview-help">Scroll to zoom. When zoomed in, click-drag to pan around the image.</p>
        </div>
      <?php else: ?>
        <div class="empty-preview">Image preview not available</div>
      <?php endif; ?>
    </aside>
  </div>
</form>

<div class="section" id="benchmark" style="margin-top:20px;">
  <fieldset>
    <legend>Benchmark this card</legend>
    <p class="muted" style="margin:0 0 10px;">Re-decodes this card's image with the selected models and scores each result against the values saved above. The saved values are <strong>never changed</strong>; results are stored as benchmark data (visible on the Statistics page as well). Models offered here are the preferred models from Settings.</p>
    <?php if (!$imageOnDisk): ?>
      <p style="color:#a00;">The source image for this record is not on disk, so it cannot be benchmarked.</p>
    <?php else: ?>
    <form method="post" class="bench-form">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="benchmark_run" value="1">
      <div>
        <div style="font-weight:700;font-size:.9rem;margin-bottom:4px;">Models</div>
        <div class="bench-models">
          <?php foreach ($benchmarkModels as $model): ?>
            <label><input type="checkbox" name="benchmark_models[]" value="<?= h($model) ?>"> <?= h($model) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:.9rem;font-weight:700;">Prompt
        <select name="benchmark_prompt">
          <?php foreach ($promptFiles as $promptFile): ?>
            <option value="<?= h($promptFile) ?>" <?= $promptFile === $activePromptFile ? 'selected' : '' ?>><?= h(basename($promptFile)) ?><?= $promptFile === $activePromptFile ? ' (active)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="actions" style="margin:0;"><button type="submit">Run benchmark</button></p>
    </form>
    <?php endif; ?>

    <?php if ($benchmarkPending > 0): ?>
      <p id="bench-pending" style="margin-top:12px;padding:8px 12px;border-radius:10px;background:#eff6ff;color:#1d4ed8;font-weight:700;">
        <?= $benchmarkPending ?> benchmark job(s) still running… this section refreshes automatically.
      </p>
    <?php endif; ?>

    <?php if (!empty($benchmarkJobs)): ?>
      <h3 style="margin:16px 0 8px;font-size:1rem;">Runs on this card</h3>
      <div class="scroll" style="max-height:none;">
      <table class="bench-table">
        <thead><tr><th>Job</th><th>Model</th><th>Prompt</th><th>Status</th><th>Accuracy</th><th>Errors</th><th>Cost</th><th>Seconds</th><th>Reasoning tok.</th><th>When</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($benchmarkJobs as $job): ?>
          <tr>
            <td class="mono">#<?= (int) $job['id'] ?></td>
            <td class="mono"><?= h((string) $job['model']) ?></td>
            <td class="mono" title="<?= h((string) $job['prompt_sha256']) ?>"><?= h(basename((string) ($job['prompt_name'] ?? ''))) ?> <span class="muted"><?= h(substr((string) $job['prompt_sha256'], 0, 8)) ?></span></td>
            <td><span class="pill" style="<?= (string) $job['status'] === 'benchmarked' ? 'background:#dcfce7;color:#166534' : ((string) $job['status'] === 'failed' ? 'background:#fee2e2;color:#991b1b' : 'background:#dbeafe;color:#1e40af') ?>"><?= h((string) $job['status']) ?></span>
              <?php if ((string) $job['status'] === 'failed'): ?><div class="muted" style="white-space:normal;max-width:320px;font-size:.75rem;"><?= h((string) $job['error_message']) ?></div><?php endif; ?></td>
            <td style="text-align:right;font-weight:700;"><?= $job['accuracy'] === null ? '-' : number_format((float) $job['accuracy'], 1) . '%' ?></td>
            <td style="text-align:right;"><?= $job['errors'] === null ? '-' : (int) $job['errors'] ?></td>
            <td style="text-align:right;"><?= $job['cost_usd'] === null ? '-' : '$' . number_format((float) $job['cost_usd'], 4) ?></td>
            <td style="text-align:right;"><?= $job['seconds'] === null ? '-' : (int) $job['seconds'] ?></td>
            <td style="text-align:right;"><?= (int) ($job['reasoning_token_count'] ?? 0) ?></td>
            <td class="muted"><?= h(substr((string) $job['created_at'], 0, 16)) ?></td>
            <td><?php if ((string) $job['status'] === 'failed'): ?>
              <form method="post" style="margin:0;"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="benchmark_retry" value="<?= (int) $job['id'] ?>"><button class="tiny-btn" type="submit">Retry</button></form>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>

    <?php if (!empty($benchmarkMatrix['rows'])): ?>
      <?php $matrixJobs = array_values(array_filter($benchmarkJobs, static fn($j) => in_array((int) $j['id'], $benchmarkScoredIds, true))); ?>
      <h3 style="margin:16px 0 8px;font-size:1rem;">Field-by-field comparison against the saved values</h3>
      <p class="muted" style="margin:0 0 8px;">Green = matches the saved value, yellow = same content in a different format, red = wrong or missing, grey = empty on both sides. Columns are ordered by accuracy.</p>
      <div class="scroll">
      <table class="bench-table">
        <thead>
          <tr>
            <th>Field</th>
            <th>Saved value</th>
            <?php foreach ($matrixJobs as $job): ?>
              <th title="job #<?= (int) $job['id'] ?> · <?= h(basename((string) ($job['prompt_name'] ?? ''))) ?>"><?= h((string) $job['model']) ?><br><span class="muted" style="font-weight:400;"><?= $job['accuracy'] === null ? '' : number_format((float) $job['accuracy'], 0) . '% · ' ?><?= h(substr((string) $job['prompt_sha256'], 0, 6)) ?></span></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php $lastGroup = ''; foreach ($benchmarkMatrix['rows'] as $row):
          $group = $row['section'] === 'header' ? 'Header' : ucfirst($row['section']) . ' row ' . $row['row_no'] . ($row['extra'] ? ' (extra, not in saved record)' : '');
          if ($group !== $lastGroup): $lastGroup = $group; ?>
            <tr class="section-head"><td colspan="<?= 2 + count($matrixJobs) ?>"><?= h($group) ?></td></tr>
          <?php endif; ?>
          <tr>
            <td class="mono"><?= h($row['field']) ?></td>
            <td class="val" style="font-weight:700;"><?= $row['final'] === '' ? '<span class="muted">(empty)</span>' : h($row['final']) ?></td>
            <?php foreach ($matrixJobs as $job): $cell = $row['cells'][(int) $job['id']] ?? null; ?>
              <td class="val" style="<?= benchCellStyle($cell) ?>" title="<?= $cell ? h($cell['error_type']) : '' ?>"><?= $cell === null ? '' : ($cell['value'] === '' ? '<span class="muted">(empty)</span>' : h($cell['value'])) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </fieldset>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  if (document.getElementById('bench-pending')) {
    const poll = setInterval(function () {
      fetch('edit.php?id=<?= $id ?>&action=benchmark_status', { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && Number(data.pending) === 0) {
            clearInterval(poll);
            window.location.href = 'edit.php?id=<?= $id ?>#benchmark';
          }
        })
        .catch(function () {});
    }, 4000);
  }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.querySelector('form');
  if (!form) return;

  const previewViewport = document.getElementById('preview-viewport');
  const previewImage = document.getElementById('preview-image');
  if (previewViewport && previewImage) {
    let previewScale = 1;
    const minScale = 1;
    const maxScale = 6;
    let isPanning = false;
    let panStartX = 0;
    let panStartY = 0;
    let startScrollLeft = 0;
    let startScrollTop = 0;

    function setPreviewZoom(nextScale, originX, originY) {
      const oldScale = previewScale;
      previewScale = Math.min(maxScale, Math.max(minScale, nextScale));
      if (previewScale === oldScale) return;

      const scrollLeftRatio = (previewViewport.scrollLeft + originX) / oldScale;
      const scrollTopRatio = (previewViewport.scrollTop + originY) / oldScale;
      previewImage.style.width = (previewScale * 100) + '%';
      previewViewport.scrollLeft = scrollLeftRatio * previewScale - originX;
      previewViewport.scrollTop = scrollTopRatio * previewScale - originY;
      previewViewport.style.cursor = previewScale > 1 ? 'move' : 'zoom-in';
    }

    previewViewport.addEventListener('wheel', function(e) {
      e.preventDefault();
      const rect = previewViewport.getBoundingClientRect();
      const zoomFactor = e.deltaY < 0 ? 1.15 : 1 / 1.15;
      setPreviewZoom(previewScale * zoomFactor, e.clientX - rect.left, e.clientY - rect.top);
    }, { passive: false });

    previewImage.addEventListener('dragstart', function(e) {
      e.preventDefault();
    });

    previewViewport.addEventListener('mousedown', function(e) {
      if (previewScale <= 1) return;
      isPanning = true;
      panStartX = e.clientX;
      panStartY = e.clientY;
      startScrollLeft = previewViewport.scrollLeft;
      startScrollTop = previewViewport.scrollTop;
      previewViewport.style.cursor = 'grabbing';
      e.preventDefault();
    });

    window.addEventListener('mousemove', function(e) {
      if (!isPanning) return;
      previewViewport.scrollLeft = startScrollLeft - (e.clientX - panStartX);
      previewViewport.scrollTop = startScrollTop - (e.clientY - panStartY);
    });

    window.addEventListener('mouseup', function() {
      if (!isPanning) return;
      isPanning = false;
      previewViewport.style.cursor = previewScale > 1 ? 'move' : 'zoom-in';
    });
  }

  function syncTableToJSON(tableSelector, jsonSelector, columns) {
    const table = document.querySelector(tableSelector + ' tbody');
    if (!table) return;
    const rows = [];
    table.querySelectorAll('tr').forEach(tr => {
      const cells = tr.querySelectorAll('input');
      if (cells.length === 0) return;
      const row = {};
      columns.forEach((col, idx) => {
        if (cells[idx]) row[col] = cells[idx].value;
      });
      rows.push(row);
    });
    const field = document.querySelector(jsonSelector);
    if (field) field.value = JSON.stringify(rows);
  }

  function handleAddRow(btnSelector, tableSelector, columns) {
    const btn = document.querySelector(btnSelector);
    if (!btn) return;
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      const table = document.querySelector(tableSelector + ' tbody');
      if (!table) return;
      const newRow = document.createElement('tr');
      newRow.style.backgroundColor = '#f9fafb';
      columns.forEach(col => {
        const td = document.createElement('td');
        td.style.cssText = 'border:1px solid #d7dce2;padding:6px;';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'col-' + col + (col === 'recovery_date' ? ' datepicker' : '');
        input.style.cssText = 'width:100%;padding:4px;border:1px solid #ddd;border-radius:4px;';
        td.appendChild(input);
        newRow.appendChild(td);
      });
      const delTd = document.createElement('td');
      delTd.style.cssText = 'border:1px solid #d7dce2;padding:6px;text-align:center;';
      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn-del-row';
      delBtn.textContent = '×';
      delBtn.style.cssText = 'padding:4px 8px;background:#f5f5f5;border:1px solid #ddd;cursor:pointer;font-size:0.8rem;';
      delBtn.addEventListener('click', function(e) {
        e.preventDefault();
        newRow.remove();
        syncTableToJSON(tableSelector, tableSelector === '.content-rows' ? 'textarea[name="content_json"]' : 'textarea[name="recovery_json"]', columns);
      });
      delTd.appendChild(delBtn);
      newRow.appendChild(delTd);
      table.appendChild(newRow);
      if (window.flatpickr && tableSelector === '.recovery-rows') {
        window.flatpickr(newRow.querySelectorAll('.datepicker'), { dateFormat: 'd.m.Y', allowInput: true });
      }
    });
  }

  function handleInsertRows(tableSelector, jsonSelector, columns) {
    const table = document.querySelector(tableSelector);
    if (!table) return;
    table.addEventListener('click', function(e) {
      if (!e.target.classList.contains('btn-insert-row')) return;
      e.preventDefault();
      const currentRow = e.target.closest('tr');
      if (!currentRow) return;

      const newRow = document.createElement('tr');
      newRow.style.backgroundColor = '#f9fafb';
      columns.forEach(col => {
        const td = document.createElement('td');
        td.style.cssText = 'border:1px solid #d7dce2;padding:6px;';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'col-' + col + (col === 'recovery_date' ? ' datepicker' : '');
        input.style.cssText = 'width:100%;padding:4px;border:1px solid #ddd;border-radius:4px;';
        td.appendChild(input);
        newRow.appendChild(td);
      });

      const insertTd = document.createElement('td');
      insertTd.style.cssText = 'border:1px solid #d7dce2;padding:6px;text-align:center;';
      const insertBtn = document.createElement('button');
      insertBtn.type = 'button';
      insertBtn.className = 'btn-insert-row';
      insertBtn.textContent = '+';
      insertBtn.style.cssText = 'padding:4px 8px;background:#ecfdf5;border:1px solid #86efac;cursor:pointer;font-size:0.8rem;';
      insertTd.appendChild(insertBtn);
      newRow.appendChild(insertTd);

      const delTd = document.createElement('td');
      delTd.style.cssText = 'border:1px solid #d7dce2;padding:6px;text-align:center;';
      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn-del-row';
      delBtn.textContent = '×';
      delBtn.style.cssText = 'padding:4px 8px;background:#f5f5f5;border:1px solid #ddd;cursor:pointer;font-size:0.8rem;';
      delTd.appendChild(delBtn);
      newRow.appendChild(delTd);

      currentRow.parentNode.insertBefore(newRow, currentRow.nextSibling);
      if (window.flatpickr && tableSelector === '.recovery-rows') {
        window.flatpickr(newRow.querySelectorAll('.datepicker'), { dateFormat: 'd.m.Y', allowInput: true });
      }
      syncTableToJSON(tableSelector, jsonSelector, columns);
    });
  }

  function handleDeleteRows(tableSelector, jsonSelector, columns) {
    const table = document.querySelector(tableSelector);
    if (!table) return;
    table.addEventListener('click', function(e) {
      if (e.target.classList.contains('btn-del-row')) {
        e.preventDefault();
        e.target.closest('tr').remove();
        syncTableToJSON(tableSelector, jsonSelector, columns);
      }
    });
  }

  const contentColumns = ['ring_position', 'ring_number', 'obs_status', 'obs_year', 'obs_nest', 'obs_notes'];
  const recoveryColumns = ['ring_number', 'recovery_status', 'recovery_date', 'recovery_location', 'recovery_person', 'recovery_notes'];

  handleAddRow('.btn-add-content-row', '.content-rows', contentColumns);
  handleInsertRows('.content-rows', 'textarea[name="content_json"]', contentColumns);
  handleDeleteRows('.content-rows', 'textarea[name="content_json"]', contentColumns);

  handleAddRow('.btn-add-recovery-row', '.recovery-rows', recoveryColumns);
  handleInsertRows('.recovery-rows', 'textarea[name="recovery_json"]', recoveryColumns);
  handleDeleteRows('.recovery-rows', 'textarea[name="recovery_json"]', recoveryColumns);

  form.addEventListener('submit', function() {
    syncTableToJSON('.content-rows', 'textarea[name="content_json"]', contentColumns);
    syncTableToJSON('.recovery-rows', 'textarea[name="recovery_json"]', recoveryColumns);
  });

  document.querySelectorAll('.editable-table input').forEach(input => {
    input.addEventListener('change', function() {
      const table = this.closest('table');
      const tableClass = table.classList[1];
      const jsonSelector = tableClass === 'content-rows' ? 'textarea[name="content_json"]' : 'textarea[name="recovery_json"]';
      const columns = tableClass === 'content-rows' ? contentColumns : recoveryColumns;
      syncTableToJSON('.' + tableClass, jsonSelector, columns);
    });
  });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
  flatpickr(".datepicker", {
    dateFormat: "d.m.Y",
    allowInput: true
  });
</script>
</body>
</html>
