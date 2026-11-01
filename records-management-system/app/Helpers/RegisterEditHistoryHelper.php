<?php

namespace App\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field-level edit history for DCS registrations (registrars can view).
 */
class RegisterEditHistoryHelper
{
    /** @var array<string, string> */
    private const FIELD_LABELS = [
        'doc_no' => 'Document No.',
        'doc_title' => 'Document Title',
        'revise_no' => 'Revision No.',
        'doc_receipt_date' => 'Receipt Date',
        'doc_receipt_time' => 'Receipt Time',
        'doc_registered_date' => 'Registered Date',
        'doc_registered_time' => 'Registered Time',
        'effectivity_date' => 'Effectivity Date',
        'no_pages' => 'No. of Pages',
        'originator' => 'Originator',
        'keywords' => 'Keywords',
        'deadline' => 'Deadline',
        'time_spent' => 'Time Spent (mins)',
        'scanned_masterlist' => 'Scanned Masterlist',
        'source_offices' => 'Source Offices',
        'distribution_offices' => 'Distribution Offices',
    ];

    public static function tableReady(): bool
    {
        return Schema::hasTable('dcs_document_edit_history');
    }

    /**
     * Snapshot of tracked values for a registration.
     *
     * @return array<string, string>
     */
    public static function snapshot(int $requestId): array
    {
        if ($requestId < 1) {
            return [];
        }

        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
        if (! $ml) {
            return [];
        }

        $originator = '';
        if (Schema::hasColumn('dcs_masterlist_registration', 'originator_name')) {
            $originator = trim((string) ($ml->originator_name ?? ''));
        }
        if ($originator === '' && ! empty($ml->originator_id) && Schema::hasTable('dcs_originators')) {
            $originator = trim((string) (DB::table('dcs_originators')->where('id', $ml->originator_id)->value('originator_name') ?? ''));
        }

        $keywords = '';
        if (Schema::hasColumn('dcs_masterlist_registration', 'keywords')) {
            $keywords = trim((string) ($ml->keywords ?? ''));
        }

        return [
            'doc_no' => self::norm((string) ($ml->doc_no ?? '')),
            'doc_title' => self::norm((string) ($ml->doc_title ?? '')),
            'revise_no' => self::norm((string) ($ml->revise_no ?? '0')),
            'doc_receipt_date' => self::normDate($ml->doc_receipt_date ?? null),
            'doc_receipt_time' => self::normTime($ml->doc_receipt_time ?? null),
            'doc_registered_date' => self::normDate($ml->doc_registered_date ?? null),
            'doc_registered_time' => self::normTime($ml->doc_registered_time ?? null),
            'effectivity_date' => self::normDate($ml->effectivity_date ?? null),
            'no_pages' => self::norm((string) ($ml->no_pages ?? '')),
            'originator' => self::norm($originator),
            'keywords' => self::norm($keywords),
            'deadline' => self::normDate($ml->deadline ?? null),
            'time_spent' => self::norm((string) ($ml->time_spent ?? '')),
            'scanned_masterlist' => self::norm((string) ($ml->scanned_masterlist ?? '')),
            'source_offices' => self::sourceOfficesLabel((int) $ml->id),
            'distribution_offices' => self::distributionOfficesLabel($requestId),
        ];
    }

