<?php
declare(strict_types=1);

require __DIR__.'/utils.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$image = isset($_POST['image']) ? basename((string)$_POST['image']) : '';
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
if ($image === '' || $action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing image or action']);
    exit;
}

$imagesDir = __DIR__.'/images/';
$imagePath = $imagesDir.$image;

if (!is_file($imagePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Image not found']);
    exit;
}

// Derive base key and extension from provided image (typically *_original.ext)
$ext = strtolower(pathinfo($image, PATHINFO_EXTENSION));
$stem = pathinfo($image, PATHINFO_FILENAME);
if (preg_match('/^(.*)_([A-Za-z0-9-]+)$/', $stem, $m)) {
    $base = $m[1];
    $variant = strtolower($m[2]);
} else {
    $base = $stem;
    $variant = 'base';
}
$originalName = $base.'_original'.($ext ? '.'.$ext : '');
$originalPath = $imagesDir.$originalName;
if (!is_file($originalPath)) {
    // fallback to provided image as source
    $originalPath = $imagePath;
}

try {
    if ($action === 'restore') {
        // Replace _final with _original (or create _final if it doesn't exist)
        $finalName = $base.'_final'.($ext ? '.'.$ext : '');
        $finalPath = $imagesDir.$finalName;
        
        // Check if _final exists with same extension
        if (!is_file($finalPath)) {
            // Try other extensions to find existing _final
            $extensions = ['png', 'jpg', 'jpeg', 'webp'];
            $existingFinalPath = null;
            $existingExt = null;
            foreach ($extensions as $e) {
                $testPath = $imagesDir.$base.'_final.'.$e;
                if (is_file($testPath)) {
                    $existingFinalPath = $testPath;
                    $existingExt = $e;
                    break;
                }
            }
            
            if ($existingFinalPath) {
                // Delete existing _final with different extension
                @unlink($existingFinalPath);
            }
        } else {
            // Delete existing _final with same extension
            @unlink($finalPath);
        }
        
        // Copy _original to _final
        if (!copy($originalPath, $finalPath)) {
            throw new RuntimeException('Failed to create/replace final image');
        }
        
        // Generate thumbnail for _final image
        $thumbPath = generate_thumbnail_path($finalPath);
        generate_thumbnail($finalPath, $thumbPath, 512, 1024);
        
        // Regenerate all variants after final image is updated
        require_once __DIR__ . '/variants.php';
        try {
            $regenerateResult = regenerateAllVariants($base);
            // Log result but don't fail if variant regeneration fails
            error_log("Variant regeneration for {$base}: " . json_encode($regenerateResult));
        } catch (Exception $e) {
            // Log error but don't fail the restore action
            error_log("Failed to regenerate variants for {$base}: " . $e->getMessage());
        }
        
        echo json_encode(['ok' => true]);
        exit;
    } elseif ($action === 'room') {
        http_response_code(501);
        echo json_encode(['ok' => false, 'error' => 'Generate room picture not implemented yet']);
        exit;
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}


