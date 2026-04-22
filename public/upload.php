<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$error = '';
$decoded = null;
$imagePath = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_record'])) {
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
        header('Location: edit.php?id=' . $id . '&saved=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['card_image']) && !isset($_POST['save_record'])) {
    if (!is_uploaded_file($_FILES['card_image']['tmp_name'] ?? '')) {
        $error = 'Please choose an image file.';
    } else {
        $targetName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $_FILES['card_image']['name']);
        $imagePath = rtrim(uploadDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $targetName;
        if (!move_uploaded_file($_FILES['card_image']['tmp_name'], $imagePath)) {
            $error = 'Failed to store uploaded image.';
        } else {
            try {
                $decoded = decodeImageWithPython($imagePath);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$header = is_array($decoded['header'] ?? null) ? $decoded['header'] : [];
$contentRows = is_array($decoded['content'] ?? null) ? $decoded['content'] : [];
$recoveryRows = is_array($decoded['recovery'] ?? null) ? $decoded['recovery'] : [];
$uncertaintiesText = is_array($decoded['uncertainties'] ?? null) ? implode("\n", $decoded['uncertainties']) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Upload & Decode</title>
  <style>body{font-family:Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 16px}input[type=text],textarea{width:100%;padding:6px}textarea{min-height:120px}fieldset{margin-bottom:12px}</style>
</head>
<body>
<p><a href="index.php">&larr; Back</a></p>
<h1>Upload & Decode</h1>
<?php if ($error !== ''): ?><p style="color:#a00;"><strong><?= h($error) ?></strong></p><?php endif; ?>

<?php if ($decoded === null): ?>
<form method="post" enctype="multipart/form-data">
  <p><input type="file" name="card_image" accept="image/*" required></p>
  <p><button type="submit">Decode with Gemini</button></p>
</form>
<?php else: ?>
<p>Review and correct values, then save.</p>
<form method="post">
  <input type="hidden" name="save_record" value="1">
  <input type="hidden" name="source_image_path" value="<?= h($imagePath) ?>">

  <fieldset>
    <legend>Header</legend>
    <label>BirdID <input type="text" name="bird_id" value="<?= h($header['bird_id'] ?? '') ?>"></label><br>
    <label>cardCode <input type="text" name="card_code" value="<?= h($header['card_code'] ?? '') ?>"></label><br>
    <label>Sex <input type="text" name="sex" value="<?= h($header['sex'] ?? '') ?>"></label><br>
    <label>ringPosition <input type="text" name="ring_position" value="<?= h($header['ring_position'] ?? '') ?>"></label><br>
    <label>ringNumber <input type="text" name="ring_number" value="<?= h($header['ring_number'] ?? '') ?>"></label><br>
    <label>ringingAge <input type="text" name="ringing_age" value="<?= h($header['ringing_age'] ?? '') ?>"></label><br>
    <label>ringingDate <input type="text" name="ringing_date" value="<?= h($header['ringing_date'] ?? '') ?>"></label><br>
    <label>ringingNest <input type="text" name="ringing_nest" value="<?= h($header['ringing_nest'] ?? '') ?>"></label><br>
    <label>scullLength <input type="text" name="scull_length" value="<?= h($header['scull_length'] ?? '') ?>"></label><br>
    <label>scullRepeat <input type="text" name="scull_repeat" value="<?= h($header['scull_repeat'] ?? '') ?>"></label>
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
    <textarea name="uncertainties"><?= h($uncertaintiesText) ?></textarea>
  </fieldset>

  <p><button type="submit">Accept and Save</button></p>
</form>
<?php endif; ?>
</body>
</html>
