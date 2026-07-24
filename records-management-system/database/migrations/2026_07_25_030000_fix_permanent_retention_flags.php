<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations to fix permanent retention flags on rdp_record_series.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE rdp_record_series
            SET is_retention_period_permanent = FALSE
            FROM rdp_retention_period
            WHERE rdp_record_series.retention_period = rdp_retention_period.id
              AND LOWER(COALESCE(rdp_retention_period.total_period, '')) != 'permanent'
              AND LOWER(COALESCE(rdp_retention_period.active_period, '')) != 'permanent'
        ");

        // Any record series without retention period is also not permanent
        DB::statement("
            UPDATE rdp_record_series
            SET is_retention_period_permanent = FALSE
            WHERE retention_period IS NULL
        ");
    }

    public function down(): void
    {
    }
};
