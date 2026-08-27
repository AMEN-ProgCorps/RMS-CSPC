<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Spatie\PdfToImage\Pdf;

class PdfPageRenderer
{
    public static function savePage(string $pdfPath, string $imagePath, int $page = 1, int $dpi = 150): void
    {
        $directory = dirname($imagePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $dpi = max(72, min(200, $dpi));
        $errors = [];

        try {
            self::withGhostscript($pdfPath, $imagePath, $page, $dpi);
            if (self::isValidImage($imagePath)) {
                return;
            }
            $errors[] = 'Ghostscript produced an empty image.';
        } catch (\Throwable $e) {
            $errors[] = 'Ghostscript: ' . $e->getMessage();
            Log::warning('PDF page render (Ghostscript) failed: ' . $e->getMessage());
        }

        try {
            self::withImagick($pdfPath, $imagePath, $page);
            if (self::isValidImage($imagePath)) {
                return;
            }
            $errors[] = 'Imagick produced an empty image.';
        } catch (\Throwable $e) {
            $errors[] = 'Imagick: ' . $e->getMessage();
            Log::warning('PDF page render (Imagick) failed: ' . $e->getMessage());
        }

        if (is_file($imagePath)) {
            @unlink($imagePath);
        }

        throw new \RuntimeException('Could not render PDF page ' . $page . '. ' . implode(' ', $errors));
    }

    private static function withGhostscript(string $pdfPath, string $imagePath, int $page, int $dpi = 100): void
    {
        $gs = self::ghostscriptBinary();
        if ($gs === null) {
            throw new \RuntimeException('gs binary not found.');
        }

        if (is_file($imagePath)) {
            @unlink($imagePath);
        }

        $cmd = sprintf(
            '%s -dSAFER -dBATCH -dNOPAUSE -dQUIET -sDEVICE=jpeg -dJPEGQ=70 -dFirstPage=%d -dLastPage=%d -r%d -sOutputFile=%s %s 2>&1',
            escapeshellarg($gs),
            $page,
            $page,
            $dpi,
            escapeshellarg($imagePath),
            escapeshellarg($pdfPath)
        );

        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException(trim(implode("\n", $output)) ?: ('exit code ' . $code));
        }
    }

    private static function ghostscriptBinary(): ?string
    {
        foreach (['gs', '/usr/bin/gs', '/usr/local/bin/gs'] as $candidate) {
            if ($candidate === 'gs') {
                $path = trim((string) shell_exec('command -v gs 2>/dev/null'));
                if ($path !== '' && is_executable($path)) {
                    return $path;
                }
                continue;
            }
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function withImagick(string $pdfPath, string $imagePath, int $page): void
    {
        if (! class_exists(Pdf::class)) {
            throw new \RuntimeException('spatie/pdf-to-image is not installed.');
        }

        if (is_file($imagePath)) {
            @unlink($imagePath);
        }

        (new Pdf($pdfPath))->selectPage($page)->save($imagePath);
    }

    private static function isValidImage(string $imagePath): bool
    {
        return is_file($imagePath) && filesize($imagePath) >= 32;
    }
}
