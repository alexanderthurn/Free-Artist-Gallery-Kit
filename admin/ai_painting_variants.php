<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/ai_variant_by_prompt.php';

/**
 * Process AI painting variants generation
 * @param string $imageBaseName Base name of the image (e.g., "IMG_2106_2")
 * @param array|null $variantNames Optional array of variant names to generate. If null, generates all existing variants.
 * @return array Result array with 'ok' key and other data
 */
function process_ai_painting_variants(string $imageBaseName, ?array $variantNames = null): array {
    $imagesDir = __DIR__ . '/images';
    $variantsDir = __DIR__ . '/variants';
    
    if (!is_dir($variantsDir)) {
        mkdir($variantsDir, 0755, true);
    }
    
    // Find the _final image for this base
    $finalImage = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $imageBaseName.'_final') === 0) {
            $finalImage = $imagesDir . '/' . $file;
            break;
        }
    }
    
    if (!$finalImage || !is_file($finalImage)) {
        return ['ok' => false, 'error' => 'final_image_not_found', 'base' => $imageBaseName];
    }
    
    // Find all variant templates
    $variantTemplates = [];
    $variantFiles = scandir($variantsDir) ?: [];
    foreach ($variantFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        // Skip JSON files
        if ($ext === 'json') continue;
        
        // Only process image files
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $variantTemplates[] = [
                'variant_name' => $fileStem,
                'variant_template' => $file,
                'variant_path' => $variantsDir . '/' . $file
            ];
        }
    }
    
    if (empty($variantTemplates)) {
        return ['ok' => false, 'error' => 'no_variant_templates_found'];
    }
    
    // Filter by variantNames if provided
    if ($variantNames !== null && is_array($variantNames)) {
        $variantTemplates = array_filter($variantTemplates, function($vt) use ($variantNames) {
            return in_array($vt['variant_name'], $variantNames, true);
        });
    }
    
    if (empty($variantTemplates)) {
        return ['ok' => false, 'error' => 'no_matching_variant_templates'];
    }
    
    // Load metadata to get dimensions
    $originalImageFile = $imageBaseName . '_original.jpg';
    $meta = load_meta($originalImageFile, $imagesDir);
    $width = $meta['width'] ?? null;
    $height = $meta['height'] ?? null;
    
    // Load or initialize ai_painting_variants object
    $jsonFile = find_json_file($imageBaseName, $imagesDir);
    if (!$jsonFile) {
        return ['ok' => false, 'error' => 'json_file_not_found'];
    }
    
    $jsonPath = $imagesDir . '/' . $jsonFile;
    $existingMeta = [];
    if (is_file($jsonPath)) {
        $content = @file_get_contents($jsonPath);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $existingMeta = $decoded;
            }
        }
    }
    
    $aiPaintingVariants = $existingMeta['ai_painting_variants'] ?? [];
    $status = $aiPaintingVariants['status'] ?? null;
    
    // Check if there's already a Replicate response for variants
    // If status is completed but image_generation_needed is set, regenerate
    $imageGenerationNeeded = $aiPaintingVariants['image_generation_needed'] ?? false;
    
    // If status is not in_progress and not completed, start generation
    if ($status !== 'in_progress' && $status !== 'completed') {
        $aiPaintingVariants['status'] = 'in_progress';
        $aiPaintingVariants['started_at'] = date('c');
        $aiPaintingVariants['variants'] = [];
        update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
    }
    
    $finalMime = mime_content_type($finalImage);
    $prompt = <<<PROMPT
You are an image editor.

