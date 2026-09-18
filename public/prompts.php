<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Prompt versions: every distinct prompt text ever used (or present in prompts/) is a
// version keyed by its sha256. Files in prompts/ stay the editable source (Settings page
// or a code editor); this page is for seeing what exists, what changed between versions,
// how each performed, and for annotating versions with a changelog note.

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sha = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['sha'] ?? '')) ?? '';
    try {
        if (isset($_POST['save_notes'])) {
            if (getPromptVersion($sha) === null) {
                throw new RuntimeException('Unknown prompt version');
            }
            setPromptVersionNotes($sha, (string) ($_POST['notes'] ?? ''));
            $message = 'Note saved.';
        } elseif (isset($_POST['restore'])) {
            $version = getPromptVersion($sha);
            if ($version === null) {
                throw new RuntimeException('Unknown prompt version');
            }
            $base = preg_replace('/\.md$/i', '', basename((string) $version['prompt_name'])) ?: 'prompt';
            $filename = sanitizePromptFilename($base . '-' . substr($sha, 0, 8) . '.md');
            $target = promptDir() . '/' . $filename;
            if (is_file($target)) {
                throw new RuntimeException('File already exists: ' . $filename);
            }
            if (file_put_contents($target, (string) $version['prompt_text']) === false) {
                throw new RuntimeException('Could not write ' . $filename);
            }
            $message = 'Restored as prompts/' . $filename . ' — you can now select it in Settings or use it with benchmark.py --prompt.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$viewSha = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['view'] ?? '')) ?? '';
$diffA = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['a'] ?? '')) ?? '';
$diffB = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['b'] ?? '')) ?? '';

$versions = listPromptVersionsWithStats();
$byUsage = $versions;
$viewVersion = $viewSha !== '' ? getPromptVersion($viewSha) : null;
$versionA = $diffA !== '' ? getPromptVersion($diffA) : null;
$versionB = $diffB !== '' ? getPromptVersion($diffB) : null;
$diff = ($versionA && $versionB) ? lineDiff((string) $versionA['prompt_text'], (string) $versionB['prompt_text']) : [];
$activePromptFile = getCurrentPromptFileRelativePath();

