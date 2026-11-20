<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GE / PE / NSTP courses are institutional (program_id NULL).
 * Major stays per-program. Partial unique indexes (PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_program_courses')) {
            return;
        }

        foreach ([
            'program_courses_type_unique',
            'program_courses_code_type_unique',
            'program_courses_year_level_unique',
            'program_courses_code_year_level_unique',
            'program_courses_unique',
            'program_courses_code_unique',
            'program_courses_shared_type_unique',
            'program_courses_shared_code_type_unique',
            'program_courses_major_type_unique',
            'program_courses_major_code_type_unique',
        ] as $indexName) {
            $this->dropUniqueIndexIfExists('dcs_program_courses', $indexName);
        }

        // Drop FK so program_id can be null, then re-add nullable FK.
        $this->dropForeignIfExists('dcs_program_courses', 'dcs_program_courses_program_id_foreign');

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE dcs_program_courses ALTER COLUMN program_id DROP NOT NULL');
        } else {
            Schema::table('dcs_program_courses', function (Blueprint $table) {
                $table->unsignedBigInteger('program_id')->nullable()->change();
            });
        }

        Schema::table('dcs_program_courses', function (Blueprint $table) {
            $table->foreign('program_id')
                ->references('id')
                ->on('dcs_programs')
                ->nullOnDelete();
        });

        $this->dedupeSharedCourses();

        // Null out program_id for shared course types.
        if (Schema::hasColumn('dcs_program_courses', 'course_type')) {
            DB::table('dcs_program_courses')
                ->whereIn('course_type', ['GE Courses', 'PE Courses', 'NSTP'])
                ->update(['program_id' => null]);
        }

        $hasYear = Schema::hasColumn('dcs_program_courses', 'year_level');
        $hasCode = Schema::hasColumn('dcs_program_courses', 'course_code');
        $hasType = Schema::hasColumn('dcs_program_courses', 'course_type');

        if ($hasType && Schema::getConnection()->getDriverName() === 'pgsql') {
            $yearCol = $hasYear ? ', year_level' : '';
            DB::statement(
                "CREATE UNIQUE INDEX program_courses_shared_type_unique
                 ON dcs_program_courses (course_type, semester_id{$yearCol}, course_name)
                 WHERE program_id IS NULL"
            );
            if ($hasCode) {
                DB::statement(
                    "CREATE UNIQUE INDEX program_courses_shared_code_type_unique
                     ON dcs_program_courses (course_type, semester_id{$yearCol}, course_code)
                     WHERE program_id IS NULL AND course_code IS NOT NULL AND TRIM(course_code) <> ''"
                );
            }
            $majorCols = $hasYear
                ? 'program_id, semester_id, year_level, course_name, course_type'
                : 'program_id, semester_id, course_name, course_type';
            DB::statement(
                "CREATE UNIQUE INDEX program_courses_major_type_unique
                 ON dcs_program_courses ({$majorCols})
                 WHERE program_id IS NOT NULL"
            );
            if ($hasCode) {
                $majorCodeCols = $hasYear
                    ? 'program_id, semester_id, year_level, course_code, course_type'
                    : 'program_id, semester_id, course_code, course_type';
                DB::statement(
                    "CREATE UNIQUE INDEX program_courses_major_code_type_unique
                     ON dcs_program_courses ({$majorCodeCols})
                     WHERE program_id IS NOT NULL AND course_code IS NOT NULL AND TRIM(course_code) <> ''"
                );
            }
        } else {
            // Fallback non-partial uniques (MySQL / SQLite tests).
            $nameParts = ['program_id', 'semester_id', 'course_name'];
            $codeParts = ['program_id', 'semester_id', 'course_code'];
            if ($hasYear) {
                $nameParts[] = 'year_level';
                $codeParts[] = 'year_level';
            }
            if ($hasType) {
                $nameParts[] = 'course_type';
                $codeParts[] = 'course_type';
            }
            $this->addUniqueIndexIfMissing('dcs_program_courses', $nameParts, 'program_courses_type_unique');
            if ($hasCode) {
                $this->addUniqueIndexIfMissing('dcs_program_courses', $codeParts, 'program_courses_code_type_unique');
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_program_courses')) {
            return;
        }

        foreach ([
            'program_courses_shared_type_unique',
            'program_courses_shared_code_type_unique',
            'program_courses_major_type_unique',
            'program_courses_major_code_type_unique',
            'program_courses_type_unique',
            'program_courses_code_type_unique',
        ] as $indexName) {
            $this->dropUniqueIndexIfExists('dcs_program_courses', $indexName);
        }

        // Cannot safely restore NOT NULL without inventing program_ids for shared rows.
        $nameParts = ['program_id', 'semester_id', 'course_name'];
        $codeParts = ['program_id', 'semester_id', 'course_code'];
        if (Schema::hasColumn('dcs_program_courses', 'year_level')) {
            $nameParts[] = 'year_level';
            $codeParts[] = 'year_level';
        }
        if (Schema::hasColumn('dcs_program_courses', 'course_type')) {
            $nameParts[] = 'course_type';
            $codeParts[] = 'course_type';
        }
        $this->addUniqueIndexIfMissing('dcs_program_courses', $nameParts, 'program_courses_type_unique');
        if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
            $this->addUniqueIndexIfMissing('dcs_program_courses', $codeParts, 'program_courses_code_type_unique');
        }
    }

    /**
     * Collapse duplicate GE/PE/NSTP rows to one keeper per type/sem/year/name; remap FKs.
     */
    private function dedupeSharedCourses(): void
    {
        if (! Schema::hasColumn('dcs_program_courses', 'course_type')) {
            return;
        }

        $hasYear = Schema::hasColumn('dcs_program_courses', 'year_level');
        $rows = DB::table('dcs_program_courses')
            ->whereIn('course_type', ['GE Courses', 'PE Courses', 'NSTP'])
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row->course_type))
                . '|' . (int) $row->semester_id
                . '|' . ($hasYear ? strtolower(trim((string) ($row->year_level ?? ''))) : '')
                . '|' . strtolower(trim((string) $row->course_name));
            $groups[$key][] = $row;
        }

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $keeper = $group[0];
            $dupIds = [];
            for ($i = 1; $i < count($group); $i++) {
                $dupIds[] = (int) $group[$i]->id;
            }
            if ($dupIds === []) {
                continue;
            }

            if (Schema::hasTable('dcs_syllabi')) {
                DB::table('dcs_syllabi')->whereIn('course_id', $dupIds)->update(['course_id' => $keeper->id]);
            }
            if (Schema::hasTable('dcs_office_intake_drf') && Schema::hasColumn('dcs_office_intake_drf', 'course_id')) {
                DB::table('dcs_office_intake_drf')->whereIn('course_id', $dupIds)->update(['course_id' => $keeper->id]);
            }
            if (Schema::hasTable('dcs_program_course_faculties')) {
                DB::table('dcs_program_course_faculties')->whereIn('program_course_id', $dupIds)->delete();
            }

            DB::table('dcs_program_courses')->whereIn('id', $dupIds)->delete();
        }
    }

    private function dropForeignIfExists(string $table, string $foreignName): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT IF EXISTS "'.$foreignName.'"');

            return;
        }
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignName) {
                $blueprint->dropForeign($foreignName);
            });
        } catch (\Throwable) {
            // ignore
        }
    }

    private function dropUniqueIndexIfExists(string $table, string $indexName): void
    {
        $tableSql = '"'.str_replace('"', '""', $table).'"';
        $nameSql = '"'.str_replace('"', '""', $indexName).'"';
        DB::statement("ALTER TABLE {$tableSql} DROP CONSTRAINT IF EXISTS {$nameSql}");
        DB::statement("DROP INDEX IF EXISTS {$nameSql}");
    }

    private function addUniqueIndexIfMissing(string $table, array $columns, string $indexName): void
    {
        $exists = collect(Schema::getIndexes($table))
            ->contains(fn (array $index) => ($index['name'] ?? '') === $indexName);
        if ($exists) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->unique($columns, $indexName);
        });
    }
};
