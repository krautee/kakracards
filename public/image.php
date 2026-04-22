<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Missing id');
}

$stmt = pdo()->prepare('SELECT source_image_path FROM cards_header WHERE id=:id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit('Not found');
}

$path = (string) $row['source_image_path'];
$realUploadDir = realpath(uploadDir());
$realPath = realpath($path);
if (
    $realUploadDir === false ||
    $realPath === false ||
    !is_file($realPath) ||
    !str_starts_with($realPath, rtrim($realUploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
) {
    http_response_code(404);
    exit('Image not found');
}

$mime = mime_content_type($realPath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($realPath));
readfile($realPath);
