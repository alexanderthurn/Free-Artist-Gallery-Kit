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

$imageBaseName = trim($_POST['image_base_name'] ?? '');
$variantName = trim($_POST['variant_name'] ?? '');

if ($imageBaseName === '' || $variantName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'image_base_name and variant_name required']);
    exit;
}

$imagesDir = __DIR__ . '/images';
$jsonFile = find_json_file($imageBaseName, $imagesDir);

if (!$jsonFile || !is_file($imagesDir . '/' . $jsonFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'JSON metadata not found']);
    exit;
}

$jsonPath = $imagesDir . '/' . $jsonFile;

// Load existing metadata
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

// Add variant to active_variants list (thread-safe)
if (!isset($meta['active_variants']) || !is_array($meta['active_variants'])) {
    $meta['active_variants'] = [];
}

// Check if variant already exists (either in list or as file)
$variantFile = $imageBaseName . '_variant_' . $variantName . '.jpg';
$variantPath = $imagesDir . '/' . $variantFile;
$alreadyExists = in_array($variantName, $meta['active_variants'], true) || is_file($variantPath);

if (!$alreadyExists) {
    $meta['active_variants'][] = $variantName;
    update_json_file($jsonPath, ['active_variants' => $meta['active_variants']], false);
}

echo json_encode([
    'ok' => true,
    'variant_name' => $variantName,
    'already_exists' => $alreadyExists,
    'active_variants' => $meta['active_variants']
]);

