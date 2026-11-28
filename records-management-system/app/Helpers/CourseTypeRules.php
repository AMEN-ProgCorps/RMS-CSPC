<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Course-type rules and year/semester caps.
 *
 * All course types (GE / PE / NSTP / Major) are per-program.
 * GE is not campus-wide and is not capped at 3rd Year.
 */
class CourseTypeRules
{
    /** @deprecated Kept for call-site compatibility; no type is institutional anymore. */
    public const SHARED_TYPES = [];

    public const MAJOR = 'Major';

    public const ALL_TYPES = [
        'GE Courses',
        'PE Courses',
        'NSTP',
        'Major',
    ];

    /** Default year levels when no custom list has been saved yet. */
    public const DEFAULT_YEAR_LEVELS = [
        '1st Year',
        '2nd Year',
        '3rd Year',
        '4th Year',
    ];

    /** @var array<string, list<string>> */
    private const FIXED_YEAR_CAPS = [
        'PE Courses' => ['1st Year', '2nd Year'],
        'NSTP' => ['1st Year'],
    ];

    /** @var list<string>|null */
    private static ?array $yearLevelsMemo = null;

    private static function yearLevelsFilePath(): string
    {
        $dir = storage_path('app/private');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'dcs_year_levels.json';
    }

    /**
     * Returns all configured year levels (persisted in Settings → Syllabi → Year Levels).
     *
     * @return list<string>
     */
    public static function allYearLevels(): array
    {
        if (self::$yearLevelsMemo !== null) {
            return self::$yearLevelsMemo;
        }

        if (Schema::hasTable('dcs_year_levels')) {
            $rows = DB::table('dcs_year_levels')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('year_level_name')
                ->map(fn ($v) => trim((string) $v))
                ->filter()
                ->unique(fn ($v) => mb_strtolower($v))
                ->values()
                ->all();
            if ($rows !== []) {
                return self::$yearLevelsMemo = $rows;
            }
        }

        $path = self::yearLevelsFilePath();
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $clean = [];
                $seen = [];
                foreach ($decoded as $val) {
                    $label = trim((string) $val);
                    $key = mb_strtolower($label);
                    if ($label !== '' && ! isset($seen[$key])) {
                        $seen[$key] = true;
                        $clean[] = $label;
                    }
                }
                if ($clean !== []) {
                    return self::$yearLevelsMemo = $clean;
                }
            }
        }

        return self::$yearLevelsMemo = self::DEFAULT_YEAR_LEVELS;
    }

    /**
     * Persists the ordered list of year levels.
     *
     * @param  list<string>  $levels
     * @return list<string>
     */
    public static function saveYearLevels(array $levels): array
    {
        $clean = [];
        $seen = [];
        foreach ($levels as $val) {
            $label = trim((string) $val);
            $key = mb_strtolower($label);
            if ($label !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $clean[] = $label;
            }
        }
        if ($clean === []) {
            throw new \InvalidArgumentException('At least one year level is required.');
        }

        if (Schema::hasTable('dcs_year_levels')) {
            $now = now();
            DB::transaction(function () use ($clean, $now) {
                DB::table('dcs_year_levels')->whereNotIn('year_level_name', $clean)->delete();
                foreach ($clean as $idx => $name) {
                    DB::table('dcs_year_levels')->updateOrInsert(
                        ['year_level_name' => $name],
                        ['sort_order' => $idx + 1, 'updated_at' => $now, 'created_at' => $now]
                    );
                }
            });
            self::$yearLevelsMemo = $clean;

            return $clean;
        }

        $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents(self::yearLevelsFilePath(), $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not save year levels.');
        }

        self::$yearLevelsMemo = $clean;

        return $clean;
    }

    /**
     * Full year level records including optional college/program assignment.
     *
     * @return list<array{id: ?int, name: string, college_id: ?int, program_id: ?int, scope_type: string, scope_label: string}>
     */
    public static function yearLevelRecords(): array
    {
        if (Schema::hasTable('dcs_year_levels')) {
            $hasCollege = Schema::hasColumn('dcs_year_levels', 'college_id');
            $hasProgram = Schema::hasColumn('dcs_year_levels', 'program_id');
            $q = DB::table('dcs_year_levels as yl')
                ->orderBy('yl.sort_order')
                ->orderBy('yl.id');
            $cols = ['yl.id', 'yl.year_level_name'];
            if ($hasCollege) {
                $q->leftJoin('dcs_colleges as c', 'c.id', '=', 'yl.college_id');
                $cols[] = 'yl.college_id';
                $cols[] = 'c.college_code';
                $cols[] = 'c.college_name';
            }
            if ($hasProgram) {
                $q->leftJoin('dcs_programs as p', 'p.id', '=', 'yl.program_id');
                $q->leftJoin('dcs_colleges as pc', 'pc.id', '=', 'p.college_id');
                $cols[] = 'yl.program_id';
                $cols[] = 'p.program_code';
                $cols[] = 'p.program_name';
                $cols[] = 'pc.college_code as prog_college_code';
            }

            $rows = $q->get($cols);
            if ($rows->isNotEmpty()) {
                return $rows->map(function ($r) use ($hasCollege, $hasProgram) {
                    $cid = $hasCollege && ! empty($r->college_id) ? (int) $r->college_id : null;
                    $pid = $hasProgram && ! empty($r->program_id) ? (int) $r->program_id : null;
                    $scopeType = 'all';
                    $scopeLabel = 'Default · All Colleges';
                    if ($pid !== null) {
                        $scopeType = 'program';
                        $pLabel = trim((string) ($r->program_code ?: $r->program_name ?: "Program #{$pid}"));
                        $cCode = trim((string) ($r->prog_college_code ?? ''));
                        $scopeLabel = $cCode !== '' ? "Program: {$pLabel} ({$cCode})" : "Program: {$pLabel}";
                    } elseif ($cid !== null) {
                        $scopeType = 'college';
                        $cLabel = trim((string) ($r->college_code ?: $r->college_name ?: "College #{$cid}"));
                        $scopeLabel = "College: {$cLabel}";
                    }

                    return [
                        'id' => (int) $r->id,
                        'name' => trim((string) $r->year_level_name),
                        'college_id' => $cid,
                        'program_id' => $pid,
                        'scope_type' => $scopeType,
                        'scope_label' => $scopeLabel,
                    ];
                })->values()->all();
            }
        }

        return array_map(fn (string $lvl) => [
            'id' => null,
            'name' => $lvl,
            'college_id' => null,
            'program_id' => null,
            'scope_type' => 'all',
            'scope_label' => 'Default · All Colleges',
        ], self::allYearLevels());
    }

    /**
     * Save or update a year level with optional college/program assignment.
     */
    public static function saveYearLevelRecord(?int $id, string $name, ?int $collegeId = null, ?int $programId = null): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Year level name is required.');
        }

        if ($programId !== null && $programId > 0) {
            if ($collegeId === null || $collegeId < 1) {
                $collegeId = (int) (DB::table('dcs_programs')->where('id', $programId)->value('college_id') ?? 0) ?: null;
            }
        } else {
            $programId = null;
        }

        if ($collegeId !== null && $collegeId < 1) {
            $collegeId = null;
        }

        if (! Schema::hasTable('dcs_year_levels')) {
            $levels = self::allYearLevels();
            if (! in_array($name, $levels, true)) {
                $levels[] = $name;
                self::saveYearLevels($levels);
            }

            return;
        }

        $now = now();
        $payload = [
            'year_level_name' => $name,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('dcs_year_levels', 'college_id')) {
            $payload['college_id'] = $collegeId;
        }
        if (Schema::hasColumn('dcs_year_levels', 'program_id')) {
            $payload['program_id'] = $programId;
        }

        if ($id !== null && $id > 0) {
            DB::table('dcs_year_levels')->where('id', $id)->update($payload);
        } else {
            $maxOrder = (int) (DB::table('dcs_year_levels')->max('sort_order') ?? 0);
            $payload['sort_order'] = $maxOrder + 1;
            $payload['created_at'] = $now;
            DB::table('dcs_year_levels')->insert($payload);
        }

        self::$yearLevelsMemo = null;
    }

    /**
     * Delete a year level record by ID (or by label fallback).
     */
    public static function deleteYearLevelRecord(?int $id, string $fallbackLabel = ''): void
    {
        if (Schema::hasTable('dcs_year_levels') && $id !== null && $id > 0) {
            DB::table('dcs_year_levels')->where('id', $id)->delete();
            self::$yearLevelsMemo = null;

            return;
        }

        if ($fallbackLabel !== '') {
            $levels = array_values(array_filter(
                self::allYearLevels(),
                fn ($lvl) => $lvl !== trim($fallbackLabel)
            ));
            if ($levels !== []) {
                self::saveYearLevels($levels);
            }
        }
    }

    /**
     * Year levels applicable to a given college and/or program (includes global levels).
     *
     * @return list<string>
     */
    public static function yearLevelsForScope(?int $collegeId = null, ?int $programId = null): array
    {
        $records = self::yearLevelRecords();
        $out = [];
        $seen = [];
        foreach ($records as $rec) {
            $rc = $rec['college_id'];
            $rp = $rec['program_id'];
            $matches = ($rc === null && $rp === null)
                || ($programId !== null && $rp === $programId)
                || ($collegeId !== null && $rp === null && $rc === $collegeId);
            if (! $matches) {
                continue;
            }
            $key = mb_strtolower($rec['name']);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $rec['name'];
            }
        }

        return $out !== [] ? $out : self::DEFAULT_YEAR_LEVELS;
    }

    /**
     * Allowed year levels for a course type within a specific college and/or program scope.
     * Only includes default (global) year levels plus those assigned to that college or program.
     *
     * @return list<string>
     */
    public static function allowedYearLevelsForScope(?string $courseType, ?int $collegeId = null, ?int $programId = null): array
    {
        if ($programId !== null && $programId > 0 && ($collegeId === null || $collegeId < 1)) {
            $collegeId = (int) (DB::table('dcs_programs')->where('id', $programId)->value('college_id') ?? 0) ?: null;
        }
        $scoped = self::yearLevelsForScope($collegeId, $programId);
        $type = trim((string) $courseType);
        if ($type !== '' && isset(self::FIXED_YEAR_CAPS[$type])) {
            $cap = array_values(array_intersect($scoped, self::FIXED_YEAR_CAPS[$type]));

            return $cap !== [] ? $cap : self::FIXED_YEAR_CAPS[$type];
        }

        return $scoped;
    }

    /**
     * Terminal year for PE/NSTP may only go through 2nd Semester
     * (and Mid Year). GE has no terminal-year semester restriction.
     *
     * @var array<string, string>
     */
    private const TERMINAL_YEAR = [
        'PE Courses' => '2nd Year',
        'NSTP' => '1st Year',
    ];

    /**
     * No course type is institutional / campus-wide anymore.
     * Kept so existing call sites keep working without branching.
     */
    public static function isSharedType(?string $courseType): bool
    {
        return false;
    }

    public static function isMajor(?string $courseType): bool
    {
        return trim((string) $courseType) === self::MAJOR;
    }

    /**
     * @return list<string>
     */
    public static function allowedYearLevels(?string $courseType): array
    {
        $all = self::allYearLevels();
        $type = trim((string) $courseType);
        if ($type !== '' && isset(self::FIXED_YEAR_CAPS[$type])) {
            $cap = array_values(array_intersect($all, self::FIXED_YEAR_CAPS[$type]));

            return $cap !== [] ? $cap : self::FIXED_YEAR_CAPS[$type];
        }

        return $all;
    }

    public static function isYearLevelAllowed(?string $courseType, ?string $yearLevel): bool
    {
        $year = trim((string) $yearLevel);
        if ($year === '') {
            return false;
        }

        return in_array($year, self::allowedYearLevels($courseType), true);
    }

    /**
     * Whether (year_level, semester_name) is valid for the course type.
     * Mid Year is allowed for any year already inside the type’s year cap.
     */
    public static function isYearSemesterAllowed(?string $courseType, ?string $yearLevel, ?string $semesterName): bool
    {
        if (! self::isYearLevelAllowed($courseType, $yearLevel)) {
            return false;
        }

        $type = trim((string) $courseType);
        $year = trim((string) $yearLevel);
        $sem = mb_strtolower(trim((string) $semesterName));

        if ($sem === '' || str_contains($sem, 'mid')) {
            return true;
        }

        $terminal = self::TERMINAL_YEAR[$type] ?? null;
        if ($terminal === null || $year !== $terminal) {
            return true;
        }

        if (str_contains($sem, '1st') || str_contains($sem, 'first')) {
            return true;
        }
        if (str_contains($sem, '2nd') || str_contains($sem, 'second')) {
            return true;
        }

        return ! (str_contains($sem, '3rd') || str_contains($sem, 'third') || str_contains($sem, 'summer'));
    }

    /**
     * @return list<string>
     */
    public static function filterYearLevelsForType(?string $courseType, array $years): array
    {
        $allowed = array_flip(self::allowedYearLevels($courseType));

        return array_values(array_filter(
            $years,
            fn ($y) => isset($allowed[trim((string) $y)])
        ));
    }
}
