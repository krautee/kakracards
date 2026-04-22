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
if (!str_starts_with($path, uploadDir() . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Image not found');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
readfile($path);
