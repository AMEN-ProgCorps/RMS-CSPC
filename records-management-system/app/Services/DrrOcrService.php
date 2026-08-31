<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use thiagoalessio\TesseractOCR\TesseractOCR;

class DrrOcrService
{
    public const MAX_PAGES_PER_REQUEST = 1;

    private const PDF_CACHE_TTL = 3600;

    public static function ocrPages(Request $request): array
    {
        @set_time_limit(90);

        $request->validate([
            'file' => 'nullable|file|mimes:pdf|max:204800',
            'storage_path' => 'nullable|string|max:500',
            'pages' => 'required|array|min:1|max:' . self::MAX_PAGES_PER_REQUEST,
            'pages.*' => 'integer|min:1|max:5000',
        ]);

        $hasFile = $request->hasFile('file');
        $storagePath = trim((string) $request->input('storage_path', ''));
        if (!$hasFile && $storagePath === '') {
            return ['ok' => false, 'reason' => 'missing_source', 'pages' => []];
        }

        $tempOwned = null;
        try {
            if ($hasFile) {
                $tempOwned = $request->file('file')->store('temp/drr-ocr', 'local');
                $pdfPath = Storage::disk('local')->path($tempOwned);
            } else {
                $pdfPath = self::resolveStoragePdf($storagePath);
                if ($pdfPath === null) {
                    return ['ok' => false, 'reason' => 'not_found', 'pages' => []];
                }
            }

            $pages = [];
            foreach (array_values(array_unique(array_map('intval', $request->input('pages', [])))) as $page) {
                if ($page < 1) {
                    continue;
                }
                $pages[] = self::ocrOnePage($pdfPath, $page);
            }

            return ['ok' => true, 'pages' => $pages];
        } catch (\Throwable $e) {
            Log::warning('DRR OCR failed: ' . $e->getMessage());

            return ['ok' => false, 'reason' => 'ocr_failed', 'message' => $e->getMessage(), 'pages' => []];
        } finally {
            if ($tempOwned) {
                Storage::disk('local')->delete($tempOwned);
            }
        }
    }

