<?php
declare(strict_types=1);

// Continue execution even if user closes browser/connection
ignore_user_abort(true);

// Increase execution time limit for long-running operations (10 minutes)
set_time_limit(600);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/meta.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// If async mode is requested, trigger async processing and return immediately
if (isset($_POST['async']) && $_POST['async'] === '1') {
    // Get count BEFORE starting processing
    $imagesDir = __DIR__ . '/images';
    $counts = get_pending_tasks_count($imagesDir);
    
    // Trigger async processing using async_http_post
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url = $scheme . '://' . $host . '/admin/process_background_tasks.php';
    
    // Start async processing (fire-and-forget)
    async_http_post($url, ['async' => '0']); // async=0 means actual processing
    
    // Return immediately with queue status (counted before processing started)
    echo json_encode([
        'ok' => true,
        'async' => true,
        'message' => 'Background processing started',
        'summary' => [
            'variants' => $counts['variants'],
            'ai' => $counts['ai'],
            'gallery' => $counts['gallery']
        ]
    ]);
    exit;
}

$imagesDir = __DIR__ . '/images';

// Preview mode - just return counts
if (isset($_GET['preview']) && $_GET['preview'] === '1') {
    $counts = get_pending_tasks_count($imagesDir);
    echo json_encode([
        'ok' => true,
        'preview' => true,
        'summary' => [
            'variants' => $counts['variants'],
            'ai' => $counts['ai'],
            'gallery' => $counts['gallery']
        ]
    ]);
    exit;
}

// Check if this is the actual processing run (async=0 or no async parameter)
// If async=1 was sent, we already handled it above and exited
$isAsyncProcessing = isset($_POST['async']) && $_POST['async'] === '0';

$results = [
    'ok' => true,
    'processed' => [],
    'skipped' => [],
    'errors' => []
];

if (!is_dir($imagesDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Images directory not found']);
    exit;
}

/**
 * Check if variant regeneration is needed
 */
function check_variant_regeneration_needed(string $baseName, string $jsonPath, string $imagesDir): bool {
    // Extract image filename from JSON path
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    // Check status flag
    $status = $meta['variant_regeneration_status'] ?? null;
    if ($status === 'needed') {
        return true;
    }
    
    // Check if in progress and stale
    if ($status === 'in_progress' && !is_task_in_progress($meta, 'variant_regeneration')) {
        return true; // Stale, retry
    }
    
    // Check file modification times
    $finalImage = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $baseName.'_final') === 0) {
            $finalImage = $imagesDir . '/' . $file;
            break;
        }
    }
    
    if (!$finalImage || !is_file($finalImage)) {
        return false; // No final image
    }
    
    $finalMtime = filemtime($finalImage);
    
    // Check if any variant is older than final image
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        $pattern = '/^' . preg_quote($baseName, '/') . '_variant_(.+)$/';
        if (preg_match($pattern, $fileStem)) {
            $variantPath = $imagesDir . '/' . $file;
            if (is_file($variantPath)) {
                $variantMtime = filemtime($variantPath);
                if ($variantMtime < $finalMtime) {
                    return true; // Variant is older than final
                }
            }
        }
    }
    
    return false;
}

/**
 * Check if AI generation is needed
 */
function check_ai_generation_needed(array $meta): array {
    $aiCorners = $meta['ai_corners'] ?? [];
    $aiFillForm = $meta['ai_fill_form'] ?? [];
    $cornersStatus = $aiCorners['status'] ?? null;
    $formStatus = $aiFillForm['status'] ?? null;
    $needs = ['corners' => false, 'form' => false];
    
    // Check corners status
    if ($cornersStatus === 'wanted') {
        $needs['corners'] = true;
    } elseif ($cornersStatus === 'in_progress' && !is_task_in_progress($meta, 'ai_corners')) {
        $needs['corners'] = true; // Stale, retry
    }
    
    // Check form status
    if ($formStatus === 'wanted') {
        $needs['form'] = true;
    } elseif ($formStatus === 'in_progress' && !is_task_in_progress($meta, 'ai_form')) {
        $needs['form'] = true; // Stale, retry
    }
    
    return $needs;
}

/**
 * Process variant regeneration
 */
