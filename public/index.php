<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'inline_update') {
    header('Content-Type: application/json');
    $id = (int) ($_POST['id'] ?? 0);
    $field = (string) ($_POST['field'] ?? '');
    $value = trim((string) ($_POST['value'] ?? ''));
    $allowed = ['bird_id', 'card_code', 'scull_length'];

    if ($id <= 0 || !in_array($field, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }

    $sql = 'UPDATE cards_header SET ' . $field . '=:value, updated_at=CURRENT_TIMESTAMP WHERE id=:id';
    $stmt = pdo()->prepare($sql);
    $stmt->execute(['id' => $id, 'value' => $value]);
    echo json_encode(['ok' => true]);
    exit;
}

$rows = pdo()->query('SELECT id, bird_id, card_code, scull_length, ring_number, source_image_filename, created_at FROM cards_header ORDER BY id DESC LIMIT 200')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>KakraCards</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:1700px;margin:18px auto;padding:0 16px;background:#f6f4ef;color:#1f2933}
    table{border-collapse:collapse;width:100%}
    th,td{border:1px solid #ddd;padding:8px;vertical-align:middle}
    th{background:#f4f4f4}
    .nav a{display:inline-block;padding:6px 10px;background:#1155cc;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}
    .sort-header{cursor:pointer;user-select:none}
    .sort-header:hover{background:#e8eef8}
    .inline-cell{width:100%;box-sizing:border-box;padding:5px 7px;border:1px solid #cfd7e3;border-radius:6px}
    .inline-cell.saving{background:#fff3cd}
    .inline-cell.saved{background:#d1fae5}
    .image-link{color:#1d4f91;text-decoration:underline;cursor:pointer}
    #hover-modal{position:fixed;display:none;z-index:9999;pointer-events:none;background:#fff;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 14px 40px rgba(0,0,0,.2);padding:8px}
    #hover-modal img{display:block;max-width:760px;max-height:760px;border-radius:6px}
  </style>
</head>
<body>
<h1>KakraCards Decoder</h1>
<p class="nav">
  <a href="index.php">Home</a>
  <a href="upload.php">Upload &amp; Decode</a>
  <a href="settings.php">Settings (Prompt)</a>
  <a href="stats.php">Statistics</a>
</p>
<table id="cards-table">
  <thead>
    <tr>
      <th>ID</th>
      <th class="sort-header" data-key="bird_id" data-type="string">BirdID</th>
      <th class="sort-header" data-key="card_code" data-type="string">Card Code</th>
      <th class="sort-header" data-key="scull_length" data-type="number">Scull Length</th>
      <th>Ring</th>
      <th>Image</th>
      <th>Created</th>
      <th>Action</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr data-bird_id="<?= h((string) $row['bird_id']) ?>" data-card_code="<?= h((string) $row['card_code']) ?>" data-scull_length="<?= h((string) $row['scull_length']) ?>">
      <td><?= (int) $row['id'] ?></td>
      <td><input class="inline-cell inline-edit" data-id="<?= (int) $row['id'] ?>" data-field="bird_id" value="<?= h((string) $row['bird_id']) ?>"></td>
      <td><input class="inline-cell inline-edit" data-id="<?= (int) $row['id'] ?>" data-field="card_code" value="<?= h((string) $row['card_code']) ?>"></td>
      <td><input class="inline-cell inline-edit" data-id="<?= (int) $row['id'] ?>" data-field="scull_length" value="<?= h((string) $row['scull_length']) ?>"></td>
      <td><?= h($row['ring_number']) ?></td>
      <td>
        <a class="image-link" href="image.php?id=<?= (int) $row['id'] ?>" data-image-url="image.php?id=<?= (int) $row['id'] ?>">
          <?= h($row['source_image_filename']) ?>
        </a>
      </td>
      <td><?= h($row['created_at']) ?></td>
      <td><a href="edit.php?id=<?= (int) $row['id'] ?>">Open/Edit</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div id="hover-modal"><img src="" alt="Card image preview"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const table = document.getElementById('cards-table');
  if (!table) return;

  const tbody = table.querySelector('tbody');
  const modal = document.getElementById('hover-modal');
  const modalImg = modal ? modal.querySelector('img') : null;
  const sortState = {};

  function sortTable(key, type) {
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const nextDir = sortState[key] === 'asc' ? 'desc' : 'asc';
    sortState[key] = nextDir;

    rows.sort(function (a, b) {
      let av = (a.dataset[key] || '').trim();
      let bv = (b.dataset[key] || '').trim();
      if (type === 'number') {
        av = Number(av || 0);
        bv = Number(bv || 0);
      } else {
        av = av.toLowerCase();
        bv = bv.toLowerCase();
      }
      if (av < bv) return nextDir === 'asc' ? -1 : 1;
      if (av > bv) return nextDir === 'asc' ? 1 : -1;
      return 0;
    });

    rows.forEach(function (row) {
      tbody.appendChild(row);
    });
  }

  table.querySelectorAll('.sort-header').forEach(function (th) {
    th.addEventListener('click', function () {
      sortTable(th.dataset.key, th.dataset.type || 'string');
    });
  });

  table.querySelectorAll('.inline-edit').forEach(function (input) {
    input.addEventListener('change', function () {
      const id = input.dataset.id;
      const field = input.dataset.field;
      const value = input.value;
      const row = input.closest('tr');
      if (!id || !field || !row) return;

      input.classList.add('saving');
      const body = new URLSearchParams();
      body.set('action', 'inline_update');
      body.set('id', id);
      body.set('field', field);
      body.set('value', value);

      fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        input.classList.remove('saving');
        if (!data || !data.ok) {
          alert('Failed to save inline edit');
          return;
        }
        row.dataset[field] = value;
        input.classList.add('saved');
        setTimeout(function () { input.classList.remove('saved'); }, 700);
      })
      .catch(function () {
        input.classList.remove('saving');
        alert('Failed to save inline edit');
      });
    });
  });

  table.querySelectorAll('.image-link').forEach(function (link) {
    link.addEventListener('mouseenter', function () {
      if (!modal || !modalImg) return;
      modalImg.src = link.dataset.imageUrl || link.getAttribute('href');
      modal.style.display = 'block';
    });
    link.addEventListener('mousemove', function (e) {
      if (!modal) return;
      modal.style.left = (e.clientX + 18) + 'px';
      modal.style.top = (e.clientY + 18) + 'px';
    });
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
