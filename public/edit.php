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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