function process_variant_regeneration(string $baseName, string $jsonPath): array {
    require_once __DIR__ . '/variants.php';
    
    // Load metadata to get dimensions
    $imageFilename = basename($jsonPath, '.json');
    $imagesDir = dirname($jsonPath);
    $meta = load_meta($imageFilename, $imagesDir);
    
    // Set status to in_progress
    update_task_status($jsonPath, 'variant_regeneration', 'in_progress');
    
    try {
        $width = isset($meta['width']) ? (string)$meta['width'] : null;
        $height = isset($meta['height']) ? (string)$meta['height'] : null;
        
        // Ensure token is loaded for variants
        global $TOKEN, $variantsDir;
        if (!isset($TOKEN) || $TOKEN === null) {
            $TOKEN = load_replicate_token();
        }
        if (!isset($variantsDir)) {
            $variantsDir = __DIR__ . '/variants';
            if (!is_dir($variantsDir)) {
                mkdir($variantsDir, 0755, true);
            }
        }
        
        $result = regenerateAllVariants($baseName, $width, $height);
        
        if ($result['ok'] ?? false) {
            update_task_status($jsonPath, 'variant_regeneration', 'completed');
            return ['ok' => true, 'result' => $result];
        } else {
            update_task_status($jsonPath, 'variant_regeneration', 'needed'); // Retry
            return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
        }
    } catch (Throwable $e) {
        update_task_status($jsonPath, 'variant_regeneration', 'needed'); // Retry
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process AI corners generation
 */
function process_ai_corners(string $baseName, string $jsonPath): array {
    $imagesDir = __DIR__ . '/images';
    
    // Load metadata to check for prediction URL
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    // Check if prediction URL exists and status is in_progress
    $aiCorners = $meta['ai_corners'] ?? [];
    $predictionUrl = $aiCorners['prediction_url'] ?? null;
    $cornersStatus = $aiCorners['status'] ?? null;
    
    if ($predictionUrl && is_string($predictionUrl) && $cornersStatus === 'in_progress') {
        // Poll the prediction once
        require_once __DIR__ . '/ai_calc_corners.php';
        $pollResult = poll_corner_prediction($jsonPath, 1.0);
        
        if (isset($pollResult['still_processing']) && $pollResult['still_processing']) {
            // Still processing - skip for now, will check again next run
            return ['ok' => true, 'still_processing' => true, 'message' => 'Prediction still in progress'];
        }
        
        if (isset($pollResult['completed']) && $pollResult['completed']) {
            // Prediction completed - corners are saved, now create final image
            $originalImage = null;
            $files = scandir($imagesDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $fileStem = pathinfo($file, PATHINFO_FILENAME);
                if (strpos($fileStem, $baseName.'_original') === 0) {
                    $originalImage = $imagesDir . '/' . $file;
                    break;
                }
            }
            
            if (!$originalImage || !is_file($originalImage)) {
                return ['ok' => false, 'error' => 'Original image not found'];
            }
            
            // Process the corners to create final image
            // calculate_corners() will use the cached succeeded response
            require_once __DIR__ . '/ai_image_by_corners.php';
            $imagePath = 'admin/images/' . basename($originalImage);
            $result = process_ai_image_by_corners($imagePath, 1.0);
            
            if ($result['ok']) {
                return ['ok' => true, 'result' => $result];
            } else {
                return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
            }
        }
        
        // Polling failed or prediction failed
        return ['ok' => false, 'error' => $pollResult['error'] ?? 'Polling failed'];
    }
    
    // Check if image generation is needed (flag set) or status is completed
    $imageGenerationNeeded = $aiCorners['image_generation_needed'] ?? false;
    
    if ($imageGenerationNeeded || $cornersStatus === 'completed') {
        $cornersUsed = $aiCorners['corners_used'] ?? null;
        $replicateResponse = $aiCorners['replicate_response'] ?? null;
        
        // If we have a Replicate response but no corners_used yet, process it first
        if ($replicateResponse && is_array($replicateResponse) && 
            isset($replicateResponse['status']) && 
            $replicateResponse['status'] === 'succeeded' &&
            (!$cornersUsed || !is_array($cornersUsed) || count($cornersUsed) !== 4)) {
            // Process the Replicate response to extract corners
            require_once __DIR__ . '/ai_calc_corners.php';
            $processResult = process_completed_corner_prediction($jsonPath, $replicateResponse, $aiCorners['offset_percent'] ?? 1.0);
            if (!$processResult['ok']) {
                return ['ok' => false, 'error' => 'Failed to process Replicate response: ' . ($processResult['error'] ?? 'Unknown')];
            }
            // Reload meta to get updated corners_used
            $meta = load_meta($imageFilename, $imagesDir);
            $aiCorners = $meta['ai_corners'] ?? [];
            $cornersUsed = $aiCorners['corners_used'] ?? null;
        }
        
        if ($cornersUsed && is_array($cornersUsed) && count($cornersUsed) === 4) {
            // Regenerate final image using the completed corners
            $originalImage = null;
            $files = scandir($imagesDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $fileStem = pathinfo($file, PATHINFO_FILENAME);
                if (strpos($fileStem, $baseName.'_original') === 0) {
                    $originalImage = $imagesDir . '/' . $file;
                    break;
                }
            }
            
            if (!$originalImage || !is_file($originalImage)) {
                return ['ok' => false, 'error' => 'Original image not found'];
            }
            
            // Process the corners to regenerate final image
            require_once __DIR__ . '/ai_image_by_corners.php';
            $imagePath = 'admin/images/' . basename($originalImage);
            $result = process_ai_image_by_corners($imagePath, $aiCorners['offset_percent'] ?? 1.0);
            
            if ($result['ok']) {
                // Clear the flag and set status to completed after successful generation
                $aiCorners['image_generation_needed'] = false;
                $aiCorners['status'] = 'completed';
                update_json_file($jsonPath, ['ai_corners' => $aiCorners], false);
                return ['ok' => true, 'result' => $result, 'message' => 'Final image regenerated'];
            } else {
                return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
            }
        }
    }
    
    // No prediction URL exists - start new prediction
    $originalImage = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $baseName.'_original') === 0) {
            $originalImage = $imagesDir . '/' . $file;
            break;
        }
    }
    
    if (!$originalImage || !is_file($originalImage)) {
        return ['ok' => false, 'error' => 'Original image not found'];
    }
    
    // Set status to in_progress
    update_task_status($jsonPath, 'ai_corners', 'in_progress');
    
    try {
        // Call function directly - it will start prediction and return early
        require_once __DIR__ . '/ai_image_by_corners.php';
        $imagePath = 'admin/images/' . basename($originalImage);
        $result = process_ai_image_by_corners($imagePath, 1.0);
        
        if ($result['ok']) {
            if (isset($result['prediction_started']) && $result['prediction_started']) {
                // Prediction started - will be polled in next run
                return ['ok' => true, 'prediction_started' => true, 'message' => 'Prediction started'];
            }
            // Status is already updated to completed in process_ai_image_by_corners
            return ['ok' => true, 'result' => $result];
        } else {
            // Status is already updated to wanted in process_ai_image_by_corners
            return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
        }
    } catch (Throwable $e) {
        update_task_status($jsonPath, 'ai_corners', 'wanted'); // Retry
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process AI form fill
 */
function process_ai_form(string $baseName, string $jsonPath): array {
    $imagesDir = __DIR__ . '/images';
    
    // Load metadata to check for prediction URL
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    // Check if prediction URL exists and status is in_progress
    $aiFillForm = $meta['ai_fill_form'] ?? [];
    $predictionUrl = $aiFillForm['prediction_url'] ?? null;
    $formStatus = $aiFillForm['status'] ?? null;
    
    if ($predictionUrl && is_string($predictionUrl) && $formStatus === 'in_progress') {
        // Poll the prediction once
        require_once __DIR__ . '/ai_fill_form.php';
        $pollResult = poll_form_prediction($jsonPath);
        
        if (isset($pollResult['still_processing']) && $pollResult['still_processing']) {
            // Still processing - skip for now, will check again next run
            return ['ok' => true, 'still_processing' => true, 'message' => 'Prediction still in progress'];
        }
        
        if (isset($pollResult['completed']) && $pollResult['completed']) {
            // Prediction completed - form data already saved by poll_form_prediction
            return ['ok' => true, 'result' => $pollResult];
        }
        
        // Polling failed or prediction failed
        return ['ok' => false, 'error' => $pollResult['error'] ?? 'Polling failed'];
    }
    
    // No prediction URL exists - start new prediction
    // Find _final or _original image
    $imageFile = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $baseName.'_final') === 0) {
            $imageFile = $file;
            break;
        }
    }
    
    if (!$imageFile) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $baseName.'_original') === 0) {
                $imageFile = $file;
                break;
            }
        }
    }
    
    if (!$imageFile) {
        return ['ok' => false, 'error' => 'Image not found'];
    }
    
    // Set status to in_progress
    update_task_status($jsonPath, 'ai_form', 'in_progress');
    
    try {
        // Call function directly - it will start prediction and return early
        require_once __DIR__ . '/ai_fill_form.php';
        $result = process_ai_fill_form($imageFile);
        
        if ($result['ok']) {
            if (isset($result['prediction_started']) && $result['prediction_started']) {
                // Prediction started - will be polled in next run
                return ['ok' => true, 'prediction_started' => true, 'message' => 'Prediction started'];
            }
            // Status is already updated to completed in process_ai_fill_form
            return ['ok' => true, 'result' => $result];
        } else {
            // Status is already updated to wanted in process_ai_fill_form
            return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
        }
    } catch (Throwable $e) {
        update_task_status($jsonPath, 'ai_form', 'wanted'); // Retry
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Poll and process AI painting variants generation (background task handler)
 */
function poll_ai_painting_variants(string $baseName, string $jsonPath): array {
    $imagesDir = __DIR__ . '/images';
    
    // Load metadata to check for painting variants status
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
    $status = $aiPaintingVariants['status'] ?? null;
    $variants = $aiPaintingVariants['variants'] ?? [];
    
    // Check if status is in_progress and has variants to poll
    if ($status === 'in_progress' && !empty($variants)) {
        $allCompleted = true;
        $anyInProgress = false;
        
        foreach ($variants as $variantName => $variantInfo) {
            $variantStatus = $variantInfo['status'] ?? null;
            $predictionUrl = $variantInfo['prediction_url'] ?? null;
            
            if ($variantStatus === 'in_progress' && $predictionUrl) {
                // Poll this variant directly from Replicate API
                try {
                    $TOKEN = load_replicate_token();
                } catch (RuntimeException $e) {
                    $anyInProgress = true;
                    continue;
                }
                
                // Make single GET request to check status
                $ch = curl_init($predictionUrl);
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => ["Authorization: Token $TOKEN"],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30
                ]);
                
                $res = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                
                if ($res === false || $httpCode >= 400) {
                    // Network error - keep status as in_progress, retry next run
                    $anyInProgress = true;
                    continue;
                }
                
                $resp = json_decode($res, true);
                if (!is_array($resp)) {
                    $anyInProgress = true;
                    continue;
                }
                
                $predictionStatus = $resp['status'] ?? 'unknown';
                
                // Update variant info with latest response
                $variants[$variantName]['prediction_status'] = $predictionStatus;
                $variants[$variantName]['replicate_response_raw'] = json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $variants[$variantName]['replicate_response'] = $resp;
                $variants[$variantName]['timestamp'] = date('c');
                
                if ($predictionStatus === 'succeeded') {
                    // Process completed prediction
                    $targetPath = $variantInfo['target_path'] ?? null;
                    if ($targetPath && is_string($targetPath)) {
                        // Extract image from response
                        require_once __DIR__ . '/utils.php';
                        $imgBytes = fetch_image_bytes($resp['output'] ?? null);
                        if ($imgBytes === null) {
                            if (is_array($resp['output']) && isset($resp['output']['images'][0])) {
                                $imgBytes = fetch_image_bytes($resp['output']['images'][0]);
                            }
                        }
                        
                        if ($imgBytes !== null) {
                            // Ensure target directory exists
                            $targetDir = dirname($targetPath);
                            if (!is_dir($targetDir)) {
                                mkdir($targetDir, 0755, true);
                            }
                            
                            file_put_contents($targetPath, $imgBytes);
                            
                            // Generate thumbnail
                            $thumbPath = generate_thumbnail_path($targetPath);
                            generate_thumbnail($targetPath, $thumbPath, 512, 1024);
                            
                            // Mark as completed
                            $variants[$variantName]['status'] = 'completed';
                            $variants[$variantName]['completed_at'] = date('c');
                        } else {
                            // Failed to extract image
                            $variants[$variantName]['status'] = 'wanted';
                            $allCompleted = false;
                        }
                    } else {
                        // No target path
                        $variants[$variantName]['status'] = 'wanted';
                        $allCompleted = false;
                    }
                } elseif ($predictionStatus === 'failed' || $predictionStatus === 'canceled') {
                    // Prediction failed - set status to wanted for retry
                    $variants[$variantName]['status'] = 'wanted';
                    $allCompleted = false;
                } else {
                    // Still processing
                    $anyInProgress = true;
                }
            } elseif ($variantStatus === 'completed') {
                // Already completed
            } else {
                $allCompleted = false;
                if ($variantStatus === 'in_progress') {
                    $anyInProgress = true;
                }
            }
        }
        
        // Update painting metadata
        $aiPaintingVariants['variants'] = $variants;
        if ($allCompleted) {
            $aiPaintingVariants['status'] = 'completed';
            $aiPaintingVariants['completed_at'] = date('c');
            $aiPaintingVariants['image_generation_needed'] = false;
        }
        update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
        
        if ($anyInProgress) {
            return ['ok' => true, 'still_processing' => true, 'message' => 'Variants still in progress'];
        }
        
        if ($allCompleted) {
            return ['ok' => true, 'completed' => true, 'message' => 'All variants completed'];
        }
    }
    
    // Check if status is wanted or not set - start new generation
    if ($status !== 'in_progress' && $status !== 'completed') {
        require_once __DIR__ . '/ai_painting_variants.php';
        $result = process_ai_painting_variants($baseName);
        
        if ($result['ok']) {
            if ($result['started'] > 0) {
                return ['ok' => true, 'started' => true, 'message' => 'Started variant generation'];
            } else {
                return ['ok' => true, 'skipped' => true, 'message' => 'No variants to generate'];
            }
        } else {
            return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
        }
    }
    
    return ['ok' => true, 'skipped' => true, 'message' => 'No action needed'];
}

