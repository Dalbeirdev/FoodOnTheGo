<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Module 39: an uploaded image is decoded and encoded again before it is stored. What is stored is pixels only — no
 * EXIF / XMP / comment blocks (a phone photo's GPS position, camera, owner), no trailing bytes appended to a valid
 * image (polyglot files). The caller has already checked the type, the size and the dimensions.
 *
 * Note: the EXIF orientation flag is not applied (the exif extension is not available here); a photo taken sideways
 * stays sideways. Clients that need it rotate before the upload.
 */
final class ImageSanitizer
{
    public static function reencode(string $path, string $mime): string
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        if ($image === false) {
            throw ValidationException::withMessages(['image' => ['The file is not a readable image.']]);
        }
        if ($mime !== 'image/jpeg') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        ob_start();
        $written = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 90),
            'image/png' => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, 90),
        };
        $data = (string) ob_get_clean();
        imagedestroy($image);
        if (! $written || $data === '') {
            throw ValidationException::withMessages(['image' => ['The image could not be processed. Please try another file.']]);
        }

        return $data;
    }
}
