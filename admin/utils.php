<?php
declare(strict_types=1);

function load_replicate_token(): string {
    $envPath = dirname(__DIR__).'/.env';
    if (!file_exists($envPath)) {
        throw new RuntimeException('.env not found at project root');
    }
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (strpos(ltrim($line), '#') === 0) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // Strip quotes
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key === 'REPLICATE_API_TOKEN' && $val !== '') {
            return $val;
        }
    }
    throw new RuntimeException('REPLICATE_API_TOKEN not found in .env');
}

function http_json_post(string $url, array $headers, array $payload): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP POST error: '.$err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('HTTP '.$code.' response: '.($res ?: '')); 
    }
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON response');
    }
    return $data;
}

function replicate_upload_file(string $token, string $filePath): string {
    if (!file_exists($filePath)) throw new InvalidArgumentException('File not found: '.$filePath);
    $ch = curl_init('https://api.replicate.com/v1/files');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers = [ 'Authorization: Bearer '.$token ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $cfile = new CURLFile($filePath);
    // Replicate expects multipart field named 'content'
    $post = [ 'content' => $cfile ];
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Upload error: '.$err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Upload HTTP '.$code.': '.($res ?: ''));
    }
    // Prefer 'url', fallback to nested fields
    if (isset($data['url']) && is_string($data['url'])) return $data['url'];
    if (isset($data['urls']['get']) && is_string($data['urls']['get'])) return $data['urls']['get'];
    if (isset($data['id']) && is_string($data['id'])) return 'https://api.replicate.com/v1/files/'.$data['id'];
    throw new RuntimeException('Unexpected upload response: '.json_encode($data));
}

function replicate_expand_square(string $token, string $imageUrl): string {
    $headers = [
        'Authorization: Bearer '.$token,
        'Prefer: wait',
    ];
    $payload = [
        'input' => [
            'sync' => true,
            'image' => $imageUrl,
            'aspect_ratio' => '1:1',
            'preserve_alpha' => false,
            'content_moderation' => false,
        ],
    ];
    $data = http_json_post('https://api.replicate.com/v1/models/bria/expand-image/predictions', $headers, $payload);
    // Output can be a string URL or array of URLs
    if (isset($data['output']) && is_string($data['output'])) return $data['output'];
    if (isset($data['output'][0]) && is_string($data['output'][0])) return $data['output'][0];
    // Some responses nest inside 'output' -> 'image'
    if (isset($data['output']['image']) && is_string($data['output']['image'])) return $data['output']['image'];
    throw new RuntimeException('Unexpected prediction response: '.json_encode($data));
}

function image_create_from_any(string $path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => imagecreatefromjpeg($path),
        'png' => imagecreatefrompng($path),
        'webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : null,
        default => null,
    };
}

function image_save_as(string $path, $im, ?int $quality = null): void {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $jpegQuality = $quality ?? 90;
            imagejpeg($im, $path, $jpegQuality);
            break;
        case 'png':
            // PNG compression level: 0-9, where 0 is no compression and 9 is maximum
            // Convert quality (0-100) to PNG compression (0-9)
            // Higher quality = lower compression
            if ($quality !== null) {
                $pngCompression = (int) round(9 - ($quality / 100) * 9);
                $pngCompression = max(0, min(9, $pngCompression));
            } else {
                $pngCompression = 6;
            }
            imagepng($im, $path, $pngCompression);
            break;
        case 'webp':
            if (!function_exists('imagewebp')) {
                // Fallback to JPEG
                $alt = preg_replace('/\.webp$/i', '.jpg', $path);
                $jpegQuality = $quality ?? 90;
                imagejpeg($im, $alt, $jpegQuality);
            } else {
                $webpQuality = $quality ?? 85;
                imagewebp($im, $path, $webpQuality);
            }
            break;
        default:
            $jpegQuality = $quality ?? 90;
            imagejpeg($im, $path, $jpegQuality);
    }
}

