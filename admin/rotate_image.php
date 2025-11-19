<?php
declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['image'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing image parameter']);
    exit;
}

require_once __DIR__ . '/utils.php';

$imageName = basename($_POST['image']);
$imagesDir = __DIR__ . '/images/';
$imagePath = $imagesDir . $imageName;

// Validate that it's a _final image
if (strpos($imageName, '_final.') === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Only _final images can be rotated']);
    exit;
}

// Check if file exists
if (!file_exists($imagePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'File not found']);
    exit;
}

// Load the image
$src = image_create_from_any($imagePath);
if (!$src) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load image']);
    exit;
}

// Rotate 90 degrees clockwise (imagerotate uses counter-clockwise, so use -90)
// Use white background (0xFFFFFF) for JPEG images to avoid black edges
$bgColor = imagecolorallocate($src, 255, 255, 255);
$rotated = imagerotate($src, -90, $bgColor);
imagedestroy($src);

if (!$rotated) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to rotate image']);
    exit;
}

// Save the rotated image
$ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
$quality = ($ext === 'webp') ? 80 : 90;

// Save the image (function returns void, so we check file existence after)
try {
    image_save_as($imagePath, $rotated, $quality);
    
    // Verify the file was saved successfully
    if (!file_exists($imagePath) || filesize($imagePath) === 0) {
        imagedestroy($rotated);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save rotated image - file not created or empty']);
        exit;
    }
} catch (Exception $e) {
    imagedestroy($rotated);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save rotated image: ' . $e->getMessage()]);
    exit;
}

imagedestroy($rotated);

// Regenerate thumbnail for the rotated _final image
$thumbPath = generate_thumbnail_path($imagePath);
if (file_exists($thumbPath)) {
    // Delete old thumbnail
    @unlink($thumbPath);
}
generate_thumbnail($imagePath, $thumbPath, 512, 1024);

// Extract base name to regenerate all variant thumbnails
// Remove _final suffix and extension to get base name
$base = str_replace('_final.' . $ext, '', $imageName);

// Regenerate thumbnails for all variants of this image
$files = scandir($imagesDir) ?: [];
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    if (strpos($file, '_thumb.') !== false) continue; // Skip existing thumbnails
    
    $fileStem = pathinfo($file, PATHINFO_FILENAME);
    // Check if this file belongs to the same base (starts with base and has underscore after)
    // This ensures we match base_original.jpg, base_final.jpg, base_variant_xxx.jpg but not base_other.jpg if base_other exists
    if (strpos($fileStem, $base . '_') === 0) {
        $variantPath = $imagesDir . $file;
        $variantThumbPath = generate_thumbnail_path($variantPath);
        
        // Delete old thumbnail if exists
        if (file_exists($variantThumbPath)) {
            @unlink($variantThumbPath);
        }
        
        // Generate new thumbnail
        generate_thumbnail($variantPath, $variantThumbPath, 512, 1024);
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'Image rotated successfully and thumbnails regenerated'
]);

