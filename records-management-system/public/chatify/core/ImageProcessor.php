<?php
// =============================================================================
// core/ImageProcessor.php — Upload-time WebP conversion + thumbnail generation
// =============================================================================
// Used by upload.php (to convert freshly uploaded images) and by
// load.php / load_dm.php / load_dm_admin.php (to resolve the thumbnail
// filename for an already-uploaded image when rendering chat history).
//
// Product behavior:
//   - Static raster images (jpg/jpeg/png/webp/bmp) are converted to a
//     single .webp file on upload (replacing the original) plus a small
//     "<name>_thumb.webp" companion used for the chat bubble preview.
//   - Animated GIFs keep their original .gif file untouched so the
//     animation survives on full view — only a static first-frame
//     "<name>_thumb.webp" is generated for the chat bubble preview.
//   - svg/ico and anything GD can't decode are left completely alone; the
//     chat bubble falls back to rendering the original file directly
//     (see thumbFilenameFor()).
//   - If the GD extension (or imagewebp() specifically) isn't available,
//     every conversion attempt is a no-op and uploads behave exactly as
//     they did before this feature existed — nothing ever throws.
// =============================================================================

class ImageProcessor
{
    private const CONVERTIBLE_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'bmp'];
    private const THUMB_MAX_DIM    = 480;
    private const THUMB_QUALITY    = 65;
    private const FULL_MAX_DIM     = 1600;
    private const FULL_QUALITY     = 82;
    private const THUMB_SUFFIX     = '_thumb.webp';

    /**
     * Attempt to convert a freshly-uploaded image in place.
     *
     * @param string $uploadDir  Absolute filesystem path to the uploads folder (no trailing slash)
     * @param string $uniqueName The filename move_uploaded_file() already saved it as
     * @param string $ext        Lowercased original extension
     * @return string            The filename callers should record — either the new
     *                           .webp name, or $uniqueName unchanged if nothing was converted
     */
    public static function processUpload(string $uploadDir, string $uniqueName, string $ext): string
    {
        if (!function_exists('imagewebp')) {
            return $uniqueName; // GD/WebP not available on this server — leave as-is
        }

        $isGif         = ($ext === 'gif');
        $isConvertible = in_array($ext, self::CONVERTIBLE_EXTS, true);
        if (!$isGif && !$isConvertible) {
            return $uniqueName; // svg, ico, etc. — pass through untouched
        }

        $srcPath   = $uploadDir . '/' . $uniqueName;
        $baseNoExt = pathinfo($uniqueName, PATHINFO_FILENAME);

        $img = self::load($srcPath, $ext);
        if ($img === false) {
            return $uniqueName; // corrupt/unreadable — don't touch the original upload
        }

        // Thumbnail is best-effort and never blocks the full-size path — a
        // missing thumb just makes the chat bubble fall back to the full
        // image (see thumbFilenameFor()).
        self::resizeAndSaveWebp($img, $uploadDir . '/' . $baseNoExt . self::THUMB_SUFFIX, self::THUMB_MAX_DIM, self::THUMB_QUALITY);

        $finalName = $uniqueName;
        if (!$isGif) {
            // Animated GIFs are skipped here — converting to WebP would
            // flatten them to a single static frame and kill the animation.
            $webpPath = $uploadDir . '/' . $baseNoExt . '.webp';
            if (self::resizeAndSaveWebp($img, $webpPath, self::FULL_MAX_DIM, self::FULL_QUALITY)) {
                @unlink($srcPath);
                $finalName = $baseNoExt . '.webp';
            }
        }

        imagedestroy($img);
        return $finalName;
    }

    /**
     * Resolve the thumbnail filename for an already-uploaded image, for
     * load.php / load_dm.php / load_dm_admin.php to use as the bubble
     * <img src>. Returns null if no thumbnail exists on disk (a pre-feature
     * upload, or a format ImageProcessor never touches) — callers should
     * fall back to rendering the full image directly in that case.
     */
    public static function thumbFilenameFor(string $uploadsDir, string $file): ?string
    {
        $thumb = pathinfo($file, PATHINFO_FILENAME) . self::THUMB_SUFFIX;
        return is_file($uploadsDir . $thumb) ? $thumb : null;
    }

    /** @return resource|\GdImage|false */
    private static function load(string $path, string $ext)
    {
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                return @imagecreatefromjpeg($path);
            case 'png':
                return @imagecreatefrompng($path);
            case 'gif':
                return @imagecreatefromgif($path);
            case 'webp':
                return @imagecreatefromwebp($path);
            case 'bmp':
                return function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false;
            default:
                return false;
        }
    }

    /** @param resource|\GdImage $img */
    private static function resizeAndSaveWebp($img, string $destPath, int $maxDim, int $quality): bool
    {
        if (!$img) return false;
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) return false;

        if ($maxDim > 0 && ($w > $maxDim || $h > $maxDim)) {
            $ratio = min($maxDim / $w, $maxDim / $h);
            $newW  = max(1, (int) round($w * $ratio));
            $newH  = max(1, (int) round($h * $ratio));
        } else {
            $newW = $w;
            $newH = $h;
        }

        $canvas = imagecreatetruecolor($newW, $newH);
        // Preserve transparency (PNG/WebP with alpha) instead of it
        // compositing to black.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $newW, $newH, $transparent);
        imagecopyresampled($canvas, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);

        $ok = imagewebp($canvas, $destPath, $quality);
        imagedestroy($canvas);
        return $ok;
    }
}