Task:
- Place the painting into the free space on the wall.
- Ensure the painting is properly scaled and positioned realistically.
- The painting should be centered or positioned appropriately on the wall.
- Maintain natural lighting and shadows.
PROMPT;
    
    $started = 0;
    $errors = [];
    $variants = $aiPaintingVariants['variants'] ?? [];
    
    // Initialize active_variants if not exists
    if (!isset($aiPaintingVariants['active_variants']) || !is_array($aiPaintingVariants['active_variants'])) {
        $aiPaintingVariants['active_variants'] = [];
    }
    
    // Process each variant template
    foreach ($variantTemplates as $variantTemplate) {
        $variantName = $variantTemplate['variant_name'];
        $targetPath = $imagesDir . '/' . $imageBaseName . '_variant_' . $variantName . '.jpg';
        
        // Check if this variant is already tracked in variants
        $existingVariant = $variants[$variantName] ?? null;
        $existingStatus = $existingVariant['status'] ?? null;
        $existingPredictionUrl = $existingVariant['prediction_url'] ?? null;
        
        // If variant is already completed and target exists, skip
        if ($existingStatus === 'completed' && is_file($targetPath) && !$imageGenerationNeeded) {
            // Ensure it's in active_variants
            if (!in_array($variantName, $aiPaintingVariants['active_variants'], true)) {
                $aiPaintingVariants['active_variants'][] = $variantName;
            }
            continue;
        }
        
        // If variant has a prediction URL and is in_progress, it will be polled by background task
        if ($existingPredictionUrl && $existingStatus === 'in_progress') {
            // Ensure it's in active_variants
            if (!in_array($variantName, $aiPaintingVariants['active_variants'], true)) {
                $aiPaintingVariants['active_variants'][] = $variantName;
            }
            continue;
        }
        
        // Add variant to active_variants immediately
        if (!in_array($variantName, $aiPaintingVariants['active_variants'], true)) {
            $aiPaintingVariants['active_variants'][] = $variantName;
        }
        
        // Create variant entry immediately (like ai_image_by_corners.php does)
        // This ensures the variant is tracked even if the API call fails
        $variants[$variantName] = [
            'variant_name' => $variantName,
            'status' => 'in_progress',
            'started_at' => date('c'),
            'prediction_url' => null, // Will be updated after API call
            'prediction_id' => null,
            'prediction_status' => 'unknown',
            'target_path' => $targetPath,
            'variant_template_path' => $variantTemplate['variant_path'],
            'final_image_path' => $finalImage,
            'prompt' => $prompt,
            'prompt_final' => null, // Will be updated after API call
            'width' => $width,
            'height' => $height
        ];
        
        // Save immediately (non-blocking, like ai_image_by_corners.php)
        $aiPaintingVariants['variants'] = $variants;
        $aiPaintingVariants['status'] = 'in_progress';
        if (!isset($aiPaintingVariants['started_at'])) {
            $aiPaintingVariants['started_at'] = date('c');
        }
        update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
        
        // Now start the async generation (non-blocking)
        $result = generate_variant_async(
            $variantName,
            $variantTemplate['variant_path'],
            $finalImage,
            $prompt,
            $targetPath,
            $width,
            $height
        );
        
        if ($result['ok'] && isset($result['prediction_started'])) {
            $started++;
            // Update variant with prediction details
            $variants[$variantName]['prediction_url'] = $result['prediction_url'] ?? null;
            $variants[$variantName]['prediction_id'] = $result['prediction_id'] ?? null;
            $variants[$variantName]['prediction_status'] = $result['prediction_status'] ?? 'unknown';
            $variants[$variantName]['prompt_final'] = $result['prompt_final'] ?? null;
            
            // Update metadata again with prediction URL
            $aiPaintingVariants['variants'] = $variants;
            update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
        } else {
            // API call failed - mark variant as wanted for retry
            $variants[$variantName]['status'] = 'wanted';
            $variants[$variantName]['error'] = $result['error'] ?? 'Unknown error';
            $aiPaintingVariants['variants'] = $variants;
            update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
            
            $errors[] = [
                'variant' => $variantName,
                'error' => $result['error'] ?? 'Unknown error'
            ];
        }
    }
    
    return [
        'ok' => true,
        'started' => $started,
        'total' => count($variantTemplates),
        'errors' => $errors,
        'message' => $started > 0 ? "Started generation for {$started} variant(s)" : 'No variants started'
    ];
}

