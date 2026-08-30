<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full DCS is RFIO-office-only; dcs_view_all_documents must not bypass that policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('condition_details')
            || ! Schema::hasColumn('condition_details', 'dcs_view_all_documents')) {
            return;
        }

        DB::table('condition_details')->update(['dcs_view_all_documents' => false]);
    }

    public function down(): void
    {
        // Non-reversible — re-grant manually if needed.
    }
};
