<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_faculties')) {
            return;
        }

        $now = now();
        $hasDeletedAt = Schema::hasColumn('dcs_faculties', 'deleted_at');
        $hasLegacyCollege = Schema::hasColumn('dcs_faculties', 'college_id');
        $hasPivot = Schema::hasTable('dcs_faculty_colleges');
        $hasDrf = Schema::hasTable('dcs_syllabi_drf');
        $hasDrfFacultyName = $hasDrf && Schema::hasColumn('dcs_syllabi_drf', 'faculty_name');

        $attachColleges = static function (int $facultyId, array $collegeIds) use ($hasPivot, $hasLegacyCollege, $now): void {
            $collegeIds = array_values(array_unique(array_filter(array_map('intval', $collegeIds), fn ($id) => $id > 0)));
            if ($collegeIds === []) {
                return;
            }
            if ($hasPivot) {
                foreach ($collegeIds as $cid) {
                    $exists = DB::table('dcs_faculty_colleges')
                        ->where('faculty_id', $facultyId)
                        ->where('college_id', $cid)
                        ->exists();
                    if (! $exists) {
                        DB::table('dcs_faculty_colleges')->insert([
                            'faculty_id' => $facultyId,
                            'college_id' => $cid,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
            if ($hasLegacyCollege) {
                $current = DB::table('dcs_faculties')->where('id', $facultyId)->value('college_id');
                if (empty($current)) {
                    DB::table('dcs_faculties')->where('id', $facultyId)->update(['college_id' => $collegeIds[0]]);
                }
            }
        };

        $collegeIdsFor = static function (int $facultyId) use ($hasPivot, $hasLegacyCollege): array {
            $ids = [];
            if ($hasPivot) {
                $ids = DB::table('dcs_faculty_colleges')
                    ->where('faculty_id', $facultyId)
                    ->pluck('college_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
            if ($hasLegacyCollege) {
                $legacy = (int) (DB::table('dcs_faculties')->where('id', $facultyId)->value('college_id') ?? 0);
                if ($legacy > 0) {
                    $ids[] = $legacy;
                }
            }

            return array_values(array_unique(array_filter($ids)));
        };

        $findOrCreateSingle = static function (string $name) use ($hasDeletedAt, $now): array {
            $trimmed = trim($name);
            $q = DB::table('dcs_faculties')
                ->whereRaw('LOWER(TRIM(faculty_name)) = ?', [mb_strtolower($trimmed)])
                ->orderBy('id');
            if ($hasDeletedAt) {
                $q->whereNull('deleted_at');
            }
            $existing = $q->first(['id', 'faculty_name']);
            if ($existing) {
                return ['id' => (int) $existing->id, 'name' => trim((string) $existing->faculty_name)];
            }

            $id = (int) DB::table('dcs_faculties')->insertGetId([
                'faculty_name' => $trimmed,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'name' => $trimmed];
        };

        // 1. Split any comma-separated faculty rows into individual faculty records.
        $commaQ = DB::table('dcs_faculties')->where('faculty_name', 'like', '%,%')->orderBy('id');
        if ($hasDeletedAt) {
            $commaQ->whereNull('deleted_at');
        }
        $commaRows = $commaQ->get(['id', 'faculty_name']);

        foreach ($commaRows as $row) {
            $oldId = (int) $row->id;
            $parts = preg_split('/\s*,\s*/u', trim((string) $row->faculty_name)) ?: [];
            $names = [];
            $seen = [];
            foreach ($parts as $p) {
                $p = trim((string) $p);
                $k = mb_strtolower($p);
                if ($p !== '' && ! isset($seen[$k])) {
                    $seen[$k] = true;
                    $names[] = $p;
                }
            }
            if ($names === []) {
                continue;
            }

            $cids = $collegeIdsFor($oldId);
            $resolved = [];
            foreach ($names as $n) {
                $single = $findOrCreateSingle($n);
                $attachColleges($single['id'], $cids);
                $resolved[] = $single;
            }

            if ($hasDrf && $resolved !== []) {
                $drfRows = DB::table('dcs_syllabi_drf')->where('faculty_id', $oldId)->get();
                foreach ($drfRows as $drf) {
                    $first = $resolved[0];
                    $update = ['faculty_id' => $first['id'], 'updated_at' => $now];
                    if ($hasDrfFacultyName) {
                        $update['faculty_name'] = $first['name'];
                    }
                    DB::table('dcs_syllabi_drf')->where('id', $drf->id)->update($update);

                    for ($idx = 1; $idx < count($resolved); $idx++) {
                        $extra = $resolved[$idx];
                        $dupExists = DB::table('dcs_syllabi_drf')
                            ->where('syllabi_id', $drf->syllabi_id)
                            ->where('faculty_id', $extra['id'])
                            ->exists();
                        if ($dupExists) {
                            continue;
                        }
                        $clone = (array) $drf;
                        unset($clone['id']);
                        $clone['faculty_id'] = $extra['id'];
                        if ($hasDrfFacultyName) {
                            $clone['faculty_name'] = $extra['name'];
                        }
                        $clone['created_at'] = $drf->created_at ?? $now;
                        $clone['updated_at'] = $now;
                        DB::table('dcs_syllabi_drf')->insert($clone);
                    }
                }
            }

            if ($hasPivot) {
                DB::table('dcs_faculty_colleges')->where('faculty_id', $oldId)->delete();
            }
            DB::table('dcs_faculties')->where('id', $oldId)->delete();
        }

        // 2. Deduplicate any remaining case-insensitive duplicate faculty names.
        $allQ = DB::table('dcs_faculties')->orderBy('id');
        if ($hasDeletedAt) {
            $allQ->whereNull('deleted_at');
        }
        $byLower = [];
        foreach ($allQ->get(['id', 'faculty_name']) as $fac) {
            $key = mb_strtolower(trim((string) $fac->faculty_name));
            if ($key === '') {
                continue;
            }
            $byLower[$key][] = (int) $fac->id;
        }

        foreach ($byLower as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $keepId = array_shift($ids);
            foreach ($ids as $dupId) {
                $attachColleges($keepId, $collegeIdsFor($dupId));
                if ($hasDrf) {
                    DB::table('dcs_syllabi_drf')
                        ->where('faculty_id', $dupId)
                        ->update(['faculty_id' => $keepId, 'updated_at' => $now]);
                }
                if ($hasPivot) {
                    DB::table('dcs_faculty_colleges')->where('faculty_id', $dupId)->delete();
                }
                DB::table('dcs_faculties')->where('id', $dupId)->delete();
            }
        }
    }

    public function down(): void
    {
        // Data cleanup migration; not reversible.
    }
};
