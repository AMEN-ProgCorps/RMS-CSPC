<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use thiagoalessio\TesseractOCR\TesseractOCR;

class RegisterScanService
{
    public static function extract(Request $request)
    {
        $request->validate([
            'scan' => 'required|file|mimes:pdf|max:10240',
            'section' => 'required|string|in:drf',
        ]);

        $file = $request->file('scan');
        $tempPath = $file->store('temp/scans', 'local');
        $fullPath = Storage::disk('local')->path($tempPath);

        try {
            $rawText = '';
            $fields = [
                'drfNo' => null,
                'drfDate' => null,
                'drfTitle' => null,
                'sourceUnit' => null,
                'sourceOfficeId' => null,
            ];

            $maxPages = 2;
            for ($page = 1; $page <= $maxPages; $page++) {
                $imagePath = Storage::disk('local')->path('temp/scans/' . uniqid('ocr_', true) . '.jpg');
                try {
                    PdfPageRenderer::savePage($fullPath, $imagePath, $page);
                    $pageText = (new TesseractOCR($imagePath))->lang('eng')->run();
                    $rawText .= ($rawText === '' ? '' : "\n") . $pageText;
                    $fields = self::parseDrfFields($rawText);
                    if (self::hasParsedValue($fields)) {
                        break;
                    }
                } catch (\Throwable $pageError) {
                    if ($page === 1) {
                        throw $pageError;
                    }
                    break;
                } finally {
                    if (isset($imagePath) && file_exists($imagePath)) {
                        @unlink($imagePath);
                    }
                }
            }

            return [
                'extracted' => true,
                'fields' => $fields,
                'raw_text_preview' => Str::limit($rawText, 500),
            ];
        } catch (\Throwable $e) {
            Log::warning('OCR extraction failed: ' . $e->getMessage());

            return ['extracted' => false, 'reason' => 'ocr_failed'];
        } finally {
            Storage::disk('local')->delete($tempPath);
        }
    }

    private static function hasParsedValue(array $fields): bool
    {
        return (bool) ($fields['drfNo'] || $fields['drfDate'] || $fields['drfTitle'] || $fields['sourceUnit'] || $fields['sourceOfficeId']);
    }

    private static function parseDrfFields(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)), fn ($line) => $line !== ''));

        $drfNo = self::valueForLabels($lines, ['DRF No', 'DRF Number', 'DRF #', 'Document Request Form No', 'No.']);
        $drfDate = self::normalizeDate(self::valueForLabels($lines, ['DRF Date', 'Date Requested', 'Date of Request', 'Date']));
        $drfTitle = self::valueForLabels($lines, ['Document Title', 'Title of Document', 'Title']);
        $sourceUnit = self::valueForLabels($lines, ['Source Unit', 'Originating Unit', 'Source Office', 'Office']);
        $matched = $sourceUnit ? self::matchSourceOffice($sourceUnit) : null;

        return [
            'drfNo' => $drfNo,
            'drfDate' => $drfDate,
            'drfTitle' => $drfTitle,
            'sourceUnit' => $matched['office_name'] ?? $sourceUnit,
            'sourceOfficeId' => $matched['id'] ?? null,
        ];
    }

    private static function valueForLabels(array $lines, array $labels): ?string
    {
        foreach ($labels as $label) {
            foreach ($lines as $i => $line) {
                if (stripos($line, $label) === false) {
                    continue;
                }
                $parts = preg_split('/' . preg_quote($label, '/') . '[.:\s]*/i', $line, 2);
                if (isset($parts[1]) && trim($parts[1]) !== '') {
                    return trim($parts[1], " \t:-");
                }
                $next = $lines[$i + 1] ?? null;
                if ($next !== null && !self::looksLikeLabel($next)) {
                    return $next;
                }
            }
        }

        return null;
    }

    private static function looksLikeLabel(string $line): bool
    {
        return (bool) preg_match('/^(DRF\s*(No|Number|Date|#)|Document Title|Title of Document|Title|Source Unit|Originating Unit|Source Office|Office|Date Requested|Date of Request)\b/i', $line);
    }

    private static function normalizeDate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw)) {
            return $raw;
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $raw, $m)) {
                $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
                try {
                    return Carbon::createFromDate((int) $year, (int) $m[1], (int) $m[2])->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private static function matchSourceOffice(string $raw): ?array
    {
        $tokens = preg_split('/[,;\/|]+/', $raw) ?: [$raw];
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $offices = DB::table($officeTbl)
            ->where('is_active', true)
            ->get(['id', 'office_name', 'office_code']);

        foreach ($tokens as $token) {
            $needle = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $token)));
            if ($needle === '') {
                continue;
            }
            $match = $offices->first(function ($office) use ($needle) {
                $name = strtolower(trim((string) $office->office_name));
                $code = strtolower(trim((string) $office->office_code));

                return $name === $needle
                    || $code === $needle
                    || ($code !== '' && str_contains($needle, $code))
                    || str_contains($name, $needle)
                    || str_contains($needle, $name);
            });
            if ($match) {
                return ['id' => (int) $match->id, 'office_name' => $match->office_name];
            }
        }

        return null;
    }
}
