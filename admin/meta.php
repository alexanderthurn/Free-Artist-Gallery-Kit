<?php
declare(strict_types=1);

require_once __DIR__.'/utils.php';

/**
 * Get the JSON metadata file path for a given image filename
 * 
 * @param string $imageFilename The image filename (e.g., "IMG_2111_2_original.jpg")
 * @param string|null $imagesDir Optional images directory, defaults to __DIR__.'/images/'
 * @return string Full path to the JSON metadata file
 */
function get_meta_path(string $imageFilename, ?string $imagesDir = null): string {
    if ($imagesDir === null) {
        $imagesDir = __DIR__.'/images/';
    }
    // Ensure trailing slash
    if (substr($imagesDir, -1) !== '/') {
        $imagesDir .= '/';
    }
    $imagePath = $imagesDir . basename($imageFilename);
    // Don't add .json if it already ends with .json
    if (substr($imagePath, -5) === '.json') {
        return $imagePath;
    }
    return $imagePath . '.json';
}

/**
 * Load metadata for an image
 * 
 * @param string $imageFilename The image filename (e.g., "IMG_2111_2_original.jpg")
 * @param string|null $imagesDir Optional images directory, defaults to __DIR__.'/images/'
 * @return array The metadata array, or empty array if file doesn't exist or is invalid
 */
function load_meta(string $imageFilename, ?string $imagesDir = null): array {
    $metaPath = get_meta_path($imageFilename, $imagesDir);
    
    if (!is_file($metaPath)) {
        return [];
    }
    
    $content = @file_get_contents($metaPath);
    if ($content === false) {
        return [];
    }
    
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return [];
    }
    
    return $decoded;
}

/**
 * Save metadata for an image (thread-safe partial update)
 * 
 * @param string $imageFilename The image filename (e.g., "IMG_2111_2_original.jpg")
 * @param array $updates Associative array of fields to update (only these fields will be updated)
 * @param string|null $imagesDir Optional images directory, defaults to __DIR__.'/images/'
 * @param bool $ensureOriginalFilename If true, ensures original_filename is set
 * @return array Result array with 'ok' (bool) and optional 'error' (string)
 */
function save_meta(string $imageFilename, array $updates, ?string $imagesDir = null, bool $ensureOriginalFilename = true): array {
    if ($imagesDir === null) {
        $imagesDir = __DIR__.'/images/';
    }
    
    // Ensure trailing slash
    if (substr($imagesDir, -1) !== '/') {
        $imagesDir .= '/';
    }
    
    $imagePath = $imagesDir . basename($imageFilename);
    if (!is_file($imagePath)) {
        return ['ok' => false, 'error' => 'Image not found'];
    }
    
    $metaPath = get_meta_path($imageFilename, $imagesDir);
    
    // Ensure original_filename is set if requested
    if ($ensureOriginalFilename && !isset($updates['original_filename'])) {
        $existingMeta = load_meta($imageFilename, $imagesDir);
        if (!isset($existingMeta['original_filename'])) {
            $updates['original_filename'] = extract_base_name($imageFilename);
        }
    }
    
    // Handle special cases for AI status fields (clear timestamps when status is cleared)
    // Note: Status is now stored in ai_corners.status and ai_fill_form.status
    // This code handles legacy top-level status fields for backward compatibility
    if (isset($updates['ai_corners_status']) && $updates['ai_corners_status'] === '') {
        $updates['ai_corners_status'] = null;
        // Also clear nested status if it exists
        if (isset($updates['ai_corners']) && is_array($updates['ai_corners'])) {
            $updates['ai_corners']['started_at'] = null;
            $updates['ai_corners']['completed_at'] = null;
        }
    }
    if (isset($updates['ai_form_status']) && $updates['ai_form_status'] === '') {
        $updates['ai_form_status'] = null;
        // Also clear nested status if it exists
        if (isset($updates['ai_fill_form']) && is_array($updates['ai_fill_form'])) {
            $updates['ai_fill_form']['started_at'] = null;
            $updates['ai_fill_form']['completed_at'] = null;
        }
    }
    
    // Use thread-safe JSON update function
    $ok = update_json_file($metaPath, $updates, false);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Failed to write metadata'];
    }
    
    return ['ok' => true];
}

