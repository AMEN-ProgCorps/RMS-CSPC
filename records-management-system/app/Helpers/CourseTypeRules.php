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
        '5th Year',
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