function ensure_1000_square(string $path): void {
    $src = image_create_from_any($path);
    if (!$src) return; // silently skip if unsupported
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW === 1000 && $srcH === 1000) { imagedestroy($src); return; }
    $dst = imagecreatetruecolor(1000, 1000);
    // Fill white background
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    // Scale to fit, keep aspect
    $scale = min(1000 / $srcW, 1000 / $srcH);
    $newW = (int) floor($srcW * $scale);
    $newH = (int) floor($srcH * $scale);
    $dstX = (int) floor((1000 - $newW) / 2);
    $dstY = (int) floor((1000 - $newH) / 2);
    imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
    image_save_as($path, $dst);
    imagedestroy($src);
    imagedestroy($dst);
}

function ensure_max_1000(string $path): void {
    $src = image_create_from_any($path);
    if (!$src) return;
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $max = max($srcW, $srcH);
    if ($max <= 1000) { imagedestroy($src); return; }
    $scale = 1000 / $max;
    $newW = (int) floor($srcW * $scale);
    $newH = (int) floor($srcH * $scale);
    $dst = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
    image_save_as($path, $dst);
    imagedestroy($src);
    imagedestroy($dst);
}

function convert_to_jpg(string $sourcePath, string $targetPath): bool {
    $imageInfo = getimagesize($sourcePath);
    if ($imageInfo === false) return false;
    
    // Read EXIF orientation if available
    $exif = function_exists('exif_read_data') ? @exif_read_data($sourcePath) : false;
    $orientation = $exif && isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
    $needsRotation = ($orientation > 1);
    
    // If source is already JPEG and no rotation needed, just copy it (preserve original compression)
    if ($imageInfo[2] === IMAGETYPE_JPEG && !$needsRotation) {
        return copy($sourcePath, $targetPath);
    }
    
    // Try Imagick first if available
    if (extension_loaded('imagick')) {
        try {
            $imagick = new Imagick($sourcePath);
            
            // Auto-orient image based on EXIF orientation
            if ($needsRotation) {
                $imagick->autoOrient();
            }
            
            $imagick->setImageFormat('jpeg');
            // Use adaptive quality: start with 85, but optimize for file size
            $imagick->setImageCompressionQuality(85);
            $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
            $imagick->stripImage(); // Remove EXIF data to reduce file size
            
            // Handle transparency: flatten to white background
            if ($imagick->getImageAlphaChannel()) {
                $white = new ImagickPixel('white');
                $imagick->setImageBackgroundColor($white);
                $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $imagick = $imagick->mergeImageLayers(Imagick::LAYER_METHOD_FLATTEN);
            }
            
            $result = $imagick->writeImage($targetPath);
            $imagick->clear();
            $imagick->destroy();
            
            // If converted file is larger than original, try lower quality
            if ($result && is_file($targetPath) && is_file($sourcePath)) {
                $originalSize = filesize($sourcePath);
                $convertedSize = filesize($targetPath);
                if ($convertedSize > $originalSize && $imageInfo[2] === IMAGETYPE_JPEG) {
                    // Try with lower quality to match or beat original size
                    $imagick = new Imagick($sourcePath);
                    if ($needsRotation) {
                        $imagick->autoOrient();
                    }
                    $imagick->setImageFormat('jpeg');
                    $imagick->setImageCompressionQuality(80);
                    $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
                    $imagick->stripImage();
                    if ($imagick->getImageAlphaChannel()) {
                        $white = new ImagickPixel('white');
                        $imagick->setImageBackgroundColor($white);
                        $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                        $imagick = $imagick->mergeImageLayers(Imagick::LAYER_METHOD_FLATTEN);
                    }
                    $imagick->writeImage($targetPath);
                    $imagick->clear();
                    $imagick->destroy();
                }
            }
            
            return $result;
        } catch (Exception $e) {
            // Fall through to GD fallback
        }
    }
    
    // Fallback to GD
    $sourceImage = null;
    switch ($imageInfo[2]) {
        case IMAGETYPE_JPEG:
            $sourceImage = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_WEBP:
            $sourceImage = function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : null;
            break;
    }
    
    if ($sourceImage === null) return false;
    
    // Handle PNG transparency with white background
    if ($imageInfo[2] === IMAGETYPE_PNG) {
        $jpg = imagecreatetruecolor(imagesx($sourceImage), imagesy($sourceImage));
        $white = imagecolorallocate($jpg, 255, 255, 255);
        imagefill($jpg, 0, 0, $white);
        imagecopy($jpg, $sourceImage, 0, 0, 0, 0, imagesx($sourceImage), imagesy($sourceImage));
        imagedestroy($sourceImage);
        $sourceImage = $jpg;
    }
    
    // Apply EXIF orientation rotation/flip
    if ($needsRotation) {
        $sourceImage = apply_exif_orientation($sourceImage, $orientation);
    }
    
    // Use quality 85 for better compression (lower file size)
    // If original was JPEG and we're recompressing, try to match original size
    $quality = 85;
    if ($imageInfo[2] === IMAGETYPE_JPEG && !$needsRotation) {
        // Already handled above (copy), but if we get here, use original quality estimate
        $quality = 85;
    }
    
    $result = imagejpeg($sourceImage, $targetPath, $quality);
    
    // If converted file is larger than original JPEG, try lower quality
    if ($result && is_file($targetPath) && is_file($sourcePath) && $imageInfo[2] === IMAGETYPE_JPEG) {
        $originalSize = filesize($sourcePath);
        $convertedSize = filesize($targetPath);
        if ($convertedSize > $originalSize) {
            // Try with progressively lower quality
            for ($q = 80; $q >= 70; $q -= 5) {
                imagejpeg($sourceImage, $targetPath, $q);
                if (filesize($targetPath) <= $originalSize) {
                    break;
                }
            }
        }
    }
    
    imagedestroy($sourceImage);
    return $result;
}

