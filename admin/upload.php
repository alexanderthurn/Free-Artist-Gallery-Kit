<?php
// Simple multi-file upload handler
// Saves originals to admin/original/ and a working copy to admin/images/

declare(strict_types=1);

require_once __DIR__.'/utils.php';

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function count_existing_paintings(string $imagesDir): int {
    $count = 0;
    if (!is_dir($imagesDir)) {
        return 0;
    }
    
    $files = scandir($imagesDir) ?: [];
    $bases = [];
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        // Count unique paintings by looking for _original images or JSON files
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $stem = pathinfo($file, PATHINFO_FILENAME);
            // Check if it's an _original image
            if (preg_match('/^(.+)_original$/i', $stem, $matches)) {
                $base = $matches[1];
                if (!isset($bases[$base])) {
                    $bases[$base] = true;
                    $count++;
                }
            }
        } elseif ($ext === 'json') {
            // Count JSON files (each represents a painting)
            $stem = pathinfo($file, PATHINFO_FILENAME);
            // Remove .json extension and check if it corresponds to an _original image
            if (preg_match('/^(.+)_original$/', $stem, $matches)) {
                $base = $matches[1];
                if (!isset($bases[$base])) {
                    $bases[$base] = true;
                    $count++;
                }
            }
        }
    }
    
    return $count;
}

function sanitize_filename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return trim($name ?? '', '_');
}

function unique_path(string $dir, string $filename): string {
    $path = rtrim($dir, '/').'/'.$filename;
    if (!file_exists($path)) return $path;
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $i = 1;
    do {
        $candidate = rtrim($dir, '/').'/'.$base.'-'.$i.($ext ? '.'.$ext : '');
        $i++;
    } while (file_exists($candidate));
    return $candidate;
}

function is_allowed_image(string $tmpFile, string $originalName): bool {
    $allowedExt = ['jpg','jpeg','png','webp'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return false;
    $info = @getimagesize($tmpFile);
    if ($info === false) return false;
    return in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

if (!isset($_FILES['images'])) {
    http_response_code(400);
    echo 'No files uploaded';
    exit;
}

$dirImages = __DIR__.'/images';
ensure_dir($dirImages);

$isAIUpload = isset($_POST['ai_upload']) && $_POST['ai_upload'] === '1';

// Count existing paintings for non-AI uploads to assign title numbers
$existingPaintingCount = 0;
if (!$isAIUpload) {
    $existingPaintingCount = count_existing_paintings($dirImages);
}

$files = $_FILES['images'];
$count = is_array($files['name']) ? count($files['name']) : 0;
$uploaded = 0;
$errors = [];
$uploadedImages = [];

for ($i = 0; $i < $count; $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = $files['name'][$i].' (upload error '.$files['error'][$i].')';
        continue;
    }
    $tmp = $files['tmp_name'][$i];
    $name = sanitize_filename($files['name'][$i]);
    if ($name === '') {
        $errors[] = 'Invalid filename';
        continue;
    }
    if (!is_allowed_image($tmp, $name)) {
        $errors[] = $name.' (unsupported format)';
        continue;
    }

    // Save into images/ with _original postfix as JPG
    $stem = pathinfo($name, PATHINFO_FILENAME);
    // Avoid double _original if already present
    if (!preg_match('/_original$/i', $stem)) {
        $stem .= '_original';
    }
    $destName = $stem.'.jpg';
    $target = unique_path($dirImages, $destName);
    
    // Convert image to JPG (handles PNG, WEBP, JPG) - keep full resolution
    // Only resize if server gives an error (file too large)
    $converted = false;
    if (!convert_to_jpg($tmp, $target)) {
        // Try to resize if conversion failed (might be too large)
        $imageInfo = @getimagesize($tmp);
        if ($imageInfo !== false) {
            $src = image_create_from_any($tmp);
            if ($src) {
                $srcW = imagesx($src);
                $srcH = imagesy($src);
                // Resize to max 2048px on longest side if larger
                $maxDim = 2048;
                if ($srcW > $maxDim || $srcH > $maxDim) {
                    $scale = min($maxDim / $srcW, $maxDim / $srcH);
                    $newW = (int) floor($srcW * $scale);
                    $newH = (int) floor($srcH * $scale);
                    $dst = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
                    $converted = imagejpeg($dst, $target, 90);
                    imagedestroy($dst);
                } else {
                    // Within limits, try direct conversion again
                    $converted = convert_to_jpg($tmp, $target);
                }
                imagedestroy($src);
            }
        }
        if (!$converted) {
            $errors[] = $name.' (failed to convert/save)';
            continue;
        }
    }

    // Store uploaded image name for AI processing
    $uploadedImages[] = basename($target);

    // Also create _final.jpg copy (overwrite if exists) - keep full resolution
    $finalStem = preg_replace('/_original$/i', '', $stem);
    $finalName = $finalStem.'_final.jpg';
    $finalTarget = $dirImages.'/'.$finalName;
    
    // Remove existing _final if it exists
    if (file_exists($finalTarget)) {
        @unlink($finalTarget);
    }
    
    // Copy the JPG to _final (full resolution)
    copy($target, $finalTarget);
    
    // Generate thumbnail for _final image (similar to optimize_images.php)
    $thumbPath = generate_thumbnail_path($finalTarget);
    generate_thumbnail($finalTarget, $thumbPath, 512, 1024);

    // For non-AI uploads, create JSON metadata file with title "#n" and frame "white"
    if (!$isAIUpload) {
        $baseName = extract_base_name(basename($target));
        $jsonPath = $target . '.json';
        
        // Only create JSON if it doesn't exist
        if (!is_file($jsonPath)) {
            $paintingNumber = $existingPaintingCount + $uploaded + 1;
            $metaData = [
                'title' => '#' . $paintingNumber,
                'description' => '',
                'width' => '',
                'height' => '',
                'tags' => '',
                'date' => '',
                'sold' => false,
                'frame_type' => 'white',
                'original_filename' => $baseName
            ];
            
            file_put_contents($jsonPath, json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    $uploaded++;
}

// Return JSON response for AI uploads
if ($isAIUpload) {
    header('Content-Type: application/json; charset=utf-8');
    if ($errors) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'Upload errors occurred',
            'errors' => $errors,
            'uploaded' => $uploaded,
            'uploaded_images' => $uploadedImages
        ]);
    } else {
        echo json_encode([
            'ok' => true,
            'uploaded' => $uploaded,
            'uploaded_images' => $uploadedImages
        ]);
    }
    exit;
}

// Regular redirect response for non-AI uploads
$query = http_build_query([
    'uploaded' => $uploaded,
    'errors' => $errors ? count($errors) : 0,
]);

header('Location: admin.html?'.$query);
exit;


