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

// Find JSON file to load metadata
$jsonFile = find_json_file($base, $imagesDir);

if (!$jsonFile || !is_file($imagesDir.$jsonFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'JSON metadata not found']);
    exit;
}

// Load JSON to get metadata
$jsonContent = file_get_contents($imagesDir.$jsonFile);
$meta = json_decode($jsonContent, true);
if (!is_array($meta)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON metadata']);
    exit;
}

// Set live status to true (thread-safe)
update_json_file($imagesDir.$jsonFile, ['live' => true], false);

// Reload meta for gallery operations
$metaContent = @file_get_contents($imagesDir.$jsonFile);
if ($metaContent !== false) {
    $decoded = json_decode($metaContent, true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}
$meta['live'] = true;

// Use unified function to update gallery entry
$result = update_gallery_entry($base, $meta, $imagesDir, $galleryDir);

if (!$result['ok']) {
    http_response_code(500);
    echo json_encode($result);
    exit;
}

echo json_encode($result);
exit;

