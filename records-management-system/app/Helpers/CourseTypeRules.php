<?php

namespace App\Helpers;

/**
 * Shared vs Major course-type rules and year/semester caps.
 *
 * GE / PE / NSTP = institutional (program_id null).
 * Major = per-program.
 */
class CourseTypeRules
{
    public const SHARED_TYPES = [
        'GE Courses',
        'PE Courses',
        'NSTP',
    ];

    public const MAJOR = 'Major';

    public const ALL_TYPES = [
        'GE Courses',
        'PE Courses',
        'NSTP',
        'Major',
    ];

    /** @var array<string, list<string>> */
    private const YEAR_CAPS = [
        'GE Courses' => ['1st Year', '2nd Year', '3rd Year'],
        'PE Courses' => ['1st Year', '2nd Year'],
        'NSTP' => ['1st Year'],
        'Major' => ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'],
    ];

    /**
     * Terminal year for each shared type may only go through 2nd Semester
     * (and Mid Year). Earlier years allow all semesters.
     *
     * @var array<string, string>
     */
    private const TERMINAL_YEAR = [
        'GE Courses' => '3rd Year',
        'PE Courses' => '2nd Year',
        'NSTP' => '1st Year',
    ];

    public static function isSharedType(?string $courseType): bool
    {
        return in_array(trim((string) $courseType), self::SHARED_TYPES, true);
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

        // Terminal year: only through 2nd Semester (1st Sem and 2nd Sem OK; block later labels if any).
        if (str_contains($sem, '1st') || str_contains($sem, 'first')) {
            return true;
        }
        if (str_contains($sem, '2nd') || str_contains($sem, 'second')) {
            return true;
        }

        // Unknown semester label on terminal year — allow rather than hard-block Mid Year variants.
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
