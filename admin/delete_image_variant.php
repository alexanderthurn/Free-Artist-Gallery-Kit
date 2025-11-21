<?php
// Delete a variant image from the images directory
declare(strict_types=1);

require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');

$filename = trim($_POST['filename'] ?? '');
if ($filename === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'filename required']);
    exit;
}

$imagesDir = __DIR__ . '/images';
$filepath = $imagesDir . '/' . basename($filename);

// Extract base name and variant name before checking/deleting
// For variant files: IMG_2152_2_variant_wohnzimmer.jpg -> base: IMG_2152_2, variant: wohnzimmer
$variantName = null;
$base = null;
$filenameStem = pathinfo($filename, PATHINFO_FILENAME);
$variantPos = strpos($filenameStem, '_variant_');
if ($variantPos !== false) {
    // Extract variant name (everything after '_variant_')
    $variantName = substr($filenameStem, $variantPos + 9); // +9 for '_variant_'
    // Extract base name (everything before '_variant_')
    $base = substr($filenameStem, 0, $variantPos);
} else {
    // Not a variant file, use extract_base_name as fallback
    $base = extract_base_name($filename);
}

// Only allow deleting variant files (those with _variant_ in the name)
if (strpos($filename, '_variant_') === false) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'can only delete variant files']);
    exit;
}

// Security: only allow deleting files in images directory
// Check if file exists first, then validate path
$fileExists = is_file($filepath);
if ($fileExists) {
    $realFilePath = realpath($filepath);
    $realImagesDir = realpath($imagesDir);
    if ($realFilePath === false || $realImagesDir === false || strpos($realFilePath, $realImagesDir) !== 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'invalid path']);
        exit;
    }
} else {
    // File doesn't exist - still allow removing from JSON if it's a valid variant name
    // Validate that the filename would be in the images directory
    $realImagesDir = realpath($imagesDir);
    if ($realImagesDir === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'images directory not found']);
        exit;
    }
    // Check if the constructed path would be in the images directory
    $normalizedPath = realpath(dirname($filepath));
    if ($normalizedPath === false || strpos($normalizedPath, $realImagesDir) !== 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'invalid path']);
        exit;
    }
}

// Delete the variant file if it exists
$fileDeleted = false;
if ($fileExists) {
    if (!unlink($filepath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'failed to delete']);
        exit;
    }
    $fileDeleted = true;
}

// Also delete thumbnail if it exists
$pathInfo = pathinfo($filepath);
$thumbPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_thumb.' . $pathInfo['extension'];
if (is_file($thumbPath)) {
    @unlink($thumbPath);
}

// Update JSON metadata to remove this variant from active variants list
// Always do this, even if file was already deleted (to clean up JSON)
if ($variantName !== null && $base !== '') {
    $jsonFile = find_json_file($base, $imagesDir);
    if ($jsonFile) {
        $metaPath = $imagesDir . '/' . $jsonFile;
        // Load existing metadata thread-safely
        $meta = [];
        if (is_file($metaPath)) {
            $metaContent = @file_get_contents($metaPath);
            if ($metaContent !== false) {
                $decoded = json_decode($metaContent, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
        }
        
        // Remove variant from active variants list inside ai_painting_variants
        $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
        $updated = false;
        
        if (isset($aiPaintingVariants['active_variants']) && is_array($aiPaintingVariants['active_variants'])) {
            $originalCount = count($aiPaintingVariants['active_variants']);
            $aiPaintingVariants['active_variants'] = array_values(array_filter($aiPaintingVariants['active_variants'], function($v) use ($variantName) {
                return $v !== $variantName;
            }));
            
            if (count($aiPaintingVariants['active_variants']) !== $originalCount) {
                $updated = true;
            }
        }
        
        // Also remove from variants tracking if it exists
        if (isset($aiPaintingVariants['variants'][$variantName])) {
            unset($aiPaintingVariants['variants'][$variantName]);
            $updated = true;
        }
        
        // Update global status based on remaining variants
        if ($updated) {
            $remainingVariants = $aiPaintingVariants['variants'] ?? [];
            $remainingActiveVariants = $aiPaintingVariants['active_variants'] ?? [];
            
            if (empty($remainingVariants) && empty($remainingActiveVariants)) {
                // No variants left - clear status or set to completed
                unset($aiPaintingVariants['status']);
                unset($aiPaintingVariants['started_at']);
                unset($aiPaintingVariants['completed_at']);
            } else {
                // Check if all remaining variants are completed
                $allCompleted = true;
                $anyInProgress = false;
                
                foreach ($remainingVariants as $vName => $vData) {
                    $vStatus = $vData['status'] ?? null;
                    if ($vStatus === 'in_progress') {
                        $anyInProgress = true;
                        $allCompleted = false;
                        break;
                    } elseif ($vStatus !== 'completed') {
                        $allCompleted = false;
                    }
                }
                
                if ($allCompleted && !$anyInProgress) {
                    $aiPaintingVariants['status'] = 'completed';
                    $aiPaintingVariants['completed_at'] = date('c');
                } elseif ($anyInProgress) {
                    $aiPaintingVariants['status'] = 'in_progress';
                } else {
                    // Some variants might be in other states (wanted, failed, etc.)
                    $aiPaintingVariants['status'] = 'in_progress';
                }
            }
            
            // Update JSON thread-safely
            update_json_file($metaPath, ['ai_painting_variants' => $aiPaintingVariants], false);
        }
    }
}

// Check if this image is already in gallery and update it
$galleryDir = dirname(__DIR__) . '/img/gallery/';
$galleryFilename = find_gallery_entry($base, $galleryDir);
$inGallery = $galleryFilename !== null;

// If in gallery, update the gallery entry to remove the deleted variant
if ($inGallery) {
    // Load metadata from images directory
    $jsonFile = find_json_file($base, $imagesDir);
    $metaFile = $jsonFile ? $imagesDir . '/' . $jsonFile : null;
    
    if (is_file($metaFile)) {
        $metaContent = file_get_contents($metaFile);
        $meta = json_decode($metaContent, true);
        if (is_array($meta)) {
            update_gallery_entry($base, $meta, $imagesDir, $galleryDir);
        }
    }
    
    // Trigger optimization to ensure gallery is updated
    // Optimization triggers removed - handled by background task processor
}

echo json_encode([
    'ok' => true, 
    'filename' => $filename, 
    'file_deleted' => $fileDeleted,
    'variant_removed_from_json' => $variantName !== null,
    'gallery_updated' => $inGallery
]);