/**
 * Apply EXIF orientation to image resource
 */
function apply_exif_orientation($image, int $orientation) {
    if ($orientation === 1 || $orientation === 0) {
        return $image; // No rotation needed
    }
    
    $width = imagesx($image);
    $height = imagesy($image);
    
    switch ($orientation) {
        case 2: // Flip horizontal
            $flipped = imagecreatetruecolor($width, $height);
            imagecopyresampled($flipped, $image, 0, 0, $width - 1, 0, $width, $height, -$width, $height);
            imagedestroy($image);
            return $flipped;
            
        case 3: // Rotate 180
            $rotated = imagecreatetruecolor($width, $height);
            imagecopyresampled($rotated, $image, 0, 0, $width - 1, $height - 1, $width, $height, -$width, -$height);
            imagedestroy($image);
            return $rotated;
            
        case 4: // Flip vertical
            $flipped = imagecreatetruecolor($width, $height);
            imagecopyresampled($flipped, $image, 0, 0, 0, $height - 1, $width, $height, $width, -$height);
            imagedestroy($image);
            return $flipped;
            
        case 5: // Rotate 90 clockwise and flip horizontal
            $rotated = imagecreatetruecolor($height, $width);
            imagecopyresampled($rotated, $image, 0, 0, 0, $width - 1, $height, $width, $height, -$width);
            imagedestroy($image);
            $width = imagesx($rotated);
            $height = imagesy($rotated);
            $flipped = imagecreatetruecolor($width, $height);
            imagecopyresampled($flipped, $rotated, 0, 0, $width - 1, 0, $width, $height, -$width, $height);
            imagedestroy($rotated);
            return $flipped;
            
        case 6: // Rotate 90 clockwise
            // Use imagerotate with -90 degrees, then adjust
            $rotated = imagerotate($image, -90, 0);
            imagedestroy($image);
            return $rotated;
            
        case 7: // Rotate 90 clockwise and flip vertical
            $rotated = imagerotate($image, -90, 0);
            imagedestroy($image);
            $width = imagesx($rotated);
            $height = imagesy($rotated);
            $flipped = imagecreatetruecolor($width, $height);
            imagecopyresampled($flipped, $rotated, 0, 0, 0, $height - 1, $width, $height, $width, -$height);
            imagedestroy($rotated);
            return $flipped;
            
        case 8: // Rotate 90 counter-clockwise
            // Use imagerotate with 90 degrees
            $rotated = imagerotate($image, 90, 0);
            imagedestroy($image);
            return $rotated;
            
        default:
            return $image;
    }
}

/**
 * Unified function to update live version in gallery
 * Copies final image, JSON metadata, and all variants
 */
