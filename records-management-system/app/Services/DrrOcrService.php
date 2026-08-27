<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use thiagoalessio\TesseractOCR\TesseractOCR;

class DrrOcrService
{
    public const MAX_PAGES_PER_REQUEST = 3;

    public static function ocrPages(Request $request): array
    {
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

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        // Absolute public URL path → relative storage path
        if (preg_match('#/storage/(.+)$#', $storagePath, $m)) {
            $rel = $m[1];
            if (!str_contains($rel, '..') && Storage::disk('public')->exists($rel)) {
                return Storage::disk('public')->path($rel);
            }
        }

        return null;
    }

    private static function ocrOnePage(string $pdfPath, int $page): array
    {
        $imagePath = Storage::disk('local')->path('temp/drr-ocr/' . uniqid('page_', true) . '.jpg');

        try {
            // Better OCR quality for content matching (moved sections / reflowed text).
            PdfPageRenderer::savePage($pdfPath, $imagePath, $page, 150);

            $size = @getimagesize($imagePath);
            $imgW = max(1, (int) ($size[0] ?? 1));
            $imgH = max(1, (int) ($size[1] ?? 1));

            $ocr = (new TesseractOCR($imagePath))->lang('eng')->psm(6);
            $tsv = (string) $ocr->tsv()->run(120);
            $words = self::parseTsvWords($tsv, $imgW, $imgH);
            $text = trim(implode(' ', array_column($words, 't')));

            if ($text === '') {
                $text = trim((string) (new TesseractOCR($imagePath))
                    ->lang('eng')
                    ->psm(6)
                    ->run(90));
            }

            return [
                'page' => $page,
                'text' => $text,
                'words' => $words,
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
            if ($conf < 0) {
                continue;
            }

            $left = (float) $cols[6];
            $top = (float) $cols[7];
            $w = max(1.0, (float) $cols[8]);
            $h = max(1.0, (float) $cols[9]);

            $words[] = [
                't' => $text,
                'x' => $left / $imgW,
                'y' => $top / $imgH,
                'w' => $w / $imgW,
                'h' => $h / $imgH,
            ];
        }

        return $words;
    }
}