function pv(?float $v): string
{
    return $v === null ? '-' : number_format($v, 1) . '%';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Prompt Versions</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:1700px;margin:18px auto;padding:0 16px;background:#f6f4ef;color:#1f2933}
    h1{margin:8px 0 14px}
    h2{margin:4px 0 10px;font-size:1.15rem}
    .nav a{display:inline-block;padding:6px 10px;background:#1155cc;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}
    .panel{background:#fff;border:1px solid #dbe2ea;border-radius:12px;padding:12px;margin-bottom:14px}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #dbe2ea;padding:6px 8px;vertical-align:top;font-size:.9rem}
    th{background:#f1f5f9;text-align:left}
    td.num{text-align:right;white-space:nowrap}
    .mono{font-family:ui-monospace,Menlo,Monaco,Consolas,monospace;font-size:.82rem}
    .muted{color:#6b7280}
    .pill{display:inline-block;padding:1px 7px;border-radius:999px;font-size:.75rem;font-weight:700}
    .pill-active{background:#dcfce7;color:#166534}
    .pill-file{background:#eef2ff;color:#3730a3}
    .pill-old{background:#f1f5f9;color:#475569}
    .btn{padding:7px 12px;border:0;border-radius:999px;background:#184d8d;color:#fff;font-weight:700;cursor:pointer;font-size:.85rem}
    .tiny-btn{padding:4px 9px;border:1px solid #c6d1df;border-radius:8px;background:#fff;cursor:pointer;font-size:.8rem;text-decoration:none;color:#1f2933;display:inline-block}
    textarea.notes{width:100%;min-width:220px;min-height:48px;box-sizing:border-box;padding:6px;border:1px solid #c7ced6;border-radius:8px;font:inherit;font-size:.85rem}
    pre.prompt{white-space:pre-wrap;background:#f8fafc;border:1px solid #dbe2ea;border-radius:10px;padding:12px;font-size:.85rem;max-height:70vh;overflow:auto}
    .diff{font-family:ui-monospace,Menlo,Monaco,Consolas,monospace;font-size:.82rem;border:1px solid #dbe2ea;border-radius:10px;overflow:auto;max-height:75vh}
    .diff div{padding:1px 10px;white-space:pre-wrap}
    .diff .del{background:#fee2e2;color:#7f1d1d}
    .diff .add{background:#dcfce7;color:#14532d}
    .diff .ctx{color:#475569}
    .diff .skip{background:#f1f5f9;color:#94a3b8;text-align:center}
    th.sortable{cursor:pointer;user-select:none}
    th.sortable::after{content:' \2195';color:#94a3b8;font-size:.75rem}
    th.sorted-asc::after{content:' \2191';color:#1d4ed8}
    th.sorted-desc::after{content:' \2193';color:#1d4ed8}
    .per-model{font-size:.78rem;color:#475569;white-space:nowrap}
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
<h1>Prompt Versions</h1>
<?php if ($message !== ''): ?><p style="color:#060;"><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($error !== ''): ?><p style="color:#a00;"><strong><?= h($error) ?></strong></p><?php endif; ?>

<div class="panel">
  <p class="muted" style="margin:0 0 10px;">Each distinct prompt text is one immutable version (identified by its sha256). Edit prompts as files in <span class="mono">prompts/</span> or on the Settings page; every change becomes a new version automatically the first time it is seen. Old versions stay comparable here and can be restored as a file. Accuracy = normalized field accuracy over all benchmark and review results made with that version.</p>
  <form method="get" id="diff-form" action="prompts.php#diff">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
      <span style="font-weight:700;">Compare:</span>
      <span class="muted">tick two versions (A = older, B = newer) and</span>
      <button class="btn" type="submit">Show diff</button>
    </div>
  </form>
    <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr>
          <th class="no-sort">A</th><th class="no-sort">B</th>
          <th>Name</th><th>Version</th><th>First seen</th><th>Status</th>
          <th>Jobs</th><th>Cards</th><th>Models</th><th>Best model</th><th>Best acc.</th><th>Mean acc.</th>
          <th class="no-sort">Change note</th><th class="no-sort"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($versions as $v): $sha = (string) $v['sha256']; ?>
        <tr>
          <td><input type="radio" form="diff-form" name="a" value="<?= h($sha) ?>" <?= $diffA === $sha ? 'checked' : '' ?>></td>
          <td><input type="radio" form="diff-form" name="b" value="<?= h($sha) ?>" <?= $diffB === $sha ? 'checked' : '' ?>></td>
          <td class="mono"><?= h(basename((string) $v['prompt_name'])) ?></td>
          <td class="mono" title="<?= h($sha) ?>"><?= h(substr($sha, 0, 10)) ?></td>
          <td class="muted" style="white-space:nowrap;"><?= h(substr((string) $v['first_seen_at'], 0, 16)) ?></td>
          <td>
            <?php if ($v['is_current_file'] !== null): ?>
              <?php if ($v['is_current_file'] === $activePromptFile): ?><span class="pill pill-active">active</span>
              <?php else: ?><span class="pill pill-file">file: <?= h(basename((string) $v['is_current_file'])) ?></span><?php endif; ?>
            <?php else: ?><span class="pill pill-old">superseded</span><?php endif; ?>
          </td>
          <td class="num"><?= (int) $v['jobs'] ?></td>
          <td class="num"><?= (int) $v['cards'] ?></td>
          <td class="num"><?= (int) $v['models'] ?></td>
          <td class="mono"><?= $v['best_model'] === null ? '-' : h($v['best_model']) ?></td>
          <td class="num"><?= pv($v['best_acc']) ?></td>
          <td class="num" title="<?php foreach ($v['per_model'] as $m => $a): ?><?= h($m) ?>: <?= number_format($a, 1) ?>%&#10;<?php endforeach; ?>"><?= pv($v['mean_acc']) ?></td>
          <td>
            <form method="post" style="margin:0;display:flex;flex-direction:column;gap:4px;">
              <input type="hidden" name="sha" value="<?= h($sha) ?>">
              <textarea class="notes" name="notes" placeholder="What changed and why"><?= h((string) ($v['notes'] ?? '')) ?></textarea>
              <div><button class="tiny-btn" type="submit" name="save_notes" value="1">Save note</button></div>
            </form>
          </td>
          <td style="white-space:nowrap;">
            <a class="tiny-btn" href="prompts.php?view=<?= h($sha) ?>#view">View</a>
            <?php if ($v['is_current_file'] === null): ?>
              <form method="post" style="margin:4px 0 0;"><input type="hidden" name="sha" value="<?= h($sha) ?>"><button class="tiny-btn" type="submit" name="restore" value="1">Restore as file</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
</div>

<?php if ($versionA && $versionB): ?>
<div class="panel" id="diff">
  <h2>Diff: <?= h(basename((string) $versionA['prompt_name'])) ?> <span class="mono muted"><?= h(substr($diffA, 0, 8)) ?></span> → <?= h(basename((string) $versionB['prompt_name'])) ?> <span class="mono muted"><?= h(substr($diffB, 0, 8)) ?></span></h2>
  <?php
    $changed = count(array_filter($diff, static fn($l) => $l['type'] !== ' '));
  ?>
  <p class="muted"><?= $changed === 0 ? 'The two versions are identical.' : $changed . ' changed line(s). Unchanged context is collapsed except around changes.' ?></p>
  <div class="diff">
    <?php
      $n = count($diff);
      $show = array_fill(0, $n, false);
      foreach ($diff as $i => $line) {
          if ($line['type'] !== ' ') {
              for ($k = max(0, $i - 2); $k <= min($n - 1, $i + 2); $k++) {
                  $show[$k] = true;
              }
          }
      }
      $skipping = false;
      foreach ($diff as $i => $line) {
          if (!$show[$i]) {
              if (!$skipping) {
                  echo '<div class="skip">…</div>';
                  $skipping = true;
              }
              continue;
          }
          $skipping = false;
          $cls = $line['type'] === '-' ? 'del' : ($line['type'] === '+' ? 'add' : 'ctx');
          echo '<div class="' . $cls . '">' . h($line['type'] . ' ' . $line['text']) . '</div>';
      }
    ?>
  </div>
</div>
<?php endif; ?>

<?php if ($viewVersion): ?>
<div class="panel" id="view">
  <h2><?= h((string) $viewVersion['prompt_name']) ?> <span class="mono muted"><?= h(substr($viewSha, 0, 10)) ?></span></h2>
  <?php if (trim((string) ($viewVersion['notes'] ?? '')) !== ''): ?><p><strong>Note:</strong> <?= nl2br(h((string) $viewVersion['notes'])) ?></p><?php endif; ?>
  <pre class="prompt"><?= h((string) $viewVersion['prompt_text']) ?></pre>
</div>
<?php endif; ?>

<script src="sortable.js"></script>
</body>
</html>
