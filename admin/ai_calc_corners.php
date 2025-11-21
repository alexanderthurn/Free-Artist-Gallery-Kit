<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/meta.php';

/**
 * Poll a corner detection prediction and process results if complete
 * 
 * @param string $jsonPath Full path to JSON metadata file
 * @param float $offsetPercent Offset percentage (0-10, default 1.0)
 * @return array Result array with 'ok' => true/false, 'completed' => bool, 'still_processing' => bool
 */
function poll_corner_prediction(string $jsonPath, float $offsetPercent = 1.0): array {
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
    
    $aiCorners = $meta['ai_corners'] ?? [];
    $predictionUrl = $aiCorners['prediction_url'] ?? null;
    if (!$predictionUrl || !is_string($predictionUrl)) {
        return ['ok' => false, 'error' => 'prediction_url_not_found'];
    }
    
    try {
        $TOKEN = load_replicate_token();
    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => 'missing REPLICATE_API_TOKEN'];
    }
    
    // Make single GET request to check status
    $ch = curl_init($predictionUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Token $TOKEN"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($res === false || $httpCode >= 400) {
        // Network error - keep status as in_progress, retry next run
        return ['ok' => true, 'still_processing' => true, 'error' => 'poll_failed', 'detail' => $err ?: 'HTTP ' . $httpCode];
    }
    
    $resp = json_decode($res, true);
    if (!is_array($resp)) {
        return ['ok' => true, 'still_processing' => true, 'error' => 'invalid_response'];
    }
    
    $status = $resp['status'] ?? 'unknown';
    
    // Save the response immediately - update ai_corners object
    $aiCorners = $meta['ai_corners'] ?? [];
    $aiCorners['replicate_response_raw'] = json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $aiCorners['replicate_response'] = $resp;
    $aiCorners['timestamp'] = date('c');
    $aiCorners['prediction_status'] = $status; // Store prediction status separately from task status
    update_json_file($jsonPath, ['ai_corners' => $aiCorners], false);
    
    if ($status === 'succeeded') {
        // Process the completed prediction
        return process_completed_corner_prediction($jsonPath, $resp, $offsetPercent);
    } elseif ($status === 'failed' || $status === 'canceled') {
        // Prediction failed - set status to wanted for retry
        require_once __DIR__ . '/meta.php';
        update_task_status($jsonPath, 'ai_corners', 'wanted');
        return [
            'ok' => false,
            'error' => 'prediction_failed',
            'status' => $status,
            'detail' => $resp['error'] ?? 'Prediction failed'
        ];
    } else {
        // Still processing
        return ['ok' => true, 'still_processing' => true, 'status' => $status];
    }
}

/**
 * Process a completed corner prediction response
 * 
 * @param string $jsonPath Full path to JSON metadata file
 * @param array $resp Replicate API response
 * @param float $offsetPercent Offset percentage
 * @return array Result array with corners data
 */
