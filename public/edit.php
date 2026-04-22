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

$contentStmt = pdo()->prepare('SELECT ring_position, ring_number, obs_status, obs_year, obs_nest, obs_notes, decoding_status FROM cards_content WHERE header_id=:id ORDER BY row_no');
$contentStmt->execute(['id' => $id]);
$contentRows = $contentStmt->fetchAll();

$recoveryStmt = pdo()->prepare('SELECT ring_number, recovery_status, recovery_date, recovery_location, recovery_person, recovery_notes FROM cards_recovery WHERE header_id=:id ORDER BY row_no');
$recoveryStmt->execute(['id' => $id]);
$recoveryRows = $recoveryStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Edit Record</title><style>body{font-family:Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 16px}input[type=text],textarea{width:100%;padding:6px}textarea{min-height:120px}fieldset{margin-bottom:12px}img{max-width:100%;height:auto;border:1px solid #ddd}</style></head>
<body>
<p><a href="index.php">&larr; Back</a></p>
<h1>Edit Record #<?= $id ?></h1>
<?php if ($message !== ''): ?><p style="color:#060;"><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($error !== ''): ?><p style="color:#a00;"><strong><?= h($error) ?></strong></p><?php endif; ?>

<?php $imagePath = (string) $header['source_image_path']; ?>
<?php if ($imagePath !== '' && is_file($imagePath)): ?>
  <p><img src="image.php?id=<?= $id ?>" alt="Source image"></p>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="id" value="<?= $id ?>">
  <fieldset>
    <legend>Header</legend>
    <label>BirdID <input type="text" name="bird_id" value="<?= h($header['bird_id']) ?>"></label><br>
    <label>cardCode <input type="text" name="card_code" value="<?= h($header['card_code']) ?>"></label><br>
    <label>Sex <input type="text" name="sex" value="<?= h($header['sex']) ?>"></label><br>
    <label>ringPosition <input type="text" name="ring_position" value="<?= h($header['ring_position']) ?>"></label><br>
    <label>ringNumber <input type="text" name="ring_number" value="<?= h($header['ring_number']) ?>"></label><br>
    <label>ringingAge <input type="text" name="ringing_age" value="<?= h($header['ringing_age']) ?>"></label><br>
    <label>ringingDate <input type="text" name="ringing_date" value="<?= h($header['ringing_date']) ?>"></label><br>
    <label>ringingNest <input type="text" name="ringing_nest" value="<?= h($header['ringing_nest']) ?>"></label><br>
    <label>scullLength <input type="text" name="scull_length" value="<?= h($header['scull_length']) ?>"></label><br>
    <label>scullRepeat <input type="text" name="scull_repeat" value="<?= h($header['scull_repeat']) ?>"></label>
  </fieldset>

  <fieldset>
    <legend>Content rows (JSON array)</legend>
    <textarea name="content_json"><?= h(json_encode($contentRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
  </fieldset>

  <fieldset>
    <legend>Recovery rows (JSON array)</legend>
    <textarea name="recovery_json"><?= h(json_encode($recoveryRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
  </fieldset>

  <fieldset>
    <legend>Decoding uncertainties (one line each)</legend>
    <textarea name="uncertainties"><?= h((string) $header['uncertainties']) ?></textarea>
  </fieldset>

  <p><button type="submit">Save changes</button></p>
</form>
</body>
</html>
