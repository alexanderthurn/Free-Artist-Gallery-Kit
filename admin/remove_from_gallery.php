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
$galleryDir = dirname(__DIR__).'/img/gallery/';

// Extract base name from image
$base = extract_base_name($image);

// Find JSON file to get original_filename
$jsonFile = find_json_file($base, $imagesDir);
$originalFilename = $base;
$meta = [];

if ($jsonFile && is_file($imagesDir.$jsonFile)) {
    $jsonContent = file_get_contents($imagesDir.$jsonFile);
    $meta = json_decode($jsonContent, true);
    if (is_array($meta) && isset($meta['original_filename'])) {
        $originalFilename = $meta['original_filename'];
    }
}

// Find gallery entry by original_filename or base name
$galleryFilename = find_gallery_entry($originalFilename, $galleryDir);
if (!$galleryFilename) {
    $galleryFilename = find_gallery_entry($base, $galleryDir);
}

if (!$galleryFilename) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Entry not found in gallery']);
    exit;
}

// Delete all files with this base name in gallery (image, json, and variants)
$deleted = [];
$galleryFiles = scandir($galleryDir) ?: [];
foreach ($galleryFiles as $gFile) {
    if ($gFile === '.' || $gFile === '..') continue;
    $fileStem = pathinfo($gFile, PATHINFO_FILENAME);
    // Check if file starts with gallery filename
    if (strpos($fileStem, $galleryFilename) === 0) {
        $filePath = $galleryDir.$gFile;
        if (is_file($filePath) && unlink($filePath)) {
            $deleted[] = $gFile;
        }
    }
}

if (empty($deleted)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to delete files']);
    exit;
}

// Update JSON file to set live status to false
if ($jsonFile && is_file($imagesDir.$jsonFile) && !empty($meta)) {
    $meta['live'] = false;
    $updatedJsonContent = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($imagesDir.$jsonFile, $updatedJsonContent);
}

echo json_encode([
    'ok' => true,
    'deleted' => $deleted
]);
exit;

