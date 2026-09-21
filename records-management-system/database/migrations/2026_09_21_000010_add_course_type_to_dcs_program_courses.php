<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds course_type to program courses and widens uniqueness.
 *
 * Older installs may still have program_courses_unique, while installs that
 * already ran the year_level migration only have *_year_level_unique. Always
 * drop with IF EXISTS so either history succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_program_courses')) {
            return;
        }

        if (! Schema::hasColumn('dcs_program_courses', 'course_type')) {
            Schema::table('dcs_program_courses', function (Blueprint $table) {
                $table->string('course_type', 50)->nullable();
            });
        }

        foreach ([
            'program_courses_year_level_unique',
            'program_courses_code_year_level_unique',
            'program_courses_unique',
            'program_courses_code_unique',
            'program_courses_type_unique',
            'program_courses_code_type_unique',
        ] as $indexName) {
            $this->dropUniqueIndexIfExists('dcs_program_courses', $indexName);
        }

        $nameParts = ['program_id', 'semester_id', 'course_name'];
        $codeParts = ['program_id', 'semester_id', 'course_code'];
        if (Schema::hasColumn('dcs_program_courses', 'year_level')) {
            $nameParts[] = 'year_level';
            $codeParts[] = 'year_level';
        }
        $nameParts[] = 'course_type';
        $codeParts[] = 'course_type';

        $this->addUniqueIndexIfMissing('dcs_program_courses', $nameParts, 'program_courses_type_unique');
        if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
            $this->addUniqueIndexIfMissing('dcs_program_courses', $codeParts, 'program_courses_code_type_unique');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_program_courses')
            || ! Schema::hasColumn('dcs_program_courses', 'course_type')) {
            return;
        }

        foreach ([
            'program_courses_type_unique',
            'program_courses_code_type_unique',
        ] as $indexName) {
            $this->dropUniqueIndexIfExists('dcs_program_courses', $indexName);
        }

        Schema::table('dcs_program_courses', function (Blueprint $table) {
            $table->dropColumn('course_type');
        });

        if (Schema::hasColumn('dcs_program_courses', 'year_level')) {
            $this->addUniqueIndexIfMissing(
                'dcs_program_courses',
                ['program_id', 'semester_id', 'course_name', 'year_level'],
                'program_courses_year_level_unique'
            );
            if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
                $this->addUniqueIndexIfMissing(
                    'dcs_program_courses',
                    ['program_id', 'semester_id', 'course_code', 'year_level'],
                    'program_courses_code_year_level_unique'
                );
            }
        } else {
            $this->addUniqueIndexIfMissing(
                'dcs_program_courses',
                ['program_id', 'semester_id', 'course_name'],
                'program_courses_unique'
            );
            if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
                $this->addUniqueIndexIfMissing(
                    'dcs_program_courses',
                    ['program_id', 'semester_id', 'course_code'],
                    'program_courses_code_unique'
                );
            }
        }
    }

    private function dropUniqueIndexIfExists(string $table, string $indexName): void
    {
        $tableSql = '"' . str_replace('"', '""', $table) . '"';
        $nameSql = '"' . str_replace('"', '""', $indexName) . '"';

        // PostgreSQL unique() indexes are constraints; MySQL keeps them as indexes.
        // IF EXISTS covers both histories (pre- and post-year_level migration).
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

        // Also detect PG constraints that Schema::getIndexes may omit.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $constraintExists = (bool) DB::selectOne(
                'SELECT 1 AS ok FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
                [$indexName, $table]
            );
            if ($constraintExists) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->unique($columns, $indexName);
        });
    }
};
