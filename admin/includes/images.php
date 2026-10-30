<?php
declare(strict_types=1);

/**
 * Validates an uploaded image, re-encodes it as WebP (which also strips hidden
 * data and anything that isn't a picture) and writes a full-size copy plus a thumbnail.
 *
 * Returns ['image' => relative path, 'thumb' => relative path, 'width' => int, 'height' => int].
 * Throws RuntimeException with a message that is safe to show to the user.
 */
function process_image_upload(array $file, string $folder, int $maxSize = 1600, int $thumbSize = 640): array
{
    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        throw new RuntimeException('Image processing is not available on this server (the PHP GD extension with WebP support is required).');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was chosen.',
        ];
        throw new RuntimeException($messages[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The upload failed.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload.');
    }
    if ($file['size'] > config('max_upload')) {
        throw new RuntimeException('Images must be smaller than ' . round(config('max_upload') / 1048576) . ' MB.');
    }

    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Please upload a JPG, PNG, WebP or GIF image.');
    }
    if ($info[0] * $info[1] > 50_000_000) {
        throw new RuntimeException('That image is too large in pixel size. Please use a smaller photo.');
    }

    ini_set('memory_limit', '512M');
    $src = match ($types[$info[2]]) {
        'jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'png' => @imagecreatefrompng($file['tmp_name']),
        'webp' => @imagecreatefromwebp($file['tmp_name']),
        'gif' => @imagecreatefromgif($file['tmp_name']),
    };
    if (!$src) {
        throw new RuntimeException('That image could not be read. Try saving it again as a JPG.');
    }

    // Phone photos are often stored sideways with a rotation flag; apply it
    if ($types[$info[2]] === 'jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $rotate = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
        if ($rotate) {
            $rotated = imagerotate($src, $rotate, 0);
            if ($rotated) {
                imagedestroy($src);
                $src = $rotated;
            }
        }
    }

    $dir = rtrim(config('site_root'), '/\\') . '/uploads/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the uploads folder.');
    }
    $base = date('Ym') . '-' . bin2hex(random_bytes(6));
    $full = save_webp_resized($src, "$dir/$base.webp", $maxSize, 80);
    save_webp_resized($src, "$dir/$base-thumb.webp", $thumbSize, 76);
    imagedestroy($src);

    return [
        'image' => "uploads/$folder/$base.webp",
        'thumb' => "uploads/$folder/$base-thumb.webp",
        'width' => $full[0],
        'height' => $full[1],
    ];
}

function save_webp_resized($src, string $path, int $max, int $quality): array
{
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (!imagewebp($dst, $path, $quality)) {
        imagedestroy($dst);
        throw new RuntimeException('Could not save the processed image.');
    }
    imagedestroy($dst);
    return [$nw, $nh];
}

/** Deletes an uploaded file, but only inside /uploads so seeded site images are never removed. */
function delete_upload(?string $relative): void
{
    if (!$relative || !str_starts_with($relative, 'uploads/') || str_contains($relative, '..')) {
        return;
    }
    $path = rtrim(config('site_root'), '/\\') . '/' . $relative;
    if (is_file($path)) {
        @unlink($path);
    }
}

/** Normalises the $_FILES structure for <input type="file" multiple>. */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}
