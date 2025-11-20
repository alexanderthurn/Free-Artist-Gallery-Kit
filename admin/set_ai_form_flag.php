<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$image = isset($_POST['image']) ? basename((string)$_POST['image']) : '';
if ($image === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing image']);
    exit;
}

$imagesDir = __DIR__ . '/images/';
$imagePath = $imagesDir . $image;

if (!is_file($imagePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Image not found']);
    exit;
}

// Extract base name to find _original image
$base = extract_base_name($image);

// Find _original image JSON file
$jsonPath = null;
$files = scandir($imagesDir) ?: [];
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $fileStem = pathinfo($file, PATHINFO_FILENAME);
    // Check if it's the JSON file for the _original image
    if (preg_match('/^' . preg_quote($base, '/') . '_original\.jpg$/', $fileStem)) {
        $jsonPath = $imagesDir . $file . '.json';
        break;
    }
}

// If not found, try other extensions
if (!$jsonPath) {
    $extensions = ['png', 'jpg', 'jpeg', 'webp'];
    foreach ($extensions as $e) {
        $testFile = $base . '_original.' . $e;
        $testJson = $imagesDir . $testFile . '.json';
        if (is_file($testJson)) {
            $jsonPath = $testJson;
            break;
        }
    }
}

if (!$jsonPath || !is_file($jsonPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'JSON metadata not found']);
    exit;
}

// Set AI generation status to 'wanted' (which will trigger both corners and form)
// Or if corners are already done, set to 'form_wanted'
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

// Set form status to wanted
update_task_status($jsonPath, 'ai_form', 'wanted');

echo json_encode([
    'ok' => true,
    'message' => 'AI form fill flag set. Will be processed by background tasks.'
]);

