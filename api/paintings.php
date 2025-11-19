<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Function to read .env file
function getEnvValue($key, $default = '') {
    $envFile = __DIR__ . '/../.env';
    if (!file_exists($envFile)) {
        return $default;
    }
    
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if ($name === $key) {
            return $value;
        }
    }
    
    return $default;
}

// Check if MOCK mode is enabled
$mockMode = getEnvValue('MOCK', '0') === '1';

// Configuration
$galleryDir = __DIR__ . '/../img/gallery/';
$perPage = isset($_GET['perPage']) ? (int)$_GET['perPage'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;

// Validate inputs
$perPage = max(1, min(100, $perPage));
$page = max(1, $page);
if ($limit !== null) {
    $limit = max(1, $limit);
}

// Get all paintings
$paintings = [];

if (!is_dir($galleryDir)) {
    echo json_encode([
        'paintings' => [],
        'total' => 0,
        'page' => $page,
        'perPage' => $perPage,
        'hasMore' => false
    ]);
    exit;
}

// Scan directory for image files
$files = scandir($galleryDir);
$imageFiles = [];
$variantFiles = []; // Track variant files separately

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    
    $filePath = $galleryDir . $file;
    if (!is_file($filePath)) continue;
    
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) continue;
    
    // Check if this is a variant file (has _variant_ in the name)
    if (strpos($file, '_variant_') !== false) {
        // Extract base name: filename_variant_name.jpg -> filename
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        $baseEnd = strpos($fileStem, '_variant_');
        if ($baseEnd !== false) {
            $base = substr($fileStem, 0, $baseEnd);
            if (!isset($variantFiles[$base])) {
                $variantFiles[$base] = [];
            }
            $variantFiles[$base][] = $file;
        }
        continue; // Skip variant files from main image list
    }
    
    $imageFiles[] = $file;
}

// Process each image file
foreach ($imageFiles as $imageFile) {
    $basename = pathinfo($imageFile, PATHINFO_FILENAME);
    $jsonFile = $galleryDir . $basename . '.json';
    
    // Load JSON metadata
    $metadata = [];
    if (file_exists($jsonFile)) {
        $jsonContent = file_get_contents($jsonFile);
        $metadata = json_decode($jsonContent, true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
    }
    
    // Create painting object
    $painting = [
        'filename' => $imageFile,
        'imageUrl' => 'img/gallery/' . $imageFile,
        'variants' => [],
        'title' => $metadata['title'] ?? '',
        'description' => $metadata['description'] ?? '',
        'width' => $metadata['width'] ?? '',
        'height' => $metadata['height'] ?? '',
        'tags' => $metadata['tags'] ?? '',
        'date' => $metadata['date'] ?? '',
        'sold' => isset($metadata['sold']) && $metadata['sold'] === true,
        'frame_type' => $metadata['frame_type'] ?? 'white'
    ];
    
    // Find variant images for this painting
    if (isset($variantFiles[$basename])) {
        foreach ($variantFiles[$basename] as $variantFile) {
            $painting['variants'][] = 'img/gallery/' . $variantFile;
        }
        // Sort variants alphabetically for consistency
        sort($painting['variants']);
    }
    
    // TEMPORARY: If no variants found, add humans_variant_room.jpg 1-5 times randomly (only in MOCK mode)
    if ($mockMode && empty($painting['variants']) && file_exists($galleryDir . 'humans_variant_room.jpg')) {
        $numVariants = rand(1, 5);
        for ($i = 0; $i < $numVariants; $i++) {
            $painting['variants'][] = 'img/gallery/humans_variant_room.jpg';
        }
    }
    
    // Apply search filter if provided
    if (!empty($search)) {
        $searchLower = strtolower($search);
        $titleMatch = !empty($painting['title']) && strpos(strtolower($painting['title']), $searchLower) !== false;
        $descMatch = !empty($painting['description']) && strpos(strtolower($painting['description']), $searchLower) !== false;
        $tagsMatch = !empty($painting['tags']) && strpos(strtolower($painting['tags']), $searchLower) !== false;
        
        if (!$titleMatch && !$descMatch && !$tagsMatch) {
            continue; // Skip this painting if no match
        }
    }
    
    $paintings[] = $painting;
}

// Sort paintings (you can customize this)
usort($paintings, function($a, $b) {
    // Sort by date if available, otherwise by title
    if (!empty($a['date']) && !empty($b['date'])) {
        return strcmp($b['date'], $a['date']); // Newest first
    }
    return strcmp($a['title'], $b['title']);
});

// TEMPORARY: Duplicate first painting 60 times for testing (only in MOCK mode)
if ($mockMode && !empty($paintings)) {
    $firstPainting = $paintings[0];
    for ($i = 1; $i < 60; $i++) {
        $duplicate = $firstPainting;
        // Make each duplicate slightly unique by adding a number to the title
        $duplicate['title'] = $firstPainting['title'] . ' ' . ($i + 1);
        
        // Add variants (humans_variant_room.jpg) 1-5 times for each duplicate
        if (file_exists($galleryDir . 'humans_variant_room.jpg')) {
            $numVariants = rand(1, 5);
            $duplicate['variants'] = [];
            for ($j = 0; $j < $numVariants; $j++) {
                $duplicate['variants'][] = 'img/gallery/humans_variant_room.jpg';
            }
        }
        
        $paintings[] = $duplicate;
    }
}

// Apply limit if specified
if ($limit !== null) {
    $paintings = array_slice($paintings, 0, $limit);
}

// Pagination
$total = count($paintings);
$offset = ($page - 1) * $perPage;
$paginatedPaintings = array_slice($paintings, $offset, $perPage);
$hasMore = ($offset + $perPage) < $total;

// Return JSON response
echo json_encode([
    'paintings' => $paginatedPaintings,
    'total' => $total,
    'page' => $page,
    'perPage' => $perPage,
    'hasMore' => $hasMore
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

