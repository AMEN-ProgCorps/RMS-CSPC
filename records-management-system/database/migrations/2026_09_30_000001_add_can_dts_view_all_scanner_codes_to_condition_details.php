<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "View All Scanner Codes" is an explicit DTS clearance.
 * Without it, the Scanner's "Available Codes" list is scoped to the user's own
 * station (documents targeted to their office, incoming or in custody).
 * Grant this clearance to roles that should see every office's codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $details = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';

        if (! Schema::hasTable($details)) {
            return;
        }

        if (! Schema::hasColumn($details, 'can_dts_view_all_scanner_codes')) {
            $after = Schema::hasColumn($details, 'can_dts_view_all_current_trans')
                ? 'can_dts_view_all_current_trans'
                : null;

            Schema::table($details, function (Blueprint $blueprint) use ($after) {
                if ($after) {
                    $blueprint->boolean('can_dts_view_all_scanner_codes')->default(false)->after($after);
                } else {
                    $blueprint->boolean('can_dts_view_all_scanner_codes')->default(false);
                }
            });
        }

        try {
            DB::statement("ALTER TABLE {$details} ALTER COLUMN can_dts_view_all_scanner_codes SET DEFAULT false");
        } catch (\Throwable) {
            // SQLite / drivers without ALTER COLUMN DEFAULT
        }
    }

    public function down(): void
    {
        $details = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';

        if (! Schema::hasTable($details) || ! Schema::hasColumn($details, 'can_dts_view_all_scanner_codes')) {
            return;
        }

        Schema::table($details, function (Blueprint $blueprint) {
            $blueprint->dropColumn('can_dts_view_all_scanner_codes');
        });
    }
};
