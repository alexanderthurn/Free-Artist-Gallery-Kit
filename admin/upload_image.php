<?php
declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Support both 'image' and 'file' field names
$file = null;
if (isset($_FILES['image'])) {
    $file = $_FILES['image'];
} elseif (isset($_FILES['file'])) {
    $file = $_FILES['file'];
}

if (!$file) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Upload error: ' . $file['error']]);
    exit;
}

// File type validation removed - all file types are now accepted

// Validate file size (max 10MB)
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File too large (max 10MB)']);
    exit;
}

// Ensure upload directory exists
$uploadDir = dirname(__DIR__) . '/img/upload';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to create upload directory']);
        exit;
    }
}

// Use original filename, but handle conflicts
$originalFilename = basename($file['name']);
$filename = $originalFilename;
$targetPath = $uploadDir . '/' . $filename;

// If file already exists, append a number to make it unique
$counter = 1;
while (file_exists($targetPath)) {
    $pathInfo = pathinfo($originalFilename);
    $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
    $baseName = $pathInfo['filename'];
    $filename = $baseName . '_' . $counter . $extension;
    $targetPath = $uploadDir . '/' . $filename;
    $counter++;
}

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save image']);
    exit;
}

// Return URL relative to site root
$url = '/img/upload/' . $filename;

echo json_encode([
    'ok' => true,
    'url' => $url,
    'filename' => $filename
]);

