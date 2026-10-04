<?php

namespace App\Helpers;

use App\Services\DcsNotificationService;
use App\Services\DocumentStorageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DCS Random Check — yearly June / December office visits.
 * Saved rows are snapshots. Finalized visits are never rewritten.
 */
class RandomCheckHelper
{
    public const CYCLE_JUNE = 'june';

    public const CYCLE_DECEMBER = 'december';

    public static function assertCanAccess(): void
    {
        RegisterQueryHelper::assertFullDcsUser('random_check');
    }

    public static function cycles(): array
    {
        return [self::CYCLE_JUNE, self::CYCLE_DECEMBER];
    }

    public static function cycleLabel(string $cycle): string
    {
        return self::normalizeCycle($cycle) === self::CYCLE_DECEMBER
            ? 'December Random Check'
            : 'June Random Check';
    }

    public static function cycleWindow(int $year, string $cycle): string
    {
        return self::normalizeCycle($cycle) === self::CYCLE_DECEMBER
            ? "July 1 – December 31, {$year} · target month: December"
            : "January 1 – June 30, {$year} · target month: June";
    }

    public static function normalizeCycle(?string $cycle): string
    {
        $cycle = strtolower(trim((string) $cycle));

        return $cycle === self::CYCLE_DECEMBER ? self::CYCLE_DECEMBER : self::CYCLE_JUNE;
    }

    public static function defaultCycle(?int $year = null): string
    {
        $year = $year ?: (int) now()->format('Y');
        if ($year !== (int) now()->format('Y')) {
            return self::CYCLE_JUNE;
        }

        return (int) now()->format('n') <= 6 ? self::CYCLE_JUNE : self::CYCLE_DECEMBER;
    }

    public static function noticeLeadDays(): int
    {
        return 7;
    }

    /**
     * @return list<array{year:int,label:string,june:array,december:array}>
     */
    public static function yearSummaries(): array
    {
        $current = (int) now()->format('Y');
        $years = [$current];
        if (self::hasYearColumn()) {
            $fromDb = DB::table('dcs_random_checks')
                ->whereNotNull('check_year')
                ->distinct()
                ->pluck('check_year')
                ->map(fn ($y) => (int) $y)
                ->all();
            $dateColumns = ['checked_at', 'created_at'];
            if (Schema::hasColumn('dcs_random_checks', 'check_date')) {
                $dateColumns[] = 'check_date';
            }
            $fromDates = DB::table('dcs_random_checks')
                ->whereNull('check_year')
                ->get($dateColumns)
                ->map(function ($row) {
                    $stamp = $row->check_date ?: $row->checked_at ?: $row->created_at;

                    return $stamp ? (int) date('Y', strtotime((string) $stamp)) : 0;
                })
                ->filter(fn ($y) => $y > 0)
                ->all();
            $fromSched = Schema::hasTable('dcs_random_check_schedules')
                ? DB::table('dcs_random_check_schedules')->distinct()->pluck('check_year')->map(fn ($y) => (int) $y)->all()
                : [];
            $years = array_values(array_unique(array_merge($years, $fromDb, $fromDates, $fromSched)));
        }
        rsort($years);

        $out = [];
        foreach ($years as $year) {
            $out[] = [
                'year' => $year,
                'label' => $year . ' Random Check',
                'june' => self::cycleSummary($year, self::CYCLE_JUNE),
                'december' => self::cycleSummary($year, self::CYCLE_DECEMBER),
            ];
        }

        return $out;
    }

    /**
     * @return array{scheduled:int,drafts:int,finalized:int,offices:int}
     */
    public static function cycleSummary(int $year, string $cycle): array
    {
        $cycle = self::normalizeCycle($cycle);
        $scheduled = 0;
        $drafts = 0;
        $finalized = 0;
        $officeIds = [];

        if (Schema::hasTable('dcs_random_check_schedules')) {
            $sched = DB::table('dcs_random_check_schedules')
                ->where('check_year', $year)
                ->where('cycle', $cycle)
                ->where('status', '!=', 'cancelled')
                ->get(['office_id']);
            $scheduled = $sched->count();
            foreach ($sched as $row) {
                $officeIds[(int) $row->office_id] = true;
            }
        }

        if (self::hasYearColumn()) {
            $checks = DB::table('dcs_random_checks')
                ->where(function ($q) use ($year) {
                    $q->where('check_year', $year);
                    $q->orWhere(function ($legacy) use ($year) {
                        $legacy->whereNull('check_year')->whereYear('checked_at', $year);
                    });
                    if (Schema::hasColumn('dcs_random_checks', 'check_date')) {
                        $q->orWhere(function ($legacy) use ($year) {
                            $legacy->whereNull('check_year')->whereYear('check_date', $year);
                        });
                    }
                })
                ->get(array_values(array_filter([
                    'office_id',
                    'is_draft',
                    'finalized_at',
                    Schema::hasColumn('dcs_random_checks', 'cycle') ? 'cycle' : null,
                    Schema::hasColumn('dcs_random_checks', 'check_date') ? 'check_date' : null,
                    'checked_at',
                ])));
            foreach ($checks as $row) {
                $rowCycle = trim((string) ($row->cycle ?? ''));
                if ($rowCycle === '') {
                    $stamp = $row->check_date ?: $row->checked_at;
                    $month = $stamp ? (int) date('n', strtotime((string) $stamp)) : 1;
                    $rowCycle = $month <= 6 ? self::CYCLE_JUNE : self::CYCLE_DECEMBER;
                }
                if (self::normalizeCycle($rowCycle) !== $cycle) {
                    continue;
                }
                $officeIds[(int) $row->office_id] = true;
                if (self::rowIsDraft($row)) {
                    $drafts++;
                } else {
                    $finalized++;
                }
            }
        }

        return [
            'scheduled' => $scheduled,
            'drafts' => $drafts,
            'finalized' => $finalized,
            'offices' => count($officeIds),
        ];
    }

