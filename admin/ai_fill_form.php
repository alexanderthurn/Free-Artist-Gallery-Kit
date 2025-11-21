<?php
declare(strict_types=1);

require_once __DIR__.'/utils.php';
require_once __DIR__.'/meta.php';

/**
 * Poll a form filling prediction and process results if complete
 * 
 * @param string $jsonPath Full path to JSON metadata file
 * @return array Result array with 'ok' => true/false, 'completed' => bool, 'still_processing' => bool
 */
function poll_form_prediction(string $jsonPath): array {
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
    
    $predictionUrl = $meta['ai_fill_form']['prediction_url'] ?? null;
    if (!$predictionUrl || !is_string($predictionUrl)) {
        return ['ok' => false, 'error' => 'prediction_url_not_found'];
    }
    
    try {
        $token = load_replicate_token();
    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => 'missing REPLICATE_API_TOKEN'];
    }
    
    // Make single GET request to check status
    $ch = curl_init($predictionUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Token $token"],
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
    
    // Save the response immediately - update ai_fill_form object
    $aiFillForm = $meta['ai_fill_form'] ?? [];
    $aiFillForm['replicate_response_raw'] = json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $aiFillForm['replicate_response'] = $resp;
    $aiFillForm['timestamp'] = date('c');
    $aiFillForm['prediction_status'] = $status; // Store prediction status separately from task status
    update_json_file($jsonPath, ['ai_fill_form' => $aiFillForm], false);
    
    if ($status === 'succeeded') {
        // Process the completed prediction
        return process_completed_form_prediction($jsonPath, $resp);
    } elseif ($status === 'failed' || $status === 'canceled') {
        // Prediction failed - set status to wanted for retry
        update_task_status($jsonPath, 'ai_form', 'wanted');
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
 * Process a completed form prediction response
 * 
 * @param string $jsonPath Full path to JSON metadata file
 * @param array $resp Replicate API response
 * @return array Result array with extracted form data
 */
function process_completed_form_prediction(string $jsonPath, array $resp): array {
    // Load meta first to get existing ai_fill_form
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
    
    // Extract text output from response
    $output = '';
    if (isset($resp['output'])) {
        if (is_array($resp['output'])) {
            $output = implode('', $resp['output']);
        } else {
            $output = (string)$resp['output'];
        }
    }
    
    // Update JSON with output text (even if empty)
    $aiFillForm = $meta['ai_fill_form'] ?? [];
    $aiFillForm['output_text'] = $output;
    $aiFillForm['output_empty'] = empty($output);
    update_json_file($jsonPath, ['ai_fill_form' => $aiFillForm], false);
    
    if (empty($output)) {
        update_task_status($jsonPath, 'ai_form', 'wanted');
        return ['ok' => false, 'error' => 'empty_output', 'sample' => is_scalar($resp['output']) ? $resp['output'] : json_encode($resp['output'])];
    }
    
    // Get image path for validation
    $imagesDir = dirname($jsonPath);
    $imageFilename = basename($jsonPath, '.json');
    $finalPath = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, pathinfo($imageFilename, PATHINFO_FILENAME)) === 0) {
            if (strpos($fileStem, '_final') !== false || strpos($fileStem, '_original') !== false) {
                $finalPath = $imagesDir . '/' . $file;
                break;
            }
        }
    }
    
    // Try to extract JSON from the output (same logic as before)
    $jsonMatch = null;
    
    // Step 1: Try to extract JSON from code blocks
    if (preg_match('/```(?:json)?\s*(\{.*)\s*```/s', $output, $matches)) {
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
            $jsonMatch = json_decode($jsonStr, true);
        }
    }
    
    // Step 2: If that fails, try to extract JSON using brace matching
    if (($jsonMatch === null || !is_array($jsonMatch)) && strpos($output, '{') !== false) {
        $jsonStart = strpos($output, '{');
        $braceCount = 0;
        $jsonEnd = $jsonStart;
        $inString = false;
        $escapeNext = false;
        
        for ($i = $jsonStart; $i < strlen($output); $i++) {
            $char = $output[$i];
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
            $jsonStr = substr($output, $jsonStart, $jsonEnd - $jsonStart);
            $jsonStr = preg_replace('/"(\w+)"\s*"\s*\\\?":/', '"$1":', $jsonStr);
            $jsonStr = preg_replace('/:\s*"\s*"([^"]+)"/', ': "$1"', $jsonStr);
            $jsonStr = preg_replace('/:\s*(\d+)"\s*"(\d+\.\d+)/', ': $1$2', $jsonStr);
            $jsonStr = preg_replace('/:\s*(\d+\.\d+)"\s*"(\d+)/', ': $1$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $jsonStr);
            $jsonStr = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $jsonStr);
            $jsonStr = preg_replace('/"\s*"([^"]+)"/', '"$1"', $jsonStr);
            $jsonMatch = json_decode($jsonStr, true);
        }
    }
    
    // Step 3: Last resort - try simple regex
    if ($jsonMatch === null || !is_array($jsonMatch)) {
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $output, $matches)) {
            $cleanedMatch = $matches[0];
            $cleanedMatch = preg_replace('/"(\w+)"\s*"\s*\\\?":/', '"$1":', $cleanedMatch);
            $cleanedMatch = preg_replace('/:\s*"\s*"([^"]+)"/', ': "$1"', $cleanedMatch);
            $cleanedMatch = preg_replace('/:\s*(\d+)"\s*"(\d+\.\d+)/', ': $1$2', $cleanedMatch);
            $cleanedMatch = preg_replace('/:\s*(\d+\.\d+)"\s*"(\d+)/', ': $1$2', $cleanedMatch);
            $cleanedMatch = preg_replace('/(\d+)\s+\.\s*(\d+)/', '$1.$2', $cleanedMatch);
            $cleanedMatch = preg_replace('/(\d+)\s*\.\s+(\d+)/', '$1.$2', $cleanedMatch);
            $cleanedMatch = preg_replace('/(\d+)\s+(\d+\.\d+)/', '$1$2', $cleanedMatch);
            $cleanedMatch = preg_replace('/(\d+\.\d+)\s+(\d+)/', '$1$2', $cleanedMatch);
            $cleanedMatch = preg_replace('/"\s*"([^"]+)"/', '"$1"', $cleanedMatch);
            $jsonMatch = json_decode($cleanedMatch, true);
        }
    }
    
    // Get current date in German format
    $currentDate = date('d.m.Y');
    
    // Get image dimensions to validate width/height
    $imageInfo = $finalPath ? @getimagesize($finalPath) : false;
    $imageWidth = $imageInfo ? $imageInfo[0] : null;
    $imageHeight = $imageInfo ? $imageInfo[1] : null;
    $isPortrait = $imageWidth && $imageHeight && $imageHeight > $imageWidth;
    $isLandscape = $imageWidth && $imageHeight && $imageWidth > $imageHeight;
    
    if ($jsonMatch && is_array($jsonMatch)) {
        // Use date from AI if available, otherwise use current date
        $date = trim($jsonMatch['date'] ?? '');
        if (empty($date)) {
            $date = $currentDate;
        }
        
        $width = trim($jsonMatch['width'] ?? '');
        $height = trim($jsonMatch['height'] ?? '');
        
        // Validate and correct width/height based on image aspect ratio
        if (!empty($width) && !empty($height) && $imageWidth && $imageHeight) {
            $widthNum = (float)$width;
            $heightNum = (float)$height;
            $imageRatio = $imageWidth / $imageHeight;
            $providedRatio = $widthNum / $heightNum;
            
            if (abs($imageRatio - $providedRatio) > 0.2) {
                if ($isPortrait && $widthNum >= $heightNum) {
                    $temp = $widthNum;
                    $widthNum = $heightNum;
                    $heightNum = $temp;
                } elseif ($isLandscape && $heightNum >= $widthNum) {
                    $temp = $widthNum;
                    $widthNum = $heightNum;
                    $heightNum = $temp;
                } else {
                    if ($widthNum > 0) {
                        $heightNum = round($widthNum / $imageRatio);
                    } elseif ($heightNum > 0) {
                        $widthNum = round($heightNum * $imageRatio);
                    }
                }
                $width = (string)round($widthNum);
                $height = (string)round($heightNum);
            }
        }
        
        // Update JSON with extracted form data
        $updates = [];
        if (!empty(trim($jsonMatch['title'] ?? ''))) {
            $updates['title'] = trim($jsonMatch['title']);
        }
        if (!empty(trim($jsonMatch['description'] ?? ''))) {
            $updates['description'] = trim($jsonMatch['description']);
        }
        if (!empty(trim($jsonMatch['tags'] ?? ''))) {
            $updates['tags'] = trim($jsonMatch['tags']);
        }
        if (!empty($date)) {
            $updates['date'] = $date;
        }
        if (!empty($width)) {
            $updates['width'] = $width;
        }
        if (!empty($height)) {
            $updates['height'] = $height;
        }
        
        $aiFillForm = $meta['ai_fill_form'] ?? [];
        $aiFillForm['extracted_data'] = [
            'title' => trim($jsonMatch['title'] ?? ''),
            'description' => trim($jsonMatch['description'] ?? ''),
            'tags' => trim($jsonMatch['tags'] ?? ''),
            'date' => $date,
            'width' => $width,
            'height' => $height
        ];
        $aiFillForm['status'] = 'completed';
        $aiFillForm['completed_at'] = date('c');
        $updates['ai_fill_form'] = $aiFillForm;
        
        update_json_file($jsonPath, $updates, false);
        
        return [
            'ok' => true,
            'completed' => true,
            'title' => trim($jsonMatch['title'] ?? ''),
            'description' => trim($jsonMatch['description'] ?? ''),
            'tags' => trim($jsonMatch['tags'] ?? ''),
            'date' => $date,
            'width' => $width,
            'height' => $height
        ];
    } else {
        // Fallback: Try to parse text response (same logic as before)
        $title = '';
        $description = '';
        $tags = '';
        $date = $currentDate;
        $width = '';
        $height = '';
        
        if (preg_match('/(\d+)\s*[x×]\s*(\d+)/i', $output, $m)) {
            $width = trim($m[1]);
            $height = trim($m[2]);
            
            if ($imageWidth && $imageHeight) {
                $widthNum = (float)$width;
                $heightNum = (float)$height;
                $imageRatio = $imageWidth / $imageHeight;
                $providedRatio = $widthNum / $heightNum;
                
                if (abs($imageRatio - $providedRatio) > 0.2) {
                    if ($isPortrait && $widthNum >= $heightNum) {
                        $temp = $widthNum;
                        $widthNum = $heightNum;
                        $heightNum = $temp;
                    } elseif ($isLandscape && $heightNum >= $widthNum) {
                        $temp = $widthNum;
                        $widthNum = $heightNum;
                        $heightNum = $temp;
                    } else {
                        if ($widthNum > 0) {
                            $heightNum = round($widthNum / $imageRatio);
                        } elseif ($heightNum > 0) {
                            $widthNum = round($heightNum * $imageRatio);
                        }
                    }
                    $width = (string)round($widthNum);
                    $height = (string)round($heightNum);
                }
            }
        }
        
        if (preg_match('/title[:\s]+([^\n]+)/i', $output, $m)) {
            $title = trim($m[1]);
        }
        if (preg_match('/description[:\s]+([^\n]+(?:\n[^\n]+)*)/i', $output, $m)) {
            $description = trim($m[1]);
        }
        if (preg_match('/tags[:\s]+([^\n]+)/i', $output, $m)) {
            $tags = trim($m[1]);
        }
        if (preg_match('/datum[:\s]+([^\n]+)/i', $output, $m)) {
            $extractedDate = trim($m[1]);
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $extractedDate)) {
                $date = $extractedDate;
            }
        }
        
        $updates = [];
        if (!empty($title)) $updates['title'] = $title;
        if (!empty($description)) $updates['description'] = $description;
        if (!empty($tags)) $updates['tags'] = $tags;
        if (!empty($date)) $updates['date'] = $date;
        if (!empty($width)) $updates['width'] = $width;
        if (!empty($height)) $updates['height'] = $height;
        
            $aiFillForm = $meta['ai_fill_form'] ?? [];
            $aiFillForm['extracted_data'] = [
                'title' => $title,
                'description' => $description,
                'tags' => $tags,
                'date' => $date,
                'width' => $width,
                'height' => $height,
                'parsing_method' => 'fallback'
            ];
            $aiFillForm['status'] = 'completed';
            $aiFillForm['completed_at'] = date('c');
            $updates['ai_fill_form'] = $aiFillForm;
            
            update_json_file($jsonPath, $updates, false);
        
        return [
            'ok' => true,
            'completed' => true,
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'date' => $date,
            'width' => $width,
            'height' => $height
        ];
    }
}

