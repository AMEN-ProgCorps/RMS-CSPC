<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role flag: with can_access_dcs, grants full DCS (same as RFIO) for any office.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';

        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, 'dcs_view_all_documents')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('dcs_view_all_documents')->default(false)->after('can_access_dcs');
            });
        }
    }

    public function down(): void
    {
        $table = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';

        if (Schema::hasTable($table) && Schema::hasColumn($table, 'dcs_view_all_documents')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('dcs_view_all_documents');
            });
        }
    }
};