function process_completed_corner_prediction(string $jsonPath, array $resp, float $offsetPercent): array {
    // Load image info from JSON path
    $imageFilename = basename($jsonPath, '.json');
    $imagesDir = dirname($jsonPath);
    
    // Find the original image file
    $originalImage = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, pathinfo($imageFilename, PATHINFO_FILENAME)) === 0 && 
            preg_match('/_original\.(jpg|jpeg|png)$/i', $file)) {
            $originalImage = $imagesDir . '/' . $file;
            break;
        }
    }
    
    if (!$originalImage || !is_file($originalImage)) {
        return ['ok' => false, 'error' => 'original_image_not_found'];
    }
    
    [$imgW, $imgH] = getimagesize($originalImage);
    
    // Extract text from response
    $outputText = '';
    if (isset($resp['output'])) {
        if (is_array($resp['output'])) {
            $outputText = implode('', $resp['output']);
        } else {
            $outputText = (string)$resp['output'];
        }
    }
    
    if (empty($outputText)) {
        update_task_status($jsonPath, 'ai_corners', 'wanted');
        return ['ok' => false, 'error' => 'empty_output', 'response' => $resp];
    }
    
    // Parse corners from output (same logic as before)
    $cornersData = null;
    
    // Try to extract JSON from code blocks
    if (preg_match('/```(?:json)?\s*(\{.*)\s*```/s', $outputText, $matches)) {
        $jsonStr = $matches[1];
        $braceCount = 0;
        $jsonEnd = 0;
        $inString = false;
        $escapeNext = false;
        
        for ($i = 0; $i < strlen($jsonStr); $i++) {
            $char = $jsonStr[$i];
            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }
            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }
            if ($char === '"' && !$escapeNext) {
                $inString = !$inString;
                continue;
            }
            if (!$inString) {
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                    if ($braceCount === 0) {
                        $jsonEnd = $i + 1;
                        break;
                    }
                }
            }
        }
        
        if ($braceCount === 0 && $jsonEnd > 0) {
            $jsonStr = substr($jsonStr, 0, $jsonEnd);
            $jsonStr = preg_replace('/"(\w+)"\s*"\s*\\\?":/', '"$1":', $jsonStr);
            $jsonStr = preg_replace('/:\s*"\s*"([^"]+)"/', ': "$1"', $jsonStr);
            $jsonStr = preg_replace('/:\s*(\d+)"\s*"(\d+\.\d+)/', ': $1$2', $jsonStr);
            $jsonStr = preg_replace('/:\s*(\d+\.\d+)"\s*"(\d+)/', ': $1$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $jsonStr);
            $jsonStr = preg_replace('/"\s*"([^"]+)"/', '"$1"', $jsonStr);
            $cornersData = json_decode($jsonStr, true);
        }
    }
    
    // Try parsing whole output as JSON
    if ($cornersData === null || !is_array($cornersData)) {
        $cleanedOutput = $outputText;
        $cleanedOutput = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $cleanedOutput);
        $cleanedOutput = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $cleanedOutput);
        $cleanedOutput = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $cleanedOutput);
        $cleanedOutput = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $cleanedOutput);
        $cleanedOutput = preg_replace('/"\s+"([^"]+)"/', '"$1"', $cleanedOutput);
        $cornersData = json_decode($cleanedOutput, true);
    }
    
    // Try brace matching
    if ($cornersData === null || !is_array($cornersData)) {
        $jsonStart = strpos($outputText, '{');
        if ($jsonStart !== false) {
            $braceCount = 0;
            $jsonEnd = $jsonStart;
            $inString = false;
            $escapeNext = false;
            
            for ($i = $jsonStart; $i < strlen($outputText); $i++) {
                $char = $outputText[$i];
                if ($escapeNext) {
                    $escapeNext = false;
                    continue;
                }
                if ($char === '\\') {
                    $escapeNext = true;
                    continue;
                }
                if ($char === '"' && !$escapeNext) {
                    $inString = !$inString;
                    continue;
                }
                if (!$inString) {
                    if ($char === '{') {
                        $braceCount++;
                    } elseif ($char === '}') {
                        $braceCount--;
                        if ($braceCount === 0) {
                            $jsonEnd = $i + 1;
                            break;
                        }
                    }
                }
            }
            
            if ($braceCount === 0 && $jsonEnd > $jsonStart) {
                $jsonStr = substr($outputText, $jsonStart, $jsonEnd - $jsonStart);
                $jsonStr = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $jsonStr);
                $jsonStr = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $jsonStr);
                $jsonStr = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $jsonStr);
                $jsonStr = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $jsonStr);
                $jsonStr = preg_replace('/"\s+"([^"]+)"/', '"$1"', $jsonStr);
                $cornersData = json_decode($jsonStr, true);
            }
        }
    }
    
    // Last resort - regex extraction
    if ($cornersData === null || !is_array($cornersData)) {
        if (preg_match('/(\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\})/s', $outputText, $matches)) {
            foreach ($matches as $match) {
                $cleanedMatch = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $match);
                $cleanedMatch = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $cleanedMatch);
                $cleanedMatch = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $cleanedMatch);
                $cleanedMatch = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $cleanedMatch);
                $cleanedMatch = preg_replace('/"\s+"([^"]+)"/', '"$1"', $cleanedMatch);
                $decoded = json_decode($cleanedMatch, true);
                if (is_array($decoded) && isset($decoded['corners'])) {
                    $cornersData = $decoded;
                    break;
                }
            }
        }
    }
    
    if (!is_array($cornersData) || !isset($cornersData['corners']) || !is_array($cornersData['corners'])) {
        update_task_status($jsonPath, 'ai_corners', 'wanted');
        return [
            'ok' => false,
            'error' => 'invalid_corners_format',
            'output_text' => substr($outputText, 0, 1000)
        ];
    }
    
    if (count($cornersData['corners']) !== 4) {
        update_task_status($jsonPath, 'ai_corners', 'wanted');
        return [
            'ok' => false,
            'error' => 'invalid_corner_count_in_response',
            'count' => count($cornersData['corners'])
        ];
    }
    
    // Convert percentages to pixel coordinates
    $rawPixelCorners = [];
    foreach ($cornersData['corners'] as $corner) {
        $normalizedCorner = [];
        foreach ($corner as $key => $value) {
            $normalizedCorner[trim($key)] = $value;
        }
        
        $xPercent = $normalizedCorner['x'] ?? $corner['x'] ?? null;
        $yPercent = $normalizedCorner['y'] ?? $corner['y'] ?? null;
        
        if ($xPercent === null || $yPercent === null) {
            update_task_status($jsonPath, 'ai_corners', 'wanted');
            return ['ok' => false, 'error' => 'missing_corner_coordinates'];
        }
        
        $xPixel = round(($xPercent / 100) * $imgW);
        $yPixel = round(($yPercent / 100) * $imgH);
        
        $rawPixelCorners[] = [
            'x' => $xPixel,
            'y' => $yPixel,
            'x_percent' => $xPercent,
            'y_percent' => $yPercent,
            'label' => trim(strtolower($normalizedCorner['label'] ?? $corner['label'] ?? ''))
        ];
    }
    
    // Calculate painting dimensions
    $topLeft = $rawPixelCorners[0];
    $topRight = $rawPixelCorners[1];
    $bottomRight = $rawPixelCorners[2];
    $bottomLeft = $rawPixelCorners[3];
    
    $topWidth = sqrt(pow($topRight['x'] - $topLeft['x'], 2) + pow($topRight['y'] - $topLeft['y'], 2));
    $bottomWidth = sqrt(pow($bottomRight['x'] - $bottomLeft['x'], 2) + pow($bottomRight['y'] - $bottomLeft['y'], 2));
    $paintingWidth = ($topWidth + $bottomWidth) / 2;
    
    $leftHeight = sqrt(pow($bottomLeft['x'] - $topLeft['x'], 2) + pow($bottomLeft['y'] - $topLeft['y'], 2));
    $rightHeight = sqrt(pow($bottomRight['x'] - $topRight['x'], 2) + pow($bottomRight['y'] - $topRight['y'], 2));
    $paintingHeight = ($leftHeight + $rightHeight) / 2;
    
    // Apply offset
    $offsetX = ($offsetPercent / 100) * $paintingWidth;
    $offsetY = ($offsetPercent / 100) * $paintingHeight;
    
    $pixelCorners = [];
    foreach ($rawPixelCorners as $idx => $corner) {
        $xPixel = $corner['x'];
        $yPixel = $corner['y'];
        
        switch ($idx) {
            case 0: $xPixel += $offsetX; $yPixel += $offsetY; break;
            case 1: $xPixel -= $offsetX; $yPixel += $offsetY; break;
            case 2: $xPixel -= $offsetX; $yPixel -= $offsetY; break;
            case 3: $xPixel += $offsetX; $yPixel -= $offsetY; break;
        }
        
        $xPixel = max(0, min($imgW - 1, round($xPixel)));
        $yPixel = max(0, min($imgH - 1, round($yPixel)));
        
        $xPercent = ($xPixel / $imgW) * 100;
        $yPercent = ($yPixel / $imgH) * 100;
        
        $pixelCorners[] = [
            'x' => $xPixel,
            'y' => $yPixel,
            'x_percent' => $xPercent,
            'y_percent' => $yPercent,
            'label' => $corner['label']
        ];
    }
    
    $resultCorners = [
        [$pixelCorners[0]['x'], $pixelCorners[0]['y']],
        [$pixelCorners[1]['x'], $pixelCorners[1]['y']],
        [$pixelCorners[2]['x'], $pixelCorners[2]['y']],
        [$pixelCorners[3]['x'], $pixelCorners[3]['y']]
    ];
    
    // Save offset, corners_used, and update status
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
    $aiCorners = $meta['ai_corners'] ?? [];
    $aiCorners['offset_percent'] = $offsetPercent;
    $aiCorners['corners_used'] = $resultCorners;
    $aiCorners['status'] = 'completed';
    $aiCorners['completed_at'] = date('c');
    update_json_file($jsonPath, ['ai_corners' => $aiCorners], false);
    
    return [
        'ok' => true,
        'completed' => true,
        'corners' => $resultCorners,
        'corners_with_percentages' => $pixelCorners
    ];
}

