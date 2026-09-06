<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DrrOcrService
{
    public const MAX_PAGES_PER_REQUEST = 1;

    private const PDF_CACHE_TTL = 3600;

    public static function ocrPages(Request $request): array
    {
        @set_time_limit(180);

        $request->validate([
            'file' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:204800',
            'storage_path' => 'nullable|string|max:500',
            'pages' => 'required|array|min:1|max:' . self::MAX_PAGES_PER_REQUEST,
            'pages.*' => 'integer|min:1|max:5000',
        ]);

        $hasFile = $request->hasFile('file');
        $storagePath = trim((string) $request->input('storage_path', ''));
        if (!$hasFile && $storagePath === '') {
            return ['ok' => false, 'reason' => 'missing_source', 'pages' => []];
        }

        if (!$hasFile && $storagePath !== '') {
            $normalized = DocumentStorageService::normalizeDcsScanPath($storagePath);
            if ($normalized === null) {
                abort(422, 'Invalid storage path.');
            }
            \App\Helpers\RegisterQueryHelper::assertCanAccessScanPath($normalized);
            $storagePath = $normalized;
        }

        $tempOwned = null;
        try {
            if ($hasFile) {
                $upload = $request->file('file');
                $tempOwned = $upload->store('temp/drr-ocr', 'local');
                $absPath = Storage::disk('local')->path($tempOwned);
                $mime = (string) ($upload->getMimeType() ?: '');
                $ext = strtolower((string) $upload->getClientOriginalExtension());

                // Canvas fallback sends a single page image — OCR it directly.
                if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                    $pageNo = (int) (($request->input('pages')[0] ?? 1));
                    return [
                        'ok' => true,
                        'pages' => [self::ocrImageFile($absPath, max(1, $pageNo))],
                    ];
                }

                $pdfPath = $absPath;
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
        // Stored paths are OFFICE/DCS/... — strip accidental uploads/ prefix.
        if (str_starts_with($path, 'uploads/')) {
            $path = substr($path, strlen('uploads/'));
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
            PdfPageRenderer::savePage($pdfPath, $imagePath, $page, 200);

            return self::ocrImageFile($imagePath, $page);
        } catch (\Throwable $e) {
            Log::warning("DRR OCR page {$page} failed: " . $e->getMessage());

            return [
                'page' => $page,
                'text' => '',
                'words' => [],
                'lines' => [],
                'used_ocr' => true,
                'ok' => false,
                'error' => $e->getMessage(),
                'engine' => 'paddleocr',
                'geometry' => 'none',
            ];
        } finally {
            if (is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    /**
     * OCR a raster page image and return normalized word/line boxes.
     *
     * @return array<string, mixed>
     */
    private static function ocrImageFile(string $imagePath, int $page = 1): array
    {
        $size = @getimagesize($imagePath);
        $imgW = max(1, (int) ($size[0] ?? 1));
        $imgH = max(1, (int) ($size[1] ?? 1));

        $result = PaddleOcrRunner::recognize($imagePath);
        $words = is_array($result['words'] ?? null) ? $result['words'] : [];
        $lines = is_array($result['lines'] ?? null) ? $result['lines'] : [];
        $text = trim((string) ($result['text'] ?? ''));

        if ($text === '' && count($lines) > 0) {
            $text = trim(implode(' ', array_column($lines, 't')));
        }

        if ($words === [] && $text !== '') {
            $words = self::syntheticWordsFromText($text);
        }

        if (! ($result['ok'] ?? false) && $text === '') {
            Log::warning("DRR PaddleOCR page {$page} failed: " . ($result['error'] ?? 'unknown'));
        }

        return [
            'page' => $page,
            'text' => $text,
            'words' => $words,
            'lines' => $lines,
            'image_w' => (int) ($result['image_w'] ?? $imgW) ?: $imgW,
            'image_h' => (int) ($result['image_h'] ?? $imgH) ?: $imgH,
            'used_ocr' => true,
            'ok' => $text !== '',
            'engine' => 'paddleocr',
            'geometry' => self::wordsHaveRealGeometry($words) ? 'ocr' : 'none',
        ];
    }

    /**
     * Matching-only word list when OCR returns text but no geometry.
     * Marked synthetic so the compare UI does not paint invented boxes.
     *
     * @return list<array{t: string, x: float, y: float, w: float, h: float, synthetic: bool}>
     */
    private static function syntheticWordsFromText(string $text): array
    {
        $words = [];
        $parts = preg_split('/\s+/', trim($text)) ?: [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $words[] = [
                't' => $part,
                'x' => 0.0,
                'y' => 0.0,
                'w' => 0.0,
                'h' => 0.0,
                'synthetic' => true,
            ];
        }

        return $words;
    }

    /**
     * @param  list<array<string, mixed>>  $words
     */
    private static function wordsHaveRealGeometry(array $words): bool
    {
        foreach ($words as $word) {
            if (($word['synthetic'] ?? false) === true) {
                continue;
            }
            $w = (float) ($word['w'] ?? 0);
            $h = (float) ($word['h'] ?? 0);
            if ($w > 0.0005 && $h > 0.0005) {
                return true;
            }
        }

        return false;
    }
}