    private static function resolveStoragePdf(string $storagePath): ?string
    {
        $path = ltrim(str_replace('\\', '/', $storagePath), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        $cacheRel = 'temp/drr-ocr/cache/' . hash('sha256', $path) . '.pdf';
        $cacheAbs = Storage::disk('local')->path($cacheRel);
        if (is_file($cacheAbs) && filesize($cacheAbs) > 32) {
            $mtime = filemtime($cacheAbs);
            if ($mtime !== false && $mtime > time() - self::PDF_CACHE_TTL) {
                return $cacheAbs;
            }
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        if (preg_match('#/storage/(.+)$#', $storagePath, $m)) {
            $rel = $m[1];
            if (!str_contains($rel, '..') && Storage::disk('public')->exists($rel)) {
                return Storage::disk('public')->path($rel);
            }
        }

        if (DocumentStorageService::dcsScanExists($path)) {
            $content = DocumentStorageService::getDcsScanContent($path);
            if ($content !== null && $content !== '') {
                Storage::disk('local')->makeDirectory('temp/drr-ocr/cache');
                Storage::disk('local')->put($cacheRel, $content);

                return $cacheAbs;
            }
        }

        return null;
    }

    private static function ocrOnePage(string $pdfPath, int $page): array
    {
        $imagePath = Storage::disk('local')->path('temp/drr-ocr/' . uniqid('page_', true) . '.jpg');

        try {
            PdfPageRenderer::savePage($pdfPath, $imagePath, $page, 150);

            $size = @getimagesize($imagePath);
            $imgW = max(1, (int) ($size[0] ?? 1));
            $imgH = max(1, (int) ($size[1] ?? 1));

            $ocr = (new TesseractOCR($imagePath))->lang('eng')->psm(6);
            $tsv = (string) $ocr->tsv()->run(45);
            $words = self::parseTsvWords($tsv, $imgW, $imgH);
            $lines = self::parseTsvLines($tsv, $imgW, $imgH);

            if ($words === [] && $lines === []) {
                $tsv = (string) (new TesseractOCR($imagePath))->lang('eng')->psm(3)->tsv()->run(45);
                $words = self::parseTsvWords($tsv, $imgW, $imgH);
                $lines = self::parseTsvLines($tsv, $imgW, $imgH);
            }

            $text = trim(implode(' ', array_column($words, 't')));

            if ($text === '' && count($lines) > 0) {
                $text = trim(implode(' ', array_column($lines, 't')));
            }

            if ($text === '') {
                $text = trim((string) (new TesseractOCR($imagePath))
                    ->lang('eng')
                    ->psm(3)
                    ->run(45));
            }

            if ($words === [] && $text !== '') {
                $words = self::syntheticWordsFromText($text);
            }

            return [
                'page' => $page,
                'text' => $text,
                'words' => $words,
                'lines' => $lines,
                'image_w' => $imgW,
                'image_h' => $imgH,
                'used_ocr' => true,
                'ok' => $text !== '',
            ];
        } catch (\Throwable $e) {
            Log::warning("DRR OCR page {$page} failed: " . $e->getMessage());

            return [
                'page' => $page,
                'text' => '',
                'words' => [],
                'used_ocr' => true,
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            if (is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    /**
     * @return list<array{t: string, x: float, y: float, w: float, h: float}>
     */
    private static function parseTsvWords(string $tsv, int $imgW, int $imgH): array
    {
        $words = [];
        $lines = preg_split('/\R/', $tsv) ?: [];
        $headerSkipped = false;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (!$headerSkipped) {
                $headerSkipped = true;
                if (str_starts_with($line, 'level')) {
                    continue;
                }
            }

            $cols = explode("\t", $line);
            if (count($cols) < 12) {
                continue;
            }

            // Tesseract level 5 = word
            if ((int) $cols[0] !== 5) {
                continue;
            }

            $text = trim((string) ($cols[11] ?? ''));
            if ($text === '') {
                continue;
            }

            $conf = (float) ($cols[10] ?? -1);
            if ($conf >= 0 && $conf < 30) {
                continue;
            }

            $left = (float) $cols[6];
            $top = (float) $cols[7];
            $w = max(1.0, (float) $cols[8]);
            $h = max(1.0, (float) $cols[9]);

            $nw = $w / $imgW;
            $nh = $h / $imgH;
            if ($nw > 0.48 && strlen($text) < 22) {
                continue;
            }
            if ($nh > 0.09 && strlen($text) < 14) {
                continue;
            }

            $words[] = [
                't' => $text,
                'x' => $left / $imgW,
                'y' => $top / $imgH,
                'w' => $nw,
                'h' => $nh,
                'conf' => $conf >= 0 ? $conf : null,
            ];
        }

        return $words;
    }

    /**
     * @return list<array{t: string, x: float, y: float, w: float, h: float}>
     */
    private static function parseTsvLines(string $tsv, int $imgW, int $imgH): array
    {
        $lines = [];
        $parsed = preg_split('/\R/', $tsv) ?: [];
        $headerSkipped = false;

        foreach ($parsed as $line) {
            if ($line === '') {
                continue;
            }
            if (!$headerSkipped) {
                $headerSkipped = true;
                if (str_starts_with($line, 'level')) {
                    continue;
                }
            }

            $cols = explode("\t", $line);
            if (count($cols) < 12) {
                continue;
            }

            if ((int) $cols[0] !== 4) {
                continue;
            }

            $text = trim((string) ($cols[11] ?? ''));
            if ($text === '') {
                continue;
            }

            $conf = (float) ($cols[10] ?? -1);
            if ($conf >= 0 && $conf < 40) {
                continue;
            }

            $left = (float) $cols[6];
            $top = (float) $cols[7];
            $w = max(1.0, (float) $cols[8]);
            $h = max(1.0, (float) $cols[9]);

            $lines[] = [
                't' => $text,
                'x' => $left / $imgW,
                'y' => $top / $imgH,
                'w' => $w / $imgW,
                'h' => $h / $imgH,
            ];
        }

        return $lines;
    }

    /**
     * Estimated word boxes when Tesseract returns text but no geometry.
     *
     * @return list<array{t: string, x: float, y: float, w: float, h: float}>
     */
    private static function syntheticWordsFromText(string $text): array
    {
        $words = [];
        $lines = preg_split('/\R+/', trim($text)) ?: [];
        if ($lines === []) {
            $lines = [trim($text)];
        }

        $lineCount = max(count($lines), 1);
        foreach ($lines as $li => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $wordCount = max(count($parts), 1);
            $lh = 0.88 / $lineCount;
            $y = 0.05 + ($li * $lh);
            $sliceW = 0.88 / $wordCount;
            foreach ($parts as $wi => $part) {
                if ($part === '') {
                    continue;
                }
                $words[] = [
                    't' => $part,
                    'x' => 0.06 + ($wi * $sliceW),
                    'y' => $y,
                    'w' => max($sliceW * 0.92, 0.008),
                    'h' => max($lh * 0.82, 0.012),
                ];
            }
        }

        return $words;
    }
}
