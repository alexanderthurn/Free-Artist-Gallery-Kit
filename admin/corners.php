<?php
// admin/extract_with_banana.php
declare(strict_types=1);

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
if ($rel===''){ http_response_code(400); echo json_encode(['ok' => false, 'error'=>'image_path required']); exit; }

// NEU: frei wählbare Hintergrundfarbe
$colorInput = trim($_POST['color'] ?? '#000000'); // Standard: schwarz

function parse_color_to_rgb(string $s): ?array {
  $s = trim($s);
  // Hex #rgb oder #rrggbb
  if (preg_match('/^#([0-9a-f]{3})$/i', $s, $m)) {
    [$r,$g,$b] = str_split(strtolower($m[1]));
    return [hexdec($r.$r), hexdec($g.$g), hexdec($b.$b)];
  }
  if (preg_match('/^#([0-9a-f]{6})$/i', $s, $m)) {
    $hex = strtolower($m[1]);
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
  }
  // Einfache Farbnamen
  $map = [
    'black'=>[0,0,0], 'white'=>[255,255,255], 'red'=>[255,0,0], 'green'=>[0,128,0],
    'blue'=>[0,0,255], 'magenta'=>[255,0,255], 'fuchsia'=>[255,0,255], 'cyan'=>[0,255,255],
    'yellow'=>[255,255,0], 'gray'=>[128,128,128], 'grey'=>[128,128,128],
    'orange'=>[255,165,0], 'purple'=>[128,0,128], 'pink'=>[255,192,203]
  ];
  $key = strtolower($s);
  return $map[$key] ?? null;
}
$bgRGB = parse_color_to_rgb($colorInput) ?? [0,0,0]; // Fallback: schwarz
$bgHex = sprintf("#%02x%02x%02x", $bgRGB[0], $bgRGB[1], $bgRGB[2]);

$abs = $rel;
if ($rel[0] !== '/' && !preg_match('#^[a-z]+://#i',$rel)) $abs = dirname(__DIR__).'/'.ltrim($rel,'/');
if (!is_file($abs)){ http_response_code(400); echo json_encode(['ok' => false, 'error'=>'image not found','path'=>$abs]); exit; }
[$imgW,$imgH] = getimagesize($abs);
$mime = mime_content_type($abs);
if (!in_array($mime,['image/jpeg','image/png','image/webp'])){ http_response_code(400); echo json_encode(['ok' => false, 'error'=>'unsupported image type','mime'=>$mime]); exit; }
$imgB64 = base64_encode(file_get_contents($abs));

// ---- Nano-Banana Version & Prompt ----
$VERSION = '2784c5d54c07d79b0a2a5385477038719ad37cb0745e61bbddf2fc236d196a6b';
$prompt = <<<PROMPT
You are an image editor performing geometric transformation ONLY.

Task:
- From the provided photo, isolate the PAINTING CANVAS only (exclude frame, wall, mat, glass, shadows).
- Rectify the canvas to a fronto-parallel view (remove perspective distortion ONLY).
- Crop tightly to the canvas bounds.
- Place the rectified canvas on a solid background of the exact color {$bgHex}.

CRITICAL COLOR PRESERVATION RULES:
- You are ONLY allowed to perform geometric transformations (perspective correction, rotation, scaling, cropping).
- You are FORBIDDEN from performing ANY color operations: NO color correction, NO white balance, NO saturation changes, NO brightness adjustment, NO contrast adjustment, NO color enhancement, NO color grading, NO tone mapping.
- Each pixel's RGB values must remain EXACTLY as they appear in the source image (only repositioned due to geometric transformation).
- The painting's color palette, saturation, brightness, and contrast must be IDENTICAL to the source.
- Do NOT "improve" or "enhance" the image - only correct geometry.
- Think of this as a mathematical transformation of pixel positions, not an artistic enhancement.

Technical requirements:
- Maintain the canvas aspect ratio.
- If edges are missing, extrapolate minimally to a clean rectangle using the exact edge colors from the source.
- Output a single new image of the rectified canvas on {$bgHex}. No borders, no watermarks, no text.
PROMPT;

$payload = [
  'input' => [
    'prompt'        => $prompt,
    'image_input'   => ["data:$mime;base64,$imgB64"],
    'output_format' => 'png'
  ]
];

// ---- Replicate Call ----
try {
    $resp = replicate_call_version($TOKEN, $VERSION, $payload);
} catch (RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'replicate_failed', 'detail' => $e->getMessage()]);
    exit;
}

// ---- Output robust extrahieren ----
$out = $resp['output'] ?? null;
$imgBytes = fetch_image_bytes($out);
if ($imgBytes === null) {
    if (is_array($out) && isset($out['images'][0])) {
        $imgBytes = fetch_image_bytes($out['images'][0]);
    }
}
if ($imgBytes === null) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'unexpected_output_format', 'sample' => is_scalar($out) ? $out : json_encode($out)]);
    exit;
}