/**
 * Process individual variant predictions (from variants/ directory)
 */
function process_individual_variants(): array {
    $variantsDir = __DIR__ . '/variants';
    if (!is_dir($variantsDir)) {
        return ['ok' => true, 'processed' => 0, 'message' => 'Variants directory not found'];
    }
    
    $files = scandir($variantsDir) ?: [];
    $processed = 0;
    $errors = [];
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
        
        $variantJsonPath = $variantsDir . '/' . $file;
        $variantMeta = [];
        if (is_file($variantJsonPath)) {
            $content = @file_get_contents($variantJsonPath);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $variantMeta = $decoded;
                }
            }
        }
        
        $status = $variantMeta['status'] ?? null;
        $predictionUrl = $variantMeta['prediction_url'] ?? null;
        
        // Only process variants that are in_progress and have a prediction URL
        if ($status === 'in_progress' && $predictionUrl) {
            require_once __DIR__ . '/ai_variant_by_prompt.php';
            $pollResult = poll_variant_prediction($variantJsonPath);
            
            if (isset($pollResult['completed']) && $pollResult['completed']) {
                $processed++;
            } elseif (isset($pollResult['still_processing']) && $pollResult['still_processing']) {
                // Still processing - skip for now
                continue;
            } else {
                $errors[] = [
                    'variant' => $variantMeta['variant_name'] ?? $file,
                    'error' => $pollResult['error'] ?? 'Unknown error'
                ];
            }
        }
    }
    
    return [
        'ok' => true,
        'processed' => $processed,
        'errors' => $errors
    ];
}

