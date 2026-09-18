<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$rows = pdo()->query(
    'SELECT id, bird_id, card_code, scull_length, ring_number, ringing_date, source_image_filename, created_at
     FROM cards_header ORDER BY id DESC LIMIT 500'
)->fetchAll();

// Decoding quality per card: every model's best run (highest normalized field accuracy
// across prompt versions), then the mean of the five best models. Using only the best
// models keeps a card's score from being dragged down by how many weak models happened
// to be tried on it; the "best" column shows the single best run.
$quality = [];
try {
    $perModel = pdo()->query(
        "SELECT header_id, model, MAX(acc) AS best_acc, COUNT(*) AS runs, COUNT(DISTINCT prompt_sha256) AS prompts
         FROM (
            SELECT header_id, model, decode_job_id, prompt_sha256,
                   100 * SUM(error_type <> 'both_empty' AND normalized_match = 1) / NULLIF(SUM(error_type <> 'both_empty'), 0) AS acc
            FROM decode_field_quality
            GROUP BY header_id, model, decode_job_id, prompt_sha256
         ) r
         GROUP BY header_id, model"
    )->fetchAll();
    $byHeader = [];
    foreach ($perModel as $r) {
        $h = (int) $r['header_id'];
        $byHeader[$h]['accs'][] = $r['best_acc'] === null ? null : (float) $r['best_acc'];
        $byHeader[$h]['runs'] = ($byHeader[$h]['runs'] ?? 0) + (int) $r['runs'];
        $byHeader[$h]['models'] = ($byHeader[$h]['models'] ?? 0) + 1;
    }
    $promptCounts = pdo()->query(
        'SELECT header_id, COUNT(DISTINCT prompt_sha256) AS prompts FROM decode_field_quality WHERE prompt_sha256 IS NOT NULL GROUP BY header_id'
    )->fetchAll();
    foreach ($promptCounts as $r) {
        $byHeader[(int) $r['header_id']]['prompts'] = (int) $r['prompts'];
    }
    foreach ($byHeader as $h => $data) {
        $accs = array_values(array_filter($data['accs'] ?? [], static fn($v) => $v !== null));
        rsort($accs);
        $top = array_slice($accs, 0, 5);
        $quality[$h] = [
            'runs' => (int) ($data['runs'] ?? 0),
            'models' => (int) ($data['models'] ?? 0),
            'prompts' => (int) ($data['prompts'] ?? 0),
            'top5' => $top ? array_sum($top) / count($top) : null,
            'best' => $accs ? $accs[0] : null,
        ];
    }
} catch (Throwable) {
    $quality = [];
}

