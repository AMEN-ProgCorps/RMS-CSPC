<?php

namespace App\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DCS Random Check — verify documents distributed to an office.
 */
class RandomCheckHelper
{
    public static function assertCanAccess(): void
    {
        RegisterQueryHelper::assertFullDcsUser('random_check');
    }

    public static function defaultDocTypeKey(): string
    {
        $keys = array_keys(OfficeIntakeHelper::documentGroupDefs());

        return $keys[0] ?? 'internal_docs';
    }

    /**
     * Offices that appear on Document Distribution (recipients only).
     *
     * @return list<array{office_id:int,office_name:string,office_code:string,cluster:mixed,label:string}>
     */
    public static function searchOffices(string $query = '', int $limit = 25): array
    {
        if (! Schema::hasTable('dcs_distribution_offices')) {
            return [];
        }

        $distOfficeIds = DB::table('dcs_distribution_offices')
            ->whereNotNull('office_id')
            ->distinct()
            ->pluck('office_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($distOfficeIds === []) {
            return [];
        }

        $idSet = array_flip($distOfficeIds);
        $q = trim($query);
        $offices = collect(RegisterQueryHelper::jsCatalog()['offices'] ?? [])
            ->filter(fn ($o) => isset($idSet[(int) ($o['office_id'] ?? 0)]));

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $offices = $offices->filter(function ($o) use ($needle) {
                $name = mb_strtolower((string) ($o['office_name'] ?? ''));
                $code = mb_strtolower((string) ($o['office_code'] ?? ''));

                return str_contains($name, $needle) || str_contains($code, $needle);
            });
        }

