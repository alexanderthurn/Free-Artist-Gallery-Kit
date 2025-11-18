<?php
// admin/variants.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/utils.php';

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
  $prompt = <<<PROMPT
You are an image editor.

Task:
- Place the painting into the free space on the wall in the room image.
- Make the scene natural.
PROMPT;
  
  $payload = [
    'version' => $VERSION,
    'input' => [
      'prompt' => $prompt,
      'image_input' => [
        "data:$variantMime;base64,$variantB64",
        "data:$finalMime;base64,$finalB64"
      ],
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
  
  file_put_contents($tempPng, $targetPath);
  
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


