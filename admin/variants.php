<?php
// admin/variants.php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';

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
  if (is_dir($variantsDir)) {
    $items = scandir($variantsDir) ?: [];
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') continue;
      $path = $variantsDir . '/' . $item;
      if (!is_file($path)) continue;
      $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg','jpeg'])) continue;
      $files[] = [
        'name' => $item,
        'url' => '/admin/variants/' . rawurlencode($item),
        'mtime' => filemtime($path),
        'size' => filesize($path)
      ];
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
  
  // ---- Text-to-Image Generation with Nano Banana ----
  // Using the same model version as corners.php, but for text-to-image generation
  $VERSION = '2784c5d54c07d79b0a2a5385477038719ad37cb0745e61bbddf2fc236d196a6b';
  
  $promptFinal = <<<PROMPT
  Erzeuge ein neues, fotorealistisches Bild eines Raumes. 
  Der Raum soll neutral gestaltet sein, die Deckenhöhe soll 2,5m sein. Der Raum soll genügend freie glatte Wandfläche bieten. Es darf kein Bild irgendwo hängen.
  Achte auf natürliche Beleuchtung und klare Linien.
  
  Raumbeschreibung des Nutzers:
  $prompt
  PROMPT;


  // Try with nano banana model (same version as corners.php)
  try {
    $payload = [
      'version' => $VERSION,
      'input' => [
        'prompt' => $promptFinal,
        'output_format' => 'jpg'
      ]
    ];
    $result = replicate_call_version($TOKEN, $VERSION, $payload);
  } catch (Exception $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'replicate_failed', 'detail' => $e->getMessage()]);
    exit;
  }
  
  // Extract image from result
  $imgBytes = fetch_image_bytes($result['output'] ?? null);
  if ($imgBytes === null) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error'=>'failed_to_extract_image', 'response' => $result]);
    exit;
  }
  
  
  file_put_contents($filepath, $imgBytes);
  
  echo json_encode([
    'ok' => true,
    'filename' => $filename,
    'url' => '/admin/variants/' . rawurlencode($filename),
    'prompt' => $prompt,
    'replaced' => is_file($filepath) && $replace
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
  
  // Security: only allow renaming files in variants directory
  $oldFilepath = $variantsDir . '/' . basename($oldFilename);
  if (strpos(realpath($oldFilepath), realpath($variantsDir)) !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error'=>'invalid path']);
    exit;
  }
  
  if (!is_file($oldFilepath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error'=>'file not found']);
    exit;
  }
  
  // Always use JPG extension
  $newFilename = sanitizeFilename($newName);
  
  $newFilepath = $variantsDir . '/' . $newFilename;
  
  // Check if new name already exists
  if (is_file($newFilepath) && $newFilepath !== $oldFilepath) {
    http_response_code(409);
    echo json_encode([
      'ok' => false,
      'error' => 'file_exists',
      'message' => 'Eine Variante mit diesem Namen existiert bereits.'
    ]);
    exit;
  }
  
  if (!rename($oldFilepath, $newFilepath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error'=>'failed to rename']);
    exit;
  }
  
  echo json_encode([
    'ok' => true,
    'old_filename' => $oldFilename,
    'new_filename' => $newFilename,
    'url' => '/admin/variants/' . rawurlencode($newFilename)
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
  
  // Security: only allow deleting files in variants directory
  $filepath = $variantsDir . '/' . basename($filename);
  if (strpos(realpath($filepath), realpath($variantsDir)) !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error'=>'invalid path']);
    exit;
  }
  
  if (!is_file($filepath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error'=>'file not found']);
    exit;
  }
  
  if (!unlink($filepath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error'=>'failed to delete']);
    exit;
  }
  
  echo json_encode(['ok' => true, 'filename' => $filename]);
}

function handleCopyToImage() {
  global $variantsDir, $TOKEN;
  
  $variantFilename = trim($_POST['variant_filename'] ?? '');
  $imageBaseName = trim($_POST['image_base_name'] ?? '');
  $width = trim($_POST['width'] ?? '');
  $height = trim($_POST['height'] ?? '');
  
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
  
  // Find the _final image for this base
  $imagesDir = dirname(__DIR__) . '/admin/images';
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
    http_response_code(404);
    echo json_encode(['ok' => false, 'error'=>'final image not found for base: '.$imageBaseName]);
    exit;
  }
  
  // Get variant name without extension
  $variantName = pathinfo($variantFilename, PATHINFO_FILENAME);
  
  // Create target filename: {imageBaseName}_variant_{variantName}.jpg (always JPG)
  $targetFilename = $imageBaseName . '_variant_' . $variantName . '.jpg';
  $targetPath = $imagesDir . '/' . $targetFilename;
  
  // Ensure images directory exists
  if (!is_dir($imagesDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error'=>'images directory not found']);
    exit;
  }
  
  // Use AI to place the painting into the variant
  // Load both images as base64
  $variantMime = mime_content_type($variantPath);
  $finalMime = mime_content_type($finalImage);
  
  if (!in_array($variantMime, ['image/jpeg','image/png','image/webp']) || 
      !in_array($finalMime, ['image/jpeg','image/png','image/webp'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error'=>'unsupported image type']);
    exit;
  }
  
  $variantB64 = base64_encode(file_get_contents($variantPath));
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
  try {
    $resp = replicate_call_version($TOKEN, $VERSION, $payload);
  } catch (RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'replicate_failed', 'detail' => $e->getMessage()]);
    exit;
  }
  
  // Extract image from result
  $imgBytes = fetch_image_bytes($resp['output'] ?? null);
  if ($imgBytes === null) {
    if (is_array($resp['output']) && isset($resp['output']['images'][0])) {
      $imgBytes = fetch_image_bytes($resp['output']['images'][0]);
    }
  }
  
  if ($imgBytes === null) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error'=>'unexpected_output_format','sample'=>is_scalar($resp['output'])?$resp['output']:json_encode($resp['output'])]);
    exit;
  }
  
  file_put_contents($targetPath, $imgBytes);
  
  // Generate thumbnail for the variant (full resolution in admin/images, just generate thumbnail)
  $thumbPath = generate_thumbnail_path($targetPath);
  generate_thumbnail($targetPath, $thumbPath, 512, 1024);

  // Check if this image is already in gallery and update it
  $galleryDir = dirname(__DIR__) . '/img/gallery/';
  $galleryFilename = find_gallery_entry($imageBaseName, $galleryDir);
  $inGallery = $galleryFilename !== null;
  
  // If in gallery, update the gallery entry to include the new variant
  if ($inGallery) {
    // Load metadata from images directory
    $jsonFile = find_json_file($imageBaseName, $imagesDir);
    if ($jsonFile) {
      $metaFile = $imagesDir . '/' . $jsonFile;
      $metaContent = file_get_contents($metaFile);
      $meta = json_decode($metaContent, true);
      if (is_array($meta)) {
        update_gallery_entry($imageBaseName, $meta, $imagesDir, $galleryDir);
        
        // Trigger async image optimization
        async_http_post('admin/optimize_images.php', ['action' => 'both']);
      }
    }
  }
  
  echo json_encode([
    'ok' => true,
    'variant_filename' => $variantFilename,
    'target_filename' => $targetFilename,
    'url' => 'images/' . rawurlencode($targetFilename),
    'gallery_updated' => $inGallery
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
    $metaPath = $imagesDir . '/' . $imageBaseName . '_original.jpg.json';
    if (is_file($metaPath)) {
      $metaContent = file_get_contents($metaPath);
      $meta = json_decode($metaContent, true);
      if (is_array($meta)) {
        if ($width === null && isset($meta['width'])) {
          $width = (string)$meta['width'];
        }
        if ($height === null && isset($meta['height'])) {
          $height = (string)$meta['height'];
        }
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
        
        // Trigger async image optimization
        async_http_post('admin/optimize_images.php', ['action' => 'both']);
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


