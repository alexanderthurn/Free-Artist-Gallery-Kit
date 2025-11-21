<?php
// admin/variants.php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/meta.php';

// Check if this file is being called directly (not required)
// When required, $_SERVER['SCRIPT_NAME'] will be the calling script, not variants.php
$isDirectCall = (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === 'variants.php') ||
                (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) === 'variants.php');

if ($isDirectCall) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $TOKEN = load_replicate_token();
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'missing REPLICATE_API_TOKEN']);
        exit;
    }
    
    $variantsDir = __DIR__ . '/variants';
    if (!is_dir($variantsDir)) {
      mkdir($variantsDir, 0755, true);
    }
    
    // ---- Action routing ----
    $action = $_POST['action'] ?? $_GET['action'] ?? 'list';
    
    switch ($action) {
      case 'list':
        handleList();
        break;
      case 'check_name':
        handleCheckName();
        break;
      case 'generate':
        handleGenerate();
        break;
      case 'upload':
        handleUpload();
        break;
      case 'rename':
        handleRename();
        break;
      case 'delete':
        handleDelete();
        break;
      case 'copy_to_image':
        handleCopyToImage();
        break;
      case 'regenerate':
        handleRegenerate();
        break;
      default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error'=>'unknown action']);
        exit;
    }
}

// Initialize variants directory if not already done
if (!isset($variantsDir)) {
    $variantsDir = __DIR__ . '/variants';
    if (!is_dir($variantsDir)) {
      mkdir($variantsDir, 0755, true);
    }
}

// Load token if not already loaded (for function calls)
if (!isset($TOKEN)) {
    try {
        $TOKEN = load_replicate_token();
    } catch (RuntimeException $e) {
        // Token will be loaded when needed in functions
    }
}

function sanitizeFilename($name) {
  // Remove extension if present, sanitize, then add .jpg
  $name = pathinfo($name, PATHINFO_FILENAME);
  // Remove dangerous characters, keep alphanumeric, spaces, hyphens, underscores
  $name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name);
  $name = trim($name);
  // Replace spaces with underscores
  $name = preg_replace('/\s+/', '_', $name);
  // Limit length
  if (strlen($name) > 100) {
    $name = substr($name, 0, 100);
  }
  // Ensure not empty
  if ($name === '') {
    $name = 'variant_' . date('Y-m-d_His');
  }
  return $name . '.jpg';
}

function handleCheckName() {
  global $variantsDir;
  
  $name = trim($_POST['name'] ?? $_GET['name'] ?? '');
  if ($name === '') {
    echo json_encode(['ok' => true, 'exists' => false]);
    exit;
  }
  
  $filename = sanitizeFilename($name);
  $filepath = $variantsDir . '/' . $filename;
  $exists = is_file($filepath);
  
  echo json_encode([
    'ok' => true,
    'exists' => $exists,
    'filename' => $filename
  ]);
}

