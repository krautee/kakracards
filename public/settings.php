<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    savePromptText((string) ($_POST['prompt_text'] ?? ''));
    $message = 'Prompt saved.';
}

$promptText = getPromptText();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Settings</title><style>body{font-family:Arial,sans-serif;max-width:1100px;margin:20px auto;padding:0 16px}textarea{width:100%;min-height:420px}</style></head>
<body>
<p><a href="index.php">&larr; Back</a></p>
<h1>Prompt Settings</h1>
<?php if ($message !== ''): ?><p><strong><?= h($message) ?></strong></p><?php endif; ?>
<form method="post">
  <textarea name="prompt_text"><?= h($promptText) ?></textarea>
  <p><button type="submit">Save prompt</button></p>
</form>
</body>
</html>
