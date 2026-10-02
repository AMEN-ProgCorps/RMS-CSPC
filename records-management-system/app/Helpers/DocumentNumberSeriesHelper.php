<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DocumentNumberSeriesHelper
{
    /**
     * @return array{series: string, number: int, width: int, suffix: string}|null
     */
    public static function parseDocNo(string $docNo): ?array
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            return null;
        }

        // 17B10 → series 17B, item 10. 11A → series …-, item 11, suffix A.
        if (preg_match('/^(.*?)(\d+)$/', $docNo, $m)) {
            return [
                'series' => $m[1],
                'number' => (int) $m[2],
                'width' => strlen($m[2]),
                'suffix' => '',
            ];
        }
        if (preg_match('/^(.*?)(\d+)([A-Za-z]+)$/', $docNo, $m)) {
            return [
                'series' => $m[1],
                'number' => (int) $m[2],
                'width' => strlen($m[2]),
                'suffix' => $m[3],
            ];
        }

        return [
            'series' => $docNo,
            'number' => null,
            'width' => 0,
            'suffix' => '',
        ];
    }

    public static function seriesKey(string $series): string
    {
        return mb_strtolower(rtrim($series, '-'));
    }

    public static function formatDocNo(string $series, int $number, int $width, string $suffix = ''): string
    {
        $pad = $width > 0 ? max($width, strlen((string) $number)) : 0;
        $digits = $pad > 0
            ? str_pad((string) $number, $pad, '0', STR_PAD_LEFT)
            : (string) $number;

        return $series.$digits.$suffix;
    }

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
            $query->whereIn('ml.revision_status', ['latest', 'obsolete']);
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

    public static function nextInSeries(string $typed, int $docTypeId, ?int $subTypeId, int $excludeRequestId = 0): ?string
    {
        $typed = trim($typed);
        if ($typed === '') {
            return null;
        }

        $parsed = self::parseDocNo($typed);
        // Typed value that does not end in a digit is a leaf prefix (17B), not item 17B.
        $series = preg_match('/\d$/', $typed)
            ? (string) ($parsed['series'] ?? $typed)
            : $typed;
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
            if ($parsed && $parsed['number'] !== null) {
                $width = max(2, (int) $parsed['width']);
            }

            return self::formatDocNo(
                $parsed && $parsed['number'] !== null ? (string) $parsed['series'] : $series,
                $max + 1,
                $width
            );
        }

        $seriesOut = $members[0]['series'];

        return self::formatDocNo($seriesOut, $max + 1, $width);
    }

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
     * DRF: YYYY-001, YYYY-002, … Only official YYYY-NNN values count (not dates or doc nos).
     *
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
     * DCN: YYYY-MM-046. Month is the form date; last part is the year-wide DCN count.
     *
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
     * @return list<array{from: string, to: string, title: ?string}>
     */
    public static function previewInsertShift(Request $request): array
    {
        $plan = self::buildShiftPlan($request);
        if (! empty($plan['error'])) {
            return [
                'ok' => false,
                'error' => $plan['error'],
                'shifts' => [],
                'next_free' => $plan['next_free'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'insert' => $plan['insert'],
            'next_free' => $plan['next_free'],
            'allows_revision' => $plan['allows_revision'],
            'shifts' => $plan['shifts'],
            'date_warning' => self::insertDateWarning($request, (string) ($plan['insert'] ?? '')),
        ];
    }

    /**
     * @return array{ok: bool, error?: string, mappings?: list<array{from: string, to: string}>}
     */
    public static function applyInsertShift(Request $request): array
    {
        $plan = self::buildShiftPlan($request);
        if (! empty($plan['error'])) {
            return ['ok' => false, 'error' => $plan['error']];
        }

        $docTypeId = (int) $request->input('doc_type_id', 0);
        $subTypeId = $request->input('sub_type_id') ? (int) $request->input('sub_type_id') : null;
        $typeFilter = (object) ['doc_type_id' => $docTypeId, 'sub_type_id' => $subTypeId];
        $requestIds = RegisterQueryHelper::requestIdsWithSameDocType($typeFilter);

        $mappings = $plan['shifts'];
        usort($mappings, function ($a, $b) {
            $pa = self::parseDocNo($a['from']);
            $pb = self::parseDocNo($b['from']);

            return ((int) ($pb['number'] ?? 0)) <=> ((int) ($pa['number'] ?? 0));
        });

        $movedIds = [];
        foreach ($mappings as $map) {
            $from = (string) $map['from'];
            $to = (string) $map['to'];
            $ids = DB::table('dcs_masterlist_registration as ml')
                ->whereIn('ml.request_id', $requestIds)
                ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower(trim($from))])
                ->when(Schema::hasColumn('dcs_masterlist_registration', 'revision_status'), function ($q) {
                    $q->whereIn('ml.revision_status', ['latest', 'obsolete']);
                })
                ->lockForUpdate()
                ->pluck('ml.id');

            if ($ids->isEmpty()) {
                continue;
            }

            $taken = DB::table('dcs_masterlist_registration as ml')
                ->whereIn('ml.request_id', $requestIds)
                ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower(trim($to))])
                ->when(Schema::hasColumn('dcs_masterlist_registration', 'revision_status'), function ($q) {
                    $q->where('ml.revision_status', 'latest');
                })
                ->whereNotIn('ml.id', $ids)
                ->exists();
            if ($taken) {
                return ['ok' => false, 'error' => 'Cannot shift: "'.$to.'" is already used by another document.'];
            }

            DB::table('dcs_masterlist_registration')
                ->whereIn('id', $ids)
                ->update([
                    'doc_no' => $to,
                    'updated_at' => now(),
                ]);
            foreach ($ids as $id) {
                $movedIds[] = (int) $id;
            }
        }

        self::separateShiftedDocuments($mappings, $movedIds);

        self::ensureShiftTable();
        if (Schema::hasTable('dcs_doc_no_shifts')) {
            DB::table('dcs_doc_no_shifts')->insert([
                'series_key' => $plan['series_key'],
                'inserted_doc_no' => $plan['insert'],
                'mappings' => json_encode($mappings),
                'rename_letters' => ! empty($plan['rename_letters']),
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $summary = collect($mappings)->map(fn ($m) => $m['from'].' → '.$m['to'])->implode(', ');
        RegisterPersistHelper::logAdminChange(
            'Inserted document number '.$plan['insert'].' and shifted: '.$summary
        );

        return ['ok' => true, 'mappings' => $mappings];
    }

    /**
     * A shifted registration is a different document from whatever now holds its old number.
     * Move its revision references with it, and give it a stack of its own when the old
     * stack is still used by a row that stayed behind.
     *
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
            $stillShared = DB::table('dcs_masterlist_registration')
                ->where('stack_group', $group)
                ->whereNotIn('id', $movedIds)
                ->exists();
            $destinations = [];
            foreach ($rows as $row) {
                $destinations[mb_strtolower(trim((string) $row->doc_no))] = true;
            }
            if (! $stillShared && count($destinations) < 2) {
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
        $live = self::liveDocNos($docTypeId, $subTypeId, $excludeRequestId);
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
                $table->json('mappings');
                $table->boolean('rename_letters')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            // Audit is optional; the shift itself already ran.
        }
    }

    /**
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