function handleList() {
  global $variantsDir;
  $files = [];
  $processedNames = []; // Track which variant names we've already added
  
  if (is_dir($variantsDir)) {
    $items = scandir($variantsDir) ?: [];
    
    // First, process image files
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') continue;
      $path = $variantsDir . '/' . $item;
      if (!is_file($path)) continue;
      $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg','jpeg'])) continue;
      
      $baseName = pathinfo($item, PATHINFO_FILENAME);
      $jsonPath = $variantsDir . '/' . $baseName . '.json';
      
      $status = null;
      $prediction_url = null;
      if (is_file($jsonPath)) {
        $jsonContent = @file_get_contents($jsonPath);
        if ($jsonContent !== false) {
          $jsonData = json_decode($jsonContent, true);
          if (is_array($jsonData)) {
            $status = $jsonData['status'] ?? null;
            $prediction_url = $jsonData['prediction_url'] ?? null;
          }
        }
      }
      
      $processedNames[] = $baseName;
      $prompt = null;
      $type = null;
      if (is_file($jsonPath)) {
        $jsonContent = @file_get_contents($jsonPath);
        if ($jsonContent !== false) {
          $jsonData = json_decode($jsonContent, true);
          if (is_array($jsonData)) {
            $type = $jsonData['type'] ?? null;
            // Only use prompt for template variants (type === 'template')
            // For template variants, prompt is the original user prompt
            if ($type === 'template') {
              $prompt = $jsonData['prompt'] ?? null;
            }
          }
        }
      }
      
      $files[] = [
        'name' => $item,
        'url' => '/admin/variants/' . rawurlencode($item),
        'mtime' => filemtime($path),
        'size' => filesize($path),
        'status' => $status,
        'prediction_url' => $prediction_url,
        'pending' => false,
        'prompt' => $prompt,
        'type' => $type
      ];
    }
    
    // Then, process JSON files for in-progress variants without image files
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') continue;
      $path = $variantsDir . '/' . $item;
      if (!is_file($path)) continue;
      $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
      if ($ext !== 'json') continue;
      
      $baseName = pathinfo($item, PATHINFO_FILENAME);
      
      // Skip if we already processed this variant (it has an image file)
      if (in_array($baseName, $processedNames)) continue;
      
      $jsonContent = @file_get_contents($path);
      if ($jsonContent === false) continue;
      
      $jsonData = json_decode($jsonContent, true);
      if (!is_array($jsonData)) continue;
      
      $status = $jsonData['status'] ?? null;
      $prediction_url = $jsonData['prediction_url'] ?? null;
      $filename = $jsonData['filename'] ?? ($baseName . '.jpg');
      
      // Only include if in_progress or wanted (pending)
      if ($status === 'in_progress' || $status === 'wanted') {
        $type = $jsonData['type'] ?? null;
        $prompt = null;
        // Only use prompt for template variants
        if ($type === 'template') {
          $prompt = $jsonData['prompt'] ?? null;
        }
        $files[] = [
          'name' => $filename,
          'url' => null, // No image file yet
          'mtime' => isset($jsonData['started_at']) ? strtotime($jsonData['started_at']) : filemtime($path),
          'size' => 0,
          'status' => $status,
          'prediction_url' => $prediction_url,
          'pending' => true,
          'variant_name' => $jsonData['variant_name'] ?? $baseName,
          'prompt' => $prompt,
          'type' => $type
        ];
      }
    }
  }
  
  // Sort by mtime desc
  usort($files, function($a, $b) {
    return $b['mtime'] - $a['mtime'];
  });
  echo json_encode(['ok' => true, 'variants' => $files]);
}

function handleGenerate() {
  global $TOKEN, $variantsDir;
  
  $prompt = trim($_POST['prompt'] ?? '');
  if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error'=>'prompt required']);
    exit;
  }
  
  $name = trim($_POST['name'] ?? '');
  $replace = ($_POST['replace'] ?? '') === 'true';
  
  // Generate filename
  if ($name !== '') {
    $filename = sanitizeFilename($name);
  } else {
    $timestamp = date('Y-m-d_His');
    $filename = 'variant_' . $timestamp . '.jpg';
  }
  
  $filepath = $variantsDir . '/' . $filename;
  
  // Check if file exists
  if (is_file($filepath) && !$replace) {
    http_response_code(409); // Conflict
    echo json_encode([
      'ok' => false,
      'error' => 'file_exists',
      'filename' => $filename,
      'message' => 'Eine Variante mit diesem Namen existiert bereits.'
    ]);
    exit;
  }
  
  // Use async variant template generation
  require_once __DIR__ . '/ai_variant_by_prompt.php';
  
  $variantBaseName = pathinfo($filename, PATHINFO_FILENAME);
  $result = generate_variant_template_async($variantBaseName, $prompt, $filepath);
  
  if (!$result['ok']) {
    http_response_code(502);
    echo json_encode([
      'ok' => false,
      'error' => $result['error'] ?? 'generation_failed',
      'detail' => $result['detail'] ?? 'Unknown error'
    ]);
    exit;
  }
  
  // Return immediately - JSON file is already created with in_progress status
  echo json_encode([
    'ok' => true,
    'prediction_started' => true,
    'filename' => $filename,
    'variant_name' => $variantBaseName,
    'prediction_url' => $result['prediction_url'] ?? null,
    'message' => 'Variant generation started, will be processed by background task'
  ]);
}