/**
 * Process variant generation (create variants that are in active_variants but don't exist as files)
 */
function process_variant_generation(string $baseName, string $jsonPath, string $imagesDir): array {
    require_once __DIR__ . '/variants.php';
    
    $created = [];
    $errors = [];
    
    // Load metadata to get active variants
    $meta = [];
    if (is_file($jsonPath)) {
        $content = @file_get_contents($jsonPath);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
    }
    
    $activeVariants = isset($meta['active_variants']) && is_array($meta['active_variants']) 
        ? $meta['active_variants'] 
        : [];
    
    if (empty($activeVariants)) {
        return ['ok' => true, 'created' => [], 'errors' => []];
    }
    
    // Get dimensions from metadata
    $width = isset($meta['width']) ? (string)$meta['width'] : null;
    $height = isset($meta['height']) ? (string)$meta['height'] : null;
    
    // Ensure token is loaded
    global $TOKEN, $variantsDir;
    if (!isset($TOKEN) || $TOKEN === null) {
        try {
            $TOKEN = load_replicate_token();
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'Failed to load REPLICATE_API_TOKEN', 'created' => [], 'errors' => []];
        }
    }
    if (!isset($variantsDir)) {
        $variantsDir = __DIR__ . '/variants';
        if (!is_dir($variantsDir)) {
            mkdir($variantsDir, 0755, true);
        }
    }
    
    // Check each active variant - if file doesn't exist, create it
    foreach ($activeVariants as $variantName) {
        $variantFile = $baseName . '_variant_' . $variantName . '.jpg';
        $variantPath = $imagesDir . '/' . $variantFile;
        
        // Skip if file already exists
        if (is_file($variantPath)) {
            continue;
        }
        
        // Check if variant template exists
        $variantTemplatePath = $variantsDir . '/' . $variantName . '.jpg';
        if (!is_file($variantTemplatePath)) {
            $errors[] = "Variant template not found: {$variantName}.jpg";
            continue;
        }
        
        // Find the _final image - REQUIRED before generating variants
        $finalImage = null;
        $files = scandir($imagesDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $baseName.'_final') === 0) {
                $finalImage = $imagesDir . '/' . $file;
                break;
            }
        }
        
        if (!$finalImage || !is_file($finalImage)) {
            // Don't generate variants if final image doesn't exist yet
            // This will be retried once the final image is created
            $errors[] = "Final image not found for {$baseName} - waiting for AI corner generation to complete";
            continue;
        }
        
        try {
            // Use the same logic as handleCopyToImage in variants.php
            // Load both images as base64
            $variantMime = mime_content_type($variantTemplatePath);
            $finalMime = mime_content_type($finalImage);
            
            if (!in_array($variantMime, ['image/jpeg','image/png','image/webp']) || 
                !in_array($finalMime, ['image/jpeg','image/png','image/webp'])) {
                $errors[] = "Unsupported image type for {$variantName}";
                continue;
            }
            
            $variantB64 = base64_encode(file_get_contents($variantTemplatePath));
            $finalB64 = base64_encode(file_get_contents($finalImage));
            
            // Use nano banana to place the painting into the variant
            $VERSION = '2784c5d54c07d79b0a2a5385477038719ad37cb0745e61bbddf2fc236d196a6b';
            
            // Build prompt with dimensions if available
            $dimensionsInfo = '';
            if ($width !== '' && $height !== '') {
                $dimensionsInfo = "\n\nPainting dimensions: {$width}cm (width) × {$height}cm (height).";
                $dimensionsInfo .= "\nRoom height: 250cm (ceiling height).";
                $dimensionsInfo .= "\nPlace the painting at an appropriate scale relative to the room dimensions. The painting should be positioned realistically on the wall, considering its actual size.";
            }
            
            $prompt = <<<PROMPT
You are an image editor.

Task:
- Place the painting into the free space on the wall.
- Ensure the painting is properly scaled and positioned realistically.
- The painting should be centered or positioned appropriately on the wall.
- Maintain natural lighting and shadows.
{$dimensionsInfo}
PROMPT;
            
            $payload = [
                'version' => $VERSION,
                'input' => [
                    'prompt' => $prompt,
                    'image_input' => [
                        "data:$variantMime;base64,$variantB64",
                        "data:$finalMime;base64,$finalB64"
                    ],
                    'aspect_ratio' => '1:1',
                    'output_format' => 'jpg'
                ]
            ];
            
            // Call Replicate API
            $resp = replicate_call_version($TOKEN, $VERSION, $payload);
            
            // Extract image from result
            $imgBytes = fetch_image_bytes($resp['output'] ?? null);
            if ($imgBytes === null) {
                if (is_array($resp['output']) && isset($resp['output']['images'][0])) {
                    $imgBytes = fetch_image_bytes($resp['output']['images'][0]);
                }
            }
            
            if ($imgBytes === null) {
                $errors[] = "Failed to generate {$variantName}: unexpected output format";
                continue;
            }
            
            // Save variant file
            file_put_contents($variantPath, $imgBytes);
            
            // Generate thumbnail
            $thumbPath = generate_thumbnail_path($variantPath);
            generate_thumbnail($variantPath, $thumbPath, 512, 1024);
            
            $created[] = $variantName;
        } catch (Throwable $e) {
            $errors[] = "Failed to generate {$variantName}: " . $e->getMessage();
        }
    }
    
    return ['ok' => true, 'created' => $created, 'errors' => $errors];
}

