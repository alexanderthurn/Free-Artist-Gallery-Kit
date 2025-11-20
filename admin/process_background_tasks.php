<?php
declare(strict_types=1);

// Continue execution even if user closes browser/connection
ignore_user_abort(true);

// Increase execution time limit for long-running operations (10 minutes)
set_time_limit(600);

require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
    $cornersStatus = $meta['ai_corners_status'] ?? null;
    $formStatus = $meta['ai_form_status'] ?? null;
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
    require_once __DIR__ . '/ai_calc_corners.php';
    
    $imagesDir = __DIR__ . '/images';
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
        // Call ai_image_by_corners logic
        // We need to call it via HTTP or extract the logic
        // For now, let's use HTTP POST to the existing endpoint
        $imagePath = 'admin/images/' . basename($originalImage);
        
        // Use curl to call the endpoint
        $ch = curl_init();
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = $scheme . '://' . $host . '/admin/ai_image_by_corners.php';
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['image_path' => $imagePath]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || $response === false) {
            update_task_status($jsonPath, 'ai_corners', 'wanted'); // Retry
            return ['ok' => false, 'error' => 'HTTP request failed', 'code' => $httpCode];
        }
        
        $result = json_decode($response, true);
        if (!is_array($result) || !($result['ok'] ?? false)) {
            update_task_status($jsonPath, 'ai_corners', 'wanted'); // Retry
            return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
        }
        
        // Update status to completed
        update_task_status($jsonPath, 'ai_corners', 'completed');
        
        return ['ok' => true, 'result' => $result];
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
        // Call ai_fill_form via HTTP
        $ch = curl_init();
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = $scheme . '://' . $host . '/admin/ai_fill_form.php';
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['image' => $imageFile]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || $response === false) {
            update_task_status($jsonPath, 'ai_form', 'wanted'); // Retry
            return ['ok' => false, 'error' => 'HTTP request failed', 'code' => $httpCode];
        }
        
        $result = json_decode($response, true);
        if (!is_array($result) || !($result['ok'] ?? false)) {
            update_task_status($jsonPath, 'ai_form', 'wanted'); // Retry
            return ['ok' => false, 'error' => $result['error'] ?? 'Unknown error'];
        }
        
        // Update status to completed
        update_task_status($jsonPath, 'ai_form', 'completed');
        
        return ['ok' => true, 'result' => $result];
    } catch (Throwable $e) {
        update_task_status($jsonPath, 'ai_form', 'wanted'); // Retry
        return ['ok' => false, 'error' => $e->getMessage()];
    }
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
    // Use existing optimize_images.php logic
    // This is handled by optimize_images.php which checks file times
    // We just need to trigger it, but we'll do it via the existing endpoint
    
    try {
        $ch = curl_init();
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = $scheme . '://' . $host . '/admin/optimize_images.php';
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['action' => 'both']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || $response === false) {
            return ['ok' => false, 'error' => 'HTTP request failed', 'code' => $httpCode];
        }
        
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Main processing loop
$files = scandir($imagesDir) ?: [];
$processedCount = 0;
$maxProcessPerRun = 10; // Limit to prevent timeout

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;
    
    // Check if it's an _original.json file
    // File format: {baseName}_original.jpg.json
    // After pathinfo filename: {baseName}_original.jpg
    $stem = pathinfo($file, PATHINFO_FILENAME);
    if (!preg_match('/_original\.jpg$/', $stem)) {
        continue;
    }
    
    if ($processedCount >= $maxProcessPerRun) {
        break; // Limit processing per run
    }
    
    // Extract baseName: remove _original.jpg suffix
    $baseName = preg_replace('/_original\.jpg$/', '', $stem);
    $jsonPath = $imagesDir . '/' . $file;
    
    $content = @file_get_contents($jsonPath);
    if ($content === false) continue;
    
    $meta = json_decode($content, true);
    if (!is_array($meta)) continue;
    
    // Check variant generation FIRST (create variants that are in active_variants but don't exist)
    // This has priority over regeneration because we need to create missing variants before regenerating existing ones
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
        
        if (!empty($missingVariants) && $processedCount < $maxProcessPerRun) {
            // Process variant generation
            $result = process_variant_generation($baseName, $jsonPath, $imagesDir);
            if ($result['ok']) {
                if (!empty($result['created'])) {
                    $results['processed'][] = ['base' => $baseName, 'task' => 'variant_generation', 'status' => 'success', 'created' => count($result['created']), 'variants' => $result['created']];
                    $processedCount++;
                }
                if (!empty($result['errors'])) {
                    $results['errors'][] = ['base' => $baseName, 'task' => 'variant_generation', 'error' => 'Some variants failed', 'details' => $result['errors']];
                }
                // If no variants were created but there were no errors, log it for debugging
                if (empty($result['created']) && empty($result['errors'])) {
                    $results['skipped'][] = ['base' => $baseName, 'task' => 'variant_generation', 'reason' => 'no_variants_created', 'missing' => $missingVariants];
                }
            } else {
                $results['errors'][] = ['base' => $baseName, 'task' => 'variant_generation', 'error' => $result['error'] ?? 'Unknown error'];
            }
            continue; // Process one task per painting per run
        } elseif (!empty($missingVariants)) {
            // Missing variants but processedCount limit reached
            $results['skipped'][] = ['base' => $baseName, 'task' => 'variant_generation', 'reason' => 'limit_reached', 'missing' => $missingVariants];
        }
    }
    
    // Check variant regeneration (regenerate existing variants if _final is newer)
    if (check_variant_regeneration_needed($baseName, $jsonPath, $imagesDir)) {
        if (is_task_in_progress($meta, 'variant_regeneration')) {
            $results['skipped'][] = ['base' => $baseName, 'task' => 'variant_regeneration', 'reason' => 'in_progress'];
            continue;
        }
        
        $result = process_variant_regeneration($baseName, $jsonPath);
        if ($result['ok']) {
            $results['processed'][] = ['base' => $baseName, 'task' => 'variant_regeneration', 'status' => 'success'];
            $processedCount++;
        } else {
            $results['errors'][] = ['base' => $baseName, 'task' => 'variant_regeneration', 'error' => $result['error']];
        }
        continue; // Process one task per painting per run
    }
    
    // Check AI generation
    $aiNeeds = check_ai_generation_needed($meta);
    if ($aiNeeds['corners'] || $aiNeeds['form']) {
        // Check if the specific task we want to process is in progress
        // For parallel processing, we check each task separately
        $skipCorners = false;
        $skipForm = false;
        
        if ($aiNeeds['corners']) {
            $cornersStatus = $meta['ai_corners_status'] ?? null;
            if ($cornersStatus === 'in_progress' && is_task_in_progress($meta, 'ai_corners')) {
                $skipCorners = true;
                $results['skipped'][] = ['base' => $baseName, 'task' => 'ai_corners', 'reason' => 'in_progress'];
            }
        }
        
        if ($aiNeeds['form']) {
            $formStatus = $meta['ai_form_status'] ?? null;
            if ($formStatus === 'in_progress' && is_task_in_progress($meta, 'ai_form')) {
                $skipForm = true;
                $results['skipped'][] = ['base' => $baseName, 'task' => 'ai_form', 'reason' => 'in_progress'];
            }
        }
        
        // If both are skipped, continue to next painting
        if ($skipCorners && $skipForm) {
            continue;
        }
        
        // Process corners if needed and not skipped
        if ($aiNeeds['corners'] && !$skipCorners) {
            $result = process_ai_corners($baseName, $jsonPath);
            if ($result['ok']) {
                $results['processed'][] = ['base' => $baseName, 'task' => 'ai_corners', 'status' => 'success'];
                $processedCount++;
            } else {
                $results['errors'][] = ['base' => $baseName, 'task' => 'ai_corners', 'error' => $result['error']];
            }
        }
        
        // Process form if needed and not skipped (can run in parallel with corners)
        if ($aiNeeds['form'] && !$skipForm && $processedCount < $maxProcessPerRun) {
            $result = process_ai_form($baseName, $jsonPath);
            if ($result['ok']) {
                $results['processed'][] = ['base' => $baseName, 'task' => 'ai_form', 'status' => 'success'];
                $processedCount++;
            } else {
                $results['errors'][] = ['base' => $baseName, 'task' => 'ai_form', 'error' => $result['error']];
            }
        }
        
        continue; // Process one painting per run
    }
    
    // Clean up orphaned variants (files that exist but aren't in active_variants)
    // Always check for cleanup to ensure orphaned files are removed
    $cleanupResult = cleanup_orphaned_variants($baseName, $jsonPath, $imagesDir);
    if (!empty($cleanupResult['cleaned'])) {
        $results['processed'][] = ['base' => $baseName, 'task' => 'variant_cleanup', 'status' => 'success', 'cleaned' => count($cleanupResult['cleaned'])];
    }
    if (!empty($cleanupResult['errors'])) {
        $results['errors'][] = ['base' => $baseName, 'task' => 'variant_cleanup', 'error' => 'Failed to delete some files', 'files' => $cleanupResult['errors']];
    }
}

// Add summary
$results['summary'] = [
    'processed_count' => count($results['processed']),
    'skipped_count' => count($results['skipped']),
    'error_count' => count($results['errors'])
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

