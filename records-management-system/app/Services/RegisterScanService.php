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
                'scan' => ['required', 'file', 'max:10240', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        $fail('Only a PDF file can be uploaded.');

                        return;
                    }
                    $problem = \App\Support\DcsUploadGuard::pdfProblem($value, 10240);
                    if ($problem !== null) {
                        $fail($problem);
                    }
                }],
                'section' => 'required|string|in:drf,distribution',
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
            $section = (string) $request->input('section', 'drf');
            $isDistribution = $section === 'distribution';
            $rawText = '';
            $fields = self::emptyFields();
            $engine = null;

            // 1) Native PDF text (digital / PDF/A with text layer) — no Paddle required.
            $native = self::extractPdfText($fullPath, $isDistribution ? 8 : 2);
            if (trim($native) !== '') {
                $rawText = $native;
                $engine = 'pdftotext';
                $fields = $isDistribution
                    ? self::parseDistributionList($rawText)
                    : self::parseDrfFields($rawText);
            }

            // 2) OCR raster pages when native text is missing or unparseable.
            $ocrError = null;
            $ocrDiagnostics = null;
            $distributionMissed = $isDistribution && empty($fields['distributionOffices']);
            if (! self::hasParsedValue($fields) || $distributionMissed) {
                $ocr = self::ocrPdfPagesDetailed($fullPath, $isDistribution ? 6 : 2, ! $isDistribution);
                $ocrText = $ocr['text'];
                $ocrError = $ocr['error'];
                $ocrDiagnostics = $ocr['diagnostics'] ?? null;
                if (trim($ocrText) !== '') {
                    $rawText = trim($rawText) !== '' ? ($rawText . "\n" . $ocrText) : $ocrText;
                    $engine = $engine ? ($engine . '+paddle') : 'paddle';
                    $fields = $isDistribution
                        ? self::parseDistributionList($rawText)
                        : self::parseDrfFields($rawText);
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
                    'parse_miss' => $isDistribution
                        ? 'Scan was read but no distribution offices could be matched — add them manually.'
                        : 'Scan was read but DRF fields could not be matched — fill them in manually.',
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
            'distributionOffices' => [],
            'distributionSeeAttached' => false,
        ];
    }

    private static function hasParsedValue(array $fields): bool
    {
        return (bool) ($fields['drfNo'] || $fields['drfDate'] || $fields['drfTitle'] || $fields['sourceUnit'] || $fields['sourceOfficeId'] || ! empty($fields['sourceOffices']) || ! empty($fields['distributionOffices']));
    }

    /** Extract embedded text from the first pages (pdftotext / poppler). */
    private static function extractPdfText(string $pdfPath, int $lastPage = 2): string
    {
        $bin = self::pdftotextBinary();
        if ($bin !== null) {
            $lastPage = max(1, $lastPage);
            foreach ([
                ['-layout', '-f', '1', '-l', (string) $lastPage, $pdfPath, '-'],
                ['-raw', '-f', '1', '-l', (string) $lastPage, $pdfPath, '-'],
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
    private static function ocrPdfPagesDetailed(string $pdfPath, int $pageCap = 2, bool $stopWhenParsed = true): array
    {
        $rawText = '';
        $primaryError = null; // keep earliest real failure (never invent page-2 noise)
        $pageErrors = [];
        $stack = PdfPageRenderer::stackDiagnostics();
        $pageCount = PdfPageRenderer::pageCount($pdfPath);

        // DRF scans are almost always 1 page. Only read further pages when the
        // PDF is known to have them. Unknown count stays on page 1.
        $pageCap = max(1, $pageCap);
        if ($pageCount !== null && $pageCount >= 2) {
            $maxPages = min($pageCap, $pageCount);
        } else {
            $maxPages = 1;
        }

        Log::info('DRF OCR start', [
            'page_count' => $pageCount,
            'max_pages' => $maxPages,
            'stack' => $stack,
        ]);

        for ($page = 1; $page <= $maxPages; $page++) {
            $pageResult = self::ocrOneDrfPage($pdfPath, $page);
            if ($pageResult['text'] !== '') {
                $rawText .= ($rawText === '' ? '' : "\n") . $pageResult['text'];
                if ($stopWhenParsed) {
                    $probe = self::parseDrfFields($rawText);
                    if (self::hasParsedValue($probe)) {
                        break;
                    }
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

        $drfNo = self::valueForLabels($lines, ['Request #', 'Request No', 'Request Number', 'DRF No', 'DRF Number', 'DRF #', 'Document Request Form No']);
        if ($drfNo !== null) {
            $drfNo = trim((string) preg_replace('/\b(date|originator|document\s*title)\b.*/i', '', $drfNo), " \t:-");
            $drfNo = $drfNo !== '' ? $drfNo : null;
        }
        $drfDate = self::formDateFromLines($lines);
        $drfTitle = self::documentTitleFromLines($lines);
        $distribution = self::distributionBlock($text);
        $seeAttached = self::isAttachedListPlaceholder($distribution);
        $distributionOffices = $seeAttached ? [] : self::officesFromDistributionCodes($distribution);

        return [
            'drfNo' => $drfNo,
            'drfDate' => $drfDate,
            'drfTitle' => $drfTitle,
            'sourceUnit' => null,
            'sourceOfficeId' => null,
            'sourceOfficeCode' => null,
            'sourceOffices' => [],
            'sourceOfficeCodes' => [],
            'sourceOfficeUnmatched' => [],
            'distributionOffices' => $distributionOffices,
            'distributionSeeAttached' => $seeAttached,
        ];
    }

    /**
     * Department column of an uploaded distribution list (full office names).
     *
     * @return array<string, mixed>
     */
    private static function parseDistributionList(string $text): array
    {
        $text = self::normalizeOcrText($text);
        $fields = self::emptyFields();
        $fields['distributionOffices'] = self::matchOfficesInDistributionList($text);

        return $fields;
    }

    /** The DRF grid says to use the attached list instead of naming offices. */
    private static function isAttachedListPlaceholder(string $block): bool
    {
        $compact = strtolower((string) preg_replace('/[^a-z]+/', '', $block));
        if ($compact === '') {
            return false;
        }

        return str_contains($compact, 'pleaseseeattached')
            || str_contains($compact, 'seeattachedlist')
            || str_contains($compact, 'attachedlistofoffice')
            || (str_contains($compact, 'seeattached') && str_contains($compact, 'distributionlist'));
    }

    /**
     * Office codes written in "Distribute document to", before Prepared by.
     *
     * @return list<array{id: int, office_name: string, office_code: string}>
     */
    private static function officesFromDistributionCodes(string $block): array
    {
        $block = trim($block);
        if ($block === '' || self::isAttachedListPlaceholder($block)) {
            return [];
        }

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $block) ?: []),
            fn ($line) => $line !== ''
        ));

        return self::matchAllOfficeCodesInText($block, $lines, true);
    }

    /**
     * Read the Department column. Names may wrap onto the next line.
     *
     * @return list<array{id: int, office_name: string, office_code: string}>
     */
    private static function matchOfficesInDistributionList(string $text): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []),
            fn ($line) => $line !== '' && ! self::isDistributionNoiseLine($line)
        ));
        $offices = self::activeOffices();
        $found = [];
        $seen = [];
        $count = count($lines);

        for ($i = 0; $i < $count;) {
            $best = null;
            $consumed = 1;
            $spanLimit = min(3, $count - $i);
            for ($span = 1; $span <= $spanLimit; $span++) {
                $window = implode(' ', array_slice($lines, $i, $span));
                $match = self::bestOfficeForWindow($window, $offices, $seen);
                if ($match === null) {
                    continue;
                }
                $best = $match;
                $consumed = $span;
                break;
            }
            if ($best !== null) {
                $seen[$best['id']] = true;
                $found[] = $best;
                $i += $consumed;
                continue;
            }
            $i++;
        }

        return $found;
    }

    private static function isDistributionNoiseLine(string $line): bool
    {
        if (! preg_match('/[a-z]/i', $line)) {
            return true;
        }
        if (preg_match('/^(date|by|no\.?)$/i', $line)) {
            return true;
        }

        return (bool) preg_match(
            '/^(department|signature|copies|distribution|retrieval|effectivity\b|title of document|republic of the philippines|camarines sur|nabua|page\s+\d+|rev\.?\s*\d+)\b/i',
            $line
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $offices
     * @param  array<int, true>  $seen
     * @return array{id: int, office_name: string, office_code: string}|null
     */
    private static function bestOfficeForWindow(string $window, $offices, array $seen): ?array
    {
        $have = self::officeMatchTokens($window);
        if ($have === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($offices as $office) {
            $payload = self::officePayload($office);
            if (isset($seen[$payload['id']])) {
                continue;
            }
            $code = self::normalizeOfficeToken((string) $payload['office_code']);
            $windowCode = self::normalizeOfficeToken($window);
            if ($code !== '' && $code === $windowCode) {
                return $payload;
            }
            $need = self::officeMatchTokens((string) $payload['office_name']);
            if ($need === [] || count($have) > count($need) + 2) {
                continue;
            }
            if (! self::officeTokensMatch($need, $have)) {
                continue;
            }
            $score = count($need);
            if ($score > $bestScore) {
                $best = $payload;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private static function officeMatchTokens(string $name): array
    {
        $name = strtolower($name);
        $name = str_replace('&', ' and ', $name);
        $name = preg_replace('/\bvice\s+president\b/', 'vp', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
        $stop = ['of', 'the', 'for', 'and', 'a', 'an', 'unit', 'office', 'center', 'services', 'service', 'department'];
        $tokens = [];
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $word) {
            if ($word === '' || in_array($word, $stop, true) || strlen($word) < 2) {
                continue;
            }
            if ($word === 'administrative') {
                $word = 'administration';
            }
            if (str_ends_with($word, 's') && strlen($word) > 4 && ! str_ends_with($word, 'ss')) {
                $word = substr($word, 0, -1);
            }
            $tokens[] = $word;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $need
     * @param  list<string>  $have
     */
    private static function officeTokensMatch(array $need, array $have): bool
    {
        foreach ($need as $token) {
            $ok = false;
            foreach ($have as $candidate) {
                if ($token === $candidate) {
                    $ok = true;
                    break;
                }
                if (strlen($token) >= 8 && strlen($candidate) >= 8 && levenshtein($token, $candidate) <= 3) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** Fix common OCR glues: "DRF No:CSPC", "August7,2026", "Titletesting". */
    private static function normalizeOcrText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/(?<=\d)[Oo](?=\d)/', '0', $text) ?? $text;
        $text = preg_replace('/(?<![A-Za-z])[Il|](?=\d)/', '1', $text) ?? $text;
        $text = preg_replace('/([A-Za-z])(\d)/', '$1 $2', $text) ?? $text;
        $text = preg_replace('/(\d)([A-Za-z])/', '$1 $2', $text) ?? $text;
        $text = preg_replace('/,(\S)/', ', $1', $text) ?? $text;
        $text = preg_replace('/([:;.])(\S)/', '$1 $2', $text) ?? $text;
        // Insert space between a glued label and its value ("Titlefoo"), without
        // splitting real words such as Officer or Offices.
        $text = preg_replace('/\b(Title|No\.?|Date|Unit|Code)([a-z])/', '$1 $2', $text) ?? $text;
        $text = preg_replace('/\bOffice(?!rs?\b)([a-z])/', 'Office $1', $text) ?? $text;

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

    /**
     * DRF date is the Date beside Request #, above the signature table.
     * Effectivity Date and the signature-row Date are ignored.
     *
     * @param  list<string>  $lines
     */
    private static function formDateFromLines(array $lines): ?string
    {
        $preparedAt = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/\bprepared\s+by\b/i', $line)) {
                $preparedAt = $i;
                break;
            }
        }

        $best = null;
        $bestScore = -100;
        foreach ($lines as $i => $line) {
            if (preg_match('/effectivity\s*date/i', $line)) {
                continue;
            }
            $chunk = trim($line . ' ' . ($lines[$i + 1] ?? ''));
            $parsed = self::normalizeDate($chunk);
            if (! $parsed) {
                continue;
            }
            $score = 0;
            if ($preparedAt === null || $i < $preparedAt) {
                $score += 10;
            }
            if (preg_match('/\bdate\s*:/i', $line)) {
                $score += 5;
            }
            if (preg_match('/\b(january|february|march|april|june|july|august|september|october|november|december|januar[a-z]|februar[a-z])\b/i', $chunk)) {
                $score += 3;
            }
            if (preg_match('/\b(prepared\s+by|reviewed\s+by|approved\s+by|signature|designation)\b/i', $line)) {
                $score -= 8;
            }
            if ($preparedAt !== null && $i >= $preparedAt && $i <= $preparedAt + 8
                && ! preg_match('/\b(request|originator|document\s*title)\b/i', $line)) {
                $score -= 6;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $parsed;
            }
        }

        return $best;
    }

    /**
     * Title is the value written on the Document Title line.
     *
     * @param  list<string>  $lines
     */
    private static function documentTitleFromLines(array $lines): ?string
    {
        $labels = ['Document Title', 'Title of Document'];
        foreach ($lines as $i => $line) {
            foreach ($labels as $label) {
                if (! self::lineHasLabel($line, $label)) {
                    continue;
                }
                $value = self::cutAtNextDrfField(self::valueAfterFlexibleLabel($line, $label));
                if ($value !== '') {
                    return self::correctOcrTitle($value);
                }

                $chunks = [];
                for ($j = $i + 1; $j < count($lines) && $j <= $i + 3; $j++) {
                    if (self::isDrfFieldBoundary($lines[$j])) {
                        break;
                    }
                    $chunks[] = $lines[$j];
                }
                $joined = self::cutAtNextDrfField(trim(implode(' ', $chunks)));
                if ($joined !== '') {
                    return self::correctOcrTitle($joined);
                }
            }
        }

        return null;
    }

    /**
     * Fix single-character OCR misreads in the title (y/v, e/c, z/e) when the
     * result is a real word. Words that are already valid, and names or codes
     * with no single correction, stay as read.
     */
    private static function correctOcrTitle(string $title): string
    {
        $fixed = preg_replace_callback('/[A-Za-z]{3,}/', function (array $match): string {
            return self::correctOcrWord($match[0]);
        }, $title);

        return $fixed ?? $title;
    }

    private static function correctOcrWord(string $word): string
    {
        $lower = strtolower($word);
        if (self::isEnglishWord($lower) || preg_match('/^[A-Z]{2,8}$/', $word)) {
            return $word;
        }

        $hits = [];
        foreach (self::ocrConfusionCandidates($lower) as $candidate) {
            if (self::isEnglishWord($candidate)) {
                $hits[$candidate] = true;
            }
        }
        if (count($hits) !== 1) {
            return $word;
        }

        return self::matchWordCase($word, (string) array_key_first($hits));
    }

    /** @return list<string> */
    private static function ocrConfusionCandidates(string $word): array
    {
        $map = [
            'v' => ['y', 'u'],
            'y' => ['v'],
            'c' => ['e', 'o'],
            'e' => ['c', 'z'],
            'z' => ['e'],
            'o' => ['c'],
            'u' => ['v'],
            'l' => ['i'],
            'i' => ['l'],
            'h' => ['b'],
            'b' => ['h'],
            'g' => ['q'],
            'q' => ['g'],
        ];
        $out = [];
        $length = strlen($word);
        for ($i = 0; $i < $length; $i++) {
            $char = $word[$i];
            foreach ($map[$char] ?? [] as $replacement) {
                $out[] = substr($word, 0, $i) . $replacement . substr($word, $i + 1);
            }
        }
        foreach (['rn' => 'm', 'cl' => 'd', 'vv' => 'w'] as $from => $to) {
            $pos = 0;
            while (($found = strpos($word, $from, $pos)) !== false) {
                $out[] = substr($word, 0, $found) . $to . substr($word, $found + strlen($from));
                $pos = $found + 1;
            }
        }

        return array_values(array_unique($out));
    }

    private static function matchWordCase(string $original, string $fixed): string
    {
        if (strtoupper($original) === $original) {
            return strtoupper($fixed);
        }
        if (preg_match('/^[A-Z]/', $original)) {
            return ucfirst($fixed);
        }

        return $fixed;
    }

    private static function isEnglishWord(string $word): bool
    {
        $word = strtolower($word);
        if ($word === '') {
            return false;
        }
        $dict = self::englishWords();

        return isset($dict[$word]);
    }

    /** @return array<string, true> */
    private static function englishWords(): array
    {
        static $words = null;
        if ($words !== null) {
            return $words;
        }
        $words = [];
        $path = resource_path('ocr/english-words.txt');
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $token = strtolower(trim($line));
                if ($token !== '') {
                    $words[$token] = true;
                }
            }
        }

        return $words;
    }

    /** Offices written under "Distribute document to (department/position):". */
    private static function distributionBlock(string $text): string
    {
        if (! preg_match(
            '/distribute\s+document\s+to\b(?:\s*\([^)]{0,80}\))?\s*:?\s*(.*?)(?=\b(?:prepared\s+by|reviewed\s+by|approved\s+by|signature|effectivity\s+date)\b|$)/is',
            $text,
            $m
        )) {
            return '';
        }

        $block = trim($m[1]);
        $block = preg_replace('/^\(?\s*department\s*\/\s*position\s*\)?\s*:?\s*/i', '', $block) ?? $block;

        return trim($block);
    }

    private static function lineHasLabel(string $line, string $label): bool
    {
        $compactLine = preg_replace('/[^a-z0-9]+/i', '', strtolower($line)) ?? '';
        $compactLabel = preg_replace('/[^a-z0-9]+/i', '', strtolower($label)) ?? '';

        return $compactLabel !== '' && str_contains($compactLine, $compactLabel);
    }

    private static function valueAfterFlexibleLabel(string $line, string $label): string
    {
        $pattern = preg_replace('/\s+/', '\\s*', preg_quote($label, '/')) ?? preg_quote($label, '/');
        $parts = preg_split('/' . $pattern . '\s*[:.#\-]*\s*/i', $line, 2);
        if (! isset($parts[1])) {
            return '';
        }

        return trim($parts[1], " \t:-");
    }

    private static function isDrfFieldBoundary(string $line): bool
    {
        return (bool) preg_match(
            '/\b(type\s+of\s+document|description\s*\/?\s*reason|distribute\s+document|request\s*#|originator|prepared\s+by|effectivity\s*date)\b/i',
            $line
        );
    }

    private static function cutAtNextDrfField(string $value): string
    {
        $value = preg_replace(
            '/\b(type\s+of\s+document|description\s*\/?\s*reason|distribute\s+document\s+to|effectivity\s*date).*/i',
            '',
            $value
        ) ?? $value;

        return trim($value, " \t:-|");
    }

    private static function normalizeDate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        $raw = preg_replace('/(\d{1,2})\s*(st|nd|rd|th)\b/i', '$1', $raw) ?? $raw;
        $raw = preg_replace('/(?<=\d)[Il|](?=\d)/', '1', $raw) ?? $raw;
        $raw = preg_replace('/(?<=\s)[Il|](?=\d)/', '1', $raw) ?? $raw;
        $raw = preg_replace('/(?<=\d)[Oo](?=\d)/', '0', $raw) ?? $raw;
        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;

        $named = self::firstNamedDate($raw);
        if ($named) {
            return $named;
        }

        if (preg_match('/\b(\d{4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})\b/', $raw, $m)) {
            return self::buildDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/\b(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{2,4})\b/', $raw, $m)) {
            $year = (int) self::expandYear($m[3]);
            $first = (int) $m[1];
            $second = (int) $m[2];
            if ($first > 12 && $second <= 12) {
                return self::buildDate($year, $second, $first);
            }
            if ($second > 12 && $first <= 12) {
                return self::buildDate($year, $first, $second);
            }

            return self::buildDate($year, $second, $first) ?? self::buildDate($year, $first, $second);
        }
        if (preg_match('/\b([A-Za-z]{3,12})\.?\s+(\d{4})\b/', $raw, $m)) {
            $monthNo = self::monthNumberFuzzy($m[1]);
            if ($monthNo) {
                return self::buildDate((int) $m[2], $monthNo, 1);
            }
        }
        if (preg_match('/\b(19|20)(\d{2})(\d{2})(\d{2})\b/', $raw, $m)) {
            $built = self::buildDate((int) ($m[1] . $m[2]), (int) $m[3], (int) $m[4]);
            if ($built) {
                return $built;
            }
        }

        return null;
    }

    private static function expandYear(string $year): string
    {
        if (strlen($year) === 2) {
            $n = (int) $year;

            return (string) ($n >= 70 ? 1900 + $n : 2000 + $n);
        }

        return $year;
    }

    /** First Month Day, Year in the text, including OCR spellings such as Januarv. */
    private static function firstNamedDate(string $raw): ?string
    {
        $year = '(\d{4}|\d{2}(?!\d))';
        if (preg_match_all('/\b([A-Za-z]{3,12})\.?\s+(\d{1,2})(?!\d)\s*[.,]?\s*' . $year . '/i', $raw, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $month = self::monthNumberFuzzy($m[1]);
                if (! $month) {
                    continue;
                }
                $built = self::buildDate((int) self::expandYear($m[3]), $month, (int) $m[2]);
                if ($built) {
                    return $built;
                }
            }
        }
        if (preg_match_all('/\b(\d{1,2})(?!\d)\s+(?:of\s+)?([A-Za-z]{3,12})\.?\s*[.,]?\s*' . $year . '/i', $raw, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $month = self::monthNumberFuzzy($m[2]);
                if (! $month) {
                    continue;
                }
                $built = self::buildDate((int) self::expandYear($m[3]), $month, (int) $m[1]);
                if ($built) {
                    return $built;
                }
            }
        }
        if (preg_match_all('/\b(\d{1,2})(?!\d)[.\-\/]([A-Za-z]{3,12})\.?[.\-\/]' . $year . '/i', $raw, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $month = self::monthNumberFuzzy($m[2]);
                if (! $month) {
                    continue;
                }
                $built = self::buildDate((int) self::expandYear($m[3]), $month, (int) $m[1]);
                if ($built) {
                    return $built;
                }
            }
        }
        if (preg_match_all('/\b([A-Za-z]{3,12})\.?[.\-\/](\d{1,2})(?!\d)[.\-\/]' . $year . '/i', $raw, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $month = self::monthNumberFuzzy($m[1]);
                if (! $month) {
                    continue;
                }
                $built = self::buildDate((int) self::expandYear($m[3]), $month, (int) $m[2]);
                if ($built) {
                    return $built;
                }
            }
        }

        return null;
    }

    private static function monthNumberFuzzy(string $name): int
    {
        $exact = self::monthNumber($name);
        if ($exact) {
            return $exact;
        }
        $key = strtolower(rtrim($name, '.'));
        $key = strtr($key, ['v' => 'y', '0' => 'o', '1' => 'l']);
        $exact = self::monthNumber($key);
        if ($exact) {
            return $exact;
        }
        if (strlen($key) < 5) {
            return 0;
        }
        $best = 0;
        $bestDist = 3;
        foreach ([
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9,
            'october' => 10, 'november' => 11, 'december' => 12,
        ] as $label => $num) {
            $dist = levenshtein($key, $label);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $num;
            }
        }

        return $bestDist <= 2 ? $best : 0;
    }

    private static function monthNumber(string $name): int
    {
        $key = strtolower(rtrim($name, '.'));
        $map = [
            'january' => 1, 'jan' => 1,
            'february' => 2, 'feb' => 2,
            'march' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'may' => 5,
            'june' => 6, 'jun' => 6,
            'july' => 7, 'jul' => 7,
            'august' => 8, 'aug' => 8,
            'september' => 9, 'sept' => 9, 'sep' => 9,
            'october' => 10, 'oct' => 10,
            'november' => 11, 'nov' => 11,
            'december' => 12, 'dec' => 12,
        ];

        return $map[$key] ?? 0;
    }

    private static function buildDate(int $year, int $month, int $day): ?string
    {
        if ($year < 1900 || $year > 2100 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
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
            foreach (self::matchOfficeNamesContained($labeled) as $office) {
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
     * Match department names written inside the distribution block, longest name first.
     *
     * @return list<array{id: int, office_name: string, office_code: string}>
     */
    private static function matchOfficeNamesContained(string $text): array
    {
        $hay = self::normalizeOfficeToken($text);
        if ($hay === '') {
            return [];
        }

        $offices = self::activeOffices()
            ->sortByDesc(fn ($office) => strlen(self::normalizeOfficeToken((string) $office->office_name)))
            ->values();

        $found = [];
        $seen = [];
        foreach ($offices as $office) {
            $name = self::normalizeOfficeToken((string) $office->office_name);
            if (strlen($name) < 5 || ! str_contains($hay, $name)) {
                continue;
            }
            $payload = self::officePayload($office);
            if (isset($seen[$payload['id']])) {
                continue;
            }
            $seen[$payload['id']] = true;
            $found[] = $payload;
            $hay = str_replace($name, ' ', $hay);
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