function update_gallery_entry(string $base, array $meta, string $imagesDir, string $galleryDir): array {
    // Find the _final variant
    $finalImage = null;
    $jsonFile = null;
    $variantFiles = [];
    
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileStem = pathinfo($file, PATHINFO_FILENAME);
        
        // Find final image
        if (strpos($fileStem, $base.'_final') === 0) {
            $finalImage = $file;
        }
        
        // Find JSON file
        if (strpos($file, $base) === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'json') {
            $jsonFile = $file;
        }
        
        // Find variant files (those with _variant_ in the name)
        if (strpos($fileStem, $base.'_variant_') === 0) {
            $variantFiles[] = $file;
        }
    }
    
    if (!$finalImage || !is_file($imagesDir.$finalImage)) {
        return ['ok' => false, 'error' => 'Final image not found for base: '.$base];
    }
    
    if (!$jsonFile || !is_file($imagesDir.$jsonFile)) {
        return ['ok' => false, 'error' => 'JSON metadata not found'];
    }
    
    $title = trim($meta['title'] ?? '');
    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required in metadata'];
    }
    
    // Convert title to filename
    $filename = mb_strtolower($title);
    $filename = preg_replace('/[^a-z0-9]+/u', '_', $filename);
    $filename = trim($filename, '_');
    
    if ($filename === '') {
        return ['ok' => false, 'error' => 'Invalid title for filename'];
    }
    
    // Ensure gallery directory exists
    if (!is_dir($galleryDir)) {
        if (!mkdir($galleryDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Failed to create gallery directory'];
        }
    }
    
    // Check if entry already exists and delete old files
    $oldGalleryFiles = [];
    if (is_dir($galleryDir)) {
        $galleryFiles = scandir($galleryDir) ?: [];
        foreach ($galleryFiles as $gFile) {
            if ($gFile === '.' || $gFile === '..') continue;
            $gJsonPath = $galleryDir.$gFile;
            if (pathinfo($gFile, PATHINFO_EXTENSION) === 'json') {
                $gJsonContent = file_get_contents($gJsonPath);
                $gMeta = json_decode($gJsonContent, true);
                if (is_array($gMeta) && isset($gMeta['original_filename']) && $gMeta['original_filename'] === $base) {
                    // Found matching entry, mark all related files for deletion
                    $oldBase = pathinfo($gFile, PATHINFO_FILENAME);
                    foreach ($galleryFiles as $gFile2) {
                        if ($gFile2 === '.' || $gFile2 === '..') continue;
                        $fileStem = pathinfo($gFile2, PATHINFO_FILENAME);
                        if (strpos($fileStem, $oldBase) === 0) {
                            $oldGalleryFiles[] = $gFile2;
                        }
                    }
                    break;
                }
            }
        }
    }
    
    // Delete old files
    foreach ($oldGalleryFiles as $oldFile) {
        $oldPath = $galleryDir.$oldFile;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
    
    // Copy final image and resize to gallery max dimensions (1536x1536)
    $finalExt = pathinfo($finalImage, PATHINFO_EXTENSION);
    $destImage = $galleryDir.$filename.'.'.$finalExt;
    
    // Copy first
    if (!copy($imagesDir.$finalImage, $destImage)) {
        return ['ok' => false, 'error' => 'Failed to copy image'];
    }
    
    // Resize to gallery max dimensions (1536x1536)
    resize_image_max($destImage, 1536, 1536, false);
    
    // Add original_filename to metadata
    $meta['original_filename'] = $base;
    
    // Copy JSON
    $destJson = $galleryDir.$filename.'.json';
    $updatedJsonContent = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!file_put_contents($destJson, $updatedJsonContent)) {
        @unlink($destImage);
        return ['ok' => false, 'error' => 'Failed to copy JSON'];
    }
    
    // Copy all variants
    $copiedVariants = [];
    foreach ($variantFiles as $variantFile) {
        // Extract variant name: base_variant_name.jpg -> variant_name
        $variantStem = pathinfo($variantFile, PATHINFO_FILENAME);
        $variantName = substr($variantStem, strlen($base) + 9); // +9 for '_variant_'
        
        $variantExt = pathinfo($variantFile, PATHINFO_EXTENSION);
        $destVariant = $galleryDir.$filename.'_variant_'.$variantName.'.'.$variantExt;
        
        if (copy($imagesDir.$variantFile, $destVariant)) {
            // Resize variant to gallery max dimensions (1536x1536)
            resize_image_max($destVariant, 1536, 1536, false);
            $copiedVariants[] = basename($destVariant);
        }
    }
    
    return [
        'ok' => true,
        'image' => $filename.'.'.$finalExt,
        'json' => $filename.'.json',
        'variants' => $copiedVariants
    ];
}

