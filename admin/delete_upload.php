<?php
declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['filename'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing filename']);
    exit;
}

$filename = basename($_POST['filename']);
$uploadDir = dirname(__DIR__) . '/img/upload';
$filePath = $uploadDir . '/' . $filename;

// Validate filename - prevent directory traversal (basename already normalizes, but check for '..' as extra security)
if (strpos($filename, '..') !== false || $filename === '' || $filename === '.') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid filename']);
    exit;
}

// Check if file exists
if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'File not found']);
    exit;
}

// Delete file
if (!unlink($filePath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to delete file']);
    exit;
}

// Also delete thumbnail if it exists (pattern: filename_thumb.ext)
$pathInfo = pathinfo($filePath);
$thumbPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];
if (file_exists($thumbPath)) {
    unlink($thumbPath);
}

echo json_encode([
    'ok' => true,
    'message' => 'File deleted successfully'
]);