/**
 * Clean up orphaned variant files (files that exist but aren't in active_variants)
 */
function cleanup_orphaned_variants(string $baseName, string $jsonPath, string $imagesDir): array {
    $cleaned = [];
    $errors = [];
    
    // Load metadata to get active variants
    $meta = [];
    if (is_file($jsonPath)) {
        $content = @file_get_contents($jsonPath);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
    }
    
    $activeVariants = isset($meta['active_variants']) && is_array($meta['active_variants']) 
        ? $meta['active_variants'] 
        : [];
    
    // Find all variant files for this base
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        
        // Check if this is a variant file (not a thumbnail)
        $pattern = '/^' . preg_quote($baseName, '/') . '_variant_(.+)$/';
        if (preg_match($pattern, $fileStem, $matches) && strpos($fileStem, '_thumb') === false) {
            $variantName = $matches[1];
            
            // If variant is not in active list, delete it
            if (!in_array($variantName, $activeVariants, true)) {
                $filePath = $imagesDir . '/' . $file;
                if (is_file($filePath)) {
                    if (@unlink($filePath)) {
                        $cleaned[] = $file;
                        
                        // Also delete thumbnail if it exists
                        $pathInfo = pathinfo($filePath);
                        $thumbPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];
                        if (is_file($thumbPath)) {
                            @unlink($thumbPath);
                        }
                    } else {
                        $errors[] = $file;
                    }
                }
            }
        }
    }
    
    return ['ok' => true, 'cleaned' => $cleaned, 'errors' => $errors];
}

/**
 * Process gallery publishing (use existing optimize_images logic)
 */