/**
 * Calculate corners for an image using AI (Replicate/Gemini)
 * 
 * @param string $imagePath Relative or absolute path to the _original image
 * @param float $offsetPercent Offset percentage (0-10, default 1.0)
 * @return array Result array with 'ok' => true/false and corner data or error info
 */
function calculate_corners(string $imagePath, float $offsetPercent = 1.0): array {
    // Clamp offset between 0 and 10 percent
    $offsetPercent = max(0, min(10, $offsetPercent));
    
    try {
        $TOKEN = load_replicate_token();
    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => 'missing REPLICATE_API_TOKEN'];
    }
    
    // Resolve absolute path
    $abs = $imagePath;
    if ($imagePath[0] !== '/' && !preg_match('#^[a-z]+://#i', $imagePath)) {
        $abs = dirname(__DIR__) . '/' . ltrim($imagePath, '/');
    }
    
    if (!is_file($abs)) {
        return ['ok' => false, 'error' => 'image not found', 'path' => $abs];
    }
    
    $rel = $imagePath;

    [$imgW, $imgH] = getimagesize($abs);
    $mime = mime_content_type($abs);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
        return ['ok' => false, 'error' => 'unsupported image type', 'mime' => $mime];
    }
    $imgB64 = base64_encode(file_get_contents($abs));

    // ---- Check for cached Replicate response ----
    $imageFilename = basename($abs);
    $imagesDir = dirname($abs);
    $jsonPath = get_meta_path($imageFilename, $imagesDir);
    $existingJson = load_meta($imageFilename, $imagesDir);
    $aiCorners = $existingJson['ai_corners'] ?? [];
    $cachedResponse = null;
    if (isset($aiCorners['replicate_response']) && 
        is_array($aiCorners['replicate_response'])) {
        // Use cached Replicate response
        $cachedResponse = $aiCorners['replicate_response'];
    }

    // Check if we already have a prediction URL (prediction already started)
    $predictionUrl = $aiCorners['prediction_url'] ?? null;
    if ($predictionUrl && is_string($predictionUrl)) {
        // Prediction already started - return early
        return [
            'ok' => true,
            'prediction_started' => true,
            'url' => $predictionUrl,
            'message' => 'Prediction already in progress'
        ];
    }
    
    // If we have cached succeeded response, use it and process normally
    if ($cachedResponse !== null && isset($cachedResponse['status']) && $cachedResponse['status'] === 'succeeded') {
        // Use cached response and process it (will recalculate corners)
        return process_completed_corner_prediction($jsonPath, $cachedResponse, $offsetPercent);
    }

    // ---- Prompt for corner detection ----
    $prompt = <<<PROMPT