/**
 * Process AI form filling for an image
 * @param string $imageFilename Image filename (e.g., "IMG_2110_2_final.jpg")
 * @return array Result array with 'ok' key and other data
 */
function process_ai_fill_form(string $imageFilename): array {
    $imagesDir = __DIR__.'/images/';
    $imagePath = $imagesDir.$imageFilename;

    if (!is_file($imagePath)) {
        return ['ok' => false, 'error' => 'Image not found'];
    }

    // Extract base name to find _original image
    $base = extract_base_name($imageFilename);

    // Find _original image (preferred)
    $originalImage = null;
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (strpos($fileStem, $base.'_original') === 0) {
            $originalImage = $file;
            break;
        }
    }

    // If no _original found, try other extensions
    if (!$originalImage) {
        $extensions = ['png', 'jpg', 'jpeg', 'webp'];
        foreach ($extensions as $e) {
            $testFile = $base.'_original.'.$e;
            if (is_file($imagesDir.$testFile)) {
                $originalImage = $testFile;
                break;
            }
        }
    }

    // Fallback to _final if no original found
    if (!$originalImage) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileStem = pathinfo($file, PATHINFO_FILENAME);
            if (strpos($fileStem, $base.'_final') === 0) {
                $originalImage = $file;
                break;
            }
        }
    }

    // Last fallback: use the provided image
    if (!$originalImage) {
        $originalImage = $imageFilename;
    }

    $finalPath = $imagesDir.$originalImage;

    if (!is_file($finalPath)) {
        return ['ok' => false, 'error' => 'Image not found', 'path' => $finalPath];
    }

    // Determine JSON file path - use _original image's JSON
    // Find the _original image for metadata
    $originalImageForMeta = null;
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        if (preg_match('/^' . preg_quote($base, '/') . '_original\.(jpg|jpeg|png|webp)$/i', $fileStem)) {
            $originalImageForMeta = $file;
            break;
        }
    }
    
    if (!$originalImageForMeta) {
        return ['ok' => false, 'error' => 'Original image not found for metadata'];
    }
    
    $jsonPath = get_meta_path($originalImageForMeta, $imagesDir);

    // Log the image path being used
    error_log('AI Fill Form: Using image: ' . $finalPath);
    error_log('AI Fill Form: Image filename: ' . $originalImage);
    error_log('AI Fill Form: JSON path: ' . $jsonPath);

    // Check if prediction URL already exists (prediction already started)
    $existingMeta = load_meta($originalImageForMeta, $imagesDir);
    $aiFillForm = $existingMeta['ai_fill_form'] ?? [];
    $predictionUrl = $aiFillForm['prediction_url'] ?? null;
    if ($predictionUrl && is_string($predictionUrl)) {
        // Prediction already started - return early
        return [
            'ok' => true,
            'prediction_started' => true,
            'url' => $predictionUrl,
            'message' => 'Prediction already in progress'
        ];
    }

    // Update status to in_progress
    update_task_status($jsonPath, 'ai_form', 'in_progress');

    try {
        $token = load_replicate_token();
        
        // Get image info and encode to base64
        [$imgW, $imgH] = getimagesize($finalPath);
        $mime = mime_content_type($finalPath);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            update_task_status($jsonPath, 'ai_form', 'wanted');
            return ['ok' => false, 'error' => 'unsupported image type', 'mime' => $mime];
        }
        
        // Read and encode image
        $imageData = file_get_contents($finalPath);
        if ($imageData === false) {
            update_task_status($jsonPath, 'ai_form', 'wanted');
            return ['ok' => false, 'error' => 'failed to read image file'];
        }
        
        $imgB64 = base64_encode($imageData);
        
        if (empty($imgB64)) {
            update_task_status($jsonPath, 'ai_form', 'wanted');
            return ['ok' => false, 'error' => 'failed to encode image'];
        }
        
        // Log image size for debugging
        error_log('AI Fill Form: Image size: ' . strlen($imageData) . ' bytes, Base64 length: ' . strlen($imgB64));
        
        $prompt = <<<PROMPT