// ---- Save as temporary PNG first, then convert to JPG ----
$imagesDir = __DIR__ . '/images/';
$name = pathinfo($abs, PATHINFO_FILENAME);
$name = str_replace('_original', '', $name);

// Save as temporary PNG first
$tempColorPng = $imagesDir . $name . '_color_temp_' . uniqid() . '.png';
file_put_contents($tempColorPng, $imgBytes);

// COLOR PRESERVATION: Match colors from original image to preserve exact colors
// Load original image and AI-processed image
if (extension_loaded('imagick')) {
  try {
    $originalImg = new Imagick($abs);
    $processedImg = new Imagick($tempColorPng);
    
    $originalImg->setImageColorspace(Imagick::COLORSPACE_RGB);
    $processedImg->setImageColorspace(Imagick::COLORSPACE_RGB);
    
    // Calculate mean and std dev for each channel from actual pixels
    // Original image statistics
    $origSumR = 0; $origSumG = 0; $origSumB = 0;
    $origSumSqR = 0; $origSumSqG = 0; $origSumSqB = 0;
    $origPixelCount = 0;
    
    $origIt = $originalImg->getPixelIterator();
    foreach ($origIt as $row) {
      foreach ($row as $pixel) {
        $color = $pixel->getColor();
        $origSumR += $color['r'];
        $origSumG += $color['g'];
        $origSumB += $color['b'];
        $origSumSqR += $color['r'] * $color['r'];
        $origSumSqG += $color['g'] * $color['g'];
        $origSumSqB += $color['b'] * $color['b'];
        $origPixelCount++;
      }
    }
    
    if ($origPixelCount > 0) {
      $origMeanR = $origSumR / $origPixelCount;
      $origMeanG = $origSumG / $origPixelCount;
      $origMeanB = $origSumB / $origPixelCount;
      $origStdR = sqrt(($origSumSqR / $origPixelCount) - ($origMeanR * $origMeanR));
      $origStdG = sqrt(($origSumSqG / $origPixelCount) - ($origMeanG * $origMeanG));
      $origStdB = sqrt(($origSumSqB / $origPixelCount) - ($origMeanB * $origMeanB));
      
      // Processed image statistics (only for non-background pixels)
      $procSumR = 0; $procSumG = 0; $procSumB = 0;
      $procSumSqR = 0; $procSumSqG = 0; $procSumSqB = 0;
      $procPixelCount = 0;
      
      $procIt = $processedImg->getPixelIterator();
      foreach ($procIt as $row) {
        foreach ($row as $pixel) {
          $color = $pixel->getColor();
          // Skip background pixels
          $dr = abs($color['r'] - $bgRGB[0]);
          $dg = abs($color['g'] - $bgRGB[1]);
          $db = abs($color['b'] - $bgRGB[2]);
          if ($dr > 15 || $dg > 15 || $db > 15) {
            $procSumR += $color['r'];
            $procSumG += $color['g'];
            $procSumB += $color['b'];
            $procSumSqR += $color['r'] * $color['r'];
            $procSumSqG += $color['g'] * $color['g'];
            $procSumSqB += $color['b'] * $color['b'];
            $procPixelCount++;
          }
        }
      }
      
      if ($procPixelCount > 0) {
        $procMeanR = $procSumR / $procPixelCount;
        $procMeanG = $procSumG / $procPixelCount;
        $procMeanB = $procSumB / $procPixelCount;
        $procStdR = sqrt(($procSumSqR / $procPixelCount) - ($procMeanR * $procMeanR));
        $procStdG = sqrt(($procSumSqG / $procPixelCount) - ($procMeanG * $procMeanG));
        $procStdB = sqrt(($procSumSqB / $procPixelCount) - ($procMeanB * $procMeanB));
        
        // Apply color matching: transform processed image to match original statistics
        // Formula: new_value = (old_value - proc_mean) * (orig_std / proc_std) + orig_mean
        if ($procStdR > 0.1 && $procStdG > 0.1 && $procStdB > 0.1 && 
            $origStdR > 0.1 && $origStdG > 0.1 && $origStdB > 0.1) {
          
          $procIt = $processedImg->getPixelIterator();
          foreach ($procIt as $row) {
            foreach ($row as $pixel) {
              $color = $pixel->getColor();
              
              // Check if background pixel
              $dr = abs($color['r'] - $bgRGB[0]);
              $dg = abs($color['g'] - $bgRGB[1]);
              $db = abs($color['b'] - $bgRGB[2]);
              $isBackground = ($dr <= 15 && $dg <= 15 && $db <= 15);
              
              if (!$isBackground) {
                // Match R channel
                $newR = ($color['r'] - $procMeanR) * ($origStdR / $procStdR) + $origMeanR;
                $newR = max(0, min(255, round($newR)));
                
                // Match G channel
                $newG = ($color['g'] - $procMeanG) * ($origStdG / $procStdG) + $origMeanG;
                $newG = max(0, min(255, round($newG)));
                
                // Match B channel
                $newB = ($color['b'] - $procMeanB) * ($origStdB / $procStdB) + $origMeanB;
                $newB = max(0, min(255, round($newB)));
                
                $pixel->setColor("rgb($newR,$newG,$newB)");
              }
            }
            $procIt->syncIterator();
          }
          $processedImg->writeImage($tempColorPng);
        }
      }
    }
    
    $originalImg->destroy();
    $processedImg->destroy();
  } catch (Exception $e) {
    // If color matching fails, continue with original processed image
    error_log("Color matching failed: " . $e->getMessage());
  }
}

