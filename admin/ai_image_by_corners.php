<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';

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
    
    // Step 1: Update status to in_progress
    $metaPath = $imagesDir . '/' . $baseName . '_original.jpg.json';
    update_task_status($metaPath, 'ai_corners', 'in_progress');
    
    // Step 2: Call calculate_corners function directly
    require_once __DIR__ . '/ai_calc_corners.php';
    
    $cornersData = calculate_corners($imagePath, $offsetPercent);
    
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
    if (!is_array($corners) || count($corners) !== 4) {
        return [
            'ok' => false,
            'error' => 'invalid_corner_count',
            'count' => is_array($corners) ? count($corners) : 0
        ];
    }

    // Step 3: Create final image using perspective transformation
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
        
        // Step 4: Save final image
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
    
    // Step 5: Generate thumbnail for _final image
    $thumbPath = generate_thumbnail_path($finalPath);
    generate_thumbnail($finalPath, $thumbPath, 512, 1024);
    
    // Step 6: Update metadata with corner positions (thread-safe)
    $metaPath = $imagesDir . '/' . $baseName . '_original.jpg.json';
    
    // Prepare updates
    $updates = [
        'manual_corners' => $corners,
        'corner_detection.corners_used' => $corners
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
    
    // Step 7: Update AI corners status to completed
    update_task_status($metaPath, 'ai_corners', 'completed');
    
    // Step 8: Set variant regeneration flag (will be processed by background task processor)
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
        if (isset($baseName) && isset($imagesDir)) {
            $metaPath = $imagesDir . '/' . $baseName . '_original.jpg.json';
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

