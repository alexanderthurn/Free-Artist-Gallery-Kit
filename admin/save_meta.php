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

// Use thread-safe JSON update function to preserve all existing data
$metaPath = $imagePath.'.json';

// Prepare updates - only update form fields, preserve everything else
$updates = [
    'title' => trim((string)($_POST['title'] ?? '')),
    'description' => trim((string)($_POST['description'] ?? '')),
    'width' => trim((string)($_POST['width'] ?? '')),
    'height' => trim((string)($_POST['height'] ?? '')),
    'tags' => trim((string)($_POST['tags'] ?? '')),
    'date' => trim((string)($_POST['date'] ?? '')),
    'sold' => isset($_POST['sold']) && $_POST['sold'] === '1',
    'frame_type' => trim((string)($_POST['frame_type'] ?? 'white')),
];

// Set original_filename if not already present
// Load just to check, but update_json_file will handle it thread-safely
$existingMeta = [];
if (is_file($metaPath)) {
    $existingContent = @file_get_contents($metaPath);
    if ($existingContent !== false) {
        $decoded = json_decode($existingContent, true);
        if (is_array($decoded)) {
            $existingMeta = $decoded;
        }
    }
}

if (!isset($existingMeta['original_filename'])) {
    $updates['original_filename'] = extract_base_name($image);
}

// Update JSON file thread-safely (loads just before saving)
$ok = update_json_file($metaPath, $updates, false);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to write metadata']);
    exit;
}

// Reload to get updated data for gallery operations
$existingMeta = [];
if (is_file($metaPath)) {
    $existingContent = @file_get_contents($metaPath);
    if ($existingContent !== false) {
        $decoded = json_decode($existingContent, true);
        if (is_array($decoded)) {
            $existingMeta = $decoded;
        }
    }
}

// Use existingMeta for gallery operations
$data = $existingMeta;

// Check if this entry should be in gallery (live status from JSON or existing gallery entry)
$galleryDir = dirname(__DIR__).'/img/gallery/';
$originalFilename = $data['original_filename'];
$galleryFilename = find_gallery_entry($originalFilename, $galleryDir);
$inGallery = $galleryFilename !== null;

// If live is true but not in gallery, copy it
if (isset($data['live']) && $data['live'] === true && !$inGallery) {
    $imagesDir = __DIR__.'/images/';
    $result = update_gallery_entry($originalFilename, $data, $imagesDir, $galleryDir);
    if ($result['ok']) {
        $inGallery = true;
    }
} else if ($inGallery) {
    // If in gallery, automatically update using unified function
    $imagesDir = __DIR__.'/images/';
    update_gallery_entry($originalFilename, $data, $imagesDir, $galleryDir);
}

echo json_encode(['ok' => true, 'in_gallery' => $inGallery]);
exit;


