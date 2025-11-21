<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/meta.php';

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

// Extract image filename from JSON filename (remove .json extension)
$imageFilename = preg_replace('/\.json$/', '', $jsonFile);

// Load existing metadata
$meta = load_meta($imageFilename, $imagesDir);

// Check if variant already exists (either in list or as file)
$variantFile = $imageBaseName . '_variant_' . $variantName . '.jpg';
$variantPath = $imagesDir . '/' . $variantFile;
$aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
$activeVariants = $aiPaintingVariants['active_variants'] ?? [];
$alreadyExists = in_array($variantName, $activeVariants, true) || is_file($variantPath);

if (!$alreadyExists) {
    // Trigger variant generation using process_ai_painting_variants
    // This will add it to active_variants AND start the async generation with full tracking
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
    
    // Reload metadata to get updated active_variants
    $meta = load_meta($imageFilename, $imagesDir);
    $aiPaintingVariants = $meta['ai_painting_variants'] ?? [];
    $activeVariants = $aiPaintingVariants['active_variants'] ?? [];
}

echo json_encode([
    'ok' => true,
    'variant_name' => $variantName,
    'already_exists' => $alreadyExists,
    'active_variants' => $activeVariants,
    'prediction_started' => !$alreadyExists && isset($result) && ($result['started'] ?? 0) > 0
]);