function process_gallery_publishing(string $baseName, string $jsonPath): array {
    try {
        // Call function directly instead of HTTP
        require_once __DIR__ . '/optimize_images.php';
        process_images('both', false); // Process both optimize and gallery
        
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Main processing loop - Sequential processing:
// 1. First: Process all AI corners tasks
// 2. Second: Process all AI form tasks
// 3. Third: Process variant generation/regeneration

$files = scandir($imagesDir) ?: [];
$processedCount = 0;
$maxProcessPerRun = 10; // Limit to prevent timeout

error_log('[Background Tasks] Scanning images directory: ' . $imagesDir);
error_log('[Background Tasks] Found ' . count($files) . ' files in directory');
error_log('[Background Tasks] Max tasks per run: ' . $maxProcessPerRun);

// Collect all JSON files first
$jsonFiles = [];
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
    
    // Check if it's an _original.json file
    $stem = pathinfo($file, PATHINFO_FILENAME);
    if (!preg_match('/_original\.jpg$/', $stem)) {
        continue;
    }
    
    $baseName = preg_replace('/_original\.jpg$/', '', $stem);
    $jsonPath = $imagesDir . '/' . $file;
    
    $imageFilename = basename($file, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    if (empty($meta)) continue;
    
    $jsonFiles[] = [
        'baseName' => $baseName,
        'jsonPath' => $jsonPath,
        'meta' => $meta,
        'file' => $file
    ];
}

$totalJsonFiles = count($jsonFiles);
error_log('[Background Tasks] Found ' . $totalJsonFiles . ' valid JSON files to process');

// ============================================
// PHASE 1: Process all AI corners tasks first
// ============================================
error_log('[Background Tasks] === PHASE 1: Processing AI corners tasks ===');
foreach ($jsonFiles as $item) {
    if ($processedCount >= $maxProcessPerRun) {
        error_log('[Background Tasks] Processing limit reached (' . $maxProcessPerRun . '), stopping phase 1');
        break;
    }
    
    $baseName = $item['baseName'];
    $jsonPath = $item['jsonPath'];
    $meta = $item['meta'];
    
    $aiNeeds = check_ai_generation_needed($meta);
    if (!$aiNeeds['corners']) {
        continue;
    }
    
    $aiCorners = $meta['ai_corners'] ?? [];
    $cornersStatus = $aiCorners['status'] ?? null;
    error_log('[Background Tasks] ' . $baseName . ': AI corners status: ' . ($cornersStatus ?? 'null'));
    
    // Check if there's a prediction URL - if so, we should poll it even if task is in_progress
    $hasPredictionUrl = isset($aiCorners['prediction_url']) && 
                        is_string($aiCorners['prediction_url']);
    
    // Only skip if in_progress AND not stale AND no prediction_url (meaning it's actively being processed synchronously)
    if ($cornersStatus === 'in_progress' && is_task_in_progress($meta, 'ai_corners') && !$hasPredictionUrl) {
        error_log('[Background Tasks] ' . $baseName . ': Skipping AI corners (already in progress, no prediction URL)');
        $originalImageFile = null;
        $files = scandir($imagesDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $baseName.'_original') === 0) {
                $originalImageFile = $file;
                break;
            }
        }
        $results['skipped'][] = [
            'base' => $baseName,
            'task' => 'ai_corners',
            'reason' => 'in_progress',
            'image' => $originalImageFile ?? $baseName . '_original.jpg',
            'ai_task' => 'corner_detection',
            'status' => $cornersStatus
        ];
        continue;
    }
    
    // Find original image filename for reporting
    $originalImageFile = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $baseName.'_original') === 0) {
            $originalImageFile = $file;
            break;
        }
    }
    
    error_log('[Background Tasks] ' . $baseName . ': Processing AI corners (image: ' . ($originalImageFile ?? $baseName . '_original.jpg') . ')');
    $result = process_ai_corners($baseName, $jsonPath);
    if ($result['ok']) {
        if (isset($result['still_processing']) && $result['still_processing']) {
            error_log('[Background Tasks] ' . $baseName . ': AI corners still processing, will check again next run');
            $results['skipped'][] = [
                'base' => $baseName,
                'task' => 'ai_corners',
                'reason' => 'still_processing',
                'image' => $originalImageFile ?? $baseName . '_original.jpg',
                'ai_task' => 'corner_detection'
            ];
            continue;
        }
        error_log('[Background Tasks] ' . $baseName . ': AI corners completed successfully');
        $results['processed'][] = [
            'base' => $baseName,
            'task' => 'ai_corners',
            'status' => 'success',
            'image' => $originalImageFile ?? $baseName . '_original.jpg',
            'ai_task' => 'corner_detection',
            'description' => 'AI corner detection and image cropping'
        ];
        $processedCount++;
        // Reload meta after corners processing (it may have changed)
        $imageFilename = basename($jsonPath, '.json');
        $imagesDir = dirname($jsonPath);
        $item['meta'] = load_meta($imageFilename, $imagesDir);
    } else {
        error_log('[Background Tasks] ' . $baseName . ': AI corners failed: ' . ($result['error'] ?? 'Unknown error'));
        $results['errors'][] = [
            'base' => $baseName,
            'task' => 'ai_corners',
            'error' => $result['error'],
            'image' => $originalImageFile ?? $baseName . '_original.jpg',
            'ai_task' => 'corner_detection'
        ];
    }
}

// ============================================
// PHASE 2: Process all AI form tasks
// ============================================
error_log('[Background Tasks] === PHASE 2: Processing AI form tasks ===');
foreach ($jsonFiles as $item) {
    if ($processedCount >= $maxProcessPerRun) {
        error_log('[Background Tasks] Processing limit reached (' . $maxProcessPerRun . '), stopping phase 2');
        break;
    }
    
    $baseName = $item['baseName'];
    $jsonPath = $item['jsonPath'];
    $meta = $item['meta'];
    
    $aiNeeds = check_ai_generation_needed($meta);
    if (!$aiNeeds['form']) {
        continue;
    }
    
    $aiFillForm = $meta['ai_fill_form'] ?? [];
    $formStatus = $aiFillForm['status'] ?? null;
    error_log('[Background Tasks] ' . $baseName . ': AI form status: ' . ($formStatus ?? 'null'));
    
    // Check if there's a prediction URL - if so, we should poll it even if task is in_progress
    $hasPredictionUrl = isset($aiFillForm['prediction_url']) && 
                        is_string($aiFillForm['prediction_url']);
    
    // Only skip if in_progress AND not stale AND no prediction_url (meaning it's actively being processed synchronously)
    if ($formStatus === 'in_progress' && is_task_in_progress($meta, 'ai_form') && !$hasPredictionUrl) {
        error_log('[Background Tasks] ' . $baseName . ': Skipping AI form (already in progress, no prediction URL)');
        $imageFile = null;
        $files = scandir($imagesDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $baseName.'_final') === 0) {
                $imageFile = $file;
                break;
            }
        }
        if (!$imageFile) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $fileStem = pathinfo($file, PATHINFO_FILENAME);
                if (strpos($fileStem, $baseName.'_original') === 0) {
                    $imageFile = $file;
                    break;
                }
            }
        }
        $results['skipped'][] = [
            'base' => $baseName,
            'task' => 'ai_form',
            'reason' => 'in_progress',
            'image' => $imageFile ?? $baseName . '_final.jpg',
            'ai_task' => 'form_filling',
            'status' => $formStatus
        ];
        continue;
    }
    
    // Find image filename for reporting
    $imageFile = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $baseName.'_final') === 0) {
            $imageFile = $file;
            break;
        }
    }
    if (!$imageFile) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $baseName.'_original') === 0) {
                $imageFile = $file;
                break;
            }
        }
    }
    
    error_log('[Background Tasks] ' . $baseName . ': Processing AI form (image: ' . ($imageFile ?? $baseName . '_final.jpg') . ')');
    $result = process_ai_form($baseName, $jsonPath);
    if ($result['ok']) {
        if (isset($result['still_processing']) && $result['still_processing']) {
            error_log('[Background Tasks] ' . $baseName . ': AI form still processing, will check again next run');
            $results['skipped'][] = [
                'base' => $baseName,
                'task' => 'ai_form',
                'reason' => 'still_processing',
                'image' => $imageFile ?? $baseName . '_final.jpg',
                'ai_task' => 'form_filling'
            ];
            continue;
        }
        $extractedFields = isset($result['result']) ? array_keys($result['result']) : [];
        error_log('[Background Tasks] ' . $baseName . ': AI form completed successfully (extracted fields: ' . implode(', ', $extractedFields) . ')');
        $results['processed'][] = [
            'base' => $baseName,
            'task' => 'ai_form',
            'status' => 'success',
            'image' => $imageFile ?? $baseName . '_final.jpg',
            'ai_task' => 'form_filling',
            'description' => 'AI form filling (title, description, tags, dimensions, date)',
            'extracted_fields' => $extractedFields
        ];
        $processedCount++;
    } else {
        error_log('[Background Tasks] ' . $baseName . ': AI form failed: ' . ($result['error'] ?? 'Unknown error'));
        $results['errors'][] = [
            'base' => $baseName,
            'task' => 'ai_form',
            'error' => $result['error'],
            'image' => $imageFile ?? $baseName . '_final.jpg',
            'ai_task' => 'form_filling'
        ];
    }
}

