<?php
// Delete a variant image from the images directory
declare(strict_types=1);

require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');

$filename = trim($_POST['filename'] ?? '');
if ($filename === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'filename required']);
    exit;
}

$imagesDir = __DIR__ . '/images';
$filepath = $imagesDir . '/' . basename($filename);

// Security: only allow deleting files in images directory
if (strpos(realpath($filepath), realpath($imagesDir)) !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid path']);
    exit;
}

if (!is_file($filepath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'file not found']);
    exit;
}

// Only allow deleting variant files (those with _variant_ in the name)
if (strpos($filename, '_variant_') === false) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'can only delete variant files']);
    exit;
}

// Extract base name before deleting
$base = extract_base_name($filename);
// Remove _variant_ part if present
$variantPos = strpos($base, '_variant_');
if ($variantPos !== false) {
    $base = substr($base, 0, $variantPos);
}

if (!unlink($filepath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'failed to delete']);
    exit;
}

// Check if this image is already in gallery and update it
$galleryDir = dirname(__DIR__) . '/img/gallery/';
$galleryFilename = find_gallery_entry($base, $galleryDir);
$inGallery = $galleryFilename !== null;

// If in gallery, update the gallery entry to remove the deleted variant
if ($inGallery) {
    // Load metadata from images directory
    $jsonFile = find_json_file($base, $imagesDir);
    $metaFile = $jsonFile ? $imagesDir . '/' . $jsonFile : null;
    
    if (is_file($metaFile)) {
        $metaContent = file_get_contents($metaFile);
        $meta = json_decode($metaContent, true);
        if (is_array($meta)) {
            update_gallery_entry($base, $meta, $imagesDir, $galleryDir);
        }
    }
    
    // Trigger optimization to ensure gallery is updated
    async_http_post('admin/optimize_images.php', ['action' => 'both', 'force' => '1']);
}

echo json_encode(['ok' => true, 'filename' => $filename, 'gallery_updated' => $inGallery]);