        return $offices
            ->take(max(1, $limit))
            ->map(function ($o) {
                $name = (string) ($o['office_name'] ?? 'Unknown');
                $code = (string) ($o['office_code'] ?? '');

                return [
                    'office_id' => (int) ($o['office_id'] ?? 0),
                    'office_name' => $name,
                    'office_code' => $code,
                    'cluster' => $o['cluster'] ?? null,
                    'label' => $code !== '' ? "{$name} ({$code})" : $name,
                ];
            })
            ->values()
            ->all();
    }

    public static function officeLabel(int $officeId): string
    {
        $office = collect(RegisterQueryHelper::jsCatalog()['offices'] ?? [])
            ->firstWhere('office_id', $officeId);

        if (! $office) {
            $table = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $row = DB::table($table)->where('id', $officeId)->first(['office_name', 'office_code']);
            if (! $row) {
                return 'Office #' . $officeId;
            }
            $name = (string) ($row->office_name ?? 'Unknown');
            $code = (string) ($row->office_code ?? '');

            return $code !== '' ? "{$name} ({$code})" : $name;
        }

        $name = (string) ($office['office_name'] ?? 'Unknown');
        $code = (string) ($office['office_code'] ?? '');

        return $code !== '' ? "{$name} ({$code})" : $name;
    }

    public static function normalizeDocTypeKey(string $groupKey): string
    {
        $key = trim($groupKey);
        if ($key === '' || $key === 'all') {
            return self::defaultDocTypeKey();
        }

        return isset(OfficeIntakeHelper::documentGroupDefs()[$key])
            ? $key
            : self::defaultDocTypeKey();
    }

    public static function docTypeLabel(string $groupKey): string
    {
        return OfficeIntakeHelper::documentGroupLabel(self::normalizeDocTypeKey($groupKey));
    }

    /**
     * @return list<array{key:string,label:string,count:int}>
     */
    public static function documentTypeGroups(int $officeId): array
    {
        return OfficeIntakeHelper::officeDocumentGroupsForCheck(
            $officeId > 0 ? $officeId : null,
            true
        );
    }

    /**
     * Load all distributed documents for an office + type into editable check rows.
     *
     * @return array{pool:int,rows:list<array<string,mixed>>,doc_type_key:string,doc_type_label:string}
     */
    public static function listCheckRows(int $officeId, string $groupKey): array
    {
        $groupKey = self::normalizeDocTypeKey($groupKey);
        $pool = OfficeIntakeHelper::listDocumentsForCheck($officeId, $groupKey, true);
        $typeLabel = self::docTypeLabel($groupKey);

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
                'doc_type_key' => (string) ($doc['doc_type_key'] ?? $groupKey),
                'doc_type_label' => (string) ($doc['doc_type_label'] ?? $typeLabel),
                'availability' => '',
                'remarks' => '',
                'recommended_actions' => '',
            ];
        }

        return [
            'pool' => count($rows),
            'rows' => $rows,
            'doc_type_key' => $groupKey,
            'doc_type_label' => $typeLabel,
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
        string $groupKey = 'all'
    ): array {
        self::assertCanAccess();

        if ($officeId < 1) {
            return ['ok' => false, 'message' => 'Select an office first.'];
        }

        if ($rows === []) {
            return ['ok' => false, 'message' => 'No documents to save for this office and type.'];
        }

        $userId = (int) (Auth::id() ?? 0);
        if ($userId < 1) {
            return ['ok' => false, 'message' => 'You must be signed in to save a random check.'];
        }

        $groupKey = self::normalizeDocTypeKey($groupKey);
        $hasItemTypeCols = Schema::hasColumn('dcs_random_check_items', 'doc_type_key');
        $hasCheckTypeCol = Schema::hasColumn('dcs_random_checks', 'doc_type_key');

        $normalized = [];
        foreach ($rows as $i => $row) {
            $mlId = (int) ($row['masterlist_id'] ?? 0);
            if ($mlId < 1) {
                continue;
            }
            $availability = strtolower(trim((string) ($row['availability'] ?? '')));
            if (! in_array($availability, ['', 'yes', 'no'], true)) {
                $availability = '';
            }

            $item = [
                'masterlist_id' => $mlId,
                'item_no' => (int) ($row['item_no'] ?? ($i + 1)),
                'doc_no' => mb_substr((string) ($row['doc_no'] ?? ''), 0, 255),
                'rev_no' => (int) ($row['rev_no'] ?? 0),
                'doc_title' => mb_substr((string) ($row['doc_title'] ?? ''), 0, 255),
                'effectivity_date' => self::normalizeDate($row['effectivity_date_raw'] ?? $row['effectivity_date'] ?? null),
                'availability' => $availability === '' ? null : $availability,
                'remarks' => trim((string) ($row['remarks'] ?? '')) ?: null,
                'recommended_actions' => trim((string) ($row['recommended_actions'] ?? '')) ?: null,
            ];

            if ($hasItemTypeCols) {
                $itemKey = self::normalizeDocTypeKey((string) ($row['doc_type_key'] ?? $groupKey));
                $item['doc_type_key'] = $itemKey === 'all' ? null : $itemKey;
                $item['doc_type_label'] = mb_substr(
                    (string) ($row['doc_type_label'] ?? self::docTypeLabel($itemKey)),
                    0,
                    80
                ) ?: null;
            }

            $normalized[] = $item;
        }

        if ($normalized === []) {
            return ['ok' => false, 'message' => 'No valid documents to save.'];
        }

        $resolvedPool = max((int) ($poolSize ?? count($normalized)), count($normalized));

        try {
            $checkId = DB::transaction(function () use (
                $officeId,
                $userId,
                $normalized,
                $existingId,
                $resolvedPool,
                $groupKey,
                $hasCheckTypeCol
            ) {
                $now = now();
                $payload = [
                    'office_id' => $officeId,
                    'checked_by' => $userId,
                    'sample_size' => count($normalized),
                    'pool_size' => $resolvedPool,
                    'checked_at' => $now,
                    'updated_at' => $now,
                ];
                if ($hasCheckTypeCol) {
                    $payload['doc_type_key'] = $groupKey;
                }

                $checkId = (int) ($existingId ?? 0);
                if ($checkId > 0 && ! DB::table('dcs_random_checks')->where('id', $checkId)->exists()) {
                    $checkId = 0;
                }

                // One active check per office + type + user — re-save updates instead of duplicating.
                if ($checkId < 1) {
                    $reuse = DB::table('dcs_random_checks')
                        ->where('office_id', $officeId)
                        ->where('checked_by', $userId);
                    if ($hasCheckTypeCol) {
                        $reuse->where('doc_type_key', $groupKey);
                    }
                    $checkId = (int) ($reuse->orderByDesc('checked_at')->orderByDesc('id')->value('id') ?? 0);
                }

                if ($checkId > 0) {
                    DB::table('dcs_random_checks')->where('id', $checkId)->update($payload);
                    DB::table('dcs_random_check_items')->where('random_check_id', $checkId)->delete();
                } else {
                    $payload['created_at'] = $now;
                    $checkId = (int) DB::table('dcs_random_checks')->insertGetId($payload);
                }

                $itemRows = [];
                foreach ($normalized as $row) {
                    $itemRows[] = array_merge($row, [
                        'random_check_id' => $checkId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                DB::table('dcs_random_check_items')->insert($itemRows);

                return $checkId;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not save random check: ' . $e->getMessage()];
        }

        RegisterPersistHelper::logAdminChange(
            'Random check saved for office #' . $officeId
            . ' / ' . self::docTypeLabel($groupKey)
            . ' (check #' . $checkId . ', ' . count($normalized) . ' items).'
        );

        return [
            'ok' => true,
            'id' => $checkId,
            'message' => 'Random check saved.',
        ];
    }

    /**
     * @return list<array{id:int,checked_at:string,sample_size:int,pool_size:int,checked_by_name:string,doc_type_key:string,doc_type_label:string}>
     */
    public static function recentChecks(int $officeId, int $limit = 10, ?string $groupKey = null): array
    {
        if ($officeId < 1 || ! Schema::hasTable('dcs_random_checks')) {
            return [];
        }

        $details = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $hasType = Schema::hasColumn('dcs_random_checks', 'doc_type_key');

        $query = DB::table('dcs_random_checks as rc')
            ->where('rc.office_id', $officeId);

        if ($hasType && $groupKey !== null && $groupKey !== '') {
            $query->where('rc.doc_type_key', self::normalizeDocTypeKey($groupKey));
        }

        $select = [
            'rc.id',
            'rc.checked_at',
            'rc.sample_size',
            'rc.pool_size',
            'rc.checked_by',
        ];
        if ($hasType) {
            $select[] = 'rc.doc_type_key';
        }

        $checks = $query
            ->orderByDesc('rc.checked_at')
            ->orderByDesc('rc.id')
            ->limit($limit)
            ->get($select);

        $userIds = $checks->pluck('checked_by')->map(fn ($id) => (int) $id)->unique()->filter()->values()->all();
        $names = [];
        if ($userIds !== [] && Schema::hasTable($details)) {
            $nameRows = DB::table($details)
                ->whereIn('account_id', $userIds)
                ->get(['account_id', 'first_name', 'last_name']);
            foreach ($nameRows as $row) {
                $id = (int) $row->account_id;
                if (isset($names[$id])) {
                    continue;
                }
                $names[$id] = trim(((string) ($row->first_name ?? '')) . ' ' . ((string) ($row->last_name ?? '')));
            }
        }

        $seen = [];
        $out = [];
        foreach ($checks as $row) {
            $id = (int) $row->id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $userId = (int) ($row->checked_by ?? 0);
            $typeKey = $hasType
                ? self::normalizeDocTypeKey((string) ($row->doc_type_key ?? ''))
                : self::defaultDocTypeKey();

            // One visible entry per user + document type (matches upsert save behavior).
            $dedupeKey = $userId . ':' . $typeKey;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $name = trim((string) ($names[$userId] ?? ''));
            if ($name === '') {
                $name = 'User #' . $userId;
            }

            $out[] = [
                'id' => $id,
                'checked_at' => $row->checked_at
                    ? Carbon::parse($row->checked_at)->format('M d, Y g:i A')
                    : '—',
                'sample_size' => (int) ($row->sample_size ?? 0),
                'pool_size' => (int) ($row->pool_size ?? 0),
                'checked_by_name' => $name,
                'doc_type_key' => $typeKey,
                'doc_type_label' => self::docTypeLabel($typeKey),
            ];
        }

        return $out;
    }

    /**
     * @return array{id:int,office_id:int,office_label:string,pool_size:int,sample_size:int,checked_at:string,doc_type_key:string,doc_type_label:string,rows:list<array<string,mixed>>}|null
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
        $typeKey = Schema::hasColumn('dcs_random_checks', 'doc_type_key')
            ? self::normalizeDocTypeKey((string) ($check->doc_type_key ?? 'all'))
            : 'all';

        $items = DB::table('dcs_random_check_items')
            ->where('random_check_id', $checkId)
            ->orderBy('item_no')
            ->get();

        $rows = [];
        foreach ($items as $item) {
            $raw = $item->effectivity_date ?? null;
            $itemKey = $hasItemType
                ? self::normalizeDocTypeKey((string) ($item->doc_type_key ?? $typeKey))
                : $typeKey;
            $itemLabel = $hasItemType
                ? ((string) ($item->doc_type_label ?? '') ?: self::docTypeLabel($itemKey))
                : self::docTypeLabel($itemKey);

            $rows[] = [
                'id' => (int) $item->id,
                'masterlist_id' => (int) $item->masterlist_id,
                'item_no' => (int) $item->item_no,
                'doc_no' => (string) ($item->doc_no ?? ''),
                'rev_no' => (int) ($item->rev_no ?? 0),
                'doc_title' => (string) ($item->doc_title ?? ''),
                'effectivity_date' => $raw ? Carbon::parse($raw)->format('M d, Y') : null,
                'effectivity_date_raw' => $raw ? Carbon::parse($raw)->format('Y-m-d') : null,
                'doc_type_key' => $itemKey,
                'doc_type_label' => $itemLabel,
                'availability' => (string) ($item->availability ?? ''),
                'remarks' => (string) ($item->remarks ?? ''),
                'recommended_actions' => (string) ($item->recommended_actions ?? ''),
            ];
        }

        return [
            'id' => (int) $check->id,
            'office_id' => (int) $check->office_id,
            'office_label' => self::officeLabel((int) $check->office_id),
            'pool_size' => (int) ($check->pool_size ?? count($rows)),
            'sample_size' => (int) ($check->sample_size ?? count($rows)),
            'checked_at' => $check->checked_at
                ? Carbon::parse($check->checked_at)->format('M d, Y g:i A')
                : '—',
            'doc_type_key' => $typeKey,
            'doc_type_label' => self::docTypeLabel($typeKey),
            'rows' => $rows,
        ];
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
