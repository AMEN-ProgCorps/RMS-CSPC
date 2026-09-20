<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class RegisterScanService
{
    public static function extract(Request $request)
    {
        $rateCheck = RateLimiterService::check('dcs_ocr');
        if (!$rateCheck['allowed']) {
            return [
                'extracted' => false,
                'reason' => 'rate_limited',
                'message' => $rateCheck['message'],
                'retry_after' => $rateCheck['retry_after'],
                'fields' => self::emptyFields(),
            ];
        }

        $lockKey = 'dcs_ocr:user:' . (auth()->id() ?: ('ip:' . (request()->ip() ?: 'guest')));
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 120);
        if (! $lock->get()) {
            return [
                'extracted' => false,
                'reason' => 'ocr_busy',
                'message' => 'Another OCR request is still running for your account. Please wait and try again.',
                'fields' => self::emptyFields(),
            ];
        }

        try {
            return self::extractLocked($request);
        } finally {
            optional($lock)->release();
        }
    }

    private static function extractLocked(Request $request)
    {
        try {
            $request->validate([
                'scan' => 'required|file|mimes:pdf|max:10240',
                'section' => 'required|string|in:drf',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Soft-fail validation so the upload UI is never blocked by extract-scan.
            return [
                'extracted' => false,
                'reason' => 'validation_failed',
                'message' => collect($e->errors())->flatten()->first() ?: 'Invalid scan upload.',
                'fields' => self::emptyFields(),
            ];
        }

        $file = $request->file('scan');
        $tempPath = $file->store('temp/scans', 'local');
        $fullPath = Storage::disk('local')->path($tempPath);

        try {
            $rawText = '';
            $fields = self::emptyFields();
            $engine = null;

            // 1) Native PDF text (digital / PDF/A with text layer) — no Paddle required.
            $native = self::extractPdfText($fullPath);
            if (trim($native) !== '') {
                $rawText = $native;
                $engine = 'pdftotext';
                $fields = self::parseDrfFields($rawText);
            }

            // 2) OCR raster pages when native text is missing or unparseable.
            $ocrError = null;
            $ocrDiagnostics = null;
            if (! self::hasParsedValue($fields)) {
                $ocr = self::ocrPdfPagesDetailed($fullPath);
                $ocrText = $ocr['text'];
                $ocrError = $ocr['error'];
                $ocrDiagnostics = $ocr['diagnostics'] ?? null;
                if (trim($ocrText) !== '') {
                    $rawText = trim($rawText) !== '' ? ($rawText . "\n" . $ocrText) : $ocrText;
                    $engine = $engine ? ($engine . '+paddle') : 'paddle';
                    $fields = self::parseDrfFields($rawText);
                }
            }

            $ok = self::hasParsedValue($fields);
            $reason = $ok ? 'ok' : (trim($rawText) === '' ? 'no_text' : 'parse_miss');
            $message = null;
            if (! $ok) {
                $message = match ($reason) {
                    'no_text' => $ocrError
                        ? ('OCR failed on server: ' . Str::limit($ocrError, 320))
                        : 'No readable text from this scan (Ghostscript/pdftoppm/PaddleOCR may be missing on the server).',
                    'parse_miss' => 'Scan was read but DRF fields could not be matched — fill them in manually.',
                    default => 'Could not auto-read this scan. Upload kept — fill fields manually.',
                };
            }

            DcsAuditService::log('ocr.extract', 'register', null, null, [
                'extracted' => $ok,
                'reason' => $reason,
                'engine' => $engine,
                'ocr_error' => $ocrError ? Str::limit($ocrError, 240) : null,
                'stack' => $ocrDiagnostics['stack'] ?? null,
            ]);

            return [
                'extracted' => $ok,
                'reason' => $reason,
                'engine' => $engine,
                'message' => $message,
                'fields' => $fields,
                'raw_text_preview' => Str::limit($rawText, 500),
                // Shown in UI / network tab so deploy can be diagnosed without SSH.
                'diagnostics' => $ok ? null : $ocrDiagnostics,
            ];
        } catch (\Throwable $e) {
            Log::warning('OCR extraction failed: ' . $e->getMessage(), [
                'exception' => $e::class,
            ]);

            // Never hard-fail the client upload flow.
            return [
                'extracted' => false,
                'reason' => 'ocr_failed',
                'message' => 'Could not auto-read this scan. Upload kept — fill fields manually.',
                'fields' => self::emptyFields(),
            ];
        } finally {
            Storage::disk('local')->delete($tempPath);
        }
    }

    /** @return array<string, mixed> */
    private static function emptyFields(): array
    {
        return [
            'drfNo' => null,
            'drfDate' => null,
            'drfTitle' => null,
            'sourceUnit' => null,
            'sourceOfficeId' => null,
            'sourceOfficeCode' => null,
            'sourceOffices' => [],
            'sourceOfficeCodes' => [],
            'sourceOfficeUnmatched' => [],
        ];
    }

    private static function hasParsedValue(array $fields): bool
    {
        return (bool) ($fields['drfNo'] || $fields['drfDate'] || $fields['drfTitle'] || $fields['sourceUnit'] || $fields['sourceOfficeId'] || ! empty($fields['sourceOffices']));
    }

    /** Extract embedded text from the first pages (pdftotext / poppler). */
    private static function extractPdfText(string $pdfPath): string
    {
        $bin = self::pdftotextBinary();
        if ($bin !== null) {
            foreach ([
                ['-layout', '-f', '1', '-l', '2', $pdfPath, '-'],
                ['-raw', '-f', '1', '-l', '2', $pdfPath, '-'],
                ['-f', '1', '-l', '1', $pdfPath, '-'],
            ] as $args) {
                try {
                    $process = new Process(array_merge([$bin], $args));
                    $process->setTimeout(30);
                    $process->run();
                    if ($process->isSuccessful()) {
                        $out = trim($process->getOutput());
                        if ($out !== '') {
                            return $out;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::info('pdftotext skipped: ' . $e->getMessage());
                }
            }
        }

        // Deploy without poppler: pull plain literal strings from simple digital PDFs.
        $fallback = self::extractPdfLiteralStrings($pdfPath);
        if ($fallback !== '') {
            Log::info('DRF used PDF literal-string text fallback (pdftotext missing or empty).');

            return $fallback;
        }

        return '';
    }

    /**
     * Best-effort text from uncompressed PDF string literals — enough for simple
     * typed DRF test PDFs when pdftotext is unavailable on the server.
     */
    private static function extractPdfLiteralStrings(string $pdfPath): string
    {
        $bytes = @file_get_contents($pdfPath);
        if ($bytes === false || $bytes === '' || strlen($bytes) > 8_000_000) {
            return '';
        }

        // Skip obvious image-only / binary-heavy scans.
        if (! str_contains($bytes, '/Type /Page') && ! str_contains($bytes, '/Type/Page')) {
            return '';
        }

        if (! preg_match_all('/\((?:\\\\.|[^\\\\\\)]){1,300}\)/s', $bytes, $matches)) {
            return '';
        }

        $parts = [];
        foreach ($matches[0] as $raw) {
            $s = substr($raw, 1, -1);
            $s = str_replace(['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'], ["\n", "\r", "\t", '(', ')', '\\'], $s);
            $s = preg_replace('/\\\\[0-7]{1,3}/', '', $s) ?? $s;
            $s = trim($s);
            if ($s === '' || strlen($s) < 2) {
                continue;
            }
            // Drop font/encoding noise tokens.
            if (preg_match('/^[A-Za-z]{1,3}\d+$/', $s)) {
                continue;
            }
            $parts[] = $s;
        }

        if ($parts === []) {
            return '';
        }

        $joined = implode("\n", $parts);
        // Require at least one DRF-ish cue so random PDF metadata is not treated as form text.
        if (! preg_match('/DRF|Document\s+Title|Date/i', $joined)) {
            return '';
        }

        return trim($joined);
    }

    private static function pdftotextBinary(): ?string
    {
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', 'pdftotext'] as $bin) {
            if (str_contains($bin, '/')) {
                if (is_executable($bin)) {
                    return $bin;
                }
                continue;
            }
            try {
                $which = new Process(['which', $bin]);
                $which->setTimeout(5);
                $which->run();
                $path = trim($which->getOutput());
                if ($path !== '' && is_executable($path)) {
                    return $path;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * @return array{text: string, error: ?string, diagnostics: array<string, mixed>}
     */
    private static function ocrPdfPagesDetailed(string $pdfPath): array
    {
        $rawText = '';
        $primaryError = null; // keep earliest real failure (never invent page-2 noise)
        $pageErrors = [];
        $stack = PdfPageRenderer::stackDiagnostics();
        $pageCount = PdfPageRenderer::pageCount($pdfPath);

        // CRITICAL: DRF scans are almost always 1 page. Only touch page 2 when we
        // positively know the PDF has 2+ pages. Unknown count → page 1 only.
        $maxPages = ($pageCount !== null && $pageCount >= 2) ? min(2, $pageCount) : 1;

        Log::info('DRF OCR start', [
            'page_count' => $pageCount,
            'max_pages' => $maxPages,
            'stack' => $stack,
        ]);

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageResult = self::ocrOneDrfPage($pdfPath, $page);
            if ($pageResult['text'] !== '') {
                $rawText .= ($rawText === '' ? '' : "\n") . $pageResult['text'];
                $probe = self::parseDrfFields($rawText);
                if (self::hasParsedValue($probe)) {
                    break;
                }
                continue;
            }

            if ($pageResult['error'] !== null) {
                if (preg_match('/does not exist|requested page/i', $pageResult['error'])) {
                    Log::info('DRF OCR stopping: page ' . $page . ' not in PDF');
                    break;
                }
                $pageErrors[] = "p{$page}: " . $pageResult['error'];
                $primaryError ??= $pageResult['error'];
                Log::warning('DRF OCR page ' . $page . ' failed: ' . $pageResult['error']);
                // If page 1 cannot render, do not keep hunting other pages.
                if ($page === 1) {
                    break;
                }
            }
        }

        if ($rawText === '' && $primaryError === null) {
            $primaryError = 'No text from OCR on page 1'
                . ($pageCount !== null ? " (PDF reports {$pageCount} page(s))" : '')
                . '.';
        }

        return [
            'text' => $rawText,
            'error' => $primaryError,
            'diagnostics' => [
                'page_count' => $pageCount,
                'pages_attempted' => $maxPages,
                'page_errors' => $pageErrors,
                'stack' => $stack,
            ],
        ];
    }

    /**
     * Render + OCR a single DRF page with DPI fallbacks.
     *
     * @return array{text: string, error: ?string}
     */
    private static function ocrOneDrfPage(string $pdfPath, int $page): array
    {
        $errors = [];
        foreach ([220, 160, 120] as $dpi) {
            $imagePath = Storage::disk('local')->path('temp/scans/' . uniqid('ocr_', true) . '.jpg');
            try {
                PdfPageRenderer::savePage($pdfPath, $imagePath, $page, $dpi);
                $ocr = PaddleOcrRunner::recognize($imagePath);
                $pageText = trim((string) ($ocr['text'] ?? ''));
                if ($pageText !== '') {
                    return ['text' => $pageText, 'error' => null];
                }
                $detail = ! empty($ocr['error'])
                    ? (string) $ocr['error']
                    : 'PaddleOCR returned empty text';
                $errors[] = "dpi {$dpi}: {$detail}";
            } catch (\Throwable $e) {
                $errors[] = "dpi {$dpi}: " . $e->getMessage();
            } finally {
                if (isset($imagePath) && is_file($imagePath)) {
                    @unlink($imagePath);
                }
            }
        }

        return [
            'text' => '',
            'error' => implode(' | ', $errors) ?: 'page OCR failed',
        ];
    }

    private static function ocrPdfPages(string $pdfPath): string
    {
        return self::ocrPdfPagesDetailed($pdfPath)['text'];
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
        $matchedOffices = self::resolveSourceOffices($sourceRaw, $text, $lines);
        $first = $matchedOffices['offices'][0] ?? null;

        return [
            'drfNo' => $drfNo,
            'drfDate' => $drfDate,
            'drfTitle' => $drfTitle,
            'sourceUnit' => $first['office_name'] ?? $sourceRaw,
            'sourceOfficeId' => $first['id'] ?? null,
            'sourceOfficeCode' => $first['office_code'] ?? null,
            // All matched source units (DRF scans often list several codes).
            'sourceOffices' => $matchedOffices['offices'],
            'sourceOfficeCodes' => array_values(array_filter(array_map(
                fn (array $o) => $o['office_code'] ?? null,
                $matchedOffices['offices']
            ))),
            // Codes found on the scan that did not map to an office row.
            'sourceOfficeUnmatched' => $matchedOffices['unmatched'],
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
     * Resolve every source office mentioned on the scan (codes / names).
     *
     * @param  list<string>  $lines
     * @return array{offices: list<array{id: int, office_name: string, office_code: string}>, unmatched: list<string>}
     */
    private static function resolveSourceOffices(?string $labeled, string $fullText, array $lines): array
    {
        $found = [];
        $seen = [];
        $candidateTokens = [];

        $push = function (?array $office) use (&$found, &$seen): void {
            if (! $office) {
                return;
            }
            $id = (int) ($office['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $found[] = $office;
        };

        $collectTokens = function (string $raw) use (&$candidateTokens): void {
            foreach (preg_split('/[,;\/|]+/', $raw) ?: [] as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $candidateTokens[] = $token;
                }
            }
        };

        if ($labeled !== null && trim($labeled) !== '') {
            $collectTokens($labeled);
            foreach (self::matchAllSourceOffices($labeled) as $office) {
                $push($office);
            }
        }

        foreach (self::matchAllOfficeCodesInText($fullText, $lines, $labeled !== null && trim($labeled) !== '') as $office) {
            $push($office);
        }

        // Bare comma/space-separated code lines (e.g. "AIDCD, ACCESS, BUDG, CCS").
        foreach ($lines as $line) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9\s,;\/|&.-]{2,120}$/', $line)) {
                continue;
            }
            if (self::looksLikeLabel($line)) {
                continue;
            }
            if (! str_contains($line, ',') && ! str_contains($line, ';') && ! str_contains($line, '/')) {
                $parts = preg_split('/\s+/', trim($line)) ?: [];
                if (count($parts) < 2) {
                    continue;
                }
            }
            $collectTokens($line);
            foreach (self::matchAllSourceOffices($line) as $office) {
                $push($office);
            }
        }

        $matchedNeedles = [];
        foreach ($found as $office) {
            $matchedNeedles[self::normalizeOfficeToken((string) ($office['office_code'] ?? ''))] = true;
            $matchedNeedles[self::normalizeOfficeToken((string) ($office['office_name'] ?? ''))] = true;
        }

        $unmatched = [];
        $unseen = [];
        foreach ($candidateTokens as $token) {
            $needle = self::normalizeOfficeToken($token);
            if ($needle === '' || isset($matchedNeedles[$needle]) || isset($unseen[$needle])) {
                continue;
            }
            // Ignore long prose tokens — keep short code-like leftovers.
            if (strlen($needle) > 16 || str_contains($token, ' ')) {
                continue;
            }
            $unseen[$needle] = true;
            $unmatched[] = strtoupper(trim($token));
        }

        return [
            'offices' => $found,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @return list<array{id: int, office_name: string, office_code: string}>
     */
    private static function matchAllSourceOffices(string $raw): array
    {
        $tokens = preg_split('/[,;\/|]+/', $raw) ?: [$raw];
        // Source Unit autofill: active offices only (inactive codes stay unmatched).
        $offices = self::activeOffices();
        $found = [];
        $seen = [];

        foreach ($tokens as $token) {
            $needle = self::normalizeOfficeToken($token);
            if ($needle === '') {
                continue;
            }

            $byCode = $offices->first(function ($office) use ($needle) {
                $code = self::normalizeOfficeToken((string) $office->office_code);

                return $code !== '' && $code === $needle;
            });
            if ($byCode) {
                $payload = self::officePayload($byCode);
                if (! isset($seen[$payload['id']])) {
                    $seen[$payload['id']] = true;
                    $found[] = $payload;
                }
                continue;
            }

            // Name match: exact only (avoid "Unit" substring false positives).
            $byName = $offices->first(function ($office) use ($needle) {
                $name = self::normalizeOfficeToken((string) $office->office_name);

                return $name !== '' && $name === $needle;
            });
            if ($byName) {
                $payload = self::officePayload($byName);
                if (! isset($seen[$payload['id']])) {
                    $seen[$payload['id']] = true;
                    $found[] = $payload;
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{id: int, office_name: string, office_code: string}>
     */
    private static function matchAllOfficeCodesInText(string $fullText, array $lines, bool $hadLabeledValue): array
    {
        $offices = self::activeOffices()
            ->filter(fn ($o) => trim((string) $o->office_code) !== '')
            ->sortByDesc(fn ($o) => strlen(trim((string) $o->office_code)))
            ->values();

        $found = [];
        $seen = [];

        foreach ($offices as $office) {
            $code = strtoupper(trim((string) $office->office_code));
            if ($code === '' || in_array($code, ['ORIGIN', '[H]'], true)) {
                continue;
            }

            if (! preg_match('/\b' . preg_quote($code, '/') . '\b/i', $fullText)) {
                continue;
            }

            if (strlen($code) <= 2) {
                $safe = $hadLabeledValue
                    || self::codeIsStandaloneLine($lines, $code)
                    || self::codeNearSourceLabel($fullText, $code);
                if (! $safe) {
                    continue;
                }
            }

            $payload = self::officePayload($office);
            if (isset($seen[$payload['id']])) {
                continue;
            }
            $seen[$payload['id']] = true;
            $found[] = $payload;
        }

        return $found;
    }

    /** @param  list<string>  $lines */
    private static function codeIsStandaloneLine(array $lines, string $code): bool
    {
        foreach ($lines as $line) {
            if (strcasecmp(trim($line), $code) === 0) {
                return true;
            }
            if (preg_match('/(?:^|[,;\/|\s])' . preg_quote($code, '/') . '(?:$|[,;\/|\s])/i', $line)) {
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
        return self::officesForScanMatch()
            ->filter(fn ($o) => self::officeIsActive($o))
            ->values();
    }

    private static function officeIsActive(object $office): bool
    {
        $raw = $office->is_active ?? false;
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw === 1;
        }
        $s = strtolower(trim((string) $raw));

        return in_array($s, ['1', 'true', 't', 'yes', 'y'], true);
    }

    /** Office catalog used for DRF scan matching. */
    private static function officesForScanMatch()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $cache = DB::table($officeTbl)->get(['id', 'office_name', 'office_code', 'is_active']);

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