    /**
     * Diff two snapshots and persist a history row when anything changed.
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    public static function record(int $requestId, array $before, array $after, string $action = 'update'): void
    {
        if (! self::tableReady() || $requestId < 1) {
            return;
        }

        $changes = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            $old = self::norm((string) ($before[$field] ?? ''));
            $new = self::norm((string) ($after[$field] ?? ''));
            if ($old === $new) {
                continue;
            }

            if ($field === 'distribution_offices' || $field === 'source_offices') {
                $officeChanges = self::officeListChanges($field, $label, $old, $new);
                if ($officeChanges !== []) {
                    array_push($changes, ...$officeChanges);
                }
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'old' => $old === '' ? '—' : $old,
                'new' => $new === '' ? '—' : $new,
            ];
        }

        if ($changes === []) {
            return;
        }

        try {
            DB::table('dcs_document_edit_history')->insert([
                'request_id' => $requestId,
                'user_id' => Auth::id(),
                'action' => in_array($action, ['update', 'register', 'draft'], true) ? $action : 'update',
                'changes' => json_encode($changes),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Non-fatal — registration must not fail because history logging failed.
        }
    }

    /**
     * Baseline entry when a document is first registered (no prior snapshot).
     *
     * @param  array<string, string>  $after
     */
    public static function recordBaseline(int $requestId, array $after, string $action = 'register'): void
    {
        if (! self::tableReady() || $requestId < 1 || $after === []) {
            return;
        }

        $changes = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            $new = self::norm((string) ($after[$field] ?? ''));
            if ($new === '') {
                continue;
            }
            $changes[] = [
                'field' => $field,
                'label' => $label,
                'old' => '—',
                'new' => $new,
            ];
        }

        if ($changes === []) {
            return;
        }

