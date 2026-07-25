<?php

namespace App\Service;

/**
 * Downscales uploaded images in place so that a single image never exceeds
 * the TV display limit (MAX_BYTES). Works on the temporary upload path
 * before ImageUploader moves the file into the public uploads directory.
 *
 * Deliberately processes ONE image per call/request: decoding a photo into
 * a GD bitmap needs a lot of memory, and handling images one by one is what
 * keeps bulk uploads from running the server out of memory.
 */
class ImageDownscaler
{
    /**
     * Maximum stored file size per image (TVs cannot handle larger files).
     * Matches the former "3M" validation constraint (10^6 bytes per MB).
     */
    public const MAX_BYTES = 3_000_000;

    /**
     * Hard cap for the uploaded original: anything above this is rejected
     * instead of downscaled (matches the "25M" form constraint).
     */
    public const MAX_ORIGINAL_BYTES = 25_000_000;

    /**
     * Decode-memory guard: GD needs roughly 5 bytes per pixel, so this cap
     * keeps a single decode at ~320MB, safely inside the 512M memory_limit.
     * Anything larger is rejected before any decoding happens.
     */
    private const MAX_MEGAPIXELS = 64;

    /** Longest edge of the first downscale attempt (4K displays). */
    private const START_EDGE = 3840;

    /** Give up shrinking below this longest edge. */
    private const MIN_EDGE = 1280;

    private const JPEG_QUALITY = 82;

    public function needsDownscale(string $path): bool
    {
        clearstatcache(true, $path);

        return filesize($path) > self::MAX_BYTES;
    }

    /**
     * Downscale the image at $path in place until it is <= MAX_BYTES.
     * The file may change format (PNG photos are re-encoded as JPEG unless
     * they use transparency); callers that derive the file extension from
     * the actual content (UploadedFile::guessExtension) pick this up
     * automatically.
     *
     * @throws ImageDownscaleException with a user-facing German message
     */
    public function downscale(string $path): void
    {
        $info = @getimagesize($path);
        if (false === $info) {
            throw new ImageDownscaleException('Die Bilddatei konnte nicht gelesen werden. Bitte laden Sie ein gültiges Bild hoch.');
        }

        [$width, $height] = $info;
        $mime = $info['mime'] ?? '';

        if ('image/gif' === $mime) {
            // GD would reduce a GIF to its first frame and destroy the animation.
            throw new ImageDownscaleException(sprintf(
                'GIF-Dateien über %d MB können nicht automatisch verkleinert werden, ohne die Animation zu zerstören. Bitte verkleinern Sie das GIF selbst.',
                self::MAX_BYTES / 1_000_000
            ));
        }

        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new ImageDownscaleException('Dieses Bildformat kann nicht automatisch verkleinert werden (unterstützt: JPEG, PNG).');
        }

        if ($width * $height > self::MAX_MEGAPIXELS * 1_000_000) {
            throw new ImageDownscaleException(sprintf(
                'Das Bild ist mit %d Megapixeln zu groß für die automatische Verkleinerung (max. %d Megapixel). Bitte verkleinern Sie es selbst.',
                (int) round($width * $height / 1_000_000),
                self::MAX_MEGAPIXELS
            ));
        }

        $source = 'image/jpeg' === $mime ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if (false === $source) {
            throw new ImageDownscaleException('Die Bilddatei ist beschädigt und konnte nicht verarbeitet werden.');
        }

        if ('image/jpeg' === $mime) {
            $source = $this->applyExifOrientation($source, $path);
        }

        // Keep transparent PNGs as PNG; opaque PNGs become JPEG (much smaller).
        $keepPngAlpha = 'image/png' === $mime && $this->pngHasAlphaChannel($path);

        try {
            $edge = min(self::START_EDGE, max(imagesx($source), imagesy($source)));

            while (true) {
                $scaled = $this->scaleToEdge($source, $edge, $keepPngAlpha);
                $encoded = $this->encode($scaled, $keepPngAlpha);

                if (strlen($encoded) <= self::MAX_BYTES) {
                    if (false === @file_put_contents($path, $encoded)) {
                        throw new ImageDownscaleException('Das verkleinerte Bild konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.');
                    }
                    clearstatcache(true, $path);

                    return;
                }

                if ($edge <= self::MIN_EDGE) {
                    // Practically unreachable for JPEG; only extreme
                    // transparency-heavy PNGs can end up here.
                    throw new ImageDownscaleException('Das Bild konnte nicht ausreichend verkleinert werden. Bitte verkleinern Sie es selbst.');
                }

                $edge = max(self::MIN_EDGE, (int) round($edge * 0.75));
            }
        } finally {
            // GdImage objects are released by the garbage collector (imagedestroy
            // is a deprecated no-op since PHP 8.0 / 8.5).
            unset($source);
        }
    }

    private function scaleToEdge(\GdImage $source, int $edge, bool $preserveAlpha): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if (max($width, $height) <= $edge) {
            return $source;
        }

        if ($width >= $height) {
            $scaled = imagescale($source, $edge, -1, IMG_BICUBIC);
        } else {
            $scaled = imagescale($source, (int) round($width * $edge / $height), $edge, IMG_BICUBIC);
        }

        if (false === $scaled) {
            throw new ImageDownscaleException('Das Bild konnte nicht verkleinert werden. Bitte versuchen Sie es erneut.');
        }

        if ($preserveAlpha) {
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
        }

        return $scaled;
    }

    private function encode(\GdImage $image, bool $asPng): string
    {
        ob_start();
        $ok = $asPng
            ? imagepng($image, null, 9)
            : imagejpeg($image, null, self::JPEG_QUALITY);
        $encoded = ob_get_clean();

        if (!$ok || false === $encoded) {
            throw new ImageDownscaleException('Das Bild konnte nicht neu gespeichert werden. Bitte versuchen Sie es erneut.');
        }

        return $encoded;
    }

    /**
     * Phone photos often store their rotation only as EXIF metadata, which is
     * lost on re-encode. Bake the orientation into the pixels when the exif
     * extension is available; without it the image is left as-is.
     */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        if ($orientation <= 1 || $orientation > 8) {
            return $image;
        }

        // Mirrored variants (2, 4, 5, 7) flip first, then rotate.
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif (4 === $orientation) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if (0 !== $angle) {
            $rotated = imagerotate($image, $angle, 0);
            if (false !== $rotated) {
                return $rotated;
            }
        }

        return $image;
    }

    /**
     * Cheap alpha detection without decoding: the PNG color type lives at a
     * fixed offset in the IHDR chunk. Types 4 (gray+alpha) and 6 (RGBA) have
     * an alpha channel; palette transparency (rare for photos this large) is
     * treated as opaque.
     */
    private function pngHasAlphaChannel(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return false;
        }

        try {
            $header = fread($handle, 26);
        } finally {
            fclose($handle);
        }

        if (!is_string($header) || strlen($header) < 26) {
            return false;
        }

        $colorType = ord($header[25]);

        return 4 === $colorType || 6 === $colorType;
    }
}
