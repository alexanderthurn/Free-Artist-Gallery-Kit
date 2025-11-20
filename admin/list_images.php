<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = __DIR__.'/images';
$result = [ 'groups' => [] ];
$VARIANT_ORDER = ['original', 'color', 'final'];

if (!is_dir($dir)) {
    echo json_encode($result);
    exit;
}

function split_last_underscore(string $stem): array {
    // Check for _variant_ pattern first
    $variantPos = strpos($stem, '_variant_');
    if ($variantPos !== false) {
        $base = substr($stem, 0, $variantPos);
        $variant = 'variant_' . substr($stem, $variantPos + 9); // 9 = length of '_variant_'
        return [$base, $variant];
    }
    
    // Otherwise, split on last underscore
    $pos = strrpos($stem, '_');
    if ($pos === false) return [$stem, 'unknown'];
    $base = substr($stem, 0, $pos);
    $variant = strtolower(substr($stem, $pos + 1));
    return [$base, $variant];
}

$allowed = ['jpg','jpeg'];
$files = scandir($dir) ?: [];
$groups = [];

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) continue;
    
    // Skip thumbnails - they should not appear in the main list
    if (strpos($file, '_thumb.') !== false) continue;
    
    $path = $dir.'/'.$file;
    $stem = pathinfo($file, PATHINFO_FILENAME);
    $extOnly = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    [$base, $variant] = split_last_underscore($stem);
    $key = $base; // group by base only, ignore extension
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'base' => $base,
            'ext' => $extOnly, // will reflect latest-seen variant's ext
            'variants' => [], // will be a map variant => item (latest by mtime)
            'meta' => null,
            'mtime' => 0,
        ];
    }
    $mtime = @filemtime($path) ?: 0;
    
    // Keep only the latest file per variant
    $item = [
        'name' => $file,
        'variant' => $variant,
        'url' => 'images/'.rawurlencode($file),
        'mtime' => $mtime,
    ];
    if (!isset($groups[$key]['variants'][$variant]) || $groups[$key]['variants'][$variant]['mtime'] < $mtime) {
        $groups[$key]['variants'][$variant] = $item;
    }
    if ($mtime > $groups[$key]['mtime']) {
        $groups[$key]['mtime'] = $mtime;
        // Track ext of the newest file overall for reference
        $groups[$key]['ext'] = $extOnly;
    }
}

// Check gallery directory for existing entries
$galleryDir = dirname(__DIR__).'/img/gallery/';
$galleryEntries = [];
if (is_dir($galleryDir)) {
    $galleryFiles = scandir($galleryDir) ?: [];
    foreach ($galleryFiles as $gFile) {
        if ($gFile === '.' || $gFile === '..') continue;
        if (pathinfo($gFile, PATHINFO_EXTENSION) !== 'json') continue;
        $gJsonPath = $galleryDir.$gFile;
        $gJsonContent = file_get_contents($gJsonPath);
        $gMeta = json_decode($gJsonContent, true);
        if (is_array($gMeta) && isset($gMeta['original_filename'])) {
            $galleryEntries[$gMeta['original_filename']] = pathinfo($gFile, PATHINFO_FILENAME);
        }
    }
}

// Attach meta per group and finalize variant arrays ordered by VARIANT_ORDER
foreach ($groups as $key => &$g) {
    // Convert to indexed array
    $variants = array_values($g['variants']);
    
    // Add variants from active_variants that don't have files yet (pending generation)
    $metaFile = null;
    $original = null;
    foreach ($variants as $v) {
        if ($v['variant'] === 'original') { 
            $original = $v; 
            $metaFile = $dir.'/'.$v['name'].'.json';
            break; 
        }
    }
    if (!$metaFile && !empty($variants)) {
        $metaFile = $dir.'/'.$variants[0]['name'].'.json';
    }
    
    if ($metaFile && is_file($metaFile)) {
        $raw = file_get_contents($metaFile);
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded) && isset($decoded['active_variants']) && is_array($decoded['active_variants'])) {
            foreach ($decoded['active_variants'] as $variantName) {
                // Check if variant file already exists
                $variantFile = $key . '_variant_' . $variantName . '.jpg';
                $variantPath = $dir . '/' . $variantFile;
                $variantExists = is_file($variantPath);
                
                if (!$variantExists) {
                    // Add as pending variant (no file yet)
                    $variants[] = [
                        'name' => $variantFile,
                        'variant' => 'variant_' . $variantName,
                        'url' => 'images/'.rawurlencode($variantFile),
                        'mtime' => 0,
                        'pending' => true, // Mark as pending generation
                    ];
                }
            }
        }
    }
    
    // Order by predefined list first, then others alphabetically by variant name
    usort($variants, function ($a, $b) use ($VARIANT_ORDER) {
        $ia = array_search($a['variant'], $VARIANT_ORDER, true);
        $ib = array_search($b['variant'], $VARIANT_ORDER, true);
        $ia = $ia === false ? PHP_INT_MAX : $ia;
        $ib = $ib === false ? PHP_INT_MAX : $ib;
        if ($ia === $ib) return strnatcasecmp($a['variant'], $b['variant']);
        return $ia < $ib ? -1 : 1;
    });
    $g['variants'] = $variants;
    // Meta from original if available, else first
    if (!$original) {
        foreach ($variants as $v) {
            if ($v['variant'] === 'original') { $original = $v; break; }
        }
    }
    if (!$metaFile) {
        $metaFile = $original ? ($dir.'/'.$original['name'].'.json') : ($dir.'/'.$variants[0]['name'].'.json');
    }
    if (is_file($metaFile)) {
        $raw = file_get_contents($metaFile);
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) $g['meta'] = $decoded;
    }
    // Check live status from JSON metadata (primary source)
    // Also check if this entry exists in gallery (for backward compatibility)
    $g['in_gallery'] = false;
    if (isset($g['meta']['live']) && $g['meta']['live'] === true) {
        $g['in_gallery'] = true;
        // If live but not in gallery, ensure it's copied
        if (!isset($galleryEntries[$key])) {
            // Entry should be in gallery but isn't - this is a sync issue
            // The gallery will be synced when user interacts with the painting
        } else {
            $g['gallery_filename'] = $galleryEntries[$key];
        }
    } else if (isset($galleryEntries[$key])) {
        // Backward compatibility: if in gallery but not marked live in JSON, mark as live
        $g['in_gallery'] = true;
        $g['gallery_filename'] = $galleryEntries[$key];
        // Update JSON to reflect live status (thread-safe)
        if (isset($g['meta']) && is_file($metaFile)) {
            update_json_file($metaFile, ['live' => true], false);
            $g['meta']['live'] = true;
        }
    }
}
unset($g);

// Sort groups by newest variant time desc
usort($groups, function ($a, $b) {
    if ($a['mtime'] === $b['mtime']) return 0;
    return ($a['mtime'] < $b['mtime']) ? 1 : -1;
});

$result['groups'] = $groups;

echo json_encode($result);
exit;


