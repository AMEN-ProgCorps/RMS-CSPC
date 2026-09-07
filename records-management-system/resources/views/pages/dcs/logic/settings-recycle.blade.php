<?php

namespace App\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Soft-delete / restore / purge for DCS Settings reference data in the DCS Recycle Bin.
 */
class SettingsRecycleHelper
{
    /**
     * @return array<string, array{table: string, label: string, name: string, type_label: string}>
     */
    public static function entities(): array
    {
        return [
            'docType' => [
                'table' => 'dcs_doc_types',
                'label' => 'Document Type',
                'name' => 'doc_type_name',
                'type_label' => 'Settings · Document Type',
            ],
            'originator' => [
                'table' => 'dcs_originators',
                'label' => 'Originator',
                'name' => 'originator_name',
                'type_label' => 'Settings · Originator',
            ],
            'faculty' => [
                'table' => 'dcs_faculties',
                'label' => 'Faculty',
                'name' => 'faculty_name',
                'type_label' => 'Settings · Faculty',
            ],
            'college' => [
                'table' => 'dcs_colleges',
                'label' => 'College',
                'name' => 'college_name',
                'type_label' => 'Settings · College',
            ],
            'program' => [
                'table' => 'dcs_programs',
                'label' => 'Program',
                'name' => 'program_name',
                'type_label' => 'Settings · Program',
            ],
            'semester' => [
                'table' => 'dcs_semesters',
                'label' => 'Semester',
                'name' => 'semester_name',
                'type_label' => 'Settings · Semester',
            ],
            'schoolYear' => [
                'table' => 'dcs_school_years',
                'label' => 'School Year',
                'name' => 'school_year',
                'type_label' => 'Settings · School Year',
            ],
            'programCourse' => [
                'table' => 'dcs_program_courses',
                'label' => 'Course',
                'name' => 'course_name',
                'type_label' => 'Settings · Course',
            ],
        ];
    }

    public static function supports(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at');
    }

    /** Scope active (non-trashed) rows when soft-delete is available. */
    public static function applyNotDeleted($query, string $table, ?string $alias = null)
    {
        if (! self::supports($table)) {
            return $query;
        }

        $col = ($alias ? $alias . '.' : '') . 'deleted_at';

        return $query->whereNull($col);
    }

    /** Unique rule that ignores soft-deleted rows when the table supports recycle bin. */
    public static function uniqueRule(string $table, string $column, ?int $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column);
        if ($ignoreId) {
            $rule = $rule->ignore($ignoreId, 'id');
        }
        if (self::supports($table)) {
            $rule = $rule->whereNull('deleted_at');
        }

