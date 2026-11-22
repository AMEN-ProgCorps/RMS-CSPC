<?php

/**
 * DCS file: Parses and issues document numbers. Family stacking uses this when a number changes.
 */

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Parse and issue DCS document numbers; family stacking when a number changes.
 *
 * Used by Register checkDocNo / revision family logic and number-series settings.
 *
 * DEBUG MAP:
 *   Doc no parse wrong (suffix/width) → parseDocNo()
 *   Next number wrong / gap           → issue / peek methods using series+width
 *   Family not stacking after renumber→ callers in RegisterQueryHelper lineage helpers
 */
class DocumentNumberSeriesHelper
{
    /**
     * @return array{series: string, number: int, width: int, suffix: string}|null
     */
    // =====================================================================
    // SECTION: DOC NO PARSE / ISSUE
    // =====================================================================

    /**
     * Splits a document number into series, number, width, and suffix.
     */
    public static function parseDocNo(string $docNo): ?array
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            return null;
        }

        // Letter suffix first: CSPC-LB-ACCTG-05a → series …-, number 5, suffix a.
        // Then trailing digits: 17B10 → series 17B, item 10; CSPC-LB-CCS-07 → …-, 7.
        if (preg_match('/^(.*?)(\d+)([A-Za-z]+)$/', $docNo, $m)) {
            return [
                'series' => $m[1],
                'number' => (int) $m[2],
                'width' => strlen($m[2]),
                'suffix' => $m[3],
            ];
        }
        if (preg_match('/^(.*?)(\d+)$/', $docNo, $m)) {
            return [
                'series' => $m[1],
                'number' => (int) $m[2],
                'width' => strlen($m[2]),
                'suffix' => '',
            ];
        }

        return [
            'series' => $docNo,
            'number' => null,
            'width' => 0,
            'suffix' => '',
        ];
    }

    /**
     * Helper used for series key.
     */
    public static function seriesKey(string $series): string
    {
        return mb_strtolower(rtrim($series, '-'));
    }

    /**
     * Helper used for prefix group key.
     */
    public static function prefixGroupKey(string $docNo): string
    {
        $docNo = trim($docNo);
        if ($docNo === '' || strcasecmp($docNo, 'N/A') === 0) {
            return '';
        }
        if (preg_match('/^(.+)-([^-]+)$/', $docNo, $m) && preg_match('/\d/', $m[2])) {
            return mb_strtolower($m[1]);
        }
        $parsed = self::parseDocNo($docNo);
        if (! $parsed) {
            return mb_strtolower($docNo);
        }
        $series = (string) ($parsed['series'] ?? '');
        if ($series === '' || $parsed['number'] === null) {
            return mb_strtolower($docNo);
        }

        return self::seriesKey($series);
    }

    /**
     * Helper used for prefix group label.
     */
    public static function prefixGroupLabel(string $docNo): string
    {
        $docNo = trim($docNo);
        if ($docNo === '' || strcasecmp($docNo, 'N/A') === 0) {
            return '';
        }
        if (preg_match('/^(.+)-([^-]+)$/', $docNo, $m) && preg_match('/\d/', $m[2])) {
            return rtrim($m[1], '-').'-';
        }
        $parsed = self::parseDocNo($docNo);
        if (! $parsed || $parsed['number'] === null) {
            return $docNo;
        }
        $series = (string) ($parsed['series'] ?? '');

        return $series !== '' ? $series : $docNo;
    }

    /**
     * Builds or formats data for format doc no.
     */
    public static function formatDocNo(string $series, int $number, int $width, string $suffix = ''): string
    {
        $pad = $width > 0 ? max($width, strlen((string) $number)) : 0;
        $digits = $pad > 0
            ? str_pad((string) $number, $pad, '0', STR_PAD_LEFT)
            : (string) $number;

        return $series.$digits.$suffix;
    }

    /**
     * Helper used for slot key.
     */
    public static function slotKey(?array $parsed): ?string
    {
        if (! $parsed || $parsed['number'] === null) {
            return null;
        }

        return self::seriesKey((string) $parsed['series'])
            .'|'.(int) $parsed['number']
            .'|'.mb_strtolower((string) ($parsed['suffix'] ?? ''));
    }

    /**
     * Helper used for live doc nos.
     * @return list<string>
     */
    public static function liveDocNos(int $docTypeId, ?int $subTypeId, int $excludeRequestId = 0): array
    {
        if ($docTypeId < 1 || ! Schema::hasTable('dcs_masterlist_registration')) {
            return [];
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereNotNull('ml.doc_no')
            ->whereRaw("TRIM(ml.doc_no) <> ''");

        $typeFilter = (object) [
            'doc_type_id' => $docTypeId,
            'sub_type_id' => $subTypeId,
        ];
        $requestIds = RegisterQueryHelper::requestIdsWithSameDocType($typeFilter);
        if ($requestIds === []) {
            return [];
        }
        $query->whereIn('ml.request_id', $requestIds);
        RegisterQueryHelper::applyNotDeleted($query, 'dr');

        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $query->where(function ($q) {
                $q->where('ml.revision_status', 'latest')
                    ->orWhereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '');
            });
        }
        if (Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $query->where(function ($q) {
                $q->where('dr.is_draft', false)->orWhereNull('dr.is_draft');
            });
        }
        if ($excludeRequestId > 0) {
            $query->where('ml.request_id', '!=', $excludeRequestId);
        }

        return $query->pluck('ml.doc_no')->filter()->unique()->values()->all();
    }

    /**
     * Latest series-tip document numbers only (excludes "Register another copy" rows).
     * Used so insert/shift does not treat separate same-number copies as series slots.
     *
     * @return list<string>
     */
    public static function liveSeriesTipDocNos(int $docTypeId, ?int $subTypeId, int $excludeRequestId = 0): array
    {
        if ($docTypeId < 1 || ! Schema::hasTable('dcs_masterlist_registration')) {
            return [];
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereNotNull('ml.doc_no')
            ->whereRaw("TRIM(ml.doc_no) <> ''");

        $requestIds = RegisterQueryHelper::requestIdsWithSameDocType((object) [
            'doc_type_id' => $docTypeId,
            'sub_type_id' => $subTypeId,
        ]);
        if ($requestIds === []) {
            return [];
        }
        $query->whereIn('ml.request_id', $requestIds);
        RegisterQueryHelper::applyNotDeleted($query, 'dr');

        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $query->where(function ($q) {
                $q->where('ml.revision_status', 'latest')
                    ->orWhereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '');
            });
        }
        if (Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            $query->where(function ($q) {
                $q->where('ml.allows_revision', true)->orWhereNull('ml.allows_revision');
            });
        }
        if (Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $query->where(function ($q) {
                $q->where('dr.is_draft', false)->orWhereNull('dr.is_draft');
            });
        }
        if ($excludeRequestId > 0) {
            $query->where('ml.request_id', '!=', $excludeRequestId);
        }

        return $query->pluck('ml.doc_no')->filter()->unique()->values()->all();
    }

    /**
     * Helper used for members in series.
     * @param  list<string>  $docNos
     * @return list<array{doc_no: string, series: string, number: int, width: int, suffix: string}>
     */
    public static function membersInSeries(array $docNos, string $series, bool $numericOnly = false): array
    {
        $want = self::seriesKey($series);
        $rows = [];
        foreach ($docNos as $docNo) {
            $parsed = self::parseDocNo((string) $docNo);
            if (! $parsed || $parsed['number'] === null) {
                continue;
            }
            if (self::seriesKey((string) $parsed['series']) !== $want) {
                continue;
            }
            if ($numericOnly && ($parsed['suffix'] ?? '') !== '') {
                continue;
            }
            $rows[] = [
                'doc_no' => (string) $docNo,
                'series' => (string) $parsed['series'],
                'number' => (int) $parsed['number'],
                'width' => (int) $parsed['width'],
                'suffix' => (string) ($parsed['suffix'] ?? ''),
            ];
        }

        usort($rows, fn ($a, $b) => $a['number'] <=> $b['number'] ?: strcmp($a['suffix'], $b['suffix']));

        return $rows;
    }

    /**
     * Next free number in the same series.
     * When $typed ends with a number (e.g. CSPC-F-01), returns the next free
     * sequential slot (…-02), not after the series max (…-1026).
     * When $typed is a leaf prefix without a trailing item number, appends after max.
     */
    public static function nextInSeries(string $typed, int $docTypeId, ?int $subTypeId, int $excludeRequestId = 0): ?string
    {
        $typed = trim($typed);
        if ($typed === '') {
            return null;
        }

        $parsed = self::parseDocNo($typed);
        // Typed value that ends in a digit: walk forward from that number.
        if ($parsed && $parsed['number'] !== null && preg_match('/\d$/', $typed)) {
            $allocated = self::allocateNextFreeNumbers($typed, $docTypeId, $subTypeId, $excludeRequestId, 1);

            return $allocated[0] ?? null;
        }

        // Leaf prefix (17B): append after the highest member.
        $series = $typed;
        if ($series === '') {
            return null;
        }

        $members = self::membersInSeries(
            self::liveDocNos($docTypeId, $subTypeId, $excludeRequestId),
            $series,
            true
        );
        $width = 2;
        $max = 0;
        foreach ($members as $row) {
            $max = max($max, $row['number']);
            $width = max($width, $row['width']);
        }
        if ($members === []) {
            return self::formatDocNo($series, 1, $width);
        }

        return self::formatDocNo($members[0]['series'], $max + 1, $width);
    }

    /**
     * Suggests the next document number for the Register form.
     */
    public static function suggestDocNo(Request $request): array
    {
        $docNo = trim((string) $request->input('doc_no', ''));
        $docTypeId = (int) $request->input('doc_type_id', 0);
        $subTypeId = $request->input('sub_type_id') ? (int) $request->input('sub_type_id') : null;
        $excludeRequestId = (int) $request->input('exclude_request_id', 0);

        $suggested = $docNo !== '' && $docTypeId > 0
            ? self::nextInSeries($docNo, $docTypeId, $subTypeId, $excludeRequestId)
            : null;

        return [
            'suggested' => $suggested,
            'series' => $docNo !== '' ? (self::parseDocNo($docNo)['series'] ?? $docNo) : null,
        ];
    }

    /**
     * Helper used for suggest form no.
     */
    public static function suggestFormNo(Request $request): array
    {
        $kind = strtolower(trim((string) $request->input('kind', 'drf')));
        if (! in_array($kind, ['drf', 'dcn'], true)) {
            $kind = 'drf';
        }

        $dateRaw = trim((string) $request->input('date', ''));
        try {
            $when = $dateRaw !== ''
                ? Carbon::parse($dateRaw)
                : now('Asia/Manila');
        } catch (\Throwable $e) {
            $when = now('Asia/Manila');
        }
        $year = (int) $when->year;
        $month = (int) $when->month;

        $excludeRequestId = (int) $request->input('exclude_request_id', 0);
        $labels = self::formNumbersForYear($kind, $year, $excludeRequestId);
        $suggested = $kind === 'dcn'
            ? self::nextDcnNo($labels, $year, $month)
            : self::nextDrfNo($labels, $year);

        while ($suggested !== '' && self::formNumberTaken($kind, $suggested, $excludeRequestId)) {
            $parsed = self::parseTrailingNumber($suggested);
            if (! $parsed) {
                break;
            }
            $suggested = $parsed['prefix'].str_pad(
                (string) ($parsed['number'] + 1),
                max($parsed['width'], strlen((string) ($parsed['number'] + 1))),
                '0',
                STR_PAD_LEFT
            );
        }

        return [
            'kind' => $kind,
            'year' => $year,
            'month' => $month,
            'suggested' => $suggested,
        ];
    }

    /**
     * Suggests the next DRF number.
     * @param  list<string>  $labels
     */
    public static function nextDrfNo(array $labels, int $year): string
    {
        $max = 0;
        foreach ($labels as $label) {
            if (preg_match('/^(\d{4})-(\d+)$/', trim((string) $label), $m) && (int) $m[1] === $year) {
                $max = max($max, (int) $m[2]);
            }
        }

        return $year.'-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Suggests the next DCN number.
     * @param  list<string>  $labels
     */
    public static function nextDcnNo(array $labels, int $year, int $month): string
    {
        $max = 0;
        foreach ($labels as $label) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d+)$/', trim((string) $label), $m) && (int) $m[1] === $year) {
                $max = max($max, (int) $m[3]);
            }
        }

        return $year.'-'
            .str_pad((string) $month, 2, '0', STR_PAD_LEFT).'-'
            .str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Preview: free the typed document number by renumbering selected holders only.
     * Does not cascade later series numbers (e.g. …-1024 when freeing …-01).
     *
     * @return array<string, mixed>
     */
    public static function previewInsertShift(Request $request): array
    {
        $docNo = trim((string) (
            $request->input('doc_no')
            ?: $request->input('insert_shift_doc_no')
            ?: $request->input('masterlistDocNo')
            ?: ''
        ));
        $docTypeId = (int) $request->input('doc_type_id', 0);
        $subTypeId = $request->input('sub_type_id') ? (int) $request->input('sub_type_id') : null;
        $excludeRequestId = (int) $request->input('exclude_request_id', 0);

        $nextFree = $docNo !== '' && $docTypeId > 0
            ? self::nextInSeries($docNo, $docTypeId, $subTypeId, $excludeRequestId)
            : null;

        if ($docNo === '' || $docTypeId < 1) {
            return [
                'ok' => false,
                'error' => 'Enter a document number and type first.',
                'candidates' => [],
                'shifts' => [],
                'next_free' => $nextFree,
            ];
        }

        $holders = self::exactDocNoHolders($docNo, $docTypeId, $subTypeId, $excludeRequestId);
        if ($holders === []) {
            return [
                'ok' => false,
                'error' => 'No registered document currently uses that exact number.',
                'candidates' => [],
                'shifts' => [],
                'next_free' => $nextFree,
            ];
        }

        $targets = self::allocateNextFreeNumbers($docNo, $docTypeId, $subTypeId, $excludeRequestId, count($holders));
        $candidates = [];
        foreach ($holders as $i => $row) {
            $to = $targets[$i] ?? null;
            $candidates[] = [
                'request_id' => (int) $row->request_id,
                'masterlist_id' => (int) $row->id,
                'from' => (string) $row->doc_no,
                'to' => $to,
                'title' => trim((string) ($row->doc_title ?? '')) ?: 'Untitled',
                'revise_no' => (int) ($row->revise_no ?? 0),
                'is_copy' => Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')
                    && ! (bool) ($row->allows_revision ?? true),
                // User must choose which registration(s) to renumber.
                'selected' => false,
            ];
        }

        // Back-compat for older JS that only reads shifts.
        $shifts = array_map(fn (array $c) => [
            'from' => $c['from'],
            'to' => $c['to'],
            'title' => $c['title'],
            'request_id' => $c['request_id'],
        ], $candidates);

        return [
            'ok' => true,
            'error' => null,
            'insert' => $docNo,
            'next_free' => $nextFree,
            'candidates' => $candidates,
            'shifts' => $shifts,
            // Exact free/renumber is about picking holders — date does not affect the number.
            'date_warning' => null,
            'mode' => 'exact_select',
        ];
    }

    /**
     * Free the typed document number by renumbering only the selected registrations.
     *
     * @return array{ok: bool, error?: string, mappings?: list<array{from: string, to: string}>}
     */
    public static function applyInsertShift(Request $request): array
    {
        $docNo = trim((string) (
            $request->input('insert_shift_doc_no')
            ?: $request->input('doc_no')
            ?: $request->input('masterlistDocNo')
            ?: ''
        ));
        $docTypeId = (int) $request->input('doc_type_id', 0);
        $subTypeId = $request->input('sub_type_id') ? (int) $request->input('sub_type_id') : null;
        $excludeRequestId = (int) $request->input('exclude_request_id', 0);

        $selected = $request->input('insert_shift_request_ids', $request->input('insert_shift_ids', []));
        if (is_string($selected)) {
            $selected = preg_split('/[\s,;|]+/', $selected) ?: [];
        }
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array) $selected))));

        if ($docNo === '' || $docTypeId < 1) {
            return ['ok' => false, 'error' => 'Enter a document number and type first.'];
        }
        if ($selectedIds === []) {
            return ['ok' => false, 'error' => 'Select at least one document to renumber.'];
        }

        $holders = self::exactDocNoHolders($docNo, $docTypeId, $subTypeId, $excludeRequestId);
        $byRequest = [];
        foreach ($holders as $row) {
            $byRequest[(int) $row->request_id] = $row;
        }

        $ordered = [];
        foreach ($selectedIds as $rid) {
            if (! isset($byRequest[$rid])) {
                return ['ok' => false, 'error' => 'A selected document no longer uses '.$docNo.'. Refresh and try again.'];
            }
            $ordered[] = $byRequest[$rid];
        }

        $targets = self::allocateNextFreeNumbers($docNo, $docTypeId, $subTypeId, $excludeRequestId, count($ordered));
        if (count($targets) < count($ordered)) {
            return ['ok' => false, 'error' => 'Could not allocate free document numbers for the selection.'];
        }

        $mappings = [];
        $movedIds = [];
        foreach ($ordered as $i => $row) {
            $to = $targets[$i];
            $taken = DB::table('dcs_masterlist_registration as ml')
                ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($to)])
                ->when(Schema::hasColumn('dcs_masterlist_registration', 'revision_status'), function ($q) {
                    $q->where('ml.revision_status', 'latest');
                })
                ->where('ml.id', '!=', (int) $row->id)
                ->exists();
            if ($taken) {
                return ['ok' => false, 'error' => 'Cannot renumber: "'.$to.'" is already used by another document.'];
            }

            DB::table('dcs_masterlist_registration')
                ->where('id', (int) $row->id)
                ->update([
                    'doc_no' => $to,
                    'updated_at' => now(),
                ]);
            $movedIds[] = (int) $row->id;
            $mappings[] = [
                'from' => (string) $row->doc_no,
                'to' => $to,
                'title' => trim((string) ($row->doc_title ?? '')),
                'request_id' => (int) $row->request_id,
            ];
        }

        self::separateShiftedDocuments($mappings, $movedIds);

        self::ensureShiftTable();
        if (Schema::hasTable('dcs_doc_no_shifts')) {
            $parsed = self::parseDocNo($docNo);
            $shift = [
                'series_key' => self::seriesKey((string) ($parsed['series'] ?? $docNo)),
                'inserted_doc_no' => $docNo,
                'rename_letters' => false,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('dcs_doc_no_shifts', 'mappings')) {
                $shift['mappings'] = json_encode($mappings);
            }
            $shiftId = (int) DB::table('dcs_doc_no_shifts')->insertGetId($shift);
            self::storeShiftLines($shiftId, $mappings);
        }

        $summary = collect($mappings)->map(fn ($m) => $m['from'].' → '.$m['to'])->implode(', ');
        RegisterPersistHelper::logAdminChange(
            'Freed document number '.$docNo.' by renumbering: '.$summary
        );

        return ['ok' => true, 'mappings' => $mappings];
    }

    /**
     * Registrations currently holding this exact document number (latest).
     *
     * @return list<object>
     */
    public static function exactDocNoHolders(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        int $excludeRequestId = 0
    ): array {
        $docNo = trim($docNo);
        if ($docNo === '' || $docTypeId < 1 || ! Schema::hasTable('dcs_masterlist_registration')) {
            return [];
        }

        $requestIds = RegisterQueryHelper::requestIdsWithSameDocType((object) [
            'doc_type_id' => $docTypeId,
            'sub_type_id' => $subTypeId,
        ]);
        if ($requestIds === []) {
            return [];
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $requestIds)
            ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($docNo)]);
        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $query->where(function ($q) {
                $q->where('ml.revision_status', 'latest')
                    ->orWhereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '');
            });
        }
        if (Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $query->where(function ($q) {
                $q->where('dr.is_draft', false)->orWhereNull('dr.is_draft');
            });
        }
        if ($excludeRequestId > 0) {
            $query->where('ml.request_id', '!=', $excludeRequestId);
        }

        $cols = ['ml.id', 'ml.request_id', 'ml.doc_no', 'ml.doc_title', 'ml.revise_no'];
        if (Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            $cols[] = 'ml.allows_revision';
        }

        return $query->orderBy('ml.id')->get($cols)->all();
    }

    /**
     * Next N free numbers after $docNo in the same series (e.g. …-01 → …-02).
     * Skips only numbers that are already taken; does not jump to after the series max
     * (so …-1024 elsewhere does not force …-1026).
     *
     * @return list<string>
     */
    public static function allocateNextFreeNumbers(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        int $excludeRequestId,
        int $count
    ): array {
        if ($count < 1) {
            return [];
        }
        $parsed = self::parseDocNo($docNo);
        if (! $parsed || $parsed['number'] === null) {
            return [];
        }
        $series = (string) $parsed['series'];
        // Keep the freed number's digit width (01 → 02), not the widest later tip (1024).
        $width = max(2, (int) $parsed['width']);
        $live = array_map('mb_strtolower', self::liveDocNos($docTypeId, $subTypeId, $excludeRequestId));
        $liveSet = array_fill_keys($live, true);

        $out = [];
        $n = (int) $parsed['number'];
        $guard = 0;
        while (count($out) < $count && $guard < 100000) {
            $guard++;
            $n++;
            $candidate = self::formatDocNo($series, $n, $width, '');
            if (isset($liveSet[mb_strtolower($candidate)])) {
                continue;
            }
            $out[] = $candidate;
            $liveSet[mb_strtolower($candidate)] = true;
        }

        return $out;
    }

    /**
     * Keep the family's stack when a number moves. A new stack is only for the
     * case where a different registration is still sitting on the old number.
     */
    public static function shouldReplaceShiftStack(bool $oldNumberStillOnStack): bool
    {
        return $oldNumberStillOnStack;
    }

    /**
     * A shifted registration is a different document from whatever now holds its old number.
     * Move its revision references with it, and give it a stack of its own when the old
     * stack is still used by a row that stayed behind.
     * @param  list<array{from?: string, to?: string}>  $mappings
     * @param  list<int>  $movedIds
     */
    private static function separateShiftedDocuments(array $mappings, array $movedIds): void
    {
        $movedIds = array_values(array_unique(array_filter(array_map('intval', $movedIds))));
        if ($movedIds === [] || $mappings === []) {
            return;
        }

        $fromTo = [];
        foreach ($mappings as $map) {
            $from = mb_strtolower(trim((string) ($map['from'] ?? '')));
            $to = trim((string) ($map['to'] ?? ''));
            if ($from !== '' && $to !== '') {
                $fromTo[$from] = $to;
            }
        }
        if ($fromTo === []) {
            return;
        }

        $moved = DB::table('dcs_masterlist_registration')
            ->whereIn('id', $movedIds)
            ->get(['id', 'request_id', 'doc_no', 'stack_group', 'revised_from_doc_no']);

        if (Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            foreach ($moved as $row) {
                $from = mb_strtolower(trim((string) ($row->revised_from_doc_no ?? '')));
                if ($from === '' || ! isset($fromTo[$from])) {
                    continue;
                }
                DB::table('dcs_masterlist_registration')
                    ->where('id', $row->id)
                    ->update([
                        'revised_from_doc_no' => $fromTo[$from],
                        'updated_at' => now(),
                    ]);
            }
        }

        $requestIds = $moved->pluck('request_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($requestIds !== []
            && Schema::hasTable('dcs_doc_revision')
            && Schema::hasTable('dcs_document_change_notice')) {
            $revisions = DB::table('dcs_doc_revision as r')
                ->join('dcs_document_change_notice as n', 'n.id', '=', 'r.dcn_id')
                ->whereIn('n.request_id', $requestIds)
                ->get(['r.id', 'r.document_no']);
            foreach ($revisions as $revision) {
                $from = mb_strtolower(trim((string) ($revision->document_no ?? '')));
                if ($from === '' || ! isset($fromTo[$from])) {
                    continue;
                }
                DB::table('dcs_doc_revision')
                    ->where('id', $revision->id)
                    ->update(['document_no' => $fromTo[$from]]);
            }
        }

        if (! Schema::hasColumn('dcs_masterlist_registration', 'stack_group')) {
            return;
        }

        $byGroup = [];
        foreach ($moved as $row) {
            $group = trim((string) ($row->stack_group ?? ''));
            if ($group === '') {
                continue;
            }
            $byGroup[$group][] = $row;
        }

        foreach ($byGroup as $group => $rows) {
            $oldNumbers = array_keys($fromTo);
            $stillOnOldNumber = $oldNumbers !== [] && DB::table('dcs_masterlist_registration')
                ->where('stack_group', $group)
                ->whereNotIn('id', $movedIds)
                ->where(function ($q) use ($oldNumbers) {
                    foreach ($oldNumbers as $number) {
                        $q->orWhereRaw('LOWER(TRIM(doc_no)) = ?', [$number]);
                    }
                })
                ->exists();
            if (! self::shouldReplaceShiftStack($stillOnOldNumber)) {
                continue;
            }

            $buckets = [];
            foreach ($rows as $row) {
                $buckets[mb_strtolower(trim((string) $row->doc_no))][] = (int) $row->id;
            }
            foreach ($buckets as $ids) {
                DB::table('dcs_masterlist_registration')
                    ->whereIn('id', $ids)
                    ->update([
                        'stack_group' => (string) Str::uuid(),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Builds or formats data for build shift plan.
     * @return array<string, mixed>
     */
    private static function buildShiftPlan(Request $request): array
    {
        $docNo = trim((string) (
            $request->input('doc_no')
            ?: $request->input('insert_shift_doc_no')
            ?: $request->input('masterlistDocNo')
            ?: ''
        ));
        $docTypeId = (int) $request->input('doc_type_id', 0);
        $subTypeId = $request->input('sub_type_id') ? (int) $request->input('sub_type_id') : null;
        $excludeRequestId = (int) $request->input('exclude_request_id', 0);
        $renameLetters = $request->boolean('rename_letters') || $request->boolean('insert_shift_rename_letters');

        $nextFree = $docNo !== '' && $docTypeId > 0
            ? self::nextInSeries($docNo, $docTypeId, $subTypeId, $excludeRequestId)
            : null;

        if ($docNo === '' || $docTypeId < 1) {
            return ['error' => 'Enter a document number and type first.', 'next_free' => $nextFree];
        }

        $parsed = self::parseDocNo($docNo);
        if (! $parsed || $parsed['number'] === null) {
            return ['error' => 'This document number has no numeric slot to insert into.', 'next_free' => $nextFree];
        }

        $series = (string) $parsed['series'];
        $slot = (int) $parsed['number'];
        $insertedSuffix = (string) ($parsed['suffix'] ?? '');
        // Series tips only — separate same-number copies are not shifted as a group.
        $live = self::liveSeriesTipDocNos($docTypeId, $subTypeId, $excludeRequestId);
        $numeric = self::membersInSeries($live, $series, true);
        $letters = self::membersInSeries($live, $series, false);

        $toShift = array_values(array_filter(
            $numeric,
            fn ($row) => $row['number'] >= $slot && ($row['suffix'] ?? '') === ''
        ));

        $shifts = [];
        foreach ($toShift as $row) {
            $shifts[] = [
                'from' => $row['doc_no'],
                'to' => self::formatDocNo($row['series'], $row['number'] + 1, $row['width'], ''),
                'title' => null,
            ];
        }

        foreach ($letters as $row) {
            if (($row['suffix'] ?? '') === '' || $row['number'] < $slot) {
                continue;
            }
            $sameSuffix = strcasecmp((string) $row['suffix'], $insertedSuffix) === 0;
            if (! $renameLetters && ! $sameSuffix) {
                continue;
            }
            if (! $renameLetters && $insertedSuffix === '') {
                continue;
            }
            $shifts[] = [
                'from' => $row['doc_no'],
                'to' => self::formatDocNo($row['series'], $row['number'] + 1, $row['width'], $row['suffix']),
                'title' => null,
            ];
        }

        if ($shifts === []) {
            return ['error' => 'Nothing to shift — that number is not in this series.', 'next_free' => $nextFree];
        }

        $targets = [];
        foreach ($shifts as $map) {
            $targets[mb_strtolower($map['to'])] = $map['from'];
        }
        $shiftFrom = array_map(fn ($m) => mb_strtolower($m['from']), $shifts);
        foreach ($live as $existing) {
            $lower = mb_strtolower(trim((string) $existing));
            if (isset($targets[$lower]) && ! in_array($lower, $shiftFrom, true)) {
                return [
                    'error' => 'Cannot shift: "'.$existing.'" is already used by another document.',
                    'next_free' => $nextFree,
                ];
            }
        }

        $titles = self::titlesForDocNos($docTypeId, $subTypeId, array_column($shifts, 'from'));
        foreach ($shifts as $i => $map) {
            $shifts[$i]['title'] = $titles[mb_strtolower($map['from'])] ?? null;
        }

        return [
            'error' => null,
            'insert' => $docNo,
            'series_key' => self::seriesKey($series),
            'next_free' => $nextFree,
            'allows_revision' => true,
            'rename_letters' => $renameLetters,
            'shifts' => $shifts,
        ];
    }

    /**
     * Helper used for titles for doc nos.
     * @param  list<string>  $docNos
     * @return array<string, string>
     */
    private static function titlesForDocNos(int $docTypeId, ?int $subTypeId, array $docNos): array
    {
        if ($docNos === [] || ! Schema::hasTable('dcs_masterlist_registration')) {
            return [];
        }
        $requestIds = RegisterQueryHelper::requestIdsWithSameDocType((object) [
            'doc_type_id' => $docTypeId,
            'sub_type_id' => $subTypeId,
        ]);
        $rows = DB::table('dcs_masterlist_registration')
            ->whereIn('request_id', $requestIds)
            ->whereIn('doc_no', $docNos)
            ->orderByDesc('id')
            ->get(['doc_no', 'doc_title']);

        $map = [];
        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row->doc_no));
            $title = trim((string) ($row->doc_title ?? ''));
            if (! isset($map[$key]) || ($map[$key] === '' && $title !== '')) {
                $map[$key] = $title;
            }
        }

        return $map;
    }

    /**
     * Helper used for ensure shift table.
     */
    private static function ensureShiftTable(): void
    {
        if (Schema::hasTable('dcs_doc_no_shifts')) {
            return;
        }
        try {
            Schema::create('dcs_doc_no_shifts', function ($table) {
                $table->id();
                $table->string('series_key', 150);
                $table->string('inserted_doc_no', 100);
                $table->boolean('rename_letters')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
            if (! Schema::hasTable('dcs_doc_no_shift_lines')) {
                Schema::create('dcs_doc_no_shift_lines', function ($table) {
                    $table->id();
                    $table->foreignId('shift_id')->constrained('dcs_doc_no_shifts')->cascadeOnDelete();
                    $table->unsignedSmallInteger('sort_order')->default(0);
                    $table->string('from_doc_no', 100);
                    $table->string('to_doc_no', 100);
                });
            }
        } catch (\Throwable $e) {
            // Audit is optional; the shift itself already ran.
        }
    }

    /**
     * Saves data for store shift lines.
     *
     * DEBUG: save failures / wrong DB rows → step through this write path.
     * @param  list<array{from?: string, to?: string}>  $mappings
     */
    private static function storeShiftLines(int $shiftId, array $mappings): void
    {
        if ($shiftId < 1 || $mappings === [] || ! Schema::hasTable('dcs_doc_no_shift_lines')) {
            return;
        }

        $sort = 0;
        foreach ($mappings as $map) {
            $from = trim((string) ($map['from'] ?? ''));
            $to = trim((string) ($map['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            DB::table('dcs_doc_no_shift_lines')->insert([
                'shift_id' => $shiftId,
                'sort_order' => $sort++,
                'from_doc_no' => mb_substr($from, 0, 100),
                'to_doc_no' => mb_substr($to, 0, 100),
            ]);
        }
    }

    /**
     * Helper used for form numbers for year.
     * @return list<string>
     */
    private static function formNumbersForYear(string $kind, int $year, int $excludeRequestId): array
    {
        if ($kind === 'dcn') {
            if (! Schema::hasTable('dcs_document_change_notice')) {
                return [];
            }
            $query = DB::table('dcs_document_change_notice')
                ->whereNotNull('dcn_no')
                ->whereRaw("TRIM(dcn_no) <> ''");
            if (Schema::hasColumn('dcs_document_change_notice', 'dcn_date')) {
                $query->whereYear('dcn_date', $year);
            }
            if ($excludeRequestId > 0) {
                $query->where('request_id', '!=', $excludeRequestId);
            }

            return $query->pluck('dcn_no')->filter()->values()->all();
        }

        if (! Schema::hasTable('dcs_document_request_form')) {
            return [];
        }
        $query = DB::table('dcs_document_request_form')
            ->whereNotNull('drf_no')
            ->whereRaw("TRIM(drf_no) <> ''");
        if (Schema::hasColumn('dcs_document_request_form', 'drf_date')) {
            $query->whereYear('drf_date', $year);
        }
        if ($excludeRequestId > 0) {
            $query->where('request_id', '!=', $excludeRequestId);
        }

        return $query->pluck('drf_no')->filter()->values()->all();
    }

    /**
     * Helper used for form number taken.
     */
    private static function formNumberTaken(string $kind, string $value, int $excludeRequestId): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if ($kind === 'drf') {
            return RegisterPersistHelper::drfNoTaken($value, $excludeRequestId);
        }
        if (! Schema::hasTable('dcs_document_change_notice')) {
            return false;
        }
        $query = DB::table('dcs_document_change_notice')
            ->whereRaw('LOWER(TRIM(dcn_no)) = ?', [mb_strtolower($value)]);
        if ($excludeRequestId > 0) {
            $query->where('request_id', '!=', $excludeRequestId);
        }

        return $query->exists();
    }

    /**
     * Helper used for next label from existing.
     * @param  list<string>  $labels
     */
    public static function nextLabelFromExisting(array $labels, string $defaultPrefix): string
    {
        $parsed = [];
        foreach ($labels as $label) {
            $row = self::parseTrailingNumber((string) $label);
            if ($row) {
                $parsed[] = $row;
            }
        }
        if ($parsed === []) {
            return $defaultPrefix.'001';
        }

        $counts = [];
        foreach ($parsed as $row) {
            $key = $row['prefix'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $prefix = (string) array_key_first($counts);
        $width = 3;
        $max = 0;
        foreach ($parsed as $row) {
            if ($row['prefix'] !== $prefix) {
                continue;
            }
            $max = max($max, $row['number']);
            $width = max($width, $row['width']);
        }

        return $prefix.str_pad((string) ($max + 1), max($width, strlen((string) ($max + 1))), '0', STR_PAD_LEFT);
    }

    /**
     * Parses or normalizes values used by parse trailing number.
     * @return array{prefix: string, number: int, width: int}|null
     */
    public static function parseTrailingNumber(string $label): ?array
    {
        $label = trim($label);
        if ($label === '' || ! preg_match('/^(.*?)(\d+)$/', $label, $m)) {
            return null;
        }

        return [
            'prefix' => $m[1],
            'number' => (int) $m[2],
            'width' => strlen($m[2]),
        ];
    }

    /**
     * Warn when the new document's effectivity date is not earlier than the
     * row currently sitting on the number being inserted. The date does not
     * choose the slot; the user can still confirm.
     */
    private static function insertDateWarning(Request $request, string $insertDocNo): ?string
    {
        $newDate = trim((string) $request->query('effectivity_date', ''));
        $insertDocNo = trim($insertDocNo);
        if ($newDate === '' || $insertDocNo === '' || ! Schema::hasTable('dcs_masterlist_registration')) {
            return null;
        }

        try {
            $new = Carbon::parse($newDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $query = DB::table('dcs_masterlist_registration')
            ->where('doc_no', $insertDocNo)
            ->whereNotNull('effectivity_date')
            ->orderByDesc('revise_no')
            ->orderByDesc('id');
        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $query->where(function ($q) {
                $q->where('revision_status', 'latest')->orWhereNull('revision_status')->orWhere('revision_status', '');
            });
        }
        $exclude = (int) $request->query('exclude_request_id', 0);
        if ($exclude > 0) {
            $query->where('request_id', '!=', $exclude);
        }
        $current = $query->value('effectivity_date');
        if (! $current) {
            return null;
        }

        try {
            $slot = Carbon::parse($current)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($new->lt($slot)) {
            return null;
        }

        return 'The effectivity date is not earlier than the document currently at this number ('
            . $slot->format('M j, Y')
            . '). You can still confirm. The date does not choose the number.';
    }
}
