<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/meta.php';

/**
 * Process AI image corners and create final image
 * @param string $imagePath Path to _original image (relative or absolute)
 * @param float $offsetPercent Offset percentage for corner detection
 * @return array Result array with 'ok' key and other data
 */
function process_ai_image_by_corners(string $imagePath, float $offsetPercent = 1.0): array {
    // Resolve absolute path
    $absPath = $imagePath;
    if ($imagePath[0] !== '/' && !preg_match('#^[a-z]+://#i', $imagePath)) {
        $absPath = dirname(__DIR__) . '/' . ltrim($imagePath, '/');
    }
    
    if (!is_file($absPath)) {
        return ['ok' => false, 'error' => 'image not found', 'path' => $absPath];
    }

    // Verify it's an _original image
    if (!preg_match('/_original\.(jpg|jpeg|png)$/i', $absPath)) {
        return ['ok' => false, 'error' => 'image_path must be an _original image'];
    }

    // Extract base name (remove _original.jpg extension)
    $baseName = preg_replace('/_original\.(jpg|jpeg|png)$/i', '', basename($absPath));
    $imagesDir = dirname($absPath);
    
    // Find the _original image filename for metadata
    $originalImageFile = $baseName . '_original.jpg';
    if (!is_file($imagesDir . '/' . $originalImageFile)) {
        // Try other extensions
        $extensions = ['png', 'jpeg', 'webp'];
        foreach ($extensions as $e) {
            $testFile = $baseName . '_original.' . $e;
            if (is_file($imagesDir . '/' . $testFile)) {
                $originalImageFile = $testFile;
                break;
            }
        }
    }
    
    // Step 1: Check if corners are already completed and available
    $metaPath = get_meta_path($originalImageFile, $imagesDir);
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
    
    $aiCorners = $existingMeta['ai_corners'] ?? [];
    $cornersStatus = $aiCorners['status'] ?? null;
    $cornersUsed = $aiCorners['corners_used'] ?? null;
    $replicateResponse = $aiCorners['replicate_response'] ?? null;
    
    // Check if there's already a completed Replicate response
    $hasCompletedReplicateResponse = $replicateResponse && 
        is_array($replicateResponse) && 
        isset($replicateResponse['status']) && 
        $replicateResponse['status'] === 'succeeded';
    $imageGenerationNeeded = $aiCorners['image_generation_needed'] ?? false;
    
    // If there's a completed Replicate response but image_generation_needed is NOT set,
    // set flag and let background task handle it (this is when user clicks the button)
    // If image_generation_needed IS already set, proceed to generate the image (background task)
    if ($hasCompletedReplicateResponse && !$imageGenerationNeeded) {
        $aiCorners['status'] = 'in_progress';
        $aiCorners['image_generation_needed'] = true;
        update_json_file($metaPath, ['ai_corners' => $aiCorners], false);
        
        return [
            'ok' => true,
            'prediction_started' => false,
            'image_generation_scheduled' => true,
            'message' => 'Image generation scheduled for background task (using existing Replicate response)'
        ];
    }
    
    // If corners are already available (completed status OR image_generation_needed flag set), use them directly
    if (($cornersStatus === 'completed' || $imageGenerationNeeded) && 
        $cornersUsed && is_array($cornersUsed) && count($cornersUsed) === 4) {
        $corners = $cornersUsed;
        // Skip to image processing - corners are already available
    } else {
        // Step 2: Update status to in_progress (only if not already completed)
        if ($cornersStatus !== 'completed') {
            update_task_status($metaPath, 'ai_corners', 'in_progress');
        }
        
        // Step 3: Call calculate_corners function directly
        require_once __DIR__ . '/ai_calc_corners.php';
        
        $cornersData = calculate_corners($imagePath, $offsetPercent);
        
        // Handle async return format - if prediction was started, return early
        if (isset($cornersData['prediction_started']) && $cornersData['prediction_started']) {
            return [
                'ok' => true,
                'prediction_started' => true,
                'url' => $cornersData['url'] ?? null,
                'message' => 'Corner detection prediction started, will be processed by background task'
            ];
        }
        
        if (!is_array($cornersData) || !$cornersData['ok'] || !isset($cornersData['corners'])) {
            // Reset status to wanted for retry
            update_task_status($metaPath, 'ai_corners', 'wanted');
            return [
                'ok' => false,
                'error' => 'corner_detection_failed',
                'detail' => $cornersData['error'] ?? 'Unknown error'
            ];
        }
        
        $corners = $cornersData['corners'];
    }
    if (!is_array($corners) || count($corners) !== 4) {
        return [
            'ok' => false,
            'error' => 'invalid_corner_count',
            'count' => is_array($corners) ? count($corners) : 0
        ];
    }

    // Step 4: Create final image using perspective transformation
    // Check if ImageMagick is available
    if (!extension_loaded('imagick') || !class_exists('Imagick')) {
        return [
            'ok' => false,
            'error' => 'imagick_extension_not_available',
            'detail' => 'ImageMagick extension is not loaded. Please install and enable the imagick PHP extension.',
            'extension_loaded' => extension_loaded('imagick'),
            'class_exists' => class_exists('Imagick')
        ];
    }
    
    try {
        $im = new Imagick($absPath);
        // Get actual image dimensions from Imagick
        $actualImgW = $im->getImageWidth();
        $actualImgH = $im->getImageHeight();
        
        // Verify dimensions match what we expect
        $imageInfo = @getimagesize($absPath);
        $expectedW = $imageInfo ? $imageInfo[0] : null;
        $expectedH = $imageInfo ? $imageInfo[1] : null;
        
        if ($expectedW && $actualImgW !== $expectedW) {
            error_log("ImageMagick width mismatch: expected {$expectedW}, got {$actualImgW}");
        }
        if ($expectedH && $actualImgH !== $expectedH) {
            error_log("ImageMagick height mismatch: expected {$expectedH}, got {$actualImgH}");
        }
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'failed_to_load_image_with_imagick',
            'detail' => $e->getMessage(),
            'path' => $absPath
        ];
    }
    
    // Calculate output dimensions based on corners
    // Top-left (0), top-right (1), bottom-right (2), bottom-left (3)
    $tl = $corners[0];
    $tr = $corners[1];
    $br = $corners[2];
    $bl = $corners[3];
    
    // Calculate average width (top and bottom edges)
    $topWidth = sqrt(pow($tr[0] - $tl[0], 2) + pow($tr[1] - $tl[1], 2));
    $bottomWidth = sqrt(pow($br[0] - $bl[0], 2) + pow($br[1] - $bl[1], 2));
    $outputWidth = (int)round(max($topWidth, $bottomWidth));
    
    // Calculate average height (left and right edges)
    $leftHeight = sqrt(pow($bl[0] - $tl[0], 2) + pow($bl[1] - $tl[1], 2));
    $rightHeight = sqrt(pow($br[0] - $tr[0], 2) + pow($br[1] - $tr[1], 2));
    $outputHeight = (int)round(max($leftHeight, $rightHeight));
    
    // Ensure minimum dimensions
    $outputWidth = max($outputWidth, 64);
    $outputHeight = max($outputHeight, 64);
    
    // Prepare perspective transformation arguments
    // Format: source_x1, source_y1, dest_x1, dest_y1, source_x2, source_y2, dest_x2, dest_y2, ...
    // Note: ImageMagick DISTORTION_PERSPECTIVE maps source corners to destination corners
    // The corners array from calculate_corners() is: [top-left, top-right, bottom-right, bottom-left]
    $args = [
        $tl[0], $tl[1],   0,           0,           // top-left: source -> dest (0,0)
        $tr[0], $tr[1],   $outputWidth - 1, 0,           // top-right: source -> dest (width-1, 0)
        $br[0], $br[1],   $outputWidth - 1, $outputHeight - 1, // bottom-right: source -> dest (width-1, height-1)
        $bl[0], $bl[1],   0,           $outputHeight - 1  // bottom-left: source -> dest (0, height-1)
    ];
    
    // Debug: Log the corners being used for extraction
    error_log("AI Image Extraction - Using corners: TL=({$tl[0]},{$tl[1]}), TR=({$tr[0]},{$tr[1]}), BR=({$br[0]},{$br[1]}), BL=({$bl[0]},{$bl[1]})");
    error_log("AI Image Extraction - Output dimensions: {$outputWidth}x{$outputHeight}");
    
    try {
        // Apply perspective transformation
        $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        
        // Set virtual pixel method to transparent for areas outside the image
        $im->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
        
        // Apply perspective distortion
        // The 'true' parameter means "bestfit" - it will adjust the output size to fit the transformation
        // But we want to control the output size, so we calculate it ourselves
        $im->distortImage(Imagick::DISTORTION_PERSPECTIVE, $args, false);
        
        // Crop to the calculated output dimensions
        // First get the image geometry after distortion
        $geometry = $im->getImageGeometry();
        $distortedW = $geometry['width'];
        $distortedH = $geometry['height'];
        
        // If the distorted image is larger than our calculated size, we might need to adjust
        // But typically it should match our calculation
        $im->extentImage($outputWidth, $outputHeight, 0, 0);
        
        // Set JPEG output format
        $im->setImageCompression(Imagick::COMPRESSION_JPEG);
        $im->setImageCompressionQuality(95);
        $im->setImageFormat('jpeg');
        
        // Step 5: Save final image
        $finalPath = $imagesDir . '/' . $baseName . '_final.jpg';
        $im->writeImage($finalPath);
        $im->clear();
        $im->destroy();
    } catch (Throwable $e) {
        $im->clear();
        $im->destroy();
        return [
            'ok' => false,
            'error' => 'failed_to_transform_image',
            'detail' => $e->getMessage()
        ];
    }
    
    // Step 6: Generate thumbnail for _final image
    $thumbPath = generate_thumbnail_path($finalPath);
    generate_thumbnail($finalPath, $thumbPath, 512, 1024);
    
    // Step 7: Update metadata with corner positions (thread-safe)
    $metaPath = get_meta_path($originalImageFile, $imagesDir);
    
    // Prepare updates - update ai_corners object
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
    $aiCorners = $existingMeta['ai_corners'] ?? [];
    $aiCorners['corners_used'] = $corners;
    
    $updates = [
        'manual_corners' => $corners,
        'ai_corners' => $aiCorners
    ];
    
    // Add image dimensions if not already present
    $imageInfo = @getimagesize($absPath);
    if ($imageInfo !== false) {
        $updates['image_dimensions'] = [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1]
        ];
    }
    
    // Set original_filename if not already present
    // Check if it exists first
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
        $updates['original_filename'] = $baseName;
    }
    
    // Only update image_dimensions if not already present
    if (isset($existingMeta['image_dimensions'])) {
        unset($updates['image_dimensions']);
    }
    
    // Save updated metadata thread-safely
    update_json_file($metaPath, $updates, false);
    
    // Step 8: Update AI corners status to completed
    update_task_status($metaPath, 'ai_corners', 'completed');
    
    // Step 9: Set variant regeneration flag (will be processed by background task processor)
    update_json_file($metaPath, ['variant_regeneration_status' => 'needed'], false);
    
    // Check if image is in gallery
    $galleryDir = dirname(__DIR__) . '/img/gallery/';
    $galleryFilename = find_gallery_entry($baseName, $galleryDir);
    $inGallery = $galleryFilename !== null;
    
    return [
        'ok' => true,
        'final_image' => $baseName . '_final.jpg',
        'corners' => $corners,
        'corners_used' => [
            'top_left' => ['x' => $tl[0], 'y' => $tl[1]],
            'top_right' => ['x' => $tr[0], 'y' => $tr[1]],
            'bottom_right' => ['x' => $br[0], 'y' => $br[1]],
            'bottom_left' => ['x' => $bl[0], 'y' => $bl[1]]
        ],
        'output_dimensions' => ['width' => $outputWidth, 'height' => $outputHeight],
        'in_gallery' => $inGallery
    ];
}

// HTTP endpoint (only when accessed directly)
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    // Continue execution even if user closes browser/connection
    ignore_user_abort(true);
    
    // Increase execution time limit for long-running operations (10 minutes)
    set_time_limit(600);
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // Get image_path from POST
        $imagePath = $_POST['image_path'] ?? '';
        if ($imagePath === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'image_path required']);
            exit;
        }

        $offsetPercent = isset($_POST['offset']) ? (float)$_POST['offset'] : 1.0;
        $result = process_ai_image_by_corners($imagePath, $offsetPercent);
        
        if (!$result['ok']) {
            http_response_code(500);
        }
        
        echo json_encode($result);
    } catch (Throwable $e) {
        // Reset status to wanted for retry
        if (isset($originalImageFile) && isset($imagesDir)) {
            $metaPath = get_meta_path($originalImageFile, $imagesDir);
            if (is_file($metaPath)) {
                update_task_status($metaPath, 'ai_corners', 'wanted');
            }
        }
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'unexpected_error',
            'detail' => $e->getMessage()
        ]);
    }
}

