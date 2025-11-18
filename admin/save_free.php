<?php
declare(strict_types=1);

require_once __DIR__.'/utils.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$base = isset($_POST['base']) ? trim((string)$_POST['base']) : '';
if ($base === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing base parameter']);
    exit;
}

$cornersJson = isset($_POST['corners']) ? trim((string)$_POST['corners']) : '';
$corners = [];
if ($cornersJson !== '') {
    $decoded = json_decode($cornersJson, true);
    if (is_array($decoded) && count($decoded) === 4) {
        $corners = $decoded;
    }
}

$imagesDir = __DIR__.'/images/';

// Handle uploaded image
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No image uploaded or upload error']);
    exit;
}

$uploadedFile = $_FILES['image'];
$tempPath = $uploadedFile['tmp_name'];

// Validate it's an image
$imageInfo = @getimagesize($tempPath);
if ($imageInfo === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid image file']);
    exit;
}

// Save as _final.jpg
$finalFilename = $base . '_final.jpg';
$finalPath = $imagesDir . $finalFilename;

// Move uploaded file to final location
if (!move_uploaded_file($tempPath, $finalPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save image']);
    exit;
}

// Update metadata with corner positions
$metaPath = $imagesDir . $base . '_original.jpg.json';
$meta = [];
if (is_file($metaPath)) {
    $existingContent = file_get_contents($metaPath);
    $meta = json_decode($existingContent, true) ?? [];
}

// Add corner positions to metadata
if (!empty($corners)) {
    $meta['manual_corners'] = $corners;
}

// Preserve original_filename if it exists
if (!isset($meta['original_filename'])) {
    $meta['original_filename'] = $base;
}

// Save updated metadata
file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Check if this entry is in gallery and update it automatically
$galleryDir = dirname(__DIR__).'/img/gallery/';
$originalFilename = $meta['original_filename'];
$galleryFilename = find_gallery_entry($originalFilename, $galleryDir);
$inGallery = $galleryFilename !== null;

// If in gallery, automatically update using unified function
if ($inGallery) {
    update_gallery_entry($originalFilename, $meta, $imagesDir, $galleryDir);
}

echo json_encode(['ok' => true, 'in_gallery' => $inGallery]);
exit;

