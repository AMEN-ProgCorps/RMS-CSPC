<?php

namespace App\Helpers;

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

    /** @var array<string, list<string>> */
    private const YEAR_CAPS = [
        'GE Courses' => ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'],
        'PE Courses' => ['1st Year', '2nd Year'],
        'NSTP' => ['1st Year'],
        'Major' => ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'],
    ];

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
        $type = trim((string) $courseType);
        if ($type === '' || ! isset(self::YEAR_CAPS[$type])) {
            return SyllabiMonitoringHelper::YEAR_LEVELS;
        }

        return self::YEAR_CAPS[$type];
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