function handleUpload() {
  global $variantsDir;
  
  if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error'=>'upload failed']);
    exit;
  }
  
  $file = $_FILES['image'];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error'=>'invalid file type']);
    exit;
  }
  
  $name = trim($_POST['name'] ?? '');
  $replace = ($_POST['replace'] ?? '') === 'true';
  
  // Use original filename, but change extension to .jpg
  $originalFilename = basename($file['name']);
  $pathInfo = pathinfo($originalFilename);
  $baseName = $pathInfo['filename'];
  
  // If a custom name is provided, use it; otherwise use original filename
  if ($name !== '') {
    // Remove extension from custom name if present
    $customBaseName = pathinfo($name, PATHINFO_FILENAME);
    $filename = $customBaseName . '.jpg';
  } else {
    // Use original filename but change extension to .jpg
    $filename = $baseName . '.jpg';
  }
  
  $filepath = $variantsDir . '/' . $filename;
  
  // Handle conflicts by appending a number if file exists
  if (is_file($filepath) && !$replace) {
    $counter = 1;
    $pathInfo = pathinfo($filename);
    $baseName = $pathInfo['filename'];
    while (is_file($filepath)) {
      $filename = $baseName . '_' . $counter . '.jpg';
      $filepath = $variantsDir . '/' . $filename;
      $counter++;
    }
  } else if (is_file($filepath) && $replace) {
    // File exists and replace is true, will overwrite
  }
  
  // Convert uploaded image to JPG (handles PNG, WEBP, JPG)
  if (!convert_to_jpg($file['tmp_name'], $filepath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error'=>'failed to convert/save file']);
    exit;
  }
  
  echo json_encode([
    'ok' => true,
    'filename' => $filename,
    'url' => '/admin/variants/' . rawurlencode($filename),
    'replaced' => $replace
  ]);
}

function handleRename() {
  global $variantsDir;
  
  $oldFilename = trim($_POST['old_filename'] ?? '');
  $newName = trim($_POST['new_name'] ?? '');
  
  if ($oldFilename === '' || $newName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error'=>'old_filename and new_name required']);
    exit;
  }
  
  $oldBaseName = pathinfo($oldFilename, PATHINFO_FILENAME);
  $oldFilepath = $variantsDir . '/' . basename($oldFilename);
  $oldJsonPath = $variantsDir . '/' . $oldBaseName . '.json';
  
  // Check if image file exists
  $imageExists = is_file($oldFilepath);
  // Check if JSON file exists (for pending variants)
  $jsonExists = is_file($oldJsonPath);
  
  if (!$imageExists && !$jsonExists) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error'=>'file not found']);
    exit;
  }
  
  // Validate paths
  $variantsDirReal = realpath($variantsDir);
  if ($imageExists) {
    $oldFilepathReal = realpath($oldFilepath);
    if ($oldFilepathReal === false || strpos($oldFilepathReal, $variantsDirReal) !== 0) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error'=>'invalid path']);
      exit;
    }
  }
  
  if ($jsonExists) {
    $oldJsonPathReal = realpath($oldJsonPath);
    if ($oldJsonPathReal === false || strpos($oldJsonPathReal, $variantsDirReal) !== 0) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error'=>'invalid json path']);
      exit;
    }
  }
  
  // Always use JPG extension for image filename
  $newFilename = sanitizeFilename($newName);
  $newBaseName = pathinfo($newFilename, PATHINFO_FILENAME);
  $newFilepath = $variantsDir . '/' . $newFilename;
  $newJsonPath = $variantsDir . '/' . $newBaseName . '.json';
  
  // Check if new name already exists (image or JSON)
  if (($imageExists && is_file($newFilepath) && $newFilepath !== $oldFilepath) ||
      ($jsonExists && is_file($newJsonPath) && $newJsonPath !== $oldJsonPath)) {
    http_response_code(409);
    echo json_encode([
      'ok' => false,
      'error' => 'file_exists',
      'message' => 'Eine Variante mit diesem Namen existiert bereits.'
    ]);
    exit;
  }
  
  // Rename image file if it exists
  if ($imageExists) {
    if (!rename($oldFilepath, $newFilepath)) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error'=>'failed to rename image']);
      exit;
    }
  }
  
  // Handle JSON file rename/update
  if ($jsonExists) {
    // Update variant_name in JSON if renaming
    $jsonContent = @file_get_contents($oldJsonPath);
    if ($jsonContent !== false) {
      $jsonData = json_decode($jsonContent, true);
      if (is_array($jsonData)) {
        $jsonData['variant_name'] = $newBaseName;
        $jsonData['filename'] = $newFilename;
        require_once __DIR__ . '/meta.php';
        update_json_file($newJsonPath, $jsonData, false);
        // Delete old JSON if rename was successful
        if (is_file($newJsonPath)) {
          @unlink($oldJsonPath);
        }
      } else {
        // If JSON is invalid, just rename the file
        @rename($oldJsonPath, $newJsonPath);
      }
    } else {
      // If we can't read it, just try to rename
      @rename($oldJsonPath, $newJsonPath);
    }
  }
  
  echo json_encode([
    'ok' => true,
    'old_filename' => $oldFilename,
    'new_filename' => $newFilename,
    'url' => $imageExists ? '/admin/variants/' . rawurlencode($newFilename) : null
  ]);
}

