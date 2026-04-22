<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$rows = pdo()->query('SELECT id, bird_id, card_code, ring_number, source_image_filename, created_at FROM cards_header ORDER BY id DESC LIMIT 200')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>KakraCards</title>
  <style>body{font-family:Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 16px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px}th{background:#f4f4f4}a.button{display:inline-block;padding:6px 10px;background:#1155cc;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}</style>
</head>
<body>
<h1>KakraCards Decoder</h1>
<p>
  <a class="button" href="upload.php">Upload & Decode</a>
  <a class="button" href="settings.php">Settings (Prompt)</a>
</p>
<table>
  <thead>
    <tr><th>ID</th><th>BirdID</th><th>Card Code</th><th>Ring</th><th>Image</th><th>Created</th><th>Action</th></tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= (int) $row['id'] ?></td>
      <td><?= h($row['bird_id']) ?></td>
      <td><?= h($row['card_code']) ?></td>
      <td><?= h($row['ring_number']) ?></td>
      <td><?= h($row['source_image_filename']) ?></td>
      <td><?= h($row['created_at']) ?></td>
      <td><a href="edit.php?id=<?= (int) $row['id'] ?>">Open/Edit</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