// ============================================
// PHASE 2.5: Process AI painting variants
// ============================================
error_log('[Background Tasks] === PHASE 2.5: Processing AI painting variants ===');
foreach ($jsonFiles as $item) {
    if ($processedCount >= $maxProcessPerRun) {
        error_log('[Background Tasks] Processing limit reached (' . $maxProcessPerRun . '), stopping phase 2.5');
        break;
    }
    
    $baseName = $item['baseName'];
    $jsonPath = $item['jsonPath'];
    $meta = $item['meta'];
    
    // Reload meta to get latest status
    $imageFilename = basename($jsonPath, '.json');
    $meta = load_meta($imageFilename, $imagesDir);
    
    $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
    $status = $aiPaintingVariants['status'] ?? null;
    
    // Skip if not in_progress or wanted
    if ($status !== 'in_progress' && $status !== 'wanted' && $status !== null) {
        continue;
    }
    
    error_log('[Background Tasks] ' . $baseName . ': Processing AI painting variants (status: ' . ($status ?? 'null') . ')');
    $result = poll_ai_painting_variants($baseName, $jsonPath);
    
    if ($result['ok']) {
        if (isset($result['still_processing']) && $result['still_processing']) {
            error_log('[Background Tasks] ' . $baseName . ': AI painting variants still processing, will check again next run');
            $results['skipped'][] = [
                'base' => $baseName,
                'task' => 'ai_painting_variants',
                'reason' => 'still_processing'
            ];
            continue;
        }
        if (isset($result['completed']) && $result['completed']) {
            error_log('[Background Tasks] ' . $baseName . ': AI painting variants completed successfully');
            $results['processed'][] = [
                'base' => $baseName,
                'task' => 'ai_painting_variants',
                'status' => 'success'
            ];
            $processedCount++;
        } elseif (isset($result['started']) && $result['started']) {
            error_log('[Background Tasks] ' . $baseName . ': AI painting variants started (' . $result['started'] . ' variants)');
            $results['processed'][] = [
                'base' => $baseName,
                'task' => 'ai_painting_variants',
                'status' => 'started',
                'started' => $result['started']
            ];
        }
    } else {
        error_log('[Background Tasks] ' . $baseName . ': AI painting variants failed: ' . ($result['error'] ?? 'Unknown error'));
        $results['errors'][] = [
            'base' => $baseName,
            'task' => 'ai_painting_variants',
            'error' => $result['error']
        ];
    }
}

// ============================================
// PHASE 2.6: Process individual variant predictions
// ============================================
error_log('[Background Tasks] === PHASE 2.6: Processing individual variant predictions ===');
$variantResult = process_individual_variants();
if ($variantResult['ok'] && $variantResult['processed'] > 0) {
    error_log('[Background Tasks] Processed ' . $variantResult['processed'] . ' individual variant(s)');
    $results['processed'][] = [
        'task' => 'individual_variants',
        'status' => 'success',
        'processed' => $variantResult['processed']
    ];
    if (!empty($variantResult['errors'])) {
        foreach ($variantResult['errors'] as $error) {
            $results['errors'][] = [
                'task' => 'individual_variants',
                'error' => $error['error'],
                'variant' => $error['variant']
            ];
        }
    }
}