function handleDelete() {
  global $variantsDir;
  
  $filename = $_POST['filename'] ?? '';
  if ($filename === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error'=>'filename required']);
    exit;
  }
  
  $baseName = pathinfo($filename, PATHINFO_FILENAME);
  
  // Security: only allow deleting files in variants directory
  $filepath = $variantsDir . '/' . basename($filename);
  $jsonPath = $variantsDir . '/' . $baseName . '.json';
  
  // Check if image file exists
  $imageExists = is_file($filepath);
  // Check if JSON file exists
  $jsonExists = is_file($jsonPath);
  
  if (!$imageExists && !$jsonExists) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error'=>'file not found']);
    exit;
  }
  
  // Validate paths
  $variantsDirReal = realpath($variantsDir);
  if ($imageExists) {
    $filepathReal = realpath($filepath);
    if ($filepathReal === false || strpos($filepathReal, $variantsDirReal) !== 0) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error'=>'invalid path']);
      exit;
    }
  }
  
  if ($jsonExists) {
    $jsonPathReal = realpath($jsonPath);
    if ($jsonPathReal === false || strpos($jsonPathReal, $variantsDirReal) !== 0) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error'=>'invalid json path']);
      exit;
    }
  }
  
  // Delete image file if it exists
  if ($imageExists) {
    if (!unlink($filepath)) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error'=>'failed to delete image']);
      exit;
    }
  }
  
  // Delete JSON file if it exists
  if ($jsonExists) {
    if (!unlink($jsonPath)) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error'=>'failed to delete json']);
      exit;
    }
  }
  
  echo json_encode(['ok' => true, 'filename' => $filename]);
}

function handleRegenerate() {
  global $variantsDir;
  
  $filename = trim($_POST['filename'] ?? '');
  $prompt = trim($_POST['prompt'] ?? '');
  
  if ($filename === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error'=>'filename required']);
    exit;
  }
  
  if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error'=>'prompt required']);
    exit;
  }
  
  $baseName = pathinfo($filename, PATHINFO_FILENAME);
  $filepath = $variantsDir . '/' . basename($filename);
  $jsonPath = $variantsDir . '/' . $baseName . '.json';
  
  // Security: only allow regenerating files in variants directory
  $variantsDirReal = realpath($variantsDir);
  
  // Delete image file if it exists
  if (is_file($filepath)) {
    $filepathReal = realpath($filepath);
    if ($filepathReal === false || strpos($filepathReal, $variantsDirReal) !== 0) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error'=>'invalid path']);
      exit;
    }
    if (!unlink($filepath)) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error'=>'failed to delete image']);
      exit;
    }
  }
  
  // Delete JSON file if it exists
  if (is_file($jsonPath)) {
    $jsonPathReal = realpath($jsonPath);
    if ($jsonPathReal === false || strpos($jsonPathReal, $variantsDirReal) !== 0) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'error'=>'invalid json path']);
      exit;
    }
    if (!unlink($jsonPath)) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error'=>'failed to delete json']);
      exit;
    }
  }
  
  // Start new generation with the provided prompt
  require_once __DIR__ . '/ai_variant_by_prompt.php';
  $result = generate_variant_template_async($baseName, $prompt, $filepath);
  
  if (!$result['ok']) {
    http_response_code(502);
    echo json_encode([
      'ok' => false,
      'error' => $result['error'] ?? 'generation_failed',
      'detail' => $result['detail'] ?? 'Unknown error'
    ]);
    exit;
  }
  
  echo json_encode([
    'ok' => true,
    'prediction_started' => true,
    'filename' => $filename,
    'variant_name' => $baseName,
    'prediction_url' => $result['prediction_url'] ?? null,
    'message' => 'Variant regeneration started, will be processed by background task'
  ]);
}

