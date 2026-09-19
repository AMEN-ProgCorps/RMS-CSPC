<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Spatie\PdfToImage\Pdf;
use Symfony\Component\Process\Process;

class PdfPageRenderer
{
    /**
     * Rasterize one PDF page to JPEG.
     *
     * Tries Ghostscript (jpeg + png), Poppler pdftoppm, then Imagick/Spatie.
     * Scanned DRF uploads often fail on one backend but succeed on another.
     */
    public static function savePage(string $pdfPath, string $imagePath, int $page = 1, int $dpi = 150): void
    {
        if (! is_file($pdfPath) || filesize($pdfPath) < 16) {
            throw new \RuntimeException('PDF file is missing or empty.');
        }

        $directory = dirname($imagePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $page = max(1, $page);
        $dpi = max(72, min(300, $dpi));
        $errors = [];

        $pageCount = self::pageCount($pdfPath);
        if ($pageCount !== null && $page > $pageCount) {
            throw new \RuntimeException(
                "PDF has {$pageCount} page(s); requested page {$page} does not exist."
            );
        }

        $strategies = [
            'ghostscript-jpeg' => fn () => self::withGhostscript($pdfPath, $imagePath, $page, $dpi, 'jpeg'),
            'ghostscript-png' => fn () => self::withGhostscript($pdfPath, $imagePath, $page, $dpi, 'png'),
            'pdftoppm' => fn () => self::withPdftoppm($pdfPath, $imagePath, $page, $dpi),
            'imagick' => fn () => self::withImagick($pdfPath, $imagePath, $page),
        ];

        foreach ($strategies as $name => $runner) {
            try {
                if (is_file($imagePath)) {
                    @unlink($imagePath);
                }
                $runner();
                if (self::isValidImage($imagePath)) {
                    return;
                }
                $errors[] = "{$name}: empty or invalid image.";
            } catch (\Throwable $e) {
                $errors[] = "{$name}: " . self::shortError($e->getMessage());
                Log::warning("PDF page render ({$name}) failed: " . $e->getMessage(), [
                    'page' => $page,
                    'dpi' => $dpi,
                ]);
            }
        }

        if (is_file($imagePath)) {
            @unlink($imagePath);
        }

        throw new \RuntimeException(
            'Could not render PDF page ' . $page . '. ' . implode(' ', $errors)
        );
    }

    /**
     * Best-effort page count. Returns null when unknown (caller may still try page 1).
     */
    public static function pageCount(string $pdfPath): ?int
    {
        if (! is_file($pdfPath)) {
            return null;
        }

        $fromPdfInfo = self::pageCountViaPdfinfo($pdfPath);
        if ($fromPdfInfo !== null) {
            return $fromPdfInfo;
        }

        try {
            if (class_exists(Pdf::class)) {
                $count = (new Pdf($pdfPath))->pageCount();
                if (is_numeric($count) && (int) $count >= 1) {
                    return (int) $count;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('PDF pageCount (Spatie) skipped: ' . $e->getMessage());
        }

        if (class_exists(\Imagick::class)) {
            try {
                $img = new \Imagick();
                $img->pingImage($pdfPath);
                $count = $img->getNumberImages();
                $img->clear();
                $img->destroy();
                if ($count >= 1) {
                    return $count;
                }
            } catch (\Throwable $e) {
                Log::debug('PDF pageCount (Imagick) skipped: ' . $e->getMessage());
            }
        }

        return null;
    }

    /** Lightweight stack probe for deploy debugging without shell access. */
    public static function stackDiagnostics(): array
    {
        $gs = self::ghostscriptBinary();
        $pdftoppm = self::pdftoppmBinary();
        $pdfinfo = self::pdfinfoBinary();

        return [
            'ghostscript' => $gs !== null,
            'ghostscript_path' => $gs,
            'pdftoppm' => $pdftoppm !== null,
            'pdfinfo' => $pdfinfo !== null,
            'imagick_ext' => class_exists(\Imagick::class),
            'spatie_pdf_to_image' => class_exists(Pdf::class),
        ];
    }

    private static function withGhostscript(
        string $pdfPath,
        string $imagePath,
        int $page,
        int $dpi,
        string $format
    ): void {
        $gs = self::ghostscriptBinary();
        if ($gs === null) {
            throw new \RuntimeException('gs binary not found.');
        }

        $usePng = $format === 'png';
        $tmpBase = $imagePath . '.gs.' . ($usePng ? 'png' : 'jpg');
        if (is_file($tmpBase)) {
            @unlink($tmpBase);
        }

        // -dSAFER can refuse some scanner PDFs; retry once without it if empty.
        $attempts = [
            ['-dSAFER', '-dBATCH', '-dNOPAUSE', '-dQUIET'],
            ['-dBATCH', '-dNOPAUSE', '-dQUIET'],
        ];

        $lastOutput = '';
        $lastCode = 1;

        foreach ($attempts as $flags) {
            if (is_file($tmpBase)) {
                @unlink($tmpBase);
            }

            $device = $usePng ? 'png16m' : 'jpeg';
            $cmd = array_merge(
                [$gs],
                $flags,
                [
                    '-sDEVICE=' . $device,
                    '-dTextAlphaBits=4',
                    '-dGraphicsAlphaBits=4',
                    '-dFirstPage=' . $page,
                    '-dLastPage=' . $page,
                    '-r' . $dpi,
                    '-sOutputFile=' . $tmpBase,
                    $pdfPath,
                ]
            );

            $process = new Process($cmd);
            $process->setTimeout(90);
            $process->run();
            $lastCode = $process->getExitCode() ?? 1;
            $lastOutput = trim($process->getErrorOutput() . "\n" . $process->getOutput());

            if ($process->isSuccessful() && self::isValidImage($tmpBase)) {
                self::finalizeRaster($tmpBase, $imagePath, $usePng);

                return;
            }
        }

        @unlink($tmpBase);
        throw new \RuntimeException(
            trim($lastOutput) !== ''
                ? trim($lastOutput)
                : ('empty image (exit ' . $lastCode . ')')
        );
    }

    private static function withPdftoppm(string $pdfPath, string $imagePath, int $page, int $dpi): void
    {
        $bin = self::pdftoppmBinary();
        if ($bin === null) {
            throw new \RuntimeException('pdftoppm not found.');
        }

        $prefix = $imagePath . '.ppm';
        // pdftoppm appends -<page>.jpg when -jpeg and single page with -f/-l.
        $expected = $prefix . '-' . $page . '.jpg';
        $also = $prefix . '.jpg';

        foreach ([$expected, $also, $prefix . '-1.jpg'] as $stale) {
            if (is_file($stale)) {
                @unlink($stale);
            }
        }

        $process = new Process([
            $bin,
            '-jpeg',
            '-r', (string) $dpi,
            '-f', (string) $page,
            '-l', (string) $page,
            '-singlefile',
            $pdfPath,
            $prefix,
        ]);
        $process->setTimeout(90);
        $process->run();

        $candidates = [$prefix . '.jpg', $expected, $prefix . '-1.jpg', $also];
        $found = null;
        foreach ($candidates as $candidate) {
            if (self::isValidImage($candidate)) {
                $found = $candidate;
                break;
            }
        }

        if ($found === null) {
            $detail = trim($process->getErrorOutput() . "\n" . $process->getOutput());
            throw new \RuntimeException($detail !== '' ? $detail : 'pdftoppm produced no image.');
        }

        if (! @rename($found, $imagePath) && ! @copy($found, $imagePath)) {
            @unlink($found);
            throw new \RuntimeException('Could not move pdftoppm output into place.');
        }
        @unlink($found);
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

    private static function finalizeRaster(string $tmpPath, string $imagePath, bool $fromPng): void
    {
        if (! $fromPng) {
            if (! @rename($tmpPath, $imagePath) && ! @copy($tmpPath, $imagePath)) {
                @unlink($tmpPath);
                throw new \RuntimeException('Could not move Ghostscript JPEG into place.');
            }
            @unlink($tmpPath);

            return;
        }

        // Convert PNG → JPEG so downstream OCR always sees .jpg.
        if (class_exists(\Imagick::class)) {
            $img = new \Imagick($tmpPath);
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(90);
            $img->writeImage($imagePath);
            $img->clear();
            $img->destroy();
            @unlink($tmpPath);

            return;
        }

        if (function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
            $gd = @imagecreatefrompng($tmpPath);
            if ($gd === false) {
                @unlink($tmpPath);
                throw new \RuntimeException('GD could not read Ghostscript PNG.');
            }
            $ok = @imagejpeg($gd, $imagePath, 90);
            imagedestroy($gd);
            @unlink($tmpPath);
            if (! $ok) {
                throw new \RuntimeException('GD could not write JPEG.');
            }

            return;
        }

        // Last resort: keep PNG bytes under the .jpg path (Paddle/Pillow still read them).
        if (! @rename($tmpPath, $imagePath) && ! @copy($tmpPath, $imagePath)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Could not move Ghostscript PNG into place.');
        }
        @unlink($tmpPath);
    }

    private static function isValidImage(string $imagePath): bool
    {
        if (! is_file($imagePath) || filesize($imagePath) < 128) {
            return false;
        }

        $info = @getimagesize($imagePath);
        if (! is_array($info)) {
            return false;
        }

        $w = (int) ($info[0] ?? 0);
        $h = (int) ($info[1] ?? 0);

        return $w >= 8 && $h >= 8;
    }

    private static function pageCountViaPdfinfo(string $pdfPath): ?int
    {
        $bin = self::pdfinfoBinary();
        if ($bin === null) {
            return null;
        }

        try {
            $process = new Process([$bin, $pdfPath]);
            $process->setTimeout(15);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            if (preg_match('/^Pages:\s*(\d+)/mi', $process->getOutput(), $m)) {
                $n = (int) $m[1];

                return $n >= 1 ? $n : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private static function ghostscriptBinary(): ?string
    {
        return self::findBinary(['gs', '/usr/bin/gs', '/usr/local/bin/gs']);
    }

    private static function pdftoppmBinary(): ?string
    {
        return self::findBinary(['pdftoppm', '/usr/bin/pdftoppm', '/usr/local/bin/pdftoppm']);
    }

    private static function pdfinfoBinary(): ?string
    {
        return self::findBinary(['pdfinfo', '/usr/bin/pdfinfo', '/usr/local/bin/pdfinfo']);
    }

    /**
     * @param  list<string>  $candidates
     */
    private static function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! str_contains($candidate, '/')) {
                $path = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
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

    private static function shortError(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);

        return mb_strlen($message) > 160 ? (mb_substr($message, 0, 157) . '…') : $message;
    }
}
