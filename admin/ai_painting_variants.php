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
    
    // Process each variant template
    foreach ($variantTemplates as $variantTemplate) {
        $variantName = $variantTemplate['variant_name'];
        $targetPath = $imagesDir . '/' . $imageBaseName . '_variant_' . $variantName . '.jpg';
        
        // Check if this variant is already in progress or completed
        $variantJsonPath = $variantsDir . '/' . $variantName . '.json';
        $variantMeta = [];
        if (is_file($variantJsonPath)) {
            $variantContent = @file_get_contents($variantJsonPath);
            if ($variantContent !== false) {
                $decoded = json_decode($variantContent, true);
                if (is_array($decoded)) {
                    $variantMeta = $decoded;
                }
            }
        }
        
        $variantStatus = $variantMeta['status'] ?? null;
        $variantPredictionUrl = $variantMeta['prediction_url'] ?? null;
        
        // If variant is already completed and target exists, skip
        if ($variantStatus === 'completed' && is_file($targetPath) && !$imageGenerationNeeded) {
            continue;
        }
        
        // If variant has a prediction URL and is in_progress, it will be polled by background task
        if ($variantPredictionUrl && $variantStatus === 'in_progress') {
            continue;
        }
        
        // Start new variant generation
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
            // Track this variant in the painting's metadata with all prediction details
            $variants[$variantName] = [
                'variant_name' => $variantName,
                'status' => 'in_progress',
                'started_at' => date('c'),
                'prediction_url' => $result['prediction_url'] ?? null,
                'prediction_id' => $result['prediction_id'] ?? null,
                'prediction_status' => $result['prediction_status'] ?? 'unknown',
                'target_path' => $targetPath,
                'variant_template_path' => $variantTemplate['variant_path'],
                'final_image_path' => $finalImage,
                'prompt' => $prompt,
                'prompt_final' => $result['prompt_final'] ?? null,
                'width' => $width,
                'height' => $height
            ];
        } else {
            $errors[] = [
                'variant' => $variantName,
                'error' => $result['error'] ?? 'Unknown error'
            ];
        }
    }
    
    // Update painting metadata with variant tracking
    $aiPaintingVariants['variants'] = $variants;
    $aiPaintingVariants['status'] = 'in_progress';
    if (!isset($aiPaintingVariants['started_at'])) {
        $aiPaintingVariants['started_at'] = date('c');
    }
    update_json_file($jsonPath, ['ai_painting_variants' => $aiPaintingVariants], false);
    
    return [
        'ok' => true,
        'started' => $started,
        'total' => count($variantTemplates),
        'errors' => $errors,
        'message' => $started > 0 ? "Started generation for {$started} variant(s)" : 'No variants started'
    ];
}

