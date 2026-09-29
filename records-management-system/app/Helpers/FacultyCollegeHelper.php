<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faculty ↔ college membership (part-timers may teach under several colleges).
 */
class FacultyCollegeHelper
{
    public static function usesPivot(): bool
    {
        return Schema::hasTable('dcs_faculty_colleges');
    }

    public static function hasLegacyCollegeColumn(): bool
    {
        return Schema::hasTable('dcs_faculties')
            && Schema::hasColumn('dcs_faculties', 'college_id');
    }

    /** @return list<int> */
    public static function collegeIdsFor(int $facultyId): array
    {
        if ($facultyId < 1) {
            return [];
        }

        if (self::usesPivot()) {
            return DB::table('dcs_faculty_colleges')
                ->where('faculty_id', $facultyId)
                ->orderBy('college_id')
                ->pluck('college_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        if (self::hasLegacyCollegeColumn()) {
            $id = DB::table('dcs_faculties')->where('id', $facultyId)->value('college_id');

            return $id ? [(int) $id] : [];
        }

        return [];
    }

    /**
     * @param  list<int>  $facultyIds
     * @return array<int, list<int>> faculty_id => college_ids
     */
    public static function collegeIdsByFaculty(array $facultyIds): array
    {
        $facultyIds = array_values(array_unique(array_filter(array_map('intval', $facultyIds))));
        if ($facultyIds === []) {
            return [];
        }

        $map = [];
        foreach ($facultyIds as $id) {
            $map[$id] = [];
        }

        if (self::usesPivot()) {
            $rows = DB::table('dcs_faculty_colleges')
                ->whereIn('faculty_id', $facultyIds)
                ->orderBy('college_id')
                ->get(['faculty_id', 'college_id']);
            foreach ($rows as $row) {
                $map[(int) $row->faculty_id][] = (int) $row->college_id;
            }

            return $map;
        }

        if (self::hasLegacyCollegeColumn()) {
            $rows = DB::table('dcs_faculties')
                ->whereIn('id', $facultyIds)
                ->get(['id', 'college_id']);
            foreach ($rows as $row) {
                if ($row->college_id !== null) {
                    $map[(int) $row->id][] = (int) $row->college_id;
                }
            }
        }

        return $map;
    }

    /** @param  list<int|string>  $collegeIds */
    public static function sync(int $facultyId, array $collegeIds): void
    {
        if ($facultyId < 1) {
            return;
        }

        $collegeIds = array_values(array_unique(array_filter(array_map('intval', $collegeIds), fn ($id) => $id > 0)));

        if (self::usesPivot()) {
            DB::table('dcs_faculty_colleges')->where('faculty_id', $facultyId)->delete();
            if ($collegeIds === []) {
                return;
            }
            $now = now();
            $rows = array_map(fn (int $collegeId) => [
                'faculty_id' => $facultyId,
                'college_id' => $collegeId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $collegeIds);
            DB::table('dcs_faculty_colleges')->insert($rows);

            return;
        }

        if (self::hasLegacyCollegeColumn()) {
            DB::table('dcs_faculties')->where('id', $facultyId)->update([
                'college_id' => $collegeIds[0] ?? null,
            ]);
        }
    }

    public static function attach(int $facultyId, int $collegeId): bool
    {
        if ($facultyId < 1 || $collegeId < 1) {
            return false;
        }

        if (self::usesPivot()) {
            $exists = DB::table('dcs_faculty_colleges')
                ->where('faculty_id', $facultyId)
                ->where('college_id', $collegeId)
                ->exists();
            if ($exists) {
                return false;
            }
            DB::table('dcs_faculty_colleges')->insert([
                'faculty_id' => $facultyId,
                'college_id' => $collegeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        }

        if (self::hasLegacyCollegeColumn()) {
            $current = DB::table('dcs_faculties')->where('id', $facultyId)->value('college_id');
            if ((int) $current === $collegeId) {
                return false;
            }
            if ($current !== null && (int) $current > 0) {
                // Legacy single-college column cannot hold a second membership.
                return false;
            }
            DB::table('dcs_faculties')->where('id', $facultyId)->update(['college_id' => $collegeId]);

            return true;
        }

        return false;
    }
}