function scoreStyle(?float $pct): string
{
    if ($pct === null) {
        return 'color:#94a3b8';
    }
    $t = max(0.0, min(1.0, ($pct - 50) / 50));
    $hue = (int) round(120 * $t);
    return "background:hsl({$hue}, 70%, 84%);font-weight:700";
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>KakraCards</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:1700px;margin:18px auto;padding:0 16px;background:#f6f4ef;color:#1f2933}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #ddd;padding:6px 8px;vertical-align:middle;font-size:.92rem}
    th{background:#f4f4f4;text-align:left;white-space:nowrap}
    td.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
    td.narrow,th.narrow{width:1%;white-space:nowrap}
    .nav a{display:inline-block;padding:6px 10px;background:#1155cc;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}
    .image-link{display:inline-block;width:28px;height:24px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;text-align:center;line-height:24px;cursor:pointer;text-decoration:none}
    .image-link:hover{background:#eef2ff}
    .image-link svg{width:16px;height:16px;vertical-align:middle;fill:none;stroke:#1d4f91;stroke-width:1.8}
    #hover-modal{position:fixed;display:none;z-index:9999;pointer-events:none;background:#fff;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 14px 40px rgba(0,0,0,.2);padding:8px}
    #hover-modal img{display:block;max-width:760px;max-height:760px;border-radius:6px}
    #hover-modal .caption{font-size:.8rem;color:#374151;margin-top:6px;text-align:center}
    .muted{color:#6b7280}
    .legend{color:#6b7280;font-size:.85rem;margin:6px 0 12px}
    th.sortable{cursor:pointer;user-select:none}
    th.sortable:hover{background:#e8eef8}
    th.sortable::after{content:' \2195';color:#94a3b8;font-size:.75rem}
    th.sorted-asc::after{content:' \2191';color:#1d4ed8}
    th.sorted-desc::after{content:' \2193';color:#1d4ed8}
  </style>
</head>
<body>
<h1>KakraCards Decoder</h1>
<p class="nav">
  <a href="index.php">Home</a>
  <a href="upload.php">Upload &amp; Decode</a>
  <a href="settings.php">Settings (Prompt)</a>
  <a href="prompts.php">Prompts</a>
  <a href="stats.php">Statistics</a>
</p>
<p class="legend">Saved (reviewed) records. Hover the camera icon to preview the card, click a column header to sort. <strong>Top-5 score</strong> = mean accuracy of the five best models on that card (each model's best run across prompt versions); <strong>best</strong> = the single best run. Low scores across the board point at a hard card; a low score with many runs means the tested models were mostly weak ones.</p>
<table id="cards-table">
  <thead>
    <tr>
      <th class="narrow">ID</th>
      <th class="narrow no-sort">Image</th>
      <th class="narrow">BirdID</th>
      <th class="narrow">Card code</th>
      <th class="narrow">Skull</th>
      <th class="narrow">Ring</th>
      <th class="narrow">Ringed</th>
      <th class="narrow" title="Decode runs scored against this record (review + benchmark)">Runs</th>
      <th class="narrow" title="Distinct models tried">Models</th>
      <th class="narrow" title="Distinct prompt versions tried">Prompts</th>
      <th class="narrow" title="Mean accuracy of the 5 best models on this card">Top-5 score</th>
      <th class="narrow" title="Best single run on this card">Best</th>
      <th class="narrow">Created</th>
      <th class="narrow no-sort">Action</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $row): $q = $quality[(int) $row['id']] ?? null; ?>
    <tr>
      <td class="num"><?= (int) $row['id'] ?></td>
      <td class="narrow">
        <a class="image-link" href="image.php?id=<?= (int) $row['id'] ?>" data-image-url="image.php?id=<?= (int) $row['id'] ?>" data-caption="<?= h((string) $row['source_image_filename']) ?><?= $q && $q['top5'] !== null ? ' · top-5 ' . number_format($q['top5'], 0) . '% · best ' . number_format((float) $q['best'], 0) . '%' : '' ?>" title="<?= h((string) $row['source_image_filename']) ?>" target="_blank">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
        </a>
      </td>
      <td class="narrow"><?= h((string) $row['bird_id']) ?></td>
      <td class="narrow"><?= h((string) $row['card_code']) ?></td>
      <td class="num"><?= h((string) $row['scull_length']) ?></td>
      <td class="narrow"><?= h((string) $row['ring_number']) ?></td>
      <td class="narrow muted"><?= h((string) $row['ringing_date']) ?></td>
      <td class="num"><?= $q ? $q['runs'] : '<span class="muted">-</span>' ?></td>
      <td class="num"><?= $q ? $q['models'] : '<span class="muted">-</span>' ?></td>
      <td class="num"><?= $q ? $q['prompts'] : '<span class="muted">-</span>' ?></td>
      <td class="num" style="<?= scoreStyle($q['top5'] ?? null) ?>"><?= $q && $q['top5'] !== null ? number_format($q['top5'], 1) . '%' : '-' ?></td>
      <td class="num" style="<?= scoreStyle($q['best'] ?? null) ?>"><?= $q && $q['best'] !== null ? number_format($q['best'], 1) . '%' : '-' ?></td>
      <td class="narrow muted"><?= h(substr((string) $row['created_at'], 0, 16)) ?></td>
      <td class="narrow"><a href="edit.php?id=<?= (int) $row['id'] ?>">Open/Edit</a> · <a href="edit.php?id=<?= (int) $row['id'] ?>#benchmark">Benchmark</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div id="hover-modal"><img src="" alt="Card image preview"><div class="caption"></div></div>

<script src="sortable.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('hover-modal');
  const modalImg = modal ? modal.querySelector('img') : null;
  const caption = modal ? modal.querySelector('.caption') : null;

  // Keep the preview inside the viewport: prefer below-right of the cursor, flip to the
  // left / above when it would not fit, and clamp as a last resort.
  function placeModal(e) {
    if (!modal) return;
    const w = modal.offsetWidth;
    const h = modal.offsetHeight;
    const pad = 12;
    let left = e.clientX + 18;
    let top = e.clientY + 18;
    if (left + w + pad > window.innerWidth) left = e.clientX - w - 18;
    if (top + h + pad > window.innerHeight) top = e.clientY - h - 18;
    left = Math.max(pad, Math.min(left, window.innerWidth - w - pad));
    top = Math.max(pad, Math.min(top, window.innerHeight - h - pad));
    modal.style.left = left + 'px';
    modal.style.top = top + 'px';
  }

  document.querySelectorAll('.image-link').forEach(function (link) {
    link.addEventListener('mouseenter', function (e) {
      if (!modal || !modalImg) return;
      modalImg.src = link.dataset.imageUrl || link.getAttribute('href');
      if (caption) caption.textContent = link.dataset.caption || '';
      modal.style.display = 'block';
      placeModal(e);
      modalImg.onload = function () { placeModal(e); };
    });
    link.addEventListener('mousemove', placeModal);
    link.addEventListener('mouseleave', function () {
      if (!modal || !modalImg) return;
      modal.style.display = 'none';
      modalImg.src = '';
    });
  });
});
</script>
</body>
</html>