// HTTP endpoint functionality (only runs when accessed directly)
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    // GET: Load metadata
    if ($method === 'GET') {
        $image = isset($_GET['image']) ? basename((string)$_GET['image']) : '';
        if ($image === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing image parameter']);
            exit;
        }
        
        $imagesDir = __DIR__.'/images/';
        $imagePath = $imagesDir . $image;
        if (!is_file($imagePath)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Image not found']);
            exit;
        }
        
        $meta = load_meta($image, $imagesDir);
        echo json_encode(['ok' => true, 'meta' => $meta]);
        exit;
    }
    
    // POST: Save metadata
    if ($method === 'POST') {
        $image = isset($_POST['image']) ? basename((string)$_POST['image']) : '';
        if ($image === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing image']);
            exit;
        }
        
        $imagesDir = __DIR__.'/images/';
        $imagePath = $imagesDir . $image;
        if (!is_file($imagePath)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Image not found']);
            exit;
        }
        
        // Prepare updates - only update fields that are present in POST
        $updates = [];
        
        if (isset($_POST['title'])) {
            $updates['title'] = trim((string)$_POST['title']);
        }
        if (isset($_POST['description'])) {
            $updates['description'] = trim((string)$_POST['description']);
        }
        if (isset($_POST['width'])) {
            $updates['width'] = trim((string)$_POST['width']);
        }
        if (isset($_POST['height'])) {
            $updates['height'] = trim((string)$_POST['height']);
        }
        if (isset($_POST['tags'])) {
            $updates['tags'] = trim((string)$_POST['tags']);
        }
        if (isset($_POST['date'])) {
            $updates['date'] = trim((string)$_POST['date']);
        }
        if (isset($_POST['sold'])) {
            $updates['sold'] = $_POST['sold'] === '1';
        }
        if (isset($_POST['frame_type'])) {
            $updates['frame_type'] = trim((string)$_POST['frame_type']);
        }
        if (isset($_POST['ai_corners_status'])) {
            $status = trim((string)$_POST['ai_corners_status']);
            // Update nested ai_corners.status
            $existingMeta = load_meta($image, $imagesDir);
            $aiCorners = $existingMeta['ai_corners'] ?? [];
            $aiCorners['status'] = $status === '' ? null : $status;
            if ($status === '') {
                $aiCorners['started_at'] = null;
                $aiCorners['completed_at'] = null;
            }
            $updates['ai_corners'] = $aiCorners;
        }
        if (isset($_POST['ai_form_status'])) {
            $status = trim((string)$_POST['ai_form_status']);
            // Update nested ai_fill_form.status
            $existingMeta = load_meta($image, $imagesDir);
            $aiFillForm = $existingMeta['ai_fill_form'] ?? [];
            $aiFillForm['status'] = $status === '' ? null : $status;
            if ($status === '') {
                $aiFillForm['started_at'] = null;
                $aiFillForm['completed_at'] = null;
            }
            $updates['ai_fill_form'] = $aiFillForm;
        }
        
        // Save metadata
        $result = save_meta($image, $updates, $imagesDir, true);
        
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode($result);
            exit;
        }
        
        // Reload metadata for gallery operations
        $meta = load_meta($image, $imagesDir);
        
        // Check if this entry should be in gallery (live status from JSON or existing gallery entry)
        $galleryDir = dirname(__DIR__).'/img/gallery/';
        $originalFilename = $meta['original_filename'] ?? extract_base_name($image);
        $galleryFilename = find_gallery_entry($originalFilename, $galleryDir);
        $inGallery = $galleryFilename !== null;
        
        // If live is true but not in gallery, copy it
        if (isset($meta['live']) && $meta['live'] === true && !$inGallery) {
            $result = update_gallery_entry($originalFilename, $meta, $imagesDir, $galleryDir);
            if ($result['ok']) {
                $inGallery = true;
            }
        } else if ($inGallery) {
            // If in gallery, automatically update using unified function
            update_gallery_entry($originalFilename, $meta, $imagesDir, $galleryDir);
        }
        
        echo json_encode(['ok' => true, 'in_gallery' => $inGallery]);
        exit;
    }
    
    // Method not allowed
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

