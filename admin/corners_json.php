<?php
declare(strict_types=1);

// Continue execution even if user closes browser/connection
ignore_user_abort(true);

// Increase execution time limit for long-running predictions (10 minutes)
set_time_limit(600);

require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $TOKEN = load_replicate_token();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing REPLICATE_API_TOKEN']);
    exit;
}

// ---- Eingabe ----
$rel = $_POST['image_path'] ?? '';
if ($rel === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'image_path required']);
    exit;
}

$abs = $rel;
if ($rel[0] !== '/' && !preg_match('#^[a-z]+://#i', $rel)) {
    $abs = dirname(__DIR__) . '/' . ltrim($rel, '/');
}
if (!is_file($abs)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'image not found', 'path' => $abs]);
    exit;
}

[$imgW, $imgH] = getimagesize($abs);
$mime = mime_content_type($abs);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unsupported image type', 'mime' => $mime]);
    exit;
}
$imgB64 = base64_encode(file_get_contents($abs));

// ---- Check for cached corner detection result ----
$jsonPath = $abs . '.json';
$cachedCorners = null;
$cachedCornersWithPercentages = null;
if (is_file($jsonPath)) {
    $existingJson = json_decode(file_get_contents($jsonPath), true);
    if (is_array($existingJson) && isset($existingJson['corner_detection']) && 
        isset($existingJson['corner_detection']['corners']) && 
        is_array($existingJson['corner_detection']['corners']) && 
        count($existingJson['corner_detection']['corners']) === 4) {
        // Use cached result
        $cachedCorners = $existingJson['corner_detection']['corners'];
        $cachedCornersWithPercentages = $existingJson['corner_detection']['corners_with_percentages'] ?? null;
    }
}

