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

$image = isset($_POST['image']) ? basename((string)$_POST['image']) : '';
if ($image === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing image']);
    exit;
}

$imagesDir = __DIR__.'/images/';
$imagePath = $imagesDir.$image;
if (!is_file($imagePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Image not found']);
    exit;
}

// Load existing metadata to preserve original_filename if it exists
$metaPath = $imagePath.'.json';
$existingMeta = [];
if (is_file($metaPath)) {
    $existingContent = file_get_contents($metaPath);
    $existingMeta = json_decode($existingContent, true) ?? [];
}

$data = [
    'title' => trim((string)($_POST['title'] ?? '')),
    'description' => trim((string)($_POST['description'] ?? '')),
    'width' => trim((string)($_POST['width'] ?? '')),
    'height' => trim((string)($_POST['height'] ?? '')),
    'tags' => trim((string)($_POST['tags'] ?? '')),
    'date' => trim((string)($_POST['date'] ?? '')),
    'sold' => isset($_POST['sold']) && $_POST['sold'] === '1',
];

// Preserve original_filename if it exists in existing metadata, otherwise set it
if (isset($existingMeta['original_filename'])) {
    $data['original_filename'] = $existingMeta['original_filename'];
} else {
    // Set original_filename based on image base name
    $data['original_filename'] = extract_base_name($image);
}

// Preserve manual_corners if they exist
if (isset($existingMeta['manual_corners'])) {
    $data['manual_corners'] = $existingMeta['manual_corners'];
}

$ok = (bool)file_put_contents($metaPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to write metadata']);
    exit;
}

// Check if this entry is in gallery and update it automatically
$galleryDir = dirname(__DIR__).'/img/gallery/';
$originalFilename = $data['original_filename'];
$galleryFilename = find_gallery_entry($originalFilename, $galleryDir);
$inGallery = $galleryFilename !== null;

// If in gallery, automatically update using unified function
if ($inGallery) {
    $imagesDir = __DIR__.'/images/';
    update_gallery_entry($originalFilename, $data, $imagesDir, $galleryDir);
}

echo json_encode(['ok' => true, 'in_gallery' => $inGallery]);
exit;