// Convert to JPG
$colorPath = $imagesDir . $name . '_color.jpg';
if (!convert_to_jpg($tempColorPng, $colorPath)) {
  @unlink($tempColorPng);
  http_response_code(500);
  echo json_encode(['ok' => false, 'error'=>'failed_to_convert_color_to_jpg']); exit;
}
@unlink($tempColorPng);

// ---- Bounding-Box (Nicht-Hintergrund-Pixel) → *_final.jpg ----
if (!extension_loaded('imagick')) {
  $colorPathRel = 'images/' . basename($colorPath);
  echo json_encode(['ok' => true, 'warning'=>'Imagick not available','black'=>$colorPathRel]); exit;
}

$im = new Imagick($colorPath);
$im->setImageColorspace(Imagick::COLORSPACE_RGB);
$it = $im->getPixelIterator();

// Toleranz gegen die gewählte Hintergrundfarbe
list($BgR,$BgG,$BgB) = $bgRGB;
$TH = 15; // max. Abweichung pro Kanal, zählt als Hintergrund

$minX=PHP_INT_MAX; $minY=PHP_INT_MAX; $maxX=-1; $maxY=-1; $y=0;
foreach ($it as $row) { $x=0; foreach ($row as $px) {
  $c = $px->getColor(); // ['r','g','b']
  $dr = abs($c['r'] - $BgR);
  $dg = abs($c['g'] - $BgG);
  $db = abs($c['b'] - $BgB);
  $isBackground = ($dr <= $TH && $dg <= $TH && $db <= $TH);
  if (!$isBackground) {
    if ($x<$minX) $minX=$x; if ($y<$minY) $minY=$y;
    if ($x>$maxX) $maxX=$x; if ($y>$maxY) $maxY=$y;
  }
  $x++;
} $y++; }

if ($maxX<0 || $maxY<0) { $im->destroy(); echo json_encode(['ok' => false, 'error'=>'no_foreground_detected','black'=>$colorPath]); exit; }

$minX = max(0,$minX-1); $minY = max(0,$minY-1);
$cropW = min($im->getImageWidth()-$minX,  $maxX-$minX+2);
$cropH = min($im->getImageHeight()-$minY, $maxY-$minY+2);

// Save as temporary PNG first, then convert to JPG
$tempFinalPng = $imagesDir . $name . '_final_temp_' . uniqid() . '.png';
$crop = clone $im; $crop->cropImage($cropW,$cropH,$minX,$minY);
$crop->setImageFormat('png'); 
$crop->writeImage($tempFinalPng); 
$crop->destroy(); 
$im->destroy();

// Convert final image to JPG
$finalPath = $imagesDir . $name . '_final.jpg';
if (!convert_to_jpg($tempFinalPng, $finalPath)) {
  @unlink($tempFinalPng);
  http_response_code(500);
  echo json_encode(['ok' => false, 'error'=>'failed_to_convert_final_to_jpg']); exit;
}
@unlink($tempFinalPng);

// Generate thumbnail for _final image
$thumbPath = generate_thumbnail_path($finalPath);
generate_thumbnail($finalPath, $thumbPath, 512, 1024);

// Set variant regeneration flag (will be processed by background task processor)
update_json_file($metaPath, ['variant_regeneration_status' => 'needed'], false);

// Return relative paths from admin directory
$colorPathRel = 'images/' . basename($colorPath);
$finalPathRel = 'images/' . basename($finalPath);

echo json_encode([
  'ok' => true,
  // Beibehaltung des Keys für Abwärtskompatibilität
  'black_image' => $colorPathRel,
  'final_image' => $finalPathRel,
  'bbox' => [
    'tl' => [$minX,$minY],
    'tr' => [$minX+$cropW-1,$minY],
    'br' => [$minX+$cropW-1,$minY+$cropH-1],
    'bl' => [$minX,$minY+$cropH-1]
  ],
  'bg' => $bgHex,
  'source' => $rel
]);