Du bist ein erfahrener Kunstkurator und Texter für eine hochwertige Online-Galerie. Deine Aufgabe ist es, eine atmosphärische und ästhetisch ansprechende Beschreibung des hochgeladenen Bildes zu verfassen.

Analysiere das Kunstwerk nicht wie eine Datenbank, sondern wie ein Kritiker. Achte besonders auf:
1. Stimmung & Atmosphäre: Welche Emotionen weckt das Bild? (z.B. melancholisch, energiegeladen, ruhig, mysteriös)
2. Komposition & Technik: Beschreibe den Pinselstrich, die Lichtführung und die Farbpalette.
3. Stil: Handelt es sich um Abstraktion, Realismus, Impressionismus etc.?

Regeln für die Beschreibung:
- WICHTIG: Schreibe lebendig und evozierend. Vermeide Floskeln wie "Das Bild zeigt" oder "Man sieht".
- Steige direkt in die Szenerie oder die Stimmung ein.
- Nutze Adjektive, die die Sinne ansprechen (z.B. "leuchtend", "düster", "texturiert", "sanft").
- Interpretiere das Gesehene, statt es nur aufzulisten.

Gib das Ergebnis ausschließlich als valides JSON-Objekt zurück:

{
  "title": "Ein poetischer oder treffender Titel auf Deutsch (2-8 Wörter)",
  "description": "Ein fließender Text auf Deutsch (2-3 Sätze). Beschreibe die Wirkung der Farben, das Lichtspiel und die zentrale Komposition. Verbinde das Motiv mit der künstlerischen Machart.",
  "tags": "Stilrichtung, Hauptfarben, Stimmung, zentrale Motive (kommagetrennt)",
  "width": "Geschätzte Breite als Zahl (String)",
  "height": "Geschätzte Höhe als Zahl (String) - Achte strikt auf das Seitenverhältnis!",
  "date": "Datum im Format dd.mm.yyyy (nur falls signiert/sichtbar, sonst leer lassen)"
}
PROMPT;
        
        // Payload format for gemini-3-pro
        $payload = [
            'input' => [
                'images' => ["data:$mime;base64,$imgB64"],
                'max_output_tokens' => 65535,
                'prompt' => $prompt,
                'temperature' => 1,
                'thinking_level' => 'low',
                'top_p' => 0.95,
                'videos' => []
            ],
        ];
        
        // Create prediction (without waiting)
        $ch = curl_init("https://api.replicate.com/v1/models/google/gemini-3-pro/predictions");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Token $token", "Content-Type: application/json"],
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
            error_log('AI Fill Form: Replicate API error - HTTP ' . $httpCode . ': ' . ($err ?: substr($res, 0, 1000)));
            update_task_status($jsonPath, 'ai_form', 'wanted');
            return [
                'ok' => false,
                'error' => 'replicate_failed',
                'detail' => $err ?: $res,
                'http_code' => $httpCode
            ];
        }
        
        $resp = json_decode($res, true);
        if (!is_array($resp)) {
            error_log('AI Fill Form: Invalid JSON response from Replicate: ' . substr($res, 0, 1000));
            update_task_status($jsonPath, 'ai_form', 'wanted');
            return ['ok' => false, 'error' => 'invalid_prediction_response', 'sample' => substr($res, 0, 500)];
        }
        
        // Log the response for debugging
        error_log('AI Fill Form: Replicate prediction created: ' . json_encode([
            'id' => $resp['id'] ?? 'unknown',
            'status' => $resp['status'] ?? 'unknown',
            'error' => $resp['error'] ?? null
        ]));
        
        if (!isset($resp['urls']['get'])) {
            error_log('AI Fill Form: Missing prediction URL in response: ' . json_encode($resp));
            update_task_status($jsonPath, 'ai_form', 'wanted');
            return ['ok' => false, 'error' => 'invalid_prediction_response', 'response' => $resp];
        }
        
        $predictionUrl = $resp['urls']['get'];
        $predictionId = $resp['id'] ?? null;
        $status = $resp['status'] ?? 'unknown';
        
        // Save prediction URL immediately - update ai_fill_form object
        $aiFillForm = $existingMeta['ai_fill_form'] ?? [];
        $aiFillForm['prediction_url'] = $predictionUrl;
        $aiFillForm['prediction_id'] = $predictionId;
        $aiFillForm['prediction_status'] = $status;
        $aiFillForm['timestamp'] = date('c');
        update_json_file($jsonPath, [
            'ai_fill_form' => $aiFillForm
        ], false);
        
        // Return early - polling will happen in background task processor
        return [
            'ok' => true,
            'prediction_started' => true,
            'url' => $predictionUrl,
            'id' => $predictionId,
            'status' => $status
        ];
        
    } catch (Throwable $e) {
        // Reset status to wanted for retry
        if (is_file($jsonPath)) {
            update_task_status($jsonPath, 'ai_form', 'wanted');
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// HTTP endpoint (only when accessed directly)
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    // Continue execution even if user closes browser/connection
    ignore_user_abort(true);
    
    // Increase execution time limit for long-running predictions (10 minutes)
    set_time_limit(600);
    
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
    
    $result = process_ai_fill_form($image);
    
    if (!$result['ok']) {
        http_response_code(500);
    }
    
    echo json_encode($result);
}
