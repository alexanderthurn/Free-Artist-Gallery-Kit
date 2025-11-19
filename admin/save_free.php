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
$tempPath = null;
$imageData = null;

// Check for file upload
if (isset($_FILES['image'])) {
    $uploadError = $_FILES['image']['error'];
    if ($uploadError === UPLOAD_ERR_OK) {
        // Standard file upload - success
        $uploadedFile = $_FILES['image'];
        $tempPath = $uploadedFile['tmp_name'];
        
        // Verify temp file exists and is readable
        if (!is_file($tempPath) || !is_readable($tempPath)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Uploaded file is not accessible', 'detail' => 'Temp file: ' . $tempPath]);
            exit;
        }
    } else {
        // File upload error - provide detailed error message
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];
        $errorMsg = $errorMessages[$uploadError] ?? 'Unknown upload error (' . $uploadError . ')';
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'File upload error: ' . $errorMsg, 'code' => $uploadError]);
        exit;
    }
} elseif (isset($_POST['image_data'])) {
    // Handle base64 encoded image data (fallback)
    $imageData = $_POST['image_data'];
    // Remove data URL prefix if present
    if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
    }
    $imageData = base64_decode($imageData, true);
    if ($imageData === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid base64 image data']);
        exit;
    }
    // Create temporary file
    $tempPath = tempnam(sys_get_temp_dir(), 'free_');
    if ($tempPath === false || file_put_contents($tempPath, $imageData) === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to create temporary file']);
        exit;
    }
} else {
    // No file data provided at all
    http_response_code(400);
    echo json_encode([
        'ok' => false, 
        'error' => 'No image uploaded or upload error',
        'detail' => 'No $_FILES[image] or $_POST[image_data] found',
        'files_keys' => array_keys($_FILES),
        'post_keys' => array_keys($_POST)
    ]);
    exit;
}

// Validate it's an image
$imageInfo = @getimagesize($tempPath);
if ($imageInfo === false) {
    if ($imageData !== null && $tempPath) {
        @unlink($tempPath);
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid image file']);
    exit;
}

// Save as _final.jpg
$finalFilename = $base . '_final.jpg';
$finalPath = $imagesDir . $finalFilename;

// Save file to final location
if ($imageData !== null) {
    // Direct write for base64 data
    if (!file_put_contents($finalPath, $imageData)) {
        @unlink($tempPath);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save image']);
        exit;
    }
    @unlink($tempPath);
} else {
    // Move uploaded file to final location
    if (!move_uploaded_file($tempPath, $finalPath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save image']);
        exit;
    }
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

// Generate thumbnail for _final image
$thumbPath = generate_thumbnail_path($finalPath);
generate_thumbnail($finalPath, $thumbPath, 512, 1024);

// Check if this entry is in gallery and update it automatically
$galleryDir = dirname(__DIR__).'/img/gallery/';
$originalFilename = $meta['original_filename'];
$galleryFilename = find_gallery_entry($originalFilename, $galleryDir);
$inGallery = $galleryFilename !== null;

// If in gallery, automatically update using unified function
if ($inGallery) {
    update_gallery_entry($originalFilename, $meta, $imagesDir, $galleryDir);
}

// Regenerate all variants after final image is saved
require_once __DIR__ . '/variants.php';
try {
    // Get dimensions from metadata if available
    $width = isset($meta['width']) ? (string)$meta['width'] : null;
    $height = isset($meta['height']) ? (string)$meta['height'] : null;
    $regenerateResult = regenerateAllVariants($base, $width, $height);
    // Log result but don't fail if variant regeneration fails
    error_log("Variant regeneration for {$base}: " . json_encode($regenerateResult));
} catch (Exception $e) {
    // Log error but don't fail the save action
    error_log("Failed to regenerate variants for {$base}: " . $e->getMessage());
}

// Always trigger optimization to ensure gallery is updated with all variants and thumbnails
// This will rebuild the gallery for all live paintings, including this one if it's live
async_http_post('admin/optimize_images.php', ['action' => 'both', 'force' => '1']);

echo json_encode(['ok' => true, 'in_gallery' => $inGallery]);
exit;