        return $rule;
    }

    public static function softDelete(string $kind, int $id): bool
    {
        $meta = self::entities()[$kind] ?? null;
        if (! $meta || ! self::supports($meta['table'])) {
            return false;
        }

        $update = ['deleted_at' => now()];
        if (Schema::hasColumn($meta['table'], 'deleted_by')) {
            $update['deleted_by'] = auth()->id();
        }

        return DB::table($meta['table'])->where('id', $id)->whereNull('deleted_at')->update($update) > 0;
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function restore(string $kind, int $id): array
    {
        $meta = self::entities()[$kind] ?? null;
        if (! $meta || ! self::supports($meta['table'])) {
            return ['ok' => false, 'message' => 'This settings item cannot be restored.'];
        }

        $row = DB::table($meta['table'])->where('id', $id)->whereNotNull('deleted_at')->first();
        if (! $row) {
            return ['ok' => false, 'message' => 'Item not found in Recycle Bin.'];
        }

        $nameCol = $meta['name'];
        $name = (string) ($row->{$nameCol} ?? '');
        if ($name !== '') {
            $conflict = DB::table($meta['table'])
                ->where($nameCol, $name)
                ->whereNull('deleted_at')
                ->where('id', '!=', $id);

            // Doc types are unique per parent level.
            if ($kind === 'docType' && Schema::hasColumn($meta['table'], 'parent_id')) {
                $conflict->where('parent_id', $row->parent_id);
            }
            if ($kind === 'faculty' && Schema::hasColumn($meta['table'], 'college_id')) {
                $conflict->where('college_id', $row->college_id);
            }
            if ($kind === 'program' && Schema::hasColumn($meta['table'], 'program_code')) {
                $conflict = DB::table($meta['table'])
                    ->where('program_code', $row->program_code)
                    ->where('college_id', $row->college_id)
                    ->whereNull('deleted_at')
                    ->where('id', '!=', $id);
            }
            if ($kind === 'programCourse') {
                $conflict = DB::table($meta['table'])
                    ->where('program_id', $row->program_id)
                    ->where('semester_id', $row->semester_id)
                    ->where('course_name', $row->course_name)
                    ->whereNull('deleted_at')
                    ->where('id', '!=', $id);
            }

            if ($conflict->exists()) {
                return [
                    'ok' => false,
                    'message' => 'Cannot restore: an active ' . strtolower($meta['label']) . ' with the same name already exists. Rename or remove the active one first.',
                ];
            }
        }

        $update = ['deleted_at' => null];
        if (Schema::hasColumn($meta['table'], 'deleted_by')) {
            $update['deleted_by'] = null;
        }

        $ok = DB::table($meta['table'])->where('id', $id)->whereNotNull('deleted_at')->update($update) > 0;

        return $ok
            ? ['ok' => true]
            : ['ok' => false, 'message' => 'Restore failed.'];
    }

    public static function permanentDestroy(string $kind, int $id): bool
    {
        $meta = self::entities()[$kind] ?? null;
        if (! $meta || ! Schema::hasTable($meta['table'])) {
            return false;
        }

        $query = DB::table($meta['table'])->where('id', $id);
        if (self::supports($meta['table'])) {
            $query->whereNotNull('deleted_at');
        }

        if ($kind === 'programCourse' && Schema::hasTable('dcs_program_course_faculties')) {
            DB::table('dcs_program_course_faculties')->where('program_course_id', $id)->delete();
        }

        if ($kind === 'faculty' && Schema::hasTable('dcs_program_course_faculties')) {
            DB::table('dcs_program_course_faculties')->where('faculty_id', $id)->delete();
        }

        if ($kind === 'docType') {
            // Soft-deleted children should already be gone; hard-delete any leftover children first.
            DB::table('dcs_doc_types')->where('parent_id', $id)->delete();
        }

        return $query->delete() > 0;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, current_page: int, last_page: int, per_page: int, retention_years: int}
     */
    public static function recycleBinList(string $search, int $page, int $perPage = 15): array
    {
        self::purgeExpired();

        $rows = collect();
        foreach (self::entities() as $kind => $meta) {
            if (! self::supports($meta['table'])) {
                continue;
            }

            $nameCol = $meta['name'];
            $select = ['id', $nameCol . ' as item_name', 'deleted_at'];
            if (Schema::hasColumn($meta['table'], 'deleted_by')) {
                $select[] = 'deleted_by';
            }

            $query = DB::table($meta['table'])->whereNotNull('deleted_at')->select($select);
            $items = $query->get()->map(function ($row) use ($kind, $meta) {
                return [
                    'kind' => $kind,
                    'id' => (int) $row->id,
                    'title' => (string) ($row->item_name ?? 'N/A'),
                    'doc_no' => '—',
                    'doc_type' => $meta['type_label'],
                    'rev_no' => '—',
                    'deleted_at_raw' => $row->deleted_at,
                    'deleted_by_id' => isset($row->deleted_by) ? (int) $row->deleted_by : null,
                ];
            });
            $rows = $rows->concat($items);
        }

        $search = trim($search);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(function (array $row) use ($needle) {
                return str_contains(mb_strtolower($row['title']), $needle)
                    || str_contains(mb_strtolower($row['doc_type']), $needle);
            })->values();
        }

        $rows = $rows->sortByDesc(fn (array $row) => (string) $row['deleted_at_raw'])->values();
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        $deletedByIds = $slice->pluck('deleted_by_id')->filter()->unique()->all();
        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $deletedByNames = $deletedByIds
            ? DB::table($accDetailsTbl)
                ->whereIn('account_id', $deletedByIds)
                ->get()
                ->mapWithKeys(fn ($d) => [(int) $d->account_id => trim($d->first_name . ' ' . $d->last_name)])
            : collect();

        $now = Carbon::now();
        $mapped = $slice->map(function (array $row) use ($deletedByNames, $now) {
            $deletedAt = $row['deleted_at_raw'] ? Carbon::parse($row['deleted_at_raw']) : null;
            $expiresAt = $deletedAt ? RegisterQueryHelper::recycleBinExpiresAt($deletedAt) : null;
            $daysLeft = $expiresAt ? (int) $now->diffInDays($expiresAt, false) : null;

            return [
                'kind' => $row['kind'],
                'id' => $row['id'],
                'request_id' => $row['id'],
                'title' => $row['title'],
                'doc_no' => $row['doc_no'],
                'doc_type' => $row['doc_type'],
                'rev_no' => $row['rev_no'],
                'deleted_at' => $deletedAt ? $deletedAt->format('M d, Y h:i A') : '—',
                'deleted_by' => $row['deleted_by_id']
                    ? ($deletedByNames[$row['deleted_by_id']] ?? null)
                    : null,
                'expires_at' => $expiresAt ? $expiresAt->format('M d, Y') : '—',
                'days_left' => $daysLeft,
                'is_settings' => true,
            ];
        })->all();

        return [
            'rows' => $mapped,
            'total' => $total,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'retention_years' => RegisterQueryHelper::RECYCLE_BIN_RETENTION_YEARS,
        ];
    }

    public static function purgeExpired(): int
    {
        $cutoff = now()->subYears(RegisterQueryHelper::RECYCLE_BIN_RETENTION_YEARS);
        $purged = 0;

        foreach (self::entities() as $kind => $meta) {
            if (! self::supports($meta['table'])) {
                continue;
            }

            $ids = DB::table($meta['table'])
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<=', $cutoff)
                ->pluck('id');

            foreach ($ids as $id) {
                if (self::permanentDestroy($kind, (int) $id)) {
                    $purged++;
                }
            }
        }

        return $purged;
    }
}