function handleCopyToImage() {
  global $variantsDir;
  
  $variantFilename = trim($_POST['variant_filename'] ?? '');
  $imageBaseName = trim($_POST['image_base_name'] ?? '');
  
  if ($variantFilename === '' || $imageBaseName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error'=>'variant_filename and image_base_name required']);
    exit;
  }
  
  // Security: only allow copying files from variants directory
  $variantPath = $variantsDir . '/' . basename($variantFilename);
  if (strpos(realpath($variantPath), realpath($variantsDir)) !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error'=>'invalid variant path']);
    exit;
  }
  
  if (!is_file($variantPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error'=>'variant file not found']);
    exit;
  }
  
  // Get variant name without extension
  $variantName = pathinfo($variantFilename, PATHINFO_FILENAME);
  
  // Use ai_painting_variants.php to generate the variant asynchronously
  // This follows the rule: "Niemals Replicate synchron in HTTP-Request-Handlern aufrufen"
  require_once __DIR__ . '/ai_painting_variants.php';
  $result = process_ai_painting_variants($imageBaseName, [$variantName]);
  
  if (!$result['ok']) {
    http_response_code(502);
    echo json_encode([
      'ok' => false,
      'error' => $result['error'] ?? 'generation_failed',
      'detail' => $result['message'] ?? 'Unknown error'
    ]);
    exit;
  }
  
  // Return immediately - generation is async and will be processed by background task
  $targetFilename = $imageBaseName . '_variant_' . $variantName . '.jpg';
  
  echo json_encode([
    'ok' => true,
    'variant_filename' => $variantFilename,
    'target_filename' => $targetFilename,
    'prediction_started' => true,
    'message' => 'Variant generation started, will be processed by background task'
  ]);
}

/**
 * Regenerate all existing variants for an image base name
 * This function finds all variant files and regenerates them using the current _final image
 */
function regenerateAllVariants(string $imageBaseName, ?string $width = null, ?string $height = null): array {
  global $variantsDir, $TOKEN;
  
  // Ensure token is loaded
  if (!isset($TOKEN) || $TOKEN === null) {
    try {
      $TOKEN = load_replicate_token();
    } catch (RuntimeException $e) {
      return ['ok' => false, 'error' => 'Failed to load REPLICATE_API_TOKEN'];
    }
  }
  
  // Ensure variants directory is set
  if (!isset($variantsDir)) {
    $variantsDir = __DIR__ . '/variants';
    if (!is_dir($variantsDir)) {
      mkdir($variantsDir, 0755, true);
    }
  }
  
  $imagesDir = dirname(__DIR__) . '/admin/images';
  
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
    // No final image found, return empty result (not an error - just no variants to regenerate)
    return ['ok' => true, 'regenerated' => 0, 'message' => 'No final image found'];
  }
  
  // Find all existing variant files for this image base
  $existingVariants = [];
  foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $fileStem = pathinfo($file, PATHINFO_FILENAME);
    // Check if this is a variant file: {base}_variant_{variantName}.jpg
    $pattern = '/^' . preg_quote($imageBaseName, '/') . '_variant_(.+)$/';
    if (preg_match($pattern, $fileStem, $matches)) {
      $variantName = $matches[1];
      // Find the corresponding template in variants directory
      $variantTemplatePath = $variantsDir . '/' . $variantName . '.jpg';
      if (is_file($variantTemplatePath)) {
        $existingVariants[] = [
          'variant_name' => $variantName,
          'variant_template' => $variantName . '.jpg',
          'variant_path' => $variantTemplatePath,
          'target_filename' => $imageBaseName . '_variant_' . $variantName . '.jpg',
          'target_path' => $imagesDir . '/' . $imageBaseName . '_variant_' . $variantName . '.jpg'
        ];
      }
    }
  }
  
  if (empty($existingVariants)) {
    return ['ok' => true, 'regenerated' => 0, 'message' => 'No existing variants found'];
  }
  
  // Load final image
  $finalMime = mime_content_type($finalImage);
  if (!in_array($finalMime, ['image/jpeg','image/png','image/webp'])) {
    return ['ok' => false, 'error' => 'Unsupported final image type'];
  }
  $finalB64 = base64_encode(file_get_contents($finalImage));
  
  // Load metadata to get dimensions if not provided
  if ($width === null || $height === null) {
    $originalImageFile = $imageBaseName . '_original.jpg';
    $meta = load_meta($originalImageFile, $imagesDir);
    if (is_array($meta)) {
      if ($width === null && isset($meta['width'])) {
        $width = (string)$meta['width'];
      }
      if ($height === null && isset($meta['height'])) {
        $height = (string)$meta['height'];
      }
    }
  }
  
  // Build dimensions info for prompt
  $dimensionsInfo = '';
  if ($width !== null && $height !== null && $width !== '' && $height !== '') {
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
  
  $VERSION = '2784c5d54c07d79b0a2a5385477038719ad37cb0745e61bbddf2fc236d196a6b';
  
  $regenerated = 0;
  $errors = [];
  
  // Regenerate each variant
  foreach ($existingVariants as $variant) {
    try {
      // Load variant template
      $variantMime = mime_content_type($variant['variant_path']);
      if (!in_array($variantMime, ['image/jpeg','image/png','image/webp'])) {
        $errors[] = "Skipped {$variant['variant_name']}: unsupported template type";
        continue;
      }
      
      $variantB64 = base64_encode(file_get_contents($variant['variant_path']));
      
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
        $errors[] = "Failed to regenerate {$variant['variant_name']}: unexpected output format";
        continue;
      }
      
      // Save regenerated variant
      file_put_contents($variant['target_path'], $imgBytes);
      
      // Generate thumbnail for the regenerated variant
      $thumbPath = generate_thumbnail_path($variant['target_path']);
      generate_thumbnail($variant['target_path'], $thumbPath, 512, 1024);
      
      // Update JSON metadata to track this variant as active
      $jsonFile = find_json_file($imageBaseName, $imagesDir);
      if ($jsonFile) {
        $metaPath = $imagesDir . '/' . $jsonFile;
        // Load existing metadata
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
        
        // Add variant to active variants list inside ai_painting_variants
        $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
        if (!isset($aiPaintingVariants['active_variants']) || !is_array($aiPaintingVariants['active_variants'])) {
          $aiPaintingVariants['active_variants'] = [];
        }
        if (!in_array($variant['variant_name'], $aiPaintingVariants['active_variants'], true)) {
          $aiPaintingVariants['active_variants'][] = $variant['variant_name'];
        }
        
        // Update JSON thread-safely
        update_json_file($metaPath, ['ai_painting_variants' => $aiPaintingVariants], false);
      }
      
      $regenerated++;
      
    } catch (Exception $e) {
      $errors[] = "Failed to regenerate {$variant['variant_name']}: " . $e->getMessage();
    }
  }
  
  // Update gallery if image is in gallery
  $galleryDir = dirname(__DIR__) . '/img/gallery/';
  $galleryFilename = find_gallery_entry($imageBaseName, $galleryDir);
  $inGallery = $galleryFilename !== null;
  
  if ($inGallery && $regenerated > 0) {
    // Load metadata and update gallery entry
    $jsonFile = find_json_file($imageBaseName, $imagesDir);
    if ($jsonFile) {
      $metaFile = $imagesDir . '/' . $jsonFile;
      $metaContent = file_get_contents($metaFile);
      $meta = json_decode($metaContent, true);
      if (is_array($meta)) {
        update_gallery_entry($imageBaseName, $meta, $imagesDir, $galleryDir);
        
        // Trigger async image optimization with force to ensure thumbnails are regenerated
        // Variant regeneration triggers removed - handled by background task processor
      }
    }
  }
  
  return [
    'ok' => true,
    'regenerated' => $regenerated,
    'total' => count($existingVariants),
    'errors' => $errors,
    'gallery_updated' => $inGallery && $regenerated > 0
  ];
}