// If we have cached corners, use them and skip API call
if ($cachedCorners !== null) {
    // Return cached corners
    echo json_encode([
        'ok' => true,
        'corners' => $cachedCorners,
        'original_corners' => $cachedCorners, // Alias for compatibility with free.html
        'corners_with_percentages' => $cachedCornersWithPercentages ?? $cachedCorners,
        'image_width' => $imgW,
        'image_height' => $imgH,
        'source' => $rel,
        'cached' => true
    ]);
    exit;
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
// Using the structure for gemini-3-pro
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
    // Step 1: Create prediction (without waiting)
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
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'replicate_failed',
            'detail' => $err ?: $res,
            'http_code' => $httpCode
        ]);
        exit;
    }
    
    $resp = json_decode($res, true);
    if (!is_array($resp) || !isset($resp['urls']['get'])) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'invalid_prediction_response', 'sample' => substr($res, 0, 500)]);
        exit;
    }
    
    $predictionUrl = $resp['urls']['get'];
    $status = $resp['status'] ?? 'unknown';
    
    // Step 2: Poll until prediction completes (max 10 minutes)
    $maxAttempts = 120; // 120 attempts * 5 seconds = 10 minutes max
    $attempt = 0;
    
    while (in_array($status, ['starting', 'processing']) && $attempt < $maxAttempts) {
        sleep(5); // Wait 5 seconds between polls
        $attempt++;
        
        $ch = curl_init($predictionUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Token $TOKEN"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($res === false || $httpCode >= 400) {
            continue; // Retry on error
        }
        
        $resp = json_decode($res, true);
        if (!is_array($resp)) {
            continue; // Retry on invalid JSON
        }
        
        $status = $resp['status'] ?? 'unknown';
        
        // If completed or failed, break the loop
        if ($status === 'succeeded' || $status === 'failed' || $status === 'canceled') {
            break;
        }
    }
    
    // Step 3: Save the complete Replicate response FIRST (before any processing)
    $existingJson = [];
    if (is_file($jsonPath)) {
        $existingJson = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($existingJson)) {
            $existingJson = [];
        }
    }
    
    // Store the complete Replicate response as string (even if invalid/failed)
    $existingJson['corner_detection'] = [
        'replicate_response_raw' => json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'replicate_response' => $resp, // Also store as array for easier access
        'timestamp' => date('c'),
        'status' => $status
    ];
    
    // Save immediately
    file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    
    // Step 4: Check final status and extract output
    if ($status !== 'succeeded') {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'prediction_not_completed',
            'status' => $status,
            'detail' => $resp['error'] ?? 'Prediction did not complete in time',
            'attempts' => $attempt
        ]);
        exit;
    }
    
    // Extract text from Replicate/Gemini response
    $outputText = '';
    if (isset($resp['output'])) {
        if (is_array($resp['output'])) {
            $outputText = implode(' ', $resp['output']);
        } else {
            $outputText = (string)$resp['output'];
        }
    }
    
    if (empty($outputText)) {
        // Update JSON with empty output status
        $existingJson['corner_detection']['output_text'] = '';
        $existingJson['corner_detection']['output_empty'] = true;
        file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'empty_output', 'response' => $resp]);
        exit;
    }
    
    // Update JSON with output text
    $existingJson['corner_detection']['output_text'] = $outputText;
    file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    
    // Try to extract JSON from the response
    $cornersData = null;
    
    // Step 1: Try to extract JSON from code blocks (```json ... ```)
    // Match code blocks with proper brace matching
    if (preg_match('/```(?:json)?\s*(\{.*)\s*```/s', $outputText, $matches)) {
        $jsonStr = $matches[1];
        
        // Find the complete JSON object by matching braces
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
            // Clean up the JSON string - remove spaces in numeric values (e.g., "87. 5" -> "87.5")
            $jsonStr = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $jsonStr); // Fix "87. 5" -> "87.5"
            $jsonStr = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $jsonStr); // Fix "87 .5" -> "87.5"
            $cornersData = json_decode($jsonStr, true);
        }
    }
    
    // Step 2: If that fails, try parsing the whole output as JSON
    if ($cornersData === null || !is_array($cornersData)) {
        $cleanedOutput = $outputText;
        // Clean up spaces in numeric values
        $cleanedOutput = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $cleanedOutput);
        $cleanedOutput = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $cleanedOutput);
        $cornersData = json_decode($cleanedOutput, true);
    }
    
    // Step 3: If that fails, try to extract JSON using brace matching
    if ($cornersData === null || !is_array($cornersData)) {
        // Find JSON object boundaries by matching braces properly
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
                // Clean up spaces in numeric values
                $jsonStr = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $jsonStr);
                $jsonStr = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $jsonStr);
                $cornersData = json_decode($jsonStr, true);
            }
        }
    }
    
    // Step 4: Last resort - try regex extraction
    if ($cornersData === null || !is_array($cornersData)) {
        if (preg_match('/(\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\})/s', $outputText, $matches)) {
            foreach ($matches as $match) {
                // Clean up spaces in numeric values
                $cleanedMatch = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $match);
                $cleanedMatch = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $cleanedMatch);
                $decoded = json_decode($cleanedMatch, true);
                if (is_array($decoded) && isset($decoded['corners'])) {
                    $cornersData = $decoded;
                    break;
                }
            }
        }
    }
    
    if (!is_array($cornersData) || !isset($cornersData['corners']) || !is_array($cornersData['corners'])) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_corners_format',
            'output_text' => substr($outputText, 0, 1000),
            'parsed' => $cornersData,
            'output_length' => strlen($outputText)
        ]);
        exit;
    }
    
    // Ensure we have exactly 4 corners in the parsed data
    if (count($cornersData['corners']) !== 4) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_corner_count_in_response',
            'count' => count($cornersData['corners']),
            'raw_corners' => $cornersData['corners'],
            'output_text' => substr($outputText, 0, 500)
        ]);
        exit;
    }
    
    // Convert percentages to pixel coordinates
    $pixelCorners = [];
    foreach ($cornersData['corners'] as $corner) {
        // Normalize keys by trimming whitespace (handles cases like " y" instead of "y")
        $normalizedCorner = [];
        foreach ($corner as $key => $value) {
            $normalizedKey = trim($key);
            $normalizedCorner[$normalizedKey] = $value;
        }
        
        // Try to get x and y values, checking both normalized and original keys
        $xPercent = null;
        $yPercent = null;
        
        if (isset($normalizedCorner['x'])) {
            $xPercent = (float)$normalizedCorner['x'];
        } elseif (isset($corner['x'])) {
            $xPercent = (float)$corner['x'];
        }
        
        if (isset($normalizedCorner['y'])) {
            $yPercent = (float)$normalizedCorner['y'];
        } elseif (isset($corner['y'])) {
            $yPercent = (float)$corner['y'];
        }
        
        if ($xPercent === null || $yPercent === null) {
            http_response_code(502);
            echo json_encode([
                'ok' => false,
                'error' => 'missing_corner_coordinates',
                'corner' => $corner,
                'normalized_corner' => $normalizedCorner,
                'all_corners' => $cornersData['corners']
            ]);
            exit;
        }
        
        // Convert percentage to pixels
        $xPixel = round(($xPercent / 100) * $imgW);
        $yPixel = round(($yPercent / 100) * $imgH);
        
        $pixelCorners[] = [
            'x' => $xPixel,
            'y' => $yPixel,
            'x_percent' => $xPercent,
            'y_percent' => $yPercent,
            'label' => trim($normalizedCorner['label'] ?? $corner['label'] ?? '') // Trim whitespace from labels
        ];
    }
    
    // Ensure we have exactly 4 corners after processing
    if (count($pixelCorners) !== 4) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'invalid_corner_count_after_processing',
            'count' => count($pixelCorners),
            'corners' => $pixelCorners,
            'raw_corners_count' => count($cornersData['corners'])
        ]);
        exit;
    }
    
    // Return in the same format as corners.php (array of [x, y] pairs)
    $resultCorners = [
        [$pixelCorners[0]['x'], $pixelCorners[0]['y']], // top-left
        [$pixelCorners[1]['x'], $pixelCorners[1]['y']], // top-right
        [$pixelCorners[2]['x'], $pixelCorners[2]['y']], // bottom-right
        [$pixelCorners[3]['x'], $pixelCorners[3]['y']]  // bottom-left
    ];
    
    // Update JSON file with extracted corner detection results (for convenience)
    // But the raw response is already saved above
    $existingJson['corner_detection']['corners'] = $resultCorners;
    $existingJson['corner_detection']['corners_with_percentages'] = $pixelCorners;
    $existingJson['corner_detection']['image_width'] = $imgW;
    $existingJson['corner_detection']['image_height'] = $imgH;
    
    // Save updated JSON file
    file_put_contents($jsonPath, json_encode($existingJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    
    echo json_encode([
        'ok' => true,
        'corners' => $resultCorners,
        'original_corners' => $resultCorners, // Alias for compatibility with free.html
        'corners_with_percentages' => $pixelCorners,
        'image_width' => $imgW,
        'image_height' => $imgH,
        'source' => $rel,
        'cached' => false
    ]);
    
} catch (RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'replicate_failed', 'detail' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'unexpected_error', 'detail' => $e->getMessage()]);
    exit;
}