// ============================================
// PHASE 3: Process variant generation/regeneration (only if no AI tasks pending)
// ============================================
error_log('[Background Tasks] === PHASE 3: Processing variant tasks ===');
foreach ($jsonFiles as $item) {
    if ($processedCount >= $maxProcessPerRun) {
        error_log('[Background Tasks] Processing limit reached (' . $maxProcessPerRun . '), stopping phase 3');
        break;
    }
    
    $baseName = $item['baseName'];
    $jsonPath = $item['jsonPath'];
    $meta = $item['meta'];
    
    // Reload meta to get latest status after AI processing
    $imageFilename = basename($jsonPath, '.json');
    $imagesDir = dirname($jsonPath);
    $meta = load_meta($imageFilename, $imagesDir);
    
    // Check if there are still pending AI tasks - if so, skip variants
    $aiNeeds = check_ai_generation_needed($meta);
    if ($aiNeeds['corners'] || $aiNeeds['form']) {
        error_log('[Background Tasks] ' . $baseName . ': Skipping variants (AI tasks still pending)');
        continue;
    }
    
    // Check variant generation (create variants that are in active_variants but don't exist)
    $activeVariants = $meta['active_variants'] ?? [];
    if (!empty($activeVariants) && is_array($activeVariants)) {
        // Check if any variant files are missing
        $missingVariants = [];
        foreach ($activeVariants as $variantName) {
            $variantFile = $baseName . '_variant_' . $variantName . '.jpg';
            $variantPath = $imagesDir . '/' . $variantFile;
            if (!is_file($variantPath)) {
                $missingVariants[] = $variantName;
            }
        }
        
        if (!empty($missingVariants)) {
            error_log('[Background Tasks] ' . $baseName . ': Missing ' . count($missingVariants) . ' variant(s): ' . implode(', ', $missingVariants));
            
            // Find final image filename for reporting
            $finalImageFile = null;
            $files = scandir($imagesDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $fileStem = pathinfo($file, PATHINFO_FILENAME);
                if (strpos($fileStem, $baseName.'_final') === 0) {
                    $finalImageFile = $file;
                    break;
                }
            }
            
            error_log('[Background Tasks] ' . $baseName . ': Processing variant generation for ' . count($missingVariants) . ' missing variant(s)');
            $result = process_variant_generation($baseName, $jsonPath, $imagesDir);
            if ($result['ok']) {
                if (!empty($result['created'])) {
                    error_log('[Background Tasks] ' . $baseName . ': Successfully created ' . count($result['created']) . ' variant(s): ' . implode(', ', $result['created']));
                    $results['processed'][] = [
                        'base' => $baseName,
                        'task' => 'variant_generation',
                        'status' => 'success',
                        'image' => $finalImageFile ?? $baseName . '_final.jpg',
                        'created' => count($result['created']),
                        'variants' => $result['created']
                    ];
                    $processedCount++;
                }
                if (!empty($result['errors'])) {
                    error_log('[Background Tasks] ' . $baseName . ': Variant generation errors: ' . json_encode($result['errors']));
                    $results['errors'][] = [
                        'base' => $baseName,
                        'task' => 'variant_generation',
                        'error' => 'Some variants failed',
                        'image' => $finalImageFile ?? $baseName . '_final.jpg',
                        'details' => $result['errors']
                    ];
                }
            } else {
                error_log('[Background Tasks] ' . $baseName . ': Variant generation failed: ' . ($result['error'] ?? 'Unknown error'));
                $results['errors'][] = [
                    'base' => $baseName,
                    'task' => 'variant_generation',
                    'error' => $result['error'] ?? 'Unknown error',
                    'image' => $finalImageFile ?? $baseName . '_final.jpg'
                ];
            }
            continue; // Skip regeneration check if we just generated variants
        }
    }
    
    // Check variant regeneration (regenerate existing variants if _final is newer)
    if (check_variant_regeneration_needed($baseName, $jsonPath, $imagesDir)) {
        error_log('[Background Tasks] ' . $baseName . ': Variant regeneration needed');
        if (is_task_in_progress($meta, 'variant_regeneration')) {
            error_log('[Background Tasks] ' . $baseName . ': Skipping variant regeneration (already in progress)');
            $results['skipped'][] = ['base' => $baseName, 'task' => 'variant_regeneration', 'reason' => 'in_progress'];
        } else {
            error_log('[Background Tasks] ' . $baseName . ': Processing variant regeneration');
            $result = process_variant_regeneration($baseName, $jsonPath);
            if ($result['ok']) {
                error_log('[Background Tasks] ' . $baseName . ': Variant regeneration completed successfully');
                $results['processed'][] = ['base' => $baseName, 'task' => 'variant_regeneration', 'status' => 'success'];
                $processedCount++;
            } else {
                error_log('[Background Tasks] ' . $baseName . ': Variant regeneration failed: ' . ($result['error'] ?? 'Unknown error'));
                $results['errors'][] = ['base' => $baseName, 'task' => 'variant_regeneration', 'error' => $result['error']];
            }
        }
    }
    
    // Clean up orphaned variants (files that exist but aren't in active_variants)
    $cleanupResult = cleanup_orphaned_variants($baseName, $jsonPath, $imagesDir);
    if (!empty($cleanupResult['cleaned'])) {
        error_log('[Background Tasks] ' . $baseName . ': Cleaned up ' . count($cleanupResult['cleaned']) . ' orphaned variant file(s): ' . implode(', ', $cleanupResult['cleaned']));
        $results['processed'][] = ['base' => $baseName, 'task' => 'variant_cleanup', 'status' => 'success', 'cleaned' => count($cleanupResult['cleaned'])];
    }
    if (!empty($cleanupResult['errors'])) {
        error_log('[Background Tasks] ' . $baseName . ': Variant cleanup errors: ' . json_encode($cleanupResult['errors']));
        $results['errors'][] = ['base' => $baseName, 'task' => 'variant_cleanup', 'error' => 'Failed to delete some files', 'files' => $cleanupResult['errors']];
    }
}

error_log('[Background Tasks] Finished processing. Total JSON files checked: ' . $totalJsonFiles);
error_log('[Background Tasks] Tasks processed: ' . $processedCount . ' / ' . $maxProcessPerRun);

// Add summary with detailed breakdown
$aiTasks = array_filter($results['processed'], function($item) {
    return in_array($item['task'], ['ai_corners', 'ai_form']);
});
$aiErrors = array_filter($results['errors'], function($item) {
    return in_array($item['task'], ['ai_corners', 'ai_form']);
});
$aiSkipped = array_filter($results['skipped'], function($item) {
    return in_array($item['task'], ['ai_corners', 'ai_form']);
});

$results['summary'] = [
    'processed_count' => count($results['processed']),
    'skipped_count' => count($results['skipped']),
    'error_count' => count($results['errors']),
    'ai_tasks' => [
        'processed' => count($aiTasks),
        'errors' => count($aiErrors),
        'skipped' => count($aiSkipped),
        'total' => count($aiTasks) + count($aiErrors) + count($aiSkipped)
    ],
    'images_affected' => array_unique(array_merge(
        array_column($results['processed'], 'base'),
        array_column($results['errors'], 'base'),
        array_column($results['skipped'], 'base')
    ))
];

error_log('[Background Tasks] Summary:');
error_log('[Background Tasks]   - Processed: ' . $results['summary']['processed_count']);
error_log('[Background Tasks]   - Skipped: ' . $results['summary']['skipped_count']);
error_log('[Background Tasks]   - Errors: ' . $results['summary']['error_count']);
error_log('[Background Tasks]   - AI Tasks: ' . $results['summary']['ai_tasks']['processed'] . ' processed, ' . $results['summary']['ai_tasks']['errors'] . ' errors, ' . $results['summary']['ai_tasks']['skipped'] . ' skipped');
error_log('[Background Tasks]   - Images affected: ' . count($results['summary']['images_affected']) . ' (' . implode(', ', $results['summary']['images_affected']) . ')');

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