    /**
     * Distribution offices grouped by cluster, with optional year/cycle status.
     *
     * @return list<array{cluster_id:int|null,cluster_name:string,offices:list<array<string,mixed>>}>
     */
    public static function officesByCluster(int $year = 0, string $cycle = '', bool $onlyVisited = false): array
    {
        $catalog = RegisterQueryHelper::jsCatalog();
        $clusters = collect($catalog['clusters'] ?? [])->keyBy('cluster_id');
        $distIds = self::distributionOfficeIdSet();
        $statusMap = ($year > 0 && $cycle !== '')
            ? self::officeStatusMap($year, self::normalizeCycle($cycle))
            : [];

        $grouped = [];
        foreach ($catalog['offices'] ?? [] as $office) {
            $id = (int) ($office['office_id'] ?? 0);
            if ($id < 1 || ! isset($distIds[$id])) {
                continue;
            }
            $status = $statusMap[$id] ?? null;
            if ($onlyVisited && $status === null) {
                continue;
            }
            $clusterId = $office['cluster'] ?? null;
            $clusterName = (string) ($clusters[(int) $clusterId]['cluster_name'] ?? 'Other offices');
            $grouped[$clusterName][] = array_merge([
                'office_id' => $id,
                'office_name' => (string) ($office['office_name'] ?? 'Unknown'),
                'office_code' => (string) ($office['office_code'] ?? ''),
                'cluster' => $clusterId,
                'label' => self::formatOfficeLabel($office['office_name'] ?? 'Unknown', $office['office_code'] ?? ''),
            ], $status ?? [
                'status' => 'open',
                'scheduled_date' => null,
                'check_id' => null,
                'is_draft' => false,
                'document_count' => 0,
            ]);
        }

        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
        $out = [];
        foreach ($grouped as $name => $offices) {
            usort($offices, fn ($a, $b) => strnatcasecmp($a['office_name'], $b['office_name']));
            $out[] = [
                'cluster_id' => (int) ($offices[0]['cluster'] ?? 0) ?: null,
                'cluster_name' => $name,
                'offices' => $offices,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{status:string,scheduled_date:?string,check_id:?int,is_draft:bool,document_count:int}>
     */
    public static function officeStatusMap(int $year, string $cycle): array
    {
        $cycle = self::normalizeCycle($cycle);
        $map = [];

        if (Schema::hasTable('dcs_random_check_schedules')) {
            $schedules = DB::table('dcs_random_check_schedules')
                ->where('check_year', $year)
                ->where('cycle', $cycle)
                ->where('status', '!=', 'cancelled')
                ->get();
            foreach ($schedules as $row) {
                $id = (int) $row->office_id;
                $map[$id] = [
                    'status' => 'scheduled',
                    'scheduled_date' => $row->scheduled_date
                        ? Carbon::parse($row->scheduled_date)->format('M d, Y')
                        : null,
                    'scheduled_date_raw' => $row->scheduled_date
                        ? Carbon::parse($row->scheduled_date)->format('Y-m-d')
                        : null,
                    'schedule_id' => (int) $row->id,
                    'check_id' => null,
                    'is_draft' => false,
                    'document_count' => 0,
                ];
            }
        }

        if (self::hasYearColumn()) {
            $checks = DB::table('dcs_random_checks')
                ->where('check_year', $year)
                ->where('cycle', $cycle)
                ->orderByDesc('id')
                ->get();
            foreach ($checks as $row) {
                $id = (int) $row->office_id;
                $draft = self::rowIsDraft($row);
                $existing = $map[$id] ?? [];
                $map[$id] = array_merge($existing, [
                    'status' => $draft ? 'draft' : 'finalized',
                    'check_id' => (int) $row->id,
                    'is_draft' => $draft,
                    'document_count' => (int) ($row->sample_size ?? 0),
                    'check_date' => ! empty($row->check_date)
                        ? Carbon::parse($row->check_date)->format('M d, Y')
                        : ($row->checked_at ? Carbon::parse($row->checked_at)->format('M d, Y') : null),
                ]);
            }
        }

        return $map;
    }

    public static function pickRandomOffice(int $year, string $cycle): ?array
    {
        $groups = self::officesByCluster($year, $cycle, false);
        $open = [];
        foreach ($groups as $group) {
            foreach ($group['offices'] as $office) {
                if (($office['status'] ?? 'open') === 'open') {
                    $open[] = $office;
                }
            }
        }
        if ($open === []) {
            return null;
        }

        return $open[array_rand($open)];
    }

    public static function officeLabel(int $officeId): string
    {
        $meta = self::officeMeta($officeId);

        return $meta['label'];
    }

    /**
     * @return array{office_id:int,office_name:string,office_code:string,cluster:mixed,label:string}
     */
    public static function officeMeta(int $officeId): array
    {
        $office = collect(RegisterQueryHelper::jsCatalog()['offices'] ?? [])
            ->firstWhere('office_id', $officeId);

        if (! $office) {
            $table = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $row = DB::table($table)->where('id', $officeId)->first(['office_name', 'office_code', 'cluster']);
            $name = (string) ($row->office_name ?? 'Unknown');
            $code = (string) ($row->office_code ?? '');

            return [
                'office_id' => $officeId,
                'office_name' => $name,
                'office_code' => $code,
                'cluster' => $row->cluster ?? null,
                'label' => self::formatOfficeLabel($name, $code),
            ];
        }

        return [
            'office_id' => $officeId,
            'office_name' => (string) ($office['office_name'] ?? 'Unknown'),
            'office_code' => (string) ($office['office_code'] ?? ''),
            'cluster' => $office['cluster'] ?? null,
            'label' => self::formatOfficeLabel($office['office_name'] ?? 'Unknown', $office['office_code'] ?? ''),
        ];
    }

    public static function searchOffices(string $query = '', int $limit = 25): array
    {
        $needle = mb_strtolower(trim($query));
        $out = [];
        foreach (self::officesByCluster() as $group) {
            foreach ($group['offices'] as $office) {
                if ($needle !== '') {
                    $hay = mb_strtolower($office['office_name'] . ' ' . $office['office_code']);
                    if (! str_contains($hay, $needle)) {
                        continue;
                    }
                }
                $out[] = $office;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{ok:bool,message:string,schedule_id?:int,check_id?:int,short_notice?:bool}
     */
    public static function scheduleVisit(
        int $officeId,
        int $year,
        string $cycle,
        string $scheduledDate,
        bool $notify = true,
        bool $generateList = true
    ): array {
        self::assertCanAccess();
        $cycle = self::normalizeCycle($cycle);
        if ($officeId < 1) {
            return ['ok' => false, 'message' => 'Select an office first.'];
        }
        $date = self::normalizeDate($scheduledDate);
        if ($date === null) {
            return ['ok' => false, 'message' => 'Select the visit date.'];
        }

        $shortNotice = Carbon::parse($date)->lt(now()->startOfDay()->addDays(self::noticeLeadDays()));
        $userId = (int) (Auth::id() ?? 0);
        if ($userId < 1) {
            return ['ok' => false, 'message' => 'You must be signed in to schedule a random check.'];
        }

        $existingFinal = self::findCheckRow($officeId, $year, $cycle);
        if ($existingFinal && ! self::rowIsDraft($existingFinal)) {
            return [
                'ok' => false,
                'message' => 'This office already has a finalized ' . self::cycleLabel($cycle)
                    . ' for ' . $year . '. Previous results stay locked.',
                'check_id' => (int) $existingFinal->id,
            ];
        }

        try {
            $result = DB::transaction(function () use ($officeId, $year, $cycle, $date, $userId) {
                $scheduleId = 0;
                if (Schema::hasTable('dcs_random_check_schedules')) {
                    $existing = DB::table('dcs_random_check_schedules')
                        ->where('check_year', $year)
                        ->where('cycle', $cycle)
                        ->where('office_id', $officeId)
                        ->first();
                    $payload = [
                        'check_year' => $year,
                        'cycle' => $cycle,
                        'office_id' => $officeId,
                        'scheduled_date' => $date,
                        'status' => 'scheduled',
                        'updated_at' => now(),
                    ];
                    if ($existing) {
                        DB::table('dcs_random_check_schedules')->where('id', $existing->id)->update($payload);
                        $scheduleId = (int) $existing->id;
                    } else {
                        $payload['created_by'] = $userId;
                        $payload['created_at'] = now();
                        $scheduleId = (int) DB::table('dcs_random_check_schedules')->insertGetId($payload);
                    }
                }

                return $scheduleId;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not schedule the visit: ' . $e->getMessage()];
        }

        $checkId = 0;
        if ($generateList) {
            $snap = self::generateSnapshot($officeId, $year, $cycle, $date, $result > 0 ? $result : null);
            if (! ($snap['ok'] ?? false)) {
                return $snap;
            }
            $checkId = (int) ($snap['id'] ?? 0);
        }

        $notified = false;
        if ($notify) {
            $notified = self::notifyOfficeScheduled($officeId, $year, $cycle, $date);
            if ($notified && $result > 0 && Schema::hasTable('dcs_random_check_schedules')) {
                DB::table('dcs_random_check_schedules')->where('id', $result)->update(['notified_at' => now()]);
            }
        }

        $unit = DocumentStorageService::sourceClusterOfficeForOfficeId($officeId);
        DocumentStorageService::ensureRandomCheckInventoryFolders($unit['cluster'], $unit['office_name']);
        DocumentStorageService::ensureRandomCheckResultYear($year);

        RegisterPersistHelper::logAdminChange(
            'Random check scheduled for office #' . $officeId
            . ' / ' . $year . ' ' . self::cycleLabel($cycle)
            . ' on ' . $date . '.'
        );

        $message = 'Visit scheduled for ' . Carbon::parse($date)->format('M d, Y') . '.';
        if ($generateList) {
            $message .= ' Document list is ready as a draft.';
        }
        if ($notify && $notified) {
            $message .= ' The office was notified.';
        } elseif ($notify && ! $notified) {
            $message .= ' The office could not be notified (missing office code).';
        }
        if ($shortNotice) {
            $message .= ' Date is inside the 1-week notice window.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'schedule_id' => $result,
            'check_id' => $checkId,
            'short_notice' => $shortNotice,
        ];
    }

    /**
     * Snapshot every distributed document for this office into a draft check.
     *
     * @return array{ok:bool,id?:int,message:string,pool?:int}
     */
    public static function generateSnapshot(
        int $officeId,
        int $year,
        string $cycle,
        ?string $checkDate = null,
        ?int $scheduleId = null
    ): array {
        self::assertCanAccess();
        $cycle = self::normalizeCycle($cycle);
        $existing = self::findCheckRow($officeId, $year, $cycle);
        if ($existing && ! self::rowIsDraft($existing)) {
            return [
                'ok' => false,
                'message' => 'This visit is already finalized. Previous-year results cannot be replaced.',
                'id' => (int) $existing->id,
            ];
        }

        $listed = self::listCheckRows($officeId);
        $rows = $listed['rows'];
        if ($rows === []) {
            return ['ok' => false, 'message' => 'No distributed documents were found for this office.'];
        }

        return self::saveCheck(
            $officeId,
            $rows,
            $existing ? (int) $existing->id : null,
            $listed['pool'],
            true,
            $year,
            $cycle,
            $checkDate,
            RegisterQueryHelper::currentUserDisplayName(),
            '',
            $scheduleId
        );
    }

    /**
     * Load all distributed documents for an office into editable check rows.
     *
     * @return array{pool:int,rows:list<array<string,mixed>>}
     */
    public static function listCheckRows(int $officeId, string $groupKey = 'all'): array
    {
        $pool = OfficeIntakeHelper::listDocumentsForCheck($officeId, $groupKey === '' ? 'all' : $groupKey, true);
        $rows = [];
        $itemNo = 0;
        foreach ($pool as $doc) {
            $itemNo++;
            $rows[] = [
                'masterlist_id' => (int) $doc['masterlist_id'],
                'item_no' => $itemNo,
                'doc_no' => (string) ($doc['doc_no'] ?? ''),
                'rev_no' => (int) ($doc['rev_no'] ?? 0),
                'doc_title' => (string) ($doc['doc_title'] ?? ''),
                'effectivity_date' => $doc['effectivity_date'] ?? null,
                'effectivity_date_raw' => $doc['effectivity_date_raw'] ?? null,
                'doc_type_key' => (string) ($doc['doc_type_key'] ?? ''),
                'doc_type_label' => (string) ($doc['doc_type_label'] ?? ''),
                'availability' => '',
                'remarks' => '',
                'recommended_actions' => '',
                'compliance_status' => '',
                'notes' => '',
            ];
        }

        return [
            'pool' => count($rows),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{ok:bool,id?:int,message:string}
     */
    public static function saveCheck(
        int $officeId,
        array $rows,
        ?int $existingId = null,
        ?int $poolSize = null,
        bool $asDraft = true,
        ?int $year = null,
        ?string $cycle = null,
        ?string $checkDate = null,
        ?string $conductedBy = null,
        ?string $testedBy = null,
        ?int $scheduleId = null
    ): array {
        self::assertCanAccess();

        if ($officeId < 1) {
            return ['ok' => false, 'message' => 'Select an office first.'];
        }
        if ($rows === []) {
            return ['ok' => false, 'message' => 'No documents to save for this office.'];
        }

        $userId = (int) (Auth::id() ?? 0);
        if ($userId < 1) {
            return ['ok' => false, 'message' => 'You must be signed in to save a random check.'];
        }

        $year = $year ?: (int) now()->format('Y');
        $cycle = self::normalizeCycle($cycle);
        $hasItemTypeCols = Schema::hasColumn('dcs_random_check_items', 'doc_type_key');
        $hasCompliance = Schema::hasColumn('dcs_random_check_items', 'compliance_status');
        $hasYear = self::hasYearColumn();

        $normalized = [];
        $seenMl = [];
        foreach ($rows as $i => $row) {
            $mlId = (int) ($row['masterlist_id'] ?? 0);
            $docNo = mb_substr(trim((string) ($row['doc_no'] ?? '')), 0, 255);
            if ($mlId < 1) {
                continue;
            }
            if (isset($seenMl[$mlId])) {
                continue;
            }
            $seenMl[$mlId] = true;
            $availability = strtolower(trim((string) ($row['availability'] ?? '')));
            if (! in_array($availability, ['', 'yes', 'no'], true)) {
                $availability = '';
            }
            $compliance = strtolower(trim((string) ($row['compliance_status'] ?? '')));
            if (! in_array($compliance, ['', 'complied', 'not_complied'], true)) {
                $compliance = '';
            }
            $remarks = trim((string) ($row['remarks'] ?? ''));
            $actions = trim((string) ($row['recommended_actions'] ?? ''));
            if (! $asDraft && $availability === 'yes' && $actions !== '' && $remarks === '') {
                return [
                    'ok' => false,
                    'message' => 'Add remarks for documents that are available but need recommended actions (item '
                        . ((int) ($row['item_no'] ?? ($i + 1))) . ').',
                ];
            }

            $item = [
                'masterlist_id' => $mlId,
                'item_no' => (int) ($row['item_no'] ?? ($i + 1)),
                'doc_no' => $docNo,
                'rev_no' => (int) ($row['rev_no'] ?? 0),
                'doc_title' => mb_substr((string) ($row['doc_title'] ?? ''), 0, 255),
                'effectivity_date' => self::normalizeDate($row['effectivity_date_raw'] ?? $row['effectivity_date'] ?? null),
                'availability' => $availability === '' ? null : $availability,
                'remarks' => $remarks !== '' ? $remarks : null,
                'recommended_actions' => $actions !== '' ? $actions : null,
            ];
            if ($hasCompliance) {
                $item['compliance_status'] = $compliance === '' ? null : $compliance;
                $item['notes'] = trim((string) ($row['notes'] ?? '')) ?: null;
            }
            if ($hasItemTypeCols) {
                $itemKey = trim((string) ($row['doc_type_key'] ?? ''));
                $item['doc_type_key'] = $itemKey !== '' ? mb_substr($itemKey, 0, 40) : null;
                $item['doc_type_label'] = mb_substr((string) ($row['doc_type_label'] ?? ''), 0, 80) ?: null;
            }
            $normalized[] = $item;
        }

        if ($normalized === []) {
            return ['ok' => false, 'message' => 'No valid documents to save.'];
        }

        $resolvedPool = max((int) ($poolSize ?? count($normalized)), count($normalized));
        $now = now();

        try {
            $checkId = DB::transaction(function () use (
                $officeId,
                $userId,
                $normalized,
                $existingId,
                $resolvedPool,
                $asDraft,
                $year,
                $cycle,
                $checkDate,
                $conductedBy,
                $testedBy,
                $scheduleId,
                $hasYear,
                $now
            ) {
                $row = $existingId ? DB::table('dcs_random_checks')->where('id', $existingId)->first() : null;
                if (! $row) {
                    $row = self::findCheckRow($officeId, $year, $cycle);
                }
                if ($row && ! self::rowIsDraft($row) && (int) $row->office_id === $officeId) {
                    throw new \RuntimeException('This random check is already finalized and cannot be edited.');
                }

                $payload = [
                    'office_id' => $officeId,
                    'checked_by' => $userId,
                    'sample_size' => count($normalized),
                    'pool_size' => $resolvedPool,
                    'checked_at' => $asDraft ? ($row?->checked_at ?? null) : $now,
                    'updated_at' => $now,
                    'doc_type_key' => 'all',
                ];
                if (Schema::hasColumn('dcs_random_checks', 'is_draft')) {
                    $payload['is_draft'] = $asDraft;
                }
                if (Schema::hasColumn('dcs_random_checks', 'finalized_at')) {
                    $payload['finalized_at'] = $asDraft ? null : $now;
                }
                if ($hasYear) {
                    $payload['check_year'] = $year;
                    $payload['cycle'] = $cycle;
                    $payload['check_date'] = self::normalizeDate($checkDate) ?: ($row?->check_date ?? $now->format('Y-m-d'));
                    $payload['conducted_by'] = trim((string) $conductedBy) ?: ($row?->conducted_by ?? RegisterQueryHelper::currentUserDisplayName());
                    $payload['tested_by'] = trim((string) $testedBy) ?: ($row?->tested_by ?? null);
                    if ($scheduleId && Schema::hasColumn('dcs_random_checks', 'schedule_id')) {
                        $payload['schedule_id'] = $scheduleId;
                    } elseif ($row && ! empty($row->schedule_id)) {
                        $payload['schedule_id'] = $row->schedule_id;
                    }
                }

                $checkId = (int) ($row->id ?? 0);
                if ($checkId > 0) {
                    DB::table('dcs_random_checks')->where('id', $checkId)->update($payload);
                    DB::table('dcs_random_check_items')->where('random_check_id', $checkId)->delete();
                } else {
                    $payload['created_at'] = $now;
                    $checkId = (int) DB::table('dcs_random_checks')->insertGetId($payload);
                }

                $itemRows = [];
                foreach ($normalized as $item) {
                    $itemRows[] = array_merge($item, [
                        'random_check_id' => $checkId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                foreach (array_chunk($itemRows, 100) as $chunk) {
                    DB::table('dcs_random_check_items')->insert($chunk);
                }

                return $checkId;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $unit = DocumentStorageService::sourceClusterOfficeForOfficeId($officeId);
        DocumentStorageService::ensureRandomCheckInventoryFolders($unit['cluster'], $unit['office_name']);
        DocumentStorageService::ensureRandomCheckResultYear($year);

        RegisterPersistHelper::logAdminChange(
            ($asDraft ? 'Random check draft saved' : 'Random check finalized')
            . ' for office #' . $officeId
            . ' / ' . $year . ' ' . self::cycleLabel($cycle)
            . ' (check #' . $checkId . ', ' . count($normalized) . ' items).'
        );

        return [
            'ok' => true,
            'id' => $checkId,
            'message' => $asDraft ? 'Draft saved. This visit can still be edited.' : 'Random check finalized. This result is now locked.',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function loadCheck(int $checkId): ?array
    {
        if ($checkId < 1 || ! Schema::hasTable('dcs_random_checks')) {
            return null;
        }

        $check = DB::table('dcs_random_checks')->where('id', $checkId)->first();
        if (! $check) {
            return null;
        }

        $hasItemType = Schema::hasColumn('dcs_random_check_items', 'doc_type_key');
        $hasCompliance = Schema::hasColumn('dcs_random_check_items', 'compliance_status');
        $items = DB::table('dcs_random_check_items')
            ->where('random_check_id', $checkId)
            ->orderBy('item_no')
            ->get();

        $rows = [];
        foreach ($items as $item) {
            $raw = $item->effectivity_date ?? null;
            $rows[] = [
                'id' => (int) $item->id,
                'masterlist_id' => (int) ($item->masterlist_id ?? 0),
                'item_no' => (int) $item->item_no,
                'doc_no' => (string) ($item->doc_no ?? ''),
                'rev_no' => (int) ($item->rev_no ?? 0),
                'doc_title' => (string) ($item->doc_title ?? ''),
                'effectivity_date' => $raw ? RegisterQueryHelper::formatSmartDate($raw) : null,
                'effectivity_date_raw' => $raw ? Carbon::parse($raw)->format('Y-m-d') : null,
                'doc_type_key' => $hasItemType ? (string) ($item->doc_type_key ?? '') : '',
                'doc_type_label' => $hasItemType ? (string) ($item->doc_type_label ?? '') : '',
                'availability' => (string) ($item->availability ?? ''),
                'remarks' => (string) ($item->remarks ?? ''),
                'recommended_actions' => (string) ($item->recommended_actions ?? ''),
                'compliance_status' => $hasCompliance ? (string) ($item->compliance_status ?? '') : '',
                'notes' => $hasCompliance ? (string) ($item->notes ?? '') : '',
            ];
        }

        $meta = self::officeMeta((int) $check->office_id);
        $year = (int) ($check->check_year ?? ($check->checked_at ? Carbon::parse($check->checked_at)->format('Y') : now()->format('Y')));
        $cycle = self::normalizeCycle((string) ($check->cycle ?? ''));
        $draft = self::rowIsDraft($check);

        return [
            'id' => (int) $check->id,
            'office_id' => (int) $check->office_id,
            'office_label' => $meta['label'],
            'office_name' => $meta['office_name'],
            'office_code' => $meta['office_code'],
            'year' => $year,
            'cycle' => $cycle,
            'cycle_label' => self::cycleLabel($cycle),
            'pool_size' => (int) ($check->pool_size ?? count($rows)),
            'sample_size' => (int) ($check->sample_size ?? count($rows)),
            'is_draft' => $draft,
            'locked' => ! $draft,
            'check_date' => ! empty($check->check_date)
                ? Carbon::parse($check->check_date)->format('Y-m-d')
                : ($check->checked_at ? Carbon::parse($check->checked_at)->format('Y-m-d') : ''),
            'check_date_label' => ! empty($check->check_date)
                ? Carbon::parse($check->check_date)->format('M d, Y')
                : ($check->checked_at ? Carbon::parse($check->checked_at)->format('M d, Y') : '—'),
            'conducted_by' => (string) ($check->conducted_by ?? ''),
            'tested_by' => (string) ($check->tested_by ?? ''),
            'shared_at' => ! empty($check->shared_at)
                ? Carbon::parse($check->shared_at)->format('M d, Y g:i A')
                : null,
            'checked_at' => $check->checked_at
                ? Carbon::parse($check->checked_at)->format('M d, Y g:i A')
                : '—',
            'rows' => $rows,
        ];
    }

    /**
     * Previous-year (or earlier cycle) result for the same office.
     *
     * @return array<string,mixed>|null
     */
    public static function previousCheckForOffice(int $officeId, int $year, string $cycle): ?array
    {
        if ($officeId < 1 || ! Schema::hasTable('dcs_random_checks')) {
            return null;
        }

        $cycle = self::normalizeCycle($cycle);
        $query = DB::table('dcs_random_checks')->where('office_id', $officeId);
        if (self::hasYearColumn()) {
            $query->where(function ($q) use ($year, $cycle) {
                $q->where('check_year', '<', $year);
                if ($cycle === self::CYCLE_DECEMBER) {
                    $q->orWhere(function ($inner) use ($year) {
                        $inner->where('check_year', $year)->where('cycle', self::CYCLE_JUNE);
                    });
                }
            });
            if (Schema::hasColumn('dcs_random_checks', 'is_draft')) {
                $query->where(function ($q) {
                    $q->where('is_draft', false)->orWhereNull('is_draft');
                });
            }
            $query->orderByDesc('check_year')->orderByDesc('cycle')->orderByDesc('id');
        } else {
            $query->orderByDesc('checked_at')->orderByDesc('id');
        }

        $row = $query->first();
        if (! $row) {
            return null;
        }

        return self::loadCheck((int) $row->id);
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function shareWithOffice(int $checkId, bool $excerptOnly = true): array
    {
        self::assertCanAccess();
        $check = self::loadCheck($checkId);
        if (! $check) {
            return ['ok' => false, 'message' => 'Random check not found.'];
        }
        if ($check['is_draft']) {
            return ['ok' => false, 'message' => 'Finalize the visit before sharing the result with the office.'];
        }

        $code = $check['office_code'] ?? '';
        $url = '/dcs/office/random-checks/' . $checkId;
        $cycle = $check['cycle_label'] ?? 'Random Check';
        $year = $check['year'] ?? '';
        $excerptNote = $excerptOnly
            ? ' A letter excerpt of documents with recommended actions is attached for printing. You can open the entire result in DCS.'
            : ' The entire random checking result is available in DCS.';
        $ok = DcsNotificationService::createNotification(
            (string) $code,
            "Random checking result ({$cycle} {$year}) is ready for your office.{$excerptNote}",
            $url
        );

        if ($ok && Schema::hasColumn('dcs_random_checks', 'shared_at')) {
            DB::table('dcs_random_checks')->where('id', $checkId)->update(['shared_at' => now(), 'updated_at' => now()]);
        }

        return [
            'ok' => $ok,
            'message' => $ok
                ? 'The office was notified. They can open the entire result in DCS.'
                : 'Could not notify the office (missing office code).',
        ];
    }

    /**
     * @param  'all'|'actions'  $filter
     * @return array<string,mixed>
     */
    public static function reportPayload(int $checkId, string $filter = 'all'): array
    {
        $check = self::loadCheck($checkId);
        if (! $check) {
            return [];
        }
        $rows = $check['rows'] ?? [];
        if ($filter === 'actions') {
            $rows = array_values(array_filter(
                $rows,
                fn ($row) => trim((string) ($row['recommended_actions'] ?? '')) !== ''
            ));
        }
        $check['rows'] = $rows;
        $check['filter'] = $filter === 'actions' ? 'actions' : 'all';
        $check['excerpt'] = $filter === 'actions';
        $check['action_count'] = count(array_filter(
            $check['rows'] ?? [],
            fn ($row) => trim((string) ($row['recommended_actions'] ?? '')) !== ''
        ));

        return $check;
    }

    /**
     * Office-facing: upcoming schedules + finalized results for the signed-in office.
     *
     * @return array{upcoming:list<array<string,mixed>>,results:list<array<string,mixed>>}
     */
    public static function officeInbox(?int $officeId = null): array
    {
        $officeId = $officeId ?: (int) (RegisterQueryHelper::currentOfficeId() ?? 0);
        if ($officeId < 1) {
            return ['upcoming' => [], 'results' => []];
        }

        $upcoming = [];
        if (Schema::hasTable('dcs_random_check_schedules')) {
            $rows = DB::table('dcs_random_check_schedules')
                ->where('office_id', $officeId)
                ->where('status', '!=', 'cancelled')
                ->whereDate('scheduled_date', '>=', now()->toDateString())
                ->orderBy('scheduled_date')
                ->get();
            foreach ($rows as $row) {
                $upcoming[] = [
                    'id' => (int) $row->id,
                    'year' => (int) $row->check_year,
                    'cycle' => self::normalizeCycle((string) $row->cycle),
                    'cycle_label' => self::cycleLabel((string) $row->cycle),
                    'scheduled_date' => Carbon::parse($row->scheduled_date)->format('M d, Y'),
                    'scheduled_date_raw' => Carbon::parse($row->scheduled_date)->format('Y-m-d'),
                ];
            }
        }

        $results = [];
        if (Schema::hasTable('dcs_random_checks')) {
            $query = DB::table('dcs_random_checks')->where('office_id', $officeId);
            if (Schema::hasColumn('dcs_random_checks', 'is_draft')) {
                $query->where(function ($q) {
                    $q->where('is_draft', false)->orWhereNull('is_draft');
                });
            }
            $rows = $query->orderByDesc('check_year')->orderByDesc('id')->limit(24)->get();
            foreach ($rows as $row) {
                $year = (int) ($row->check_year ?? ($row->checked_at ? Carbon::parse($row->checked_at)->format('Y') : 0));
                $cycle = self::normalizeCycle((string) ($row->cycle ?? ''));
                $results[] = [
                    'id' => (int) $row->id,
                    'year' => $year,
                    'cycle' => $cycle,
                    'cycle_label' => self::cycleLabel($cycle),
                    'label' => $year . ' ' . self::cycleLabel($cycle),
                    'check_date' => ! empty($row->check_date)
                        ? Carbon::parse($row->check_date)->format('M d, Y')
                        : ($row->checked_at ? Carbon::parse($row->checked_at)->format('M d, Y') : '—'),
                    'sample_size' => (int) ($row->sample_size ?? 0),
                ];
            }
        }

        return ['upcoming' => $upcoming, 'results' => $results];
    }

    public static function officeCanViewCheck(int $checkId, ?int $officeId = null): bool
    {
        $officeId = $officeId ?: (int) (RegisterQueryHelper::currentOfficeId() ?? 0);
        if ($officeId < 1) {
            return false;
        }
        $check = DB::table('dcs_random_checks')->where('id', $checkId)->first();
        if (! $check || (int) $check->office_id !== $officeId) {
            return false;
        }

        return ! self::rowIsDraft($check);
    }

    public static function defaultDocTypeKey(): string
    {
        return 'all';
    }

    public static function normalizeDocTypeKey(string $groupKey): string
    {
        $key = trim($groupKey);

        return $key === '' ? 'all' : $key;
    }

    public static function docTypeLabel(string $groupKey): string
    {
        $key = self::normalizeDocTypeKey($groupKey);
        if ($key === 'all') {
            return 'All distributed documents';
        }

        return OfficeIntakeHelper::documentGroupLabel($key);
    }

    public static function documentTypeGroups(int $officeId): array
    {
        return OfficeIntakeHelper::officeDocumentGroupsForCheck(
            $officeId > 0 ? $officeId : null,
            true
        );
    }

    /**
     * @return list<array{id:int,checked_at:string,sample_size:int,pool_size:int,checked_by_name:string,doc_type_key:string,doc_type_label:string}>
     */
    public static function recentChecks(int $officeId, int $limit = 10, ?string $groupKey = null): array
    {
        if ($officeId < 1 || ! Schema::hasTable('dcs_random_checks')) {
            return [];
        }

        $query = DB::table('dcs_random_checks as rc')->where('rc.office_id', $officeId);
        $checks = $query->orderByDesc('rc.checked_at')->orderByDesc('rc.id')->limit($limit)->get();
        $out = [];
        foreach ($checks as $row) {
            $year = (int) ($row->check_year ?? 0);
            $cycle = self::normalizeCycle((string) ($row->cycle ?? ''));
            $out[] = [
                'id' => (int) $row->id,
                'checked_at' => $row->checked_at
                    ? Carbon::parse($row->checked_at)->format('M d, Y g:i A')
                    : '—',
                'sample_size' => (int) ($row->sample_size ?? 0),
                'pool_size' => (int) ($row->pool_size ?? 0),
                'checked_by_name' => $year > 0 ? $year . ' ' . self::cycleLabel($cycle) : 'Saved check',
                'doc_type_key' => 'all',
                'doc_type_label' => self::docTypeLabel('all'),
            ];
        }

        return $out;
    }

    protected static function notifyOfficeScheduled(int $officeId, int $year, string $cycle, string $date): bool
    {
        $meta = self::officeMeta($officeId);
        $code = $meta['office_code'];
        if ($code === '') {
            return false;
        }
        $when = Carbon::parse($date)->format('F j, Y');
        $label = self::cycleLabel($cycle);

        return DcsNotificationService::createNotification(
            $code,
            "Document Control will conduct {$label} {$year} in your office on {$when}. Please prepare your controlled copies. You may view details in DCS.",
            '/dcs/office/random-checks'
        );
    }

    protected static function findCheckRow(int $officeId, int $year, string $cycle): ?object
    {
        if (! Schema::hasTable('dcs_random_checks')) {
            return null;
        }
        $query = DB::table('dcs_random_checks')->where('office_id', $officeId);
        if (self::hasYearColumn()) {
            $query->where('check_year', $year)->where('cycle', self::normalizeCycle($cycle));
        }

        return $query->orderByDesc('id')->first();
    }

    protected static function rowIsDraft(object $row): bool
    {
        // A visit is locked only after Finalize. Scheduling a snapshot must stay editable.
        if (Schema::hasColumn('dcs_random_checks', 'finalized_at')) {
            return empty($row->finalized_at);
        }
        if (! Schema::hasColumn('dcs_random_checks', 'is_draft')) {
            return true;
        }

        $flag = $row->is_draft ?? true;
        if (is_bool($flag)) {
            return $flag;
        }
        if (is_numeric($flag)) {
            return (int) $flag === 1;
        }
        $text = strtolower(trim((string) $flag));

        return in_array($text, ['1', 't', 'true', 'yes'], true);
    }

    protected static function hasYearColumn(): bool
    {
        return Schema::hasTable('dcs_random_checks')
            && Schema::hasColumn('dcs_random_checks', 'check_year');
    }

    /** @return array<int,true> */
    protected static function distributionOfficeIdSet(): array
    {
        if (! Schema::hasTable('dcs_distribution_offices')) {
            return [];
        }

        return DB::table('dcs_distribution_offices')
            ->whereNotNull('office_id')
            ->distinct()
            ->pluck('office_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    protected static function formatOfficeLabel(string $name, string $code): string
    {
        $name = trim($name) !== '' ? $name : 'Unknown';
        $code = trim($code);

        return $code !== '' ? "{$name} ({$code})" : $name;
    }

    protected static function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
