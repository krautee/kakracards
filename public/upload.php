<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$error = '';
$message = '';
$decoded = null;
$usageMetadata = [];
$imagePath = '';
$reviewJobId = 0;
$promptFiles = listPromptFiles();
$activePromptFile = getCurrentPromptFileRelativePath();
$selectedPromptFile = (string) ($_POST['prompt_file'] ?? $activePromptFile);
if (!in_array($selectedPromptFile, $promptFiles, true)) {
  $selectedPromptFile = $activePromptFile;
}
$preferredModels = preferredGeminiModels();
$selectedModels = normalizedModelSelection($_POST['gemini_models'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (int) ($_GET['saved'] ?? 0) === 1) {
  $message = 'Record saved.';
}

function asJson(mixed $value, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($value, JSON_UNESCAPED_UNICODE);
    exit;
}

if ((string) ($_GET['action'] ?? '') === 'jobs_json') {
    $jobs = listDecodeJobs(200);
  asJson(['jobs' => $jobs, 'runtime' => workerSpawnDiagnostics()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'retry_job') {
    $jobId = (int) ($_POST['job_id'] ?? 0);
    if ($jobId <= 0) {
        asJson(['ok' => false, 'error' => 'Missing job id'], 400);
    }

    try {
        retryDecodeJob($jobId);
      try {
        startDecodeJobWorker($jobId);
      } catch (Throwable $workerError) {
        markDecodeJobFailed($jobId, $workerError->getMessage());
        throw $workerError;
      }
        asJson(['ok' => true]);
    } catch (Throwable $e) {
        asJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_job') {
    $jobId = (int) ($_POST['job_id'] ?? 0);
    if ($jobId <= 0) {
        asJson(['ok' => false, 'error' => 'Missing job id'], 400);
    }

    try {
        $job = getDecodeJob($jobId);
        if ($job === null) {
            asJson(['ok' => false, 'error' => 'Job not found'], 404);
        }
        if ((string) ($job['status'] ?? '') !== 'failed') {
            asJson(['ok' => false, 'error' => 'Only failed jobs can be deleted'], 400);
        }
        deleteDecodeJob($jobId);
        asJson(['ok' => true]);
    } catch (Throwable $e) {
        asJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discard_record'])) {
    $reviewJobIds = array_filter(array_map('intval', explode(',', (string) ($_POST['review_job_ids'] ?? ''))));
    foreach ($reviewJobIds as $rid) {
        if ($rid > 0) {
            markDecodeJobRejected($rid);
        }
    }
    header('Location: upload.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_record'])) {
    $reviewJobIds = array_filter(array_map('intval', explode(',', (string) ($_POST['review_job_ids'] ?? ''))));
  $uncertaintiesByJobJson = (string) ($_POST['uncertainties_by_job_json'] ?? '{}');
  $uncertaintiesByJob = json_decode($uncertaintiesByJobJson, true);
  if (!is_array($uncertaintiesByJob)) {
    $uncertaintiesByJob = [];
  }
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

    $imagePath = (string) ($_POST['source_image_path'] ?? '');
    if ($imagePath === '' || !is_file($imagePath)) {
        $error = 'Missing source image path while saving.';
    } else {
        $id = insertDecodedRecord($decoded, $imagePath);
      saveDecodeFieldQuality($id, $reviewJobIds, $decoded);
      foreach ($reviewJobIds as $rid) {
        $key = (string) $rid;
        $lines = [];
        if (isset($uncertaintiesByJob[$key]['lines']) && is_array($uncertaintiesByJob[$key]['lines'])) {
          $lines = $uncertaintiesByJob[$key]['lines'];
        }
        setDecodeJobUncertainties((int) $rid, $lines);
      }
        markDecodeJobsSaved($reviewJobIds, $id);
        if (countPendingDecodeJobs() > 0) {
          header('Location: upload.php?saved=1&focus=jobs');
        } else {
          header('Location: edit.php?id=' . $id . '&saved=1');
        }
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enqueue_jobs'])) {
    $files = $_FILES['card_images'] ?? null;
    if (!is_array($files) || !isset($files['tmp_name']) || !is_array($files['tmp_name'])) {
        $error = 'Please choose at least one image.';
    } else {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        $queued = 0;
        $failed = 0;

        foreach ($files['tmp_name'] as $i => $tmpName) {
            if (!is_uploaded_file((string) $tmpName)) {
                continue;
            }
            $originalName = (string) ($files['name'][$i] ?? '');
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                $failed++;
                continue;
            }

            $baseName = (string) pathinfo($originalName, PATHINFO_FILENAME);
            $safeBaseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
            $targetName = date('Ymd_His') . '_' . $i . '_' . $safeBaseName . '.' . $extension;
            $targetPath = rtrim(uploadDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $targetName;

            if (!move_uploaded_file((string) $tmpName, $targetPath)) {
                $failed++;
                continue;
            }

            $jobId = 0;
            try {
                if ($extension === 'pdf') {
                    $pageImages = convertPdfToImages($targetPath, date('Ymd_His') . '_' . $i . '_' . $safeBaseName);
                    foreach ($pageImages as $pageImagePath) {
                        $group = md5(uniqid('', true));
                        foreach ($selectedModels as $modelToUse) {
                            $jobId = createDecodeJob($pageImagePath, $selectedPromptFile, $modelToUse, $group);
                            try {
                                startDecodeJobWorker($jobId);
                            } catch (Throwable $workerError) {
                                markDecodeJobFailed($jobId, $workerError->getMessage());
                                throw $workerError;
                            }
                            $queued++;
                        }
                    }
                } else {
                    $group = md5(uniqid('', true));
                    foreach ($selectedModels as $modelToUse) {
                        $jobId = createDecodeJob($targetPath, $selectedPromptFile, $modelToUse, $group);
                        try {
                            startDecodeJobWorker($jobId);
                        } catch (Throwable $workerError) {
                            markDecodeJobFailed($jobId, $workerError->getMessage());
                            throw $workerError;
                        }
                        $queued++;
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                if ($error === '') {
                    $error = $e->getMessage();
                }
            }
        }

        if ($queued > 0) {
            $message = "Queued $queued image(s) for background decoding.";
        }
        if ($failed > 0) {
            $error = trim(($error !== '' ? $error . ' ' : '') . "$failed file(s) failed to queue.");
        }
    }
}

$comparisonDataJson = 'null';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['review_job_id'])) {
    $reviewJobId = (int) ($_GET['review_job_id'] ?? 0);
    if ($reviewJobId > 0) {
        $job = getDecodeJob($reviewJobId);
        if ($job === null) {
            $error = 'Review job not found.';
        } elseif (!in_array((string) $job['status'], ['succeeded', 'saved'], true)) {
            $error = 'Job is not finished yet.';
        } else {
            $group = (string) ($job['comparison_group'] ?? '');
            if ($group !== '') {
                $groupJobs = getDecodeJobsByComparisonGroup($group);
            } else {
                $groupJobs = [$job];
            }
            
            // Ensure all group jobs are finished
            $allFinished = true;
            $validGroupJobs = [];
            foreach ($groupJobs as $gj) {
                $st = (string) $gj['status'];
                if (in_array($st, ['queued', 'running'], true)) {
                    $allFinished = false;
                } elseif (in_array($st, ['succeeded', 'saved'], true)) {
                    $validGroupJobs[] = $gj;
                }
            }
            
            if (!$allFinished) {
                 $error = 'Not all jobs in this comparison group are finished yet.';
            } elseif (empty($validGroupJobs)) {
                 $error = 'No successful jobs to review in this comparison group.';
            } else {
                $comparisonData = buildComparisonData($validGroupJobs);
                $comparisonDataJson = json_encode($comparisonData, JSON_UNESCAPED_UNICODE);
                $imagePath = (string) ($job['source_image_path'] ?? '');
            }
        }
    }
}

$jobs = listDecodeJobs(200);
$runtimeDiag = workerSpawnDiagnostics();
$header = is_array($decoded['header'] ?? null) ? $decoded['header'] : [];
$contentRows = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
$recoveryRows = is_array($decoded['recovery'] ?? null) ? $decoded['recovery'] : [];
$uncertaintiesText = is_array($decoded['uncertainties'] ?? null) ? implode("\n", $decoded['uncertainties']) : '';

$previewDataUri = '';
if ($imagePath !== '' && is_file($imagePath)) {
    $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';
    $previewDataUri = 'data:' . $mimeType . ';base64,' . base64_encode((string) file_get_contents($imagePath));
}

function usageMetadataFields(array $usageMetadata): array
{
    return usageMetadataHumanLines($usageMetadata);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Upload & Decode</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>
    :root{color-scheme:light}
    body{font-family:Arial,sans-serif;max-width:1700px;margin:18px auto;padding:0 16px;background:#f6f4ef;color:#1f2933}
    a{color:#184d8d}
    .nav a{display:inline-block;padding:6px 10px;background:#1155cc;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}
    h1{margin:8px 0 16px}
    input[type=text],input[type=date],textarea,select{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #c7ced6;border-radius:10px;background:#fff;font:inherit}
    textarea{min-height:120px;resize:vertical}
    fieldset{margin:0;border:1px solid #d7dce2;border-radius:16px;background:#fff;padding:14px}
    legend{padding:0 8px;font-weight:700}
    .section{margin-bottom:14px}
    .queue-wrap{display:grid;grid-template-columns:1fr;gap:14px}
    .queue-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .btn-main{padding:10px 16px;border:0;border-radius:999px;background:#184d8d;color:#fff;font-weight:700;cursor:pointer}
    .btn-main:hover{background:#123e72}
    .jobs-table{width:100%;border-collapse:collapse}
    .jobs-table th,.jobs-table td{border:1px solid #d7dce2;padding:8px;vertical-align:middle}
    .jobs-table th{background:#f6f9fc;text-align:left}
    .thumb{width:54px;height:54px;object-fit:cover;border-radius:8px;border:1px solid #cfd8e3;background:#fff}
    .thumb-link{display:inline-block;cursor:pointer}
    #hover-modal{position:fixed;display:none;z-index:9999;pointer-events:none;background:#fff;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 14px 40px rgba(0,0,0,.2);padding:8px}
    #hover-modal img{display:block;max-width:760px;max-height:760px;border-radius:6px}
    .status{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.8rem;font-weight:700}
    .status-queued{background:#e5e7eb;color:#374151}
    .status-running{background:#dbeafe;color:#1e40af}
    .status-succeeded{background:#dcfce7;color:#166534}
    .status-saved{background:#ccfbf1;color:#0f766e}
    .status-failed{background:#fee2e2;color:#991b1b}
    .model-name{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.78rem;font-weight:700;border:1px solid transparent;white-space:nowrap}
    .tiny-btn{padding:6px 10px;border:1px solid #c6d1df;border-radius:8px;background:#fff;cursor:pointer;text-decoration:none;color:#1f2933;display:inline-block}
    .tiny-btn:hover{background:#f8fafc}
    .panel-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(420px,1.15fr);gap:20px;align-items:start}
    .jobs-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,0.8fr);gap:20px;align-items:start}
    .header-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}
    .header-field{display:flex;flex-direction:column;gap:4px}
    .header-field label{font-weight:700;font-size:0.9rem;color:#1f2933}
    .header-field input{padding:6px 8px;border:1px solid #c7ced6;border-radius:8px;font-size:0.88rem}
    .preview{position:sticky;top:16px;display:flex;flex-direction:column;gap:12px}
    .preview-frame{padding:14px;border:1px solid #d7dce2;border-radius:20px;background:linear-gradient(180deg,#ffffff,#f3f0e8);box-shadow:0 12px 30px rgba(15,23,42,.08)}
    .preview-frame img{display:block;width:100%;height:auto;border-radius:14px;background:#fff}
    .preview-viewport{max-height:calc(100vh - 72px);min-height:540px;overflow:auto;border-radius:14px;background:#fff;cursor:zoom-in;overscroll-behavior:contain}
    .preview-viewport img{display:block;width:100%;height:auto;max-width:none;transform-origin:top left;user-select:none;-webkit-user-drag:none}
    .preview-help{margin:8px 2px 0;color:#6b7280;font-size:.85rem}
    .meta{padding:14px 16px;border:1px solid #d7dce2;border-radius:16px;background:#fff}
    .empty-preview{display:grid;place-items:center;min-height:300px;border:1px dashed #c7ced6;border-radius:14px;color:#6b7280;background:#f9fafb;text-align:center;padding:20px}
    .editable-table input{margin:0}
    .btn-del-row:hover{background:#ffe0e0 !important}
    .wait-indicator{display:none;padding:10px 12px;border-radius:10px;background:#eff6ff;color:#1d4ed8;font-weight:700}
    .spin{display:inline-block;width:14px;height:14px;border:2px solid #93c5fd;border-top-color:#1d4ed8;border-radius:999px;vertical-align:-2px;animation:spin 0.8s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    @media (max-width: 980px){.panel-grid{grid-template-columns:1fr}.jobs-grid{grid-template-columns:1fr}.preview{position:static}.header-grid{grid-template-columns:repeat(2,1fr)}}
  </style>
</head>
<body>
<p class="nav">
  <a href="index.php">Home</a>
  <a href="upload.php">Upload &amp; Decode</a>
  <a href="settings.php">Settings (Prompt)</a>
  <a href="stats.php">Statistics</a>
</p>
<h1>Upload & Decode</h1>
<?php if ($message !== ''): ?><p style="color:#065f46;"><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($error !== ''): ?><p style="color:#a00;"><strong><?= h($error) ?></strong></p><?php endif; ?>
<p style="margin-top:0;color:#4b5563;">
  Worker spawn: <strong><?= (bool) ($runtimeDiag['can_spawn'] ?? false) ? 'enabled' : 'disabled' ?></strong>
  <?php if (!(bool) ($runtimeDiag['can_spawn'] ?? false)): ?>
    (disable_functions: <?= h((string) ($runtimeDiag['disable_functions'] ?? '')) ?>)
  <?php endif; ?>
</p>

<div class="queue-wrap section">
  <form id="queue-form" method="post" enctype="multipart/form-data">
    <input type="hidden" name="enqueue_jobs" value="1">
    <fieldset>
      <legend>Queue Images For Background Decode</legend>
      <div class="queue-actions">
        <input type="file" name="card_images[]" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,application/pdf" multiple required>
        <select name="prompt_file" aria-label="Prompt file">
          <?php foreach ($promptFiles as $promptFile): ?>
            <option value="<?= h($promptFile) ?>" <?= $promptFile === $selectedPromptFile ? 'selected' : '' ?>><?= h($promptFile) ?><?= $promptFile === $activePromptFile ? ' (active)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn-main" type="submit">Start background decode</button>
      </div>
      <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
        <span style="font-weight:700; color:#374151; font-size:0.9rem;">Models:</span>
        <?php foreach ($preferredModels as $model): ?>
          <label style="font-size:0.9rem;"><input type="checkbox" name="gemini_models[]" value="<?= h($model) ?>" <?= in_array($model, $selectedModels, true) ? 'checked' : '' ?>> <?= h($model) ?></label>
        <?php endforeach; ?>
      </div>
      <p id="wait-indicator" class="wait-indicator"><span class="spin"></span> Processing... <span id="wait-seconds">0</span>s</p>
    </fieldset>
  </form>
</div>

<div class="section" id="jobs-top">
  <fieldset>
    <legend>Decode Jobs</legend>
    <table class="jobs-table">
      <thead>
        <tr>
          <th>Thumb</th>
          <th>File</th>
          <th>Model</th>
          <th>Status</th>
          <th>Elapsed</th>
          <th>Action</th>
          <th>Error</th>
          <th>Retry / Delete</th>
        </tr>
      </thead>
      <tbody id="jobs-body">
        <tr><td colspan="8" style="color:#6b7280;text-align:center;">Loading jobs...</td></tr>
      </tbody>
    </table>
  </fieldset>
</div>

<div id="hover-modal"><img src="" alt="Decode job image preview"></div>

<?php if ($comparisonDataJson !== 'null'): ?>
  <div id="review-focus-anchor" style="position:relative;top:-8px;"></div>
  <p>Review and correct values, then save. Black values are resolved. Double click red values to select them. Double click black values to edit.</p>
  <form id="review-form" method="post">
    <input type="hidden" name="review_job_ids" id="review_job_ids" value="">
    <input type="hidden" name="source_image_path" value="<?= h($imagePath) ?>">
    <input type="hidden" name="uncertainties_by_job_json" id="uncertainties_by_job_json" value="{}">
    <div class="panel-grid">
      <div>
        <div class="editor" id="comparison-editor">
        </div>
        <textarea name="content_json" id="content_json" style="display:none;"></textarea>
        <textarea name="recovery_json" id="recovery_json" style="display:none;"></textarea>
        <textarea name="uncertainties" id="uncertainties" style="display:none;"></textarea>
        <p class="actions">
          <button class="btn-main" type="submit" name="save_record" id="save_record" value="1">Accept and Save</button>
          <button class="btn-main" type="submit" name="discard_record" value="1" style="background:#b91c1c;">Discard</button>
        </p>
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
          <div class="empty-preview">Preview will appear here after upload.</div>
        <?php endif; ?>
      </aside>
    </div>
  </form>
<?php endif; ?>

<script>
(function () {
  const queueForm = document.getElementById('queue-form');
  const waitIndicator = document.getElementById('wait-indicator');
  const waitSeconds = document.getElementById('wait-seconds');
  const hoverModal = document.getElementById('hover-modal');
  const hoverModalImg = hoverModal ? hoverModal.querySelector('img') : null;
  const params = new URLSearchParams(window.location.search);
  let submitStart = 0;
  let submitTimer = null;

  function scrollToAnchor(anchorId) {
    const anchor = document.getElementById(anchorId);
    if (!anchor) return;
    const top = Math.max(0, Math.floor(anchor.getBoundingClientRect().top + window.pageYOffset - 8));
    window.scrollTo({ top: top, behavior: 'auto' });
  }

  if (params.get('focus') === 'jobs') {
    scrollToAnchor('jobs-top');
  }

  if (params.has('review_job_id')) {
    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }
    scrollToAnchor('review-focus-anchor');
    window.addEventListener('load', function () {
      setTimeout(function () {
        scrollToAnchor('review-focus-anchor');
      }, 0);
    });
  }

  if (queueForm && waitIndicator && waitSeconds) {
    queueForm.addEventListener('submit', function () {
      submitStart = Date.now();
      waitIndicator.style.display = 'inline-block';
      if (submitTimer) {
        clearInterval(submitTimer);
      }
      submitTimer = setInterval(function () {
        const secs = Math.floor((Date.now() - submitStart) / 1000);
        waitSeconds.textContent = String(secs);
      }, 250);
    });
  }

  const reviewForm = document.getElementById('review-form');
  if (reviewForm) {
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

      previewViewport.addEventListener('wheel', function (e) {
        e.preventDefault();
        const rect = previewViewport.getBoundingClientRect();
        const zoomFactor = e.deltaY < 0 ? 1.15 : 1 / 1.15;
        setPreviewZoom(previewScale * zoomFactor, e.clientX - rect.left, e.clientY - rect.top);
      }, { passive: false });

      previewImage.addEventListener('dragstart', function (e) {
        e.preventDefault();
      });

      previewViewport.addEventListener('mousedown', function (e) {
        if (previewScale <= 1) return;
        isPanning = true;
        panStartX = e.clientX;
        panStartY = e.clientY;
        startScrollLeft = previewViewport.scrollLeft;
        startScrollTop = previewViewport.scrollTop;
        previewViewport.style.cursor = 'grabbing';
        e.preventDefault();
      });

      window.addEventListener('mousemove', function (e) {
        if (!isPanning) return;
        previewViewport.scrollLeft = startScrollLeft - (e.clientX - panStartX);
        previewViewport.scrollTop = startScrollTop - (e.clientY - panStartY);
      });

      window.addEventListener('mouseup', function () {
        if (!isPanning) return;
        isPanning = false;
        previewViewport.style.cursor = previewScale > 1 ? 'move' : 'zoom-in';
      });
    }

    function syncTableToJSON(tableSelector, jsonSelector, columns) {
      const table = reviewForm.querySelector(tableSelector + ' tbody');
      if (!table) return;
      const rows = [];
      table.querySelectorAll('tr').forEach(function (tr) {
        const cells = tr.querySelectorAll('input');
        if (cells.length === 0) return;
        const row = {};
        columns.forEach(function (col, idx) {
          if (cells[idx]) row[col] = cells[idx].value;
        });
        rows.push(row);
      });
      const field = reviewForm.querySelector(jsonSelector);
      if (field) field.value = JSON.stringify(rows);
    }

    function handleAddRow(btnSelector, tableSelector, columns) {
      const btn = reviewForm.querySelector(btnSelector);
      if (!btn) return;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const table = reviewForm.querySelector(tableSelector + ' tbody');
        if (!table) return;
        const newRow = document.createElement('tr');
        newRow.style.backgroundColor = '#f9fafb';
        columns.forEach(function (col) {
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
        delTd.appendChild(delBtn);
        newRow.appendChild(delTd);
        table.appendChild(newRow);
        if (window.flatpickr && tableSelector === '.recovery-rows') {
          window.flatpickr(newRow.querySelectorAll('.datepicker'), { dateFormat: 'd.m.Y', allowInput: true });
        }
      });
    }

    function handleDeleteRows(tableSelector) {
      const table = reviewForm.querySelector(tableSelector);
      if (!table) return;
      table.addEventListener('click', function (e) {
        const target = e.target;
        if (target && target.classList.contains('btn-del-row')) {
          e.preventDefault();
          const tr = target.closest('tr');
          if (tr) tr.remove();
        }
      });
    }

    const contentColumns = ['ring_position', 'ring_number', 'obs_status', 'obs_year', 'obs_nest', 'obs_notes'];
    const recoveryColumns = ['ring_number', 'recovery_status', 'recovery_date', 'recovery_location', 'recovery_person', 'recovery_notes'];

    handleAddRow('.btn-add-content-row', '.content-rows', contentColumns);
    handleDeleteRows('.content-rows');
    handleAddRow('.btn-add-recovery-row', '.recovery-rows', recoveryColumns);
    handleDeleteRows('.recovery-rows');

    reviewForm.addEventListener('submit', function () {
      syncTableToJSON('.content-rows', 'textarea[name="content_json"]', contentColumns);
      syncTableToJSON('.recovery-rows', 'textarea[name="recovery_json"]', recoveryColumns);
    });
  }

  function statusBadge(status) {
    return '<span class="status status-' + status + '">' + status + '</span>';
  }

  function elapsedSeconds(job) {
    const status = String(job.status || 'queued');
    const created = job.created_at ? new Date(job.created_at) : null;
    const started = job.started_at ? new Date(job.started_at) : created;
    const finished = job.finished_at ? new Date(job.finished_at) : new Date();
    if (!started || Number.isNaN(started.getTime())) return 0;
    if (status === 'queued') {
        if (!created || Number.isNaN(created.getTime())) return 0;
        return Math.max(0, Math.floor((Date.now() - created.getTime()) / 1000));
    }
    return Math.max(0, Math.floor((finished.getTime() - started.getTime()) / 1000));
  }

  function esc(text) {
    return String(text || '').replace(/[&<>"']/g, function (ch) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
    });
  }

  function isPreciseEditorDate(value) {
    return /^\d{2}\.\d{2}\.\d{4}$/.test(String(value || '').trim());
  }

  const modelPalette = ['#0f766e', '#1d4ed8', '#b45309', '#7c3aed', '#b91c1c', '#0e7490', '#be185d', '#4d7c0f'];

  function colorForModel(model) {
    const text = String(model || 'unknown');
    let hash = 0;
    for (let i = 0; i < text.length; i++) {
      hash = ((hash << 5) - hash) + text.charCodeAt(i);
      hash |= 0;
    }
    return modelPalette[Math.abs(hash) % modelPalette.length];
  }

  function modelHtml(model) {
    const color = colorForModel(model);
    return '<span class="model-name" style="color:' + color + ';border-color:' + color + '33;background:' + color + '12;">' + esc(model || 'unknown') + '</span>';
  }

  function actionHtml(job) {
    const status = String(job.status || 'queued');
    const savedId = Number(job.saved_header_id || 0);
    if (status === 'succeeded' && savedId === 0) {
      return '<a class="tiny-btn" href="upload.php?review_job_id=' + Number(job.id) + '">Review</a>';
    }
    if (savedId > 0) {
      return '<a class="tiny-btn" href="edit.php?id=' + savedId + '">Open saved</a>';
    }
    if (status === 'failed') {
      return '<button class="tiny-btn retry-btn" data-id="' + Number(job.id) + '" type="button">Retry</button>';
    }
    return '<span style="color:#6b7280;">-</span>';
  }

  function groupedActionHtml(groupJobs) {
    const hasWaiting = groupJobs.some(function (job) {
      const status = String(job.status || 'queued');
      return status === 'queued' || status === 'running';
    });
    if (hasWaiting) {
      return '<span style="color:#6b7280;">Waiting...</span>';
    }

    const savedJob = groupJobs.find(function (job) {
      return Number(job.saved_header_id || 0) > 0;
    });
    if (savedJob) {
      return '<a class="tiny-btn" href="edit.php?id=' + Number(savedJob.saved_header_id || 0) + '">Open saved</a>';
    }

    const reviewJob = groupJobs.find(function (job) {
      const status = String(job.status || 'queued');
      return status === 'succeeded' && Number(job.saved_header_id || 0) === 0;
    });
    if (reviewJob) {
      return '<a class="tiny-btn" href="upload.php?review_job_id=' + Number(reviewJob.id) + '">Review</a>';
    }

    const failedIds = groupJobs.filter(function (job) {
      return String(job.status || 'queued') === 'failed';
    }).map(function (job) {
      return Number(job.id);
    });
    if (failedIds.length === 1) {
      return '<button class="tiny-btn retry-btn" data-id="' + failedIds[0] + '" type="button">Retry</button>';
    }
    if (failedIds.length > 1) {
      return '<button class="tiny-btn retry-group-btn" data-ids="' + failedIds.join(',') + '" type="button">Retry all failed</button>';
    }

    return '<span style="color:#6b7280;">-</span>';
  }

  function rowRetryDeleteHtml(job) {
    const status = String(job.status || 'queued');
    if (status === 'failed') {
      // Per-row retry: the group-level Action column can show "Review" instead of
      // "Retry" when other models in the same comparison group already succeeded,
      // so this failed row needs its own retry control regardless of group state
      // (e.g. transient network errors like DNS resolution failures on one model).
      return '<button class="tiny-btn retry-btn" data-id="' + Number(job.id) + '" type="button" title="Retry this failed job">Retry</button> '
        + '<button class="tiny-btn delete-btn" data-id="' + Number(job.id) + '" type="button" title="Delete this failed job">[x]</button>';
    }
    return '<span style="color:#d1d5db;">-</span>';
  }

  function renderJobs(jobs) {
    const body = document.getElementById('jobs-body');
    if (!body) return;
    const groups = [];
    const groupMap = {};
    jobs.forEach(function (job) {
      const groupId = String(job.comparison_group || 'job-' + Number(job.id));
      if (!groupMap[groupId]) {
        groupMap[groupId] = { id: groupId, jobs: [] };
        groups.push(groupMap[groupId]);
      }
      groupMap[groupId].jobs.push(job);
    });

    const rows = [];
    groups.forEach(function (group) {
      const groupJobs = group.jobs;
      const rowSpan = groupJobs.length;
      const firstJob = groupJobs[0];
      groupJobs.forEach(function (job, index) {
        const status = String(job.status || 'queued');
        const elapsed = elapsedSeconds(job);
        let elapsedText = elapsed + 's';
        if (status === 'queued') elapsedText = 'waiting ' + elapsed + 's';
        if (status === 'running') elapsedText = 'running ' + elapsed + 's';

        let row = '<tr data-id="' + Number(job.id) + '">';
        if (index === 0) {
          row += '<td rowspan="' + rowSpan + '"><a class="thumb-link image-link-thumb" href="image.php?job_id=' + Number(firstJob.id) + '" data-image-url="image.php?job_id=' + Number(firstJob.id) + '"><img class="thumb" src="image.php?job_id=' + Number(firstJob.id) + '" alt="thumb"></a></td>';
        }
        row += '<td>' + esc(job.source_image_filename || '') + '</td>'
          + '<td>' + modelHtml(job.requested_model || job.decoding_model || '') + '</td>'
          + '<td>' + statusBadge(status) + '</td>'
          + '<td>' + elapsedText + '</td>';
        if (index === 0) {
          row += '<td rowspan="' + rowSpan + '">' + groupedActionHtml(groupJobs) + '</td>';
        }
        row += '<td>' + esc(job.error_message || '') + '</td>'
          + '<td>' + rowRetryDeleteHtml(job) + '</td>'
          + '</tr>';
        rows.push(row);
      });
    });

    body.innerHTML = rows.join('');

    body.querySelectorAll('.retry-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const id = Number(btn.getAttribute('data-id') || 0);
        if (!id) return;
        const payload = new URLSearchParams();
        payload.set('action', 'retry_job');
        payload.set('job_id', String(id));
        fetch('upload.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString()
        }).then(function () {
          refreshJobs();
        });
      });
    });

    body.querySelectorAll('.delete-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const id = Number(btn.getAttribute('data-id') || 0);
        if (!id) return;
        if (!confirm('Are you sure you want to delete this failed job?')) return;
        const payload = new URLSearchParams();
        payload.set('action', 'delete_job');
        payload.set('job_id', String(id));
        fetch('upload.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString()
        }).then(function () {
          refreshJobs();
        });
      });
    });

    body.querySelectorAll('.retry-group-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const ids = String(btn.getAttribute('data-ids') || '').split(',').map(function (value) {
          return Number(value || 0);
        }).filter(function (id) {
          return id > 0;
        });
        if (ids.length === 0) return;

        const jobs = ids.map(function (id) {
          const payload = new URLSearchParams();
          payload.set('action', 'retry_job');
          payload.set('job_id', String(id));
          return fetch('upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
          });
        });

        Promise.all(jobs).then(function () {
          refreshJobs();
        });
      });
    });

    body.querySelectorAll('.image-link-thumb').forEach(function (link) {
      link.addEventListener('mouseenter', function () {
        if (!hoverModal || !hoverModalImg) return;
        hoverModalImg.src = link.dataset.imageUrl || link.getAttribute('href');
        hoverModal.style.display = 'block';
      });
      link.addEventListener('mousemove', function (e) {
        if (!hoverModal) return;
        hoverModal.style.left = (e.clientX + 18) + 'px';
        hoverModal.style.top = (e.clientY + 18) + 'px';
      });
      link.addEventListener('mouseleave', function () {
        if (!hoverModal || !hoverModalImg) return;
        hoverModal.style.display = 'none';
        hoverModalImg.src = '';
      });
    });
  }

  function refreshJobs() {
    fetch('upload.php?action=jobs_json', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !Array.isArray(data.jobs)) return;
        renderJobs(data.jobs);
      })
      .catch(function () {
      });
  }

  document.querySelectorAll('.model-name').forEach(function (el) {
    const model = el.textContent || 'unknown';
    const color = colorForModel(model);
    el.style.color = color;
    el.style.borderColor = color + '33';
    el.style.background = color + '12';
  });

  renderJobs(<?= json_encode($jobs, JSON_UNESCAPED_UNICODE) ?>);
  refreshJobs();
  setInterval(refreshJobs, 3000);
})();
</script>
<?php if ($comparisonDataJson !== 'null'): ?>
<script>
(function() {
  const data = <?= $comparisonDataJson ?>;
  const editor = document.getElementById('comparison-editor');
  const reviewJobIds = document.getElementById('review_job_ids');
  const saveBtn = document.getElementById('save_record');
  const uncertaintiesByJob = data.uncertaintiesByJob || {};
  const modelPalette = ['#0f766e', '#1d4ed8', '#b45309', '#7c3aed', '#b91c1c', '#0e7490', '#be185d', '#4d7c0f'];
  
  reviewJobIds.value = data.jobIds.join(',');

  function colorForModel(model) {
    const text = String(model || 'unknown');
    let hash = 0;
    for (let i = 0; i < text.length; i++) {
      hash = ((hash << 5) - hash) + text.charCodeAt(i);
      hash |= 0;
    }
    return modelPalette[Math.abs(hash) % modelPalette.length];
  }

  function modelPill(model) {
    const color = colorForModel(model);
    return `<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:0.75rem;font-weight:700;color:${color};border:1px solid ${color}33;background:${color}12;">${esc(model || 'unknown')}</span>`;
  }

  // The state of each field.
  // state = { value: "resolved string", isResolved: true } 
  // or { values: [{val, models}], isResolved: false }
  
  function initState(arr) {
    const unique = {};
    arr.forEach(item => {
      const v = item.value.trim();
      if (!unique[v]) unique[v] = [];
      unique[v].push(item.model);
    });
    const keys = Object.keys(unique);
    if (keys.length === 1) {
      return { value: keys[0], isResolved: true };
    }
    return { 
      values: keys.map(k => ({ val: k, models: unique[k] })),
      isResolved: false 
    };
  }

  const headerState = {};
  for (const k in data.header) {
    headerState[k] = initState(data.header[k]);
  }

  const contentState = data.content.map(row => {
    const res = {};
    for (const k in row) res[k] = initState(row[k]);
    return res;
  });

  const recoveryState = data.recovery.map(row => {
    const res = {};
    for (const k in row) res[k] = initState(row[k]);
    return res;
  });
  
  function esc(text) {
    return String(text || '').replace(/[&<>"']/g, function (ch) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
    });
  }

  function renderField(stateObj, onChange) {
    if (stateObj.isResolved) {
      return `<div class="resolved-val" style="padding:6px 8px; border:1px solid #c7ced6; border-radius:8px; cursor:text;" tabindex="0">${esc(stateObj.value) || '<span style="color:#aaa;">empty</span>'}</div>`;
    } else {
      let html = `<div style="display:flex; gap:4px; flex-wrap:wrap; border:1px solid #fca5a5; padding:4px; border-radius:8px; background:#fef2f2;">`;
      stateObj.values.forEach(v => {
        const mainModel = v.models[0] || 'unknown';
        const color = colorForModel(mainModel);
        const modelTags = v.models.map(m => modelPill(m)).join(' ');
        html += `<div class="conflict-val" title="${esc(v.models.join(', '))}" data-val="${esc(v.val)}" style="padding:4px 8px; background:#fff; border:1px solid ${color}66; border-radius:6px; cursor:pointer; font-weight:700; color:${color};">`
          + `${esc(v.val) || '<span style="color:#94a3b8;">empty</span>'}`
          + `<div style="margin-top:4px; display:flex; gap:4px; flex-wrap:wrap;">${modelTags}</div>`
          + `</div>`;
      });
      html += `</div>`;
      return html;
    }
  }

  function attachFieldEvents(container, stateObj, onChange, fieldKey) {
    const resolved = container.querySelector('.resolved-val');
    if (resolved) {
      resolved.addEventListener('dblclick', () => {
        const isMultiLine = stateObj.value.includes('\n') || container.id === 'ui-unc';
        const input = document.createElement(isMultiLine ? 'textarea' : 'input');
        if (!isMultiLine) input.type = 'text';
        input.value = stateObj.value;
        input.style.cssText = 'width:100%; padding:6px 8px; border:1px solid #2563eb; border-radius:8px; outline:none; font-family:inherit;';
        if (isMultiLine) input.style.minHeight = '60px';
        container.innerHTML = '';
        container.appendChild(input);
        const canOpenCalendar = !isMultiLine
          && (fieldKey === 'recovery_date' || fieldKey === 'ringing_date');

        if (canOpenCalendar && window.flatpickr) {
          const picker = window.flatpickr(input, {
            dateFormat: 'd.m.Y',
            allowInput: true,
            clickOpens: true,
            defaultDate: String(stateObj.value || '').trim()
          });
          if (picker && typeof picker.open === 'function') {
            picker.open();
          }
        }
        input.focus();
        input.addEventListener('blur', () => {
          stateObj.value = input.value;
          onChange();
        });
        input.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !isMultiLine) input.blur();
        });
      });
    }

    container.querySelectorAll('.conflict-val').forEach(el => {
      el.addEventListener('dblclick', () => {
        stateObj.value = el.getAttribute('data-val');
        stateObj.isResolved = true;
        onChange();
      });
    });
  }

  function checkAllResolved() {
    let resolved = true;
    for (const k in headerState) if (!headerState[k].isResolved) resolved = false;
    contentState.forEach(row => {
      for (const k in row) if (!row[k].isResolved) resolved = false;
    });
    recoveryState.forEach(row => {
      for (const k in row) if (!row[k].isResolved) resolved = false;
    });
    saveBtn.disabled = !resolved;
    if (!resolved) {
      saveBtn.style.opacity = '0.5';
      saveBtn.style.cursor = 'not-allowed';
      saveBtn.title = 'Resolve all red differences first';
    } else {
      saveBtn.style.opacity = '1';
      saveBtn.style.cursor = 'pointer';
      saveBtn.title = '';
    }
  }

  function combinedUncertaintiesText() {
    const ordered = Object.keys(uncertaintiesByJob)
      .map(jobId => ({ jobId, payload: uncertaintiesByJob[jobId] || {} }))
      .sort((a, b) => Number(a.jobId) - Number(b.jobId));
    const blocks = [];
    ordered.forEach(entry => {
      const model = String(entry.payload.model || data.jobModelMap?.[entry.jobId] || 'unknown');
      const lines = Array.isArray(entry.payload.lines) ? entry.payload.lines : [];
      const trimmed = lines.map(function (line) { return String(line || '').trim(); }).filter(Boolean);
      if (trimmed.length === 0) return;
      blocks.push(model + ':\n' + trimmed.join('\n'));
    });
    return blocks.join('\n\n');
  }

  function updateHiddenInputs() {
    document.getElementById('uncertainties').value = combinedUncertaintiesText();
    const byJobField = document.getElementById('uncertainties_by_job_json');
    if (byJobField) {
      byJobField.value = JSON.stringify(uncertaintiesByJob);
    }
    
    // Header is saved normally as individual inputs, so we need to inject hidden inputs for header
    document.querySelectorAll('.injected-header').forEach(el => el.remove());
    for (const k in headerState) {
        if (headerState[k].isResolved) {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = k;
            inp.value = headerState[k].value;
            inp.className = 'injected-header';
            document.getElementById('review-form').appendChild(inp);
        }
    }

    const cJSON = contentState.map(r => {
        const obj = {};
        for(let k in r) obj[k] = r[k].isResolved ? r[k].value : '';
        return obj;
    });
    document.getElementById('content_json').value = JSON.stringify(cJSON);

    const rJSON = recoveryState.map(r => {
        const obj = {};
        for(let k in r) obj[k] = r[k].isResolved ? r[k].value : '';
        return obj;
    });
    document.getElementById('recovery_json').value = JSON.stringify(rJSON);
  }

  function render() {
    let html = '';

    if (Array.isArray(data.models) && data.models.length > 0) {
      html += `<div class="section"><fieldset><legend>Models in comparison</legend><div style="display:flex;gap:8px;flex-wrap:wrap;">`;
      data.models.forEach(model => {
        html += modelPill(model);
      });
      html += `</div></fieldset></div>`;
    }
    
    // Uncertainties
    html += `<div class="section"><fieldset><legend>Decoding uncertainties</legend>`;
    const uncertaintyEntries = Object.keys(uncertaintiesByJob)
      .map(jobId => ({ jobId, payload: uncertaintiesByJob[jobId] || {} }))
      .sort((a, b) => Number(a.jobId) - Number(b.jobId));
    if (uncertaintyEntries.length === 0) {
      html += `<div style="border:1px solid #d7dce2;border-radius:10px;padding:10px;color:#9ca3af;font-style:italic;">No uncertainties</div>`;
    } else {
      uncertaintyEntries.forEach(entry => {
        const model = String(entry.payload.model || data.jobModelMap?.[entry.jobId] || 'unknown');
        const color = colorForModel(model);
        const lines = Array.isArray(entry.payload.lines) ? entry.payload.lines : [];
        const text = lines.length > 0 ? lines.join('\n') : '(empty)';
        html += `<div style="margin-bottom:8px;border:1px solid ${color}55;border-radius:10px;background:${color}12;padding:10px;">`
          + `<div style="margin-bottom:6px;">${modelPill(model)} <span style="color:#6b7280;font-size:0.8rem;">job #${esc(entry.jobId)}</span></div>`
          + `<pre style="margin:0;white-space:pre-wrap;font:inherit;">${esc(text)}</pre>`
          + `</div>`;
      });
    }
    html += `</fieldset></div>`;

    // Header
    html += `<div class="section"><fieldset><legend>Header</legend><div class="header-grid">`;
    const hLabels = {
      bird_id: 'BirdID', card_code: 'cardCode', sex: 'Sex', ring_position: 'ringPosition',
      ring_number: 'ringNumber', ringing_age: 'ringingAge', ringing_date: 'ringingDate',
      ringing_nest: 'ringingNest', scull_length: 'scullLength', scull_repeat: 'scullRepeat'
    };
    for (const k in hLabels) {
      html += `<div class="header-field"><label>${hLabels[k]}</label><div id="ui-h-${k}">${renderField(headerState[k])}</div></div>`;
    }
    html += `</div></fieldset></div>`;

    // Content
    const cCols = ['ring_position', 'ring_number', 'obs_status', 'obs_year', 'obs_nest', 'obs_notes'];
    const cHead = ['Position', 'Number', 'Status', 'Year', 'Nest', 'Notes', 'Ins', 'Del'];
    html += `<div class="section"><fieldset><legend>Content rows</legend>`;
    html += `<div style="overflow-x:auto;">`;
    html += `<table style="width:100%;border-collapse:collapse;margin-top:8px;">`;
    html += `<thead><tr style="background:#f6f9fc;">`;
    cHead.forEach(h => html += `<th style="border:1px solid #d7dce2;padding:8px;text-align:left;font-weight:700;font-size:0.85rem;">${h}</th>`);
    html += `</tr></thead><tbody>`;
    if (contentState.length === 0) {
      html += `<tr><td colspan="8" style="border:1px solid #d7dce2;padding:16px;text-align:center;color:#999;font-style:italic;">No content rows</td></tr>`;
    } else {
      contentState.forEach((row, i) => {
        html += `<tr style="${i % 2 ? 'background:#f9fafb;' : ''}">`;
        cCols.forEach(col => {
          html += `<td style="border:1px solid #d7dce2;padding:6px;vertical-align:top;"><div id="ui-c-${i}-${col}">${renderField(row[col])}</div></td>`;
        });
        html += `<td style="border:1px solid #d7dce2;padding:6px;text-align:center;vertical-align:top;"><button type="button" class="tiny-btn btn-insert-content-row" data-row="${i}" title="Insert row below">+</button></td>`;
        html += `<td style="border:1px solid #d7dce2;padding:6px;text-align:center;vertical-align:top;"><button type="button" class="tiny-btn btn-del-content-row" data-row="${i}" title="Delete content row">x</button></td>`;
        html += `</tr>`;
      });
    }
    html += `</tbody></table></div><div style="margin-top:8px;"><button type="button" class="tiny-btn btn-add-content-row">+ Add content row</button></div></fieldset></div>`;

    // Recovery
    const rCols = ['ring_number', 'recovery_status', 'recovery_date', 'recovery_location', 'recovery_person', 'recovery_notes'];
    const rHead = ['Number', 'Status', 'Date', 'Location', 'Person', 'Notes', 'Ins', 'Del'];
    html += `<div class="section"><fieldset><legend>Recovery rows</legend>`;
    html += `<div style="overflow-x:auto;">`;
    html += `<table style="width:100%;border-collapse:collapse;margin-top:8px;">`;
    html += `<thead><tr style="background:#f6f9fc;">`;
    rHead.forEach(h => html += `<th style="border:1px solid #d7dce2;padding:8px;text-align:left;font-weight:700;font-size:0.85rem;">${h}</th>`);
    html += `</tr></thead><tbody>`;
    if (recoveryState.length === 0) {
      html += `<tr><td colspan="8" style="border:1px solid #d7dce2;padding:16px;text-align:center;color:#999;font-style:italic;">No recovery rows</td></tr>`;
    } else {
      recoveryState.forEach((row, i) => {
        html += `<tr style="${i % 2 ? 'background:#f9fafb;' : ''}">`;
        rCols.forEach(col => {
          html += `<td style="border:1px solid #d7dce2;padding:6px;vertical-align:top;"><div id="ui-r-${i}-${col}">${renderField(row[col])}</div></td>`;
        });
        html += `<td style="border:1px solid #d7dce2;padding:6px;text-align:center;vertical-align:top;"><button type="button" class="tiny-btn btn-insert-recovery-row" data-row="${i}" title="Insert row below">+</button></td>`;
        html += `<td style="border:1px solid #d7dce2;padding:6px;text-align:center;vertical-align:top;"><button type="button" class="tiny-btn btn-del-recovery-row" data-row="${i}" title="Delete recovery row">x</button></td>`;
        html += `</tr>`;
      });
    }
    html += `</tbody></table></div><div style="margin-top:8px;"><button type="button" class="tiny-btn btn-add-recovery-row">+ Add recovery row</button></div></fieldset></div>`;

    editor.innerHTML = html;

    for (const k in hLabels) attachFieldEvents(document.getElementById(`ui-h-${k}`), headerState[k], () => renderAndCheck(), k);
    
    contentState.forEach((row, i) => {
      cCols.forEach(col => attachFieldEvents(document.getElementById(`ui-c-${i}-${col}`), row[col], () => renderAndCheck(), col));
    });
    
    recoveryState.forEach((row, i) => {
      rCols.forEach(col => attachFieldEvents(document.getElementById(`ui-r-${i}-${col}`), row[col], () => renderAndCheck(), col));
    });

    editor.querySelectorAll('.btn-add-content-row').forEach(btn => {
      btn.addEventListener('click', function () {
        const next = {};
        cCols.forEach(col => {
          next[col] = { value: '', isResolved: true };
        });
        contentState.push(next);
        renderAndCheck();
      });
    });

    editor.querySelectorAll('.btn-insert-content-row').forEach(btn => {
      btn.addEventListener('click', function () {
        const idx = Number(btn.getAttribute('data-row') || -1);
        if (idx < 0 || idx >= contentState.length) return;
        const next = {};
        cCols.forEach(col => {
          next[col] = { value: '', isResolved: true };
        });
        contentState.splice(idx + 1, 0, next);
        renderAndCheck();
      });
    });

    editor.querySelectorAll('.btn-del-content-row').forEach(btn => {
      btn.addEventListener('click', function () {
        const idx = Number(btn.getAttribute('data-row') || -1);
        if (idx < 0 || idx >= contentState.length) return;
        contentState.splice(idx, 1);
        renderAndCheck();
      });
    });

    editor.querySelectorAll('.btn-add-recovery-row').forEach(btn => {
      btn.addEventListener('click', function () {
        const next = {};
        rCols.forEach(col => {
          next[col] = { value: '', isResolved: true };
        });
        recoveryState.push(next);
        renderAndCheck();
      });
    });

    editor.querySelectorAll('.btn-insert-recovery-row').forEach(btn => {
      btn.addEventListener('click', function () {
        const idx = Number(btn.getAttribute('data-row') || -1);
        if (idx < 0 || idx >= recoveryState.length) return;
        const next = {};
        rCols.forEach(col => {
          next[col] = { value: '', isResolved: true };
        });
        recoveryState.splice(idx + 1, 0, next);
        renderAndCheck();
      });
    });

    editor.querySelectorAll('.btn-del-recovery-row').forEach(btn => {
      btn.addEventListener('click', function () {
        const idx = Number(btn.getAttribute('data-row') || -1);
        if (idx < 0 || idx >= recoveryState.length) return;
        recoveryState.splice(idx, 1);
        renderAndCheck();
      });
    });
  }

  function renderAndCheck() {
    render();
    checkAllResolved();
    updateHiddenInputs();
  }

  renderAndCheck();
})();
</script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
  flatpickr(".datepicker", {
    dateFormat: "d.m.Y",
    allowInput: true
  });
</script>
</body>
</html>
