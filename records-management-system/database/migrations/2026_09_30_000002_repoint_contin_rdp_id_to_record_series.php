<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * contin_rdp_id on dts_transaction_flow originally pointed at
 * rdp_record_series_type (record series TYPES). The admin
 * Transaction Flows details panel now lets admins pick an actual
 * Record Series entry (rdp_record_series, as listed on the
 * /admin/rdp/record-series page), so the foreign key is repointed.
 *
 * Any previously stored type ids are cleared because they would
 * resolve to unrelated record series under the new target table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dts_transaction_flow') || ! Schema::hasColumn('dts_transaction_flow', 'contin_rdp_id')) {
            return;
        }

        Schema::table('dts_transaction_flow', function (Blueprint $table) {
            $table->dropForeign(['contin_rdp_id']);
        });

        // Old semantics were type ids — never valid record series ids.
        DB::table('dts_transaction_flow')->whereNotNull('contin_rdp_id')->update(['contin_rdp_id' => null]);

        if (Schema::hasTable('rdp_record_series')) {
            Schema::table('dts_transaction_flow', function (Blueprint $table) {
                $table->foreign('contin_rdp_id')
                    ->references('id')
                    ->on('rdp_record_series')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dts_transaction_flow') || ! Schema::hasColumn('dts_transaction_flow', 'contin_rdp_id')) {
            return;
        }

        Schema::table('dts_transaction_flow', function (Blueprint $table) {
            $table->dropForeign(['contin_rdp_id']);
        });

        DB::table('dts_transaction_flow')->whereNotNull('contin_rdp_id')->update(['contin_rdp_id' => null]);

        if (Schema::hasTable('rdp_record_series_type')) {
            Schema::table('dts_transaction_flow', function (Blueprint $table) {
                $table->foreign('contin_rdp_id')
                    ->references('id')
                    ->on('rdp_record_series_type')
                    ->cascadeOnDelete();
            });
        }
    }
};
