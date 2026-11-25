<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GE / PE / NSTP are no longer institutional: all course types are per-program.
 * Add school_year_id and uniqueness on (program, school year, semester, year, type, name/code).
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
            'program_courses_program_sy_type_unique',
            'program_courses_program_sy_code_unique',
        ] as $indexName) {
            $this->dropUniqueIndexIfExists('dcs_program_courses', $indexName);
        }

        if (! Schema::hasColumn('dcs_program_courses', 'school_year_id')) {
            Schema::table('dcs_program_courses', function (Blueprint $table) {
                $table->foreignId('school_year_id')
                    ->nullable()
                    ->after('semester_id')
                    ->constrained('dcs_school_years')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('dcs_school_years') && Schema::hasColumn('dcs_school_years', 'is_active')) {
            $activeId = DB::table('dcs_school_years')->where('is_active', true)->value('id');
            if ($activeId) {
                DB::table('dcs_program_courses')
                    ->whereNull('school_year_id')
                    ->update(['school_year_id' => $activeId]);
            }
        }
        if (Schema::hasTable('dcs_school_years')) {
            $fallbackId = DB::table('dcs_school_years')->orderByDesc('id')->value('id');
            if ($fallbackId) {
                DB::table('dcs_program_courses')
                    ->whereNull('school_year_id')
                    ->update(['school_year_id' => $fallbackId]);
            }
        }

        $hasYear = Schema::hasColumn('dcs_program_courses', 'year_level');
        $hasCode = Schema::hasColumn('dcs_program_courses', 'course_code');
        $hasType = Schema::hasColumn('dcs_program_courses', 'course_type');
        $hasSy = Schema::hasColumn('dcs_program_courses', 'school_year_id');
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql' && $hasType && $hasSy) {
            $yearCol = $hasYear ? ', year_level' : '';
            DB::statement(
                "CREATE UNIQUE INDEX program_courses_program_sy_type_unique
                 ON dcs_program_courses (program_id, school_year_id, semester_id{$yearCol}, course_type, course_name)
                 WHERE program_id IS NOT NULL AND school_year_id IS NOT NULL"
            );
            if ($hasCode) {
                DB::statement(
                    "CREATE UNIQUE INDEX program_courses_program_sy_code_unique
                     ON dcs_program_courses (program_id, school_year_id, semester_id{$yearCol}, course_type, course_code)
                     WHERE program_id IS NOT NULL
                       AND school_year_id IS NOT NULL
                       AND course_code IS NOT NULL
                       AND TRIM(course_code) <> ''"
                );
            }
        } else {
            $nameParts = ['program_id', 'school_year_id', 'semester_id', 'course_name'];
            $codeParts = ['program_id', 'school_year_id', 'semester_id', 'course_code'];
            if ($hasYear) {
                $nameParts[] = 'year_level';
                $codeParts[] = 'year_level';
            }
            if ($hasType) {
                $nameParts[] = 'course_type';
                $codeParts[] = 'course_type';
            }
            $this->addUniqueIndexIfMissing('dcs_program_courses', $nameParts, 'program_courses_program_sy_type_unique');
            if ($hasCode) {
                $this->addUniqueIndexIfMissing('dcs_program_courses', $codeParts, 'program_courses_program_sy_code_unique');
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_program_courses')) {
            return;
        }

        foreach ([
            'program_courses_program_sy_type_unique',
            'program_courses_program_sy_code_unique',
        ] as $indexName) {
            $this->dropUniqueIndexIfExists('dcs_program_courses', $indexName);
        }

        if (Schema::hasColumn('dcs_program_courses', 'school_year_id')) {
            Schema::table('dcs_program_courses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('school_year_id');
            });
        }
    }

    private function dropUniqueIndexIfExists(string $table, string $indexName): void
    {
        $tableSql = '"'.str_replace('"', '""', $table).'"';
        $nameSql = '"'.str_replace('"', '""', $indexName).'"';
        try {
            DB::statement("ALTER TABLE {$tableSql} DROP CONSTRAINT IF EXISTS {$nameSql}");
        } catch (\Throwable) {
            // ignore
        }
        try {
            DB::statement("DROP INDEX IF EXISTS {$nameSql}");
        } catch (\Throwable) {
            // ignore
        }
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
