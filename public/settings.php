<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$message = '';
$error = '';
$promptFiles = listPromptFiles();
$activePromptFile = getCurrentPromptFileRelativePath();
$selectedPromptFile = (string) ($_POST['prompt_file'] ?? $_GET['prompt_file'] ?? $activePromptFile);
if (!in_array($selectedPromptFile, $promptFiles, true)) {
  $selectedPromptFile = $activePromptFile;
}
$preferredModels = preferredGeminiModels();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $promptText = (string) ($_POST['prompt_text'] ?? '');
    $selectedModels = array_values(array_filter(array_map('trim', (array) ($_POST['gemini_models'] ?? [])), static fn($value) => $value !== ''));
    $saveAsFilename = trim((string) ($_POST['prompt_filename'] ?? ''));

    try {
        if ($saveAsFilename !== '') {
            $selectedPromptFile = savePromptTextToFile($promptText, $saveAsFilename, true);
        } else {
            $selectedPromptFile = savePromptTextToFile($promptText, basename($selectedPromptFile), true);
        }
        savePreferredGeminiModels($selectedModels);
        $promptFiles = listPromptFiles();
        $activePromptFile = getCurrentPromptFileRelativePath();
        $preferredModels = preferredGeminiModels();
        $message = 'Prompt and Gemini model preferences saved.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$promptText = getPromptTextFromRelativePath($selectedPromptFile);
$availableModels = allowedModels();
$preferredModels = preferredGeminiModels();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Settings</title><style>body{font-family:Arial,sans-serif;max-width:1700px;margin:18px auto;padding:0 16px;background:#f6f4ef;color:#1f2933}.nav a{display:inline-block;padding:6px 10px;background:#1155cc;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}textarea{width:100%;min-height:420px}</style></head>
<body>
<p class="nav"><a href="index.php">Home</a><a href="upload.php">Upload &amp; Decode</a><a href="settings.php">Settings (Prompt)</a><a href="stats.php">Statistics</a></p>
<h1>Prompt and Model Settings</h1>
<?php if ($message !== ''): ?><p><strong><?= h($message) ?></strong></p><?php endif; ?>
<?php if ($error !== ''): ?><p style="color:#a00;"><strong><?= h($error) ?></strong></p><?php endif; ?>
<form method="post">
  <p>
    <label for="prompt_file"><strong>Edit prompt file</strong></label><br>
    <select id="prompt_file" name="prompt_file">
      <?php foreach ($promptFiles as $promptFile): ?>
        <option value="<?= h($promptFile) ?>" <?= $promptFile === $selectedPromptFile ? 'selected' : '' ?>><?= h($promptFile) ?><?= $promptFile === $activePromptFile ? ' (active)' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </p>
  <p>
    <label for="prompt_filename"><strong>Save as new file (optional)</strong></label><br>
    <input id="prompt_filename" name="prompt_filename" type="text" placeholder="my_prompt.md">
  </p>
  <details style="border:1px solid #c7ced6; border-radius:10px; margin-bottom:16px; background:#f8fafc;" id="preferred-models-details">
    <summary style="font-weight:bold; padding:12px; cursor:pointer; user-select:none;"><strong>Preferred models</strong> (Gemini + OpenRouter)</summary>
    <div style="padding:0 12px 12px 12px; display:flex; flex-direction:column; gap:6px;">
      <?php foreach ($availableModels as $model): ?>
        <label style="display:flex; align-items:center; gap:8px;">
          <input type="checkbox" name="gemini_models[]" value="<?= h($model) ?>" <?= in_array($model, $preferredModels, true) ? 'checked' : '' ?>>
          <?= h($model) ?>
        </label>
      <?php endforeach; ?>
      <?php if (empty($availableModels)): ?>
        <p style="margin:10px 0 0;color:#a00;">Unable to fetch any models. Check GEMINI_API_KEY / OPENROUTER_API_KEY and OPENROUTER_MODELS in .env.</p>
      <?php endif; ?>
    </div>
  </details>
  <p style="margin-top:-6px;color:#555;">Saving this page writes prompt text to the selected file and updates the preferred models list.</p>
  <textarea name="prompt_text"><?= h($promptText) ?></textarea>
  <p><button type="submit">Save settings</button></p>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const select = document.getElementById('prompt_file');
  if (!select) return;
  select.addEventListener('change', function () {
    const url = new URL(window.location.href);
    url.searchParams.set('prompt_file', select.value);
    window.location.href = url.toString();
  });
});
</script>
</body>
</html>