        try {
            DB::table('dcs_document_edit_history')->insert([
                'request_id' => $requestId,
                'user_id' => Auth::id(),
                'action' => $action === 'draft' ? 'draft' : 'register',
                'changes' => json_encode($changes),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * @return list<array{id:int,request_id:int,action:string,user_name:string,edited_by:string,created_at:string,created_at_raw:string,changes:list<array{field:string,label:string,old:string,new:string}>}>
     */
    public static function listForRequest(int $requestId): array
    {
        return self::listForRequests([$requestId], 100);
    }

    /**
     * @param  list<int>  $requestIds
     * @return list<array{id:int,request_id:int,action:string,user_name:string,edited_by:string,created_at:string,created_at_raw:string,changes:list<array{field:string,label:string,old:string,new:string}>}>
     */
    public static function listForRequests(array $requestIds, int $limit = 200): array
    {
        if (! self::tableReady()) {
            return [];
        }

        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
        if ($requestIds === []) {
            return [];
        }

        $rows = DB::table('dcs_document_edit_history')
            ->whereIn('request_id', $requestIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min(500, $limit)))
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $userIds = $rows->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $names = self::userDisplayNames($userIds);

        $out = [];
        foreach ($rows as $row) {
            $changes = json_decode((string) ($row->changes ?? '[]'), true);
            if (! is_array($changes)) {
                $changes = [];
            }
            $uid = (int) ($row->user_id ?? 0);
            $created = (string) ($row->created_at ?? '');
            $userName = $names[$uid] ?? 'Unknown user';
            $out[] = [
                'id' => (int) $row->id,
                'request_id' => (int) ($row->request_id ?? 0),
                'action' => (string) ($row->action ?? 'update'),
                'user_name' => $userName,
                'edited_by' => $userName,
                'created_at' => self::formatDisplayDateTime($created),
                'created_at_raw' => $created,
                'changes' => array_values(array_map(static function ($c) {
                    return [
                        'field' => (string) ($c['field'] ?? ''),
                        'label' => (string) ($c['label'] ?? ($c['field'] ?? '')),
                        'old' => (string) ($c['old'] ?? '—'),
                        'new' => (string) ($c['new'] ?? '—'),
                    ];
                }, $changes)),
            ];
        }

        return $out;
    }

    /**
     * Prefer "Office added/removed …" over rewriting the full office list.
     *
     * @return list<array{field:string,label:string,old:string,new:string}>
     */
    private static function officeListChanges(string $field, string $label, string $old, string $new): array
    {
        $oldNames = self::splitOfficeNames($old);
        $newNames = self::splitOfficeNames($new);
        $oldSet = array_fill_keys(array_map('mb_strtolower', $oldNames), true);
        $newSet = array_fill_keys(array_map('mb_strtolower', $newNames), true);

        $added = [];
        foreach ($newNames as $name) {
            if (! isset($oldSet[mb_strtolower($name)])) {
                $added[] = $name;
            }
        }
        $removed = [];
        foreach ($oldNames as $name) {
            if (! isset($newSet[mb_strtolower($name)])) {
                $removed[] = $name;
            }
        }

        $scope = $field === 'distribution_offices' ? 'Distribution' : 'Source Offices';
        $out = [];
        foreach ($added as $name) {
            $out[] = [
                'field' => $field,
                'label' => $scope,
                'old' => '—',
                'new' => 'Office added in '.$scope.': '.$name,
            ];
        }
        foreach ($removed as $name) {
            $out[] = [
                'field' => $field,
                'label' => $scope,
                'old' => '—',
                'new' => 'Office removed from '.$scope.': '.$name,
            ];
        }

        // Fallback if parse failed but strings differed.
        if ($out === [] && ($old !== '' || $new !== '')) {
            $out[] = [
                'field' => $field,
                'label' => $label,
                'old' => $old === '' ? '—' : $old,
                'new' => $new === '' ? '—' : $new,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function splitOfficeNames(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($part) => trim((string) $part),
            preg_split('/\s*,\s*/u', $csv) ?: []
        )));
    }

    private static function sourceOfficesLabel(int $masterlistId): string
    {
        if ($masterlistId < 1 || ! Schema::hasTable('dcs_masterlist_source_offices')) {
            return '';
        }
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $names = DB::table('dcs_masterlist_source_offices as s')
            ->leftJoin($officeTable.' as o', 'o.id', '=', 's.office_id')
            ->where('s.masterlist_id', $masterlistId)
            ->orderBy('o.office_name')
            ->pluck('o.office_name')
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return implode(', ', $names);
    }

    private static function distributionOfficesLabel(int $requestId): string
    {
        if ($requestId < 1 || ! Schema::hasTable('dcs_document_distribution') || ! Schema::hasTable('dcs_distribution_offices')) {
            return '';
        }
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $names = DB::table('dcs_distribution_offices as d')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'd.distribution_id')
            ->leftJoin($officeTable.' as o', 'o.id', '=', 'd.office_id')
            ->where('dist.request_id', $requestId)
            ->orderBy('o.office_name')
            ->pluck('o.office_name')
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return implode(', ', $names);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private static function userDisplayNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $detailsTable = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $accountTable = Schema::hasTable('sys_accounts') ? 'sys_accounts' : 'accounts';
        $out = [];

        if (Schema::hasTable($detailsTable)) {
            $rows = DB::table($detailsTable)->whereIn('account_id', $userIds)->get(['account_id', 'first_name', 'last_name']);
            foreach ($rows as $row) {
                $name = trim(trim((string) ($row->first_name ?? '')).' '.trim((string) ($row->last_name ?? '')));
                if ($name !== '') {
                    $out[(int) $row->account_id] = $name;
                }
            }
        }

        $missing = array_values(array_filter($userIds, fn ($id) => ! isset($out[$id])));
        if ($missing !== [] && Schema::hasTable($accountTable)) {
            $users = DB::table($accountTable)->whereIn('id', $missing)->get(['id', 'username']);
            foreach ($users as $u) {
                $out[(int) $u->id] = trim((string) ($u->username ?? '')) ?: ('User #'.$u->id);
            }
        }

        return $out;
    }

    private static function norm(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private static function normDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return self::norm((string) $value);
        }
    }

    private static function normTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (\Throwable) {
            return self::norm($raw);
        }
    }

    private static function formatDisplayDateTime(string $value): string
    {
        if ($value === '') {
            return '—';
        }
        try {
            return Carbon::parse($value)->format('M j, Y g:i A');
        } catch (\Throwable) {
            return $value;
        }
    }
}