Analyze this image and identify the four corners of the painting canvas (excluding frame, wall, mat, glass, shadows).

Return the coordinates as percentages relative to the image dimensions in JSON format:
{
  "corners": [
    {"x": 10.5, "y": 15.2, "label": "top-left"},
    {"x": 89.3, "y": 14.8, "label": "top-right"},
    {"x": 88.7, "y": 85.1, "label": "bottom-right"},
    {"x": 11.2, "y": 84.9, "label": "bottom-left"}
  ]
}

The coordinates should be percentages (0-100) where:
- x: horizontal position as percentage of image width
- y: vertical position as percentage of image height
- Order: top-left, top-right, bottom-right, bottom-left

Return ONLY valid JSON, no other text.
PROMPT;

    // ---- Replicate API Call (Google Gemini 3 Pro) ----
    $payload = [
        'input' => [
            'images' => ["data:$mime;base64,$imgB64"],
            'max_output_tokens' => 65535,
            'prompt' => $prompt,
            'temperature' => 1,
            'thinking_level' => 'low',
            'top_p' => 0.95,
            'videos' => []
        ]
    ];

    try {
        // Create prediction (without waiting)
        $ch = curl_init("https://api.replicate.com/v1/models/google/gemini-3-pro/predictions");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Token $TOKEN", "Content-Type: application/json"],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false || $httpCode >= 400) {
            require_once __DIR__ . '/meta.php';
            update_task_status($jsonPath, 'ai_corners', 'wanted');
            return [
                'ok' => false,
                'error' => 'replicate_failed',
                'detail' => $err ?: $res,
                'http_code' => $httpCode
            ];
        }
        
        $resp = json_decode($res, true);
        if (!is_array($resp) || !isset($resp['urls']['get'])) {
            require_once __DIR__ . '/meta.php';
            update_task_status($jsonPath, 'ai_corners', 'wanted');
            return ['ok' => false, 'error' => 'invalid_prediction_response', 'sample' => substr($res, 0, 500)];
        }
        
        $predictionUrl = $resp['urls']['get'];
        $predictionId = $resp['id'] ?? null;
        $status = $resp['status'] ?? 'unknown';
        
        // Save prediction URL immediately - update ai_corners object
        require_once __DIR__ . '/meta.php';
        $aiCorners = $existingJson['ai_corners'] ?? [];
        $aiCorners['prediction_url'] = $predictionUrl;
        $aiCorners['prediction_id'] = $predictionId;
        $aiCorners['prediction_status'] = $status;
        $aiCorners['timestamp'] = date('c');
        update_json_file($jsonPath, [
            'ai_corners' => $aiCorners
        ], false);
        
        // Set status to in_progress
        update_task_status($jsonPath, 'ai_corners', 'in_progress');
        
        // Return early - polling will happen in background task processor
        return [
            'ok' => true,
            'prediction_started' => true,
            'url' => $predictionUrl,
            'id' => $predictionId,
            'status' => $status
        ];
    } catch (Throwable $e) {
        require_once __DIR__ . '/meta.php';
        update_task_status($jsonPath, 'ai_corners', 'wanted');
        return ['ok' => false, 'error' => 'replicate_api_error', 'detail' => $e->getMessage()];
    }
}

// HTTP endpoint - for backward compatibility
// Only run if this script is being called directly (not included)
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'ai_calc_corners.php') {
    // Continue execution even if user closes browser/connection
    ignore_user_abort(true);
    
    // Increase execution time limit for long-running predictions (10 minutes)
    set_time_limit(600);
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // ---- Eingabe ----
        $rel = $_POST['image_path'] ?? '';
        if ($rel === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'image_path required']);
            exit;
        }
        
        // Get offset from POST (default: 1.0 percent)
        $offsetPercent = isset($_POST['offset']) ? (float)$_POST['offset'] : 1.0;
        
        $result = calculate_corners($rel, $offsetPercent);
        
        // Set appropriate HTTP status code
        if (!$result['ok']) {
            http_response_code(500);
        }
        
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'unexpected_error', 'detail' => $e->getMessage()]);
    }
}