/**
 * Extract base name from image filename (removes variant suffix)
 */
function extract_base_name(string $image): string {
    $stem = pathinfo($image, PATHINFO_FILENAME);
    return preg_replace('/_[^_]+$/', '', $stem);
}

/**
 * Find JSON metadata file for a given base name
 */
function find_json_file(string $base, string $imagesDir): ?string {
    $files = scandir($imagesDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (strpos($file, $base) === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'json') {
            return $file;
        }
    }
    return null;
}

/**
 * Find gallery entry by original_filename
 */
function find_gallery_entry(string $originalFilename, string $galleryDir): ?string {
    if (!is_dir($galleryDir)) {
        return null;
    }
    $galleryFiles = scandir($galleryDir) ?: [];
    foreach ($galleryFiles as $gFile) {
        if ($gFile === '.' || $gFile === '..') continue;
        if (pathinfo($gFile, PATHINFO_EXTENSION) !== 'json') continue;
        $gJsonPath = $galleryDir.$gFile;
        $gJsonContent = file_get_contents($gJsonPath);
        $gMeta = json_decode($gJsonContent, true);
        if (is_array($gMeta) && isset($gMeta['original_filename']) && $gMeta['original_filename'] === $originalFilename) {
            return pathinfo($gFile, PATHINFO_FILENAME);
        }
    }
    return null;
}

/**
 * Fetch image bytes from various formats (URL, data URI, array)
 */
function fetch_image_bytes($thing): ?string {
    if (is_string($thing)) {
        if (preg_match('#^https?://#i', $thing)) {
            $ch = curl_init($thing);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 120
            ]);
            $bin = curl_exec($ch);
            curl_close($ch);
            return $bin !== false ? $bin : null;
        }
        if (str_starts_with($thing, 'data:image/')) {
            $comma = strpos($thing, ',');
            if ($comma === false) return null;
            return base64_decode(substr($thing, $comma + 1));
        }
    }
    if (is_array($thing)) {
        if (isset($thing['image'])) return fetch_image_bytes($thing['image']);
        if (isset($thing[0])) return fetch_image_bytes($thing[0]);
    }
    return null;
}

/**
 * Call Replicate API with version
 */
function replicate_call_version(string $token, string $version, array $payload): array {
    $payload['version'] = $version;
    $ch = curl_init("https://api.replicate.com/v1/predictions");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Token $token", "Content-Type: application/json", "Prefer: wait"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 300
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($res === false || $http >= 400) {
        throw new RuntimeException('Replicate API error: ' . ($err ?: $res));
    }
    
    $resp = json_decode($res, true);
    if (!is_array($resp)) {
        throw new RuntimeException('Invalid JSON response');
    }
    
    return $resp;
}

/**
 * Call Replicate API with model name
 */
function replicate_call_model(string $token, string $model, array $payload): array {
    $ch = curl_init("https://api.replicate.com/v1/models/$model/predictions");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Token $token", "Content-Type: application/json", "Prefer: wait"],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 300
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($res === false || $http >= 400) {
        throw new RuntimeException('Replicate API error: ' . ($err ?: $res));
    }
    
    $resp = json_decode($res, true);
    if (!is_array($resp)) {
        throw new RuntimeException('Invalid JSON response');
    }
    
    return $resp;
}

/**
 * Resize image to maximum dimensions, maintaining aspect ratio
 * Overwrites original if resizing is needed
 * If force is true, always resizes even if within limits (recompresses with current quality settings)
 */
