<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                'sourceOfficeCode' => null,
            ];

            $maxPages = 2;
            for ($page = 1; $page <= $maxPages; $page++) {
                $imagePath = Storage::disk('local')->path('temp/scans/' . uniqid('ocr_', true) . '.jpg');
                try {
                    PdfPageRenderer::savePage($fullPath, $imagePath, $page);
                    $ocr = PaddleOcrRunner::recognize($imagePath);
                    $pageText = trim((string) ($ocr['text'] ?? ''));
                    if ($pageText === '' && ! empty($ocr['error'])) {
                        throw new \RuntimeException((string) $ocr['error']);
                    }
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
        $text = self::normalizeOcrText($text);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)), fn ($line) => $line !== ''));

        $drfNo = self::valueForLabels($lines, ['DRF No', 'DRF Number', 'DRF #', 'Document Request Form No', 'No.']);
        $drfDate = self::normalizeDate(self::valueForLabels($lines, ['DRF Date', 'Date Requested', 'Date of Request', 'Date']));
        $drfTitle = self::valueForLabels($lines, ['Document Title', 'Title of Document', 'Title']);
        $sourceRaw = self::valueForLabels($lines, [
            'Source Unit',
            'Originating Unit',
            'Requesting Unit',
            'Requesting Office',
            'Source Office',
            'Office Code',
            'Unit Code',
            'College/Unit',
            'Unit/Office',
            'From Unit',
            'Unit',
            'Office',
        ]);
        $matched = self::resolveSourceOffice($sourceRaw, $text, $lines);

        return [
            'drfNo' => $drfNo,
            'drfDate' => $drfDate,
            'drfTitle' => $drfTitle,
            'sourceUnit' => $matched['office_name'] ?? $sourceRaw,
            'sourceOfficeId' => $matched['id'] ?? null,
            'sourceOfficeCode' => $matched['office_code'] ?? null,
        ];
    }

    /** Fix common OCR glues: "DRF No:CSPC", "August7,2026", "Titletesting". */
    private static function normalizeOcrText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/([A-Za-z])(\d)/', '$1 $2', $text) ?? $text;
        $text = preg_replace('/(\d)([A-Za-z])/', '$1 $2', $text) ?? $text;
        $text = preg_replace('/,(\S)/', ', $1', $text) ?? $text;
        $text = preg_replace('/([:;.])(\S)/', '$1 $2', $text) ?? $text;
        // Insert space between label-ish Title/No and following lowercase value.
        $text = preg_replace('/\b(Title|No\.?|Date|Unit|Office|Code)([a-z])/', '$1 $2', $text) ?? $text;

        return $text;
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
                if ($next !== null && ! self::looksLikeLabel($next)) {
                    return $next;
                }
            }
        }

        return null;
    }

    private static function looksLikeLabel(string $line): bool
    {
        return (bool) preg_match(
            '/^(DRF\s*(No|Number|Date|#)|Document Title|Title of Document|Title|Source Unit|Originating Unit|Requesting Unit|Requesting Office|Source Office|Office Code|Unit Code|College\/Unit|Unit\/Office|From Unit|Unit|Office|Date Requested|Date of Request)\b/i',
            $line
        );
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

    /**
     * Prefer labeled value → office code/name match; else scan full OCR for office codes
     * (users often write only the code, e.g. "CAS" / "ICTU").
     *
     * @param  list<string>  $lines
     * @return array{id: int, office_name: string, office_code: string}|null
     */
    private static function resolveSourceOffice(?string $labeled, string $fullText, array $lines): ?array
    {
        if ($labeled !== null && trim($labeled) !== '') {
            $matched = self::matchSourceOffice($labeled);
            if ($matched) {
                return $matched;
            }
        }

        return self::matchOfficeCodeInText($fullText, $lines, $labeled !== null && trim($labeled) !== '');
    }

    /**
     * @return array{id: int, office_name: string, office_code: string}|null
     */
    private static function matchSourceOffice(string $raw): ?array
    {
        $tokens = preg_split('/[,;\/|]+/', $raw) ?: [$raw];
        $offices = self::activeOffices();

        foreach ($tokens as $token) {
            $needle = self::normalizeOfficeToken($token);
            if ($needle === '') {
                continue;
            }

            // Exact office code first (what users put on DRF scans).
            $byCode = $offices->first(function ($office) use ($needle) {
                $code = self::normalizeOfficeToken((string) $office->office_code);

                return $code !== '' && $code === $needle;
            });
            if ($byCode) {
                return self::officePayload($byCode);
            }

            $byName = $offices->first(function ($office) use ($needle) {
                $name = self::normalizeOfficeToken((string) $office->office_name);

                return $name !== '' && ($name === $needle || str_contains($name, $needle) || str_contains($needle, $name));
            });
            if ($byName) {
                return self::officePayload($byName);
            }
        }

        return null;
    }

    /**
     * Scan OCR text for known office codes (longest codes first to avoid partials).
     *
     * @param  list<string>  $lines
     * @return array{id: int, office_name: string, office_code: string}|null
     */
    private static function matchOfficeCodeInText(string $fullText, array $lines, bool $hadLabeledValue): ?array
    {
        $offices = self::activeOffices()
            ->filter(fn ($o) => trim((string) $o->office_code) !== '')
            ->sortByDesc(fn ($o) => strlen(trim((string) $o->office_code)))
            ->values();

        foreach ($offices as $office) {
            $code = strtoupper(trim((string) $office->office_code));
            if ($code === '' || in_array($code, ['ORIGIN', '[H]'], true)) {
                continue;
            }

            if (! preg_match('/\b' . preg_quote($code, '/') . '\b/i', $fullText)) {
                continue;
            }

            // Short codes (BC, DC, GS…) are easy false positives — require a label
            // nearby, a labeled value we already saw, or a standalone line.
            if (strlen($code) <= 2) {
                $safe = $hadLabeledValue
                    || self::codeIsStandaloneLine($lines, $code)
                    || self::codeNearSourceLabel($fullText, $code);
                if (! $safe) {
                    continue;
                }
            }

            return self::officePayload($office);
        }

        return null;
    }

    /** @param  list<string>  $lines */
    private static function codeIsStandaloneLine(array $lines, string $code): bool
    {
        foreach ($lines as $line) {
            if (strcasecmp(trim($line), $code) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function codeNearSourceLabel(string $text, string $code): bool
    {
        return (bool) preg_match(
            '/(?:Source\s*Unit|Originating\s*Unit|Requesting\s*Unit|Source\s*Office|Office\s*Code|Unit\s*Code|Unit|Office)\b[^\n]{0,40}\b'
            . preg_quote($code, '/')
            . '\b/i',
            $text
        );
    }

    private static function normalizeOfficeToken(string $raw): string
    {
        $raw = strtolower(trim($raw));
        // Drop punctuation; keep letters/numbers so "CAS" / "cas." still match.
        $raw = preg_replace('/[^\p{L}\p{N}]+/u', '', $raw) ?? '';

        return $raw;
    }

    private static function activeOffices()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $cache = DB::table($officeTbl)
            ->where('is_active', true)
            ->get(['id', 'office_name', 'office_code']);

        return $cache;
    }

    /**
     * @param  object{id: mixed, office_name: mixed, office_code: mixed}  $office
     * @return array{id: int, office_name: string, office_code: string}
     */
    private static function officePayload(object $office): array
    {
        return [
            'id' => (int) $office->id,
            'office_name' => (string) $office->office_name,
            'office_code' => (string) $office->office_code,
        ];
    }
}
