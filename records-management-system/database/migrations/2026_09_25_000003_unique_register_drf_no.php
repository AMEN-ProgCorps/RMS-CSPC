<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Register DRF numbers must be unique (case-insensitive, ignoring blanks).
 */
return new class extends Migration
{
    private const TABLE = 'dcs_document_request_form';

    private const INDEX = 'dcs_document_request_form_drf_no_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'drf_no')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $duplicateCount = (int) DB::table(self::TABLE)
            ->selectRaw('LOWER(TRIM(drf_no)) as n')
            ->whereNotNull('drf_no')
            ->whereRaw("TRIM(drf_no) <> ''")
            ->groupByRaw('LOWER(TRIM(drf_no))')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicateCount > 0) {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS ' . self::INDEX
            . ' ON ' . self::TABLE . ' (LOWER(TRIM(drf_no)))'
            . " WHERE drf_no IS NOT NULL AND TRIM(drf_no) <> ''"
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);
    }
};