function resize_image_max(string $path, int $maxWidth, int $maxHeight, bool $force = false): void {
    $src = image_create_from_any($path);
    if (!$src) return;
    
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    
    // Check if resizing is needed
    if (!$force && $srcW <= $maxWidth && $srcH <= $maxHeight) {
        imagedestroy($src);
        return;
    }
    
    // Calculate new dimensions maintaining aspect ratio
    // If force and within limits, keep original dimensions but recompress
    if ($force && $srcW <= $maxWidth && $srcH <= $maxHeight) {
        $newW = $srcW;
        $newH = $srcH;
    } else {
        $scale = min($maxWidth / $srcW, $maxHeight / $srcH);
        $newW = (int) floor($srcW * $scale);
        $newH = (int) floor($srcH * $scale);
    }
    
    // Create resized image
    $dst = imagecreatetruecolor($newW, $newH);
    
    // Preserve transparency for PNG
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefill($dst, 0, 0, $transparent);
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
    
    // Save over original
    image_save_as($path, $dst);
    
    imagedestroy($src);
    imagedestroy($dst);
}

/**
 * Generate thumbnail from source image
 * If the thumbnail file size is larger than the original, just copy the original instead
 */
function generate_thumbnail(string $sourcePath, string $thumbPath, int $maxWidth, int $maxHeight): void {
    $src = image_create_from_any($sourcePath);
    if (!$src) return;
    
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    
    // Get original file size
    $originalSize = @filesize($sourcePath);
    if ($originalSize === false) {
        $originalSize = 0;
    }
    
    // Calculate thumbnail dimensions maintaining aspect ratio
    $scale = min($maxWidth / $srcW, $maxHeight / $srcH);
    $newW = (int) floor($srcW * $scale);
    $newH = (int) floor($srcH * $scale);
    
    // Create thumbnail
    $dst = imagecreatetruecolor($newW, $newH);
    
    // Preserve transparency for PNG
    $ext = strtolower(pathinfo($thumbPath, PATHINFO_EXTENSION));
    if ($ext === 'png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefill($dst, 0, 0, $transparent);
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
    
    // Save thumbnail with higher quality (95 for JPEG, 90 for WebP)
    $ext = strtolower(pathinfo($thumbPath, PATHINFO_EXTENSION));
    $quality = ($ext === 'webp') ? 80 : 90;
    image_save_as($thumbPath, $dst, $quality);
    
    imagedestroy($src);
    imagedestroy($dst);
    
    // Check if thumbnail file size is larger than original
    $thumbSize = @filesize($thumbPath);
    if ($thumbSize !== false && $originalSize > 0 && $thumbSize >= $originalSize) {
        // Thumbnail is larger or same size, use original instead
        @unlink($thumbPath);
        copy($sourcePath, $thumbPath);
    }
}

/**
 * Generate thumbnail path from source path
 */
function generate_thumbnail_path(string $sourcePath): string {
    $pathInfo = pathinfo($sourcePath);
    $dir = $pathInfo['dirname'];
    $filename = $pathInfo['filename'];
    $ext = $pathInfo['extension'];
    return $dir.'/'.$filename.'_thumb.'.$ext;
}

/**
 * Make an asynchronous (fire-and-forget) HTTP POST request
 * Returns immediately without waiting for response
 */
function async_http_post(string $url, array $data = []): void {
    // Build query string
    $postData = http_build_query($data);
    
    // Get the base URL for the current request
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $scheme . '://' . $host;
    
    // If URL is relative, make it absolute
    if (strpos($url, 'http') !== 0) {
        // Ensure URL starts with / for proper path resolution
        $url = $baseUrl . '/' . ltrim($url, '/');
    }
    
    // Try PHP curl extension first (most reliable)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1, // Very short timeout for async behavior
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_NOSIGNAL => 1, // Allow timeout to work properly
        ]);
        // Execute without waiting for response (fire and forget)
        curl_exec($ch);
        curl_close($ch);
        return;
    }
    
    // Fallback: Use exec to spawn background curl process (non-blocking)
    if (function_exists('exec')) {
        $cmd = sprintf(
            'curl -X POST -d %s %s > /dev/null 2>&1 &',
            escapeshellarg($postData),
            escapeshellarg($url)
        );
        @exec($cmd);
        return;
    }
    
    // Last resort: use file_get_contents with very short timeout
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $postData,
            'timeout' => 1, // Increased timeout slightly for reliability
            'ignore_errors' => true
        ]
    ]);
    @file_get_contents($url, false, $context);
}


