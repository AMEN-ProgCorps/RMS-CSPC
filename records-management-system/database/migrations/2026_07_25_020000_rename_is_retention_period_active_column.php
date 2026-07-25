<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rdp_record_series', function (Blueprint $table) {
            if (Schema::hasColumn('rdp_record_series', 'is_retention_period_active') && !Schema::hasColumn('rdp_record_series', 'is_retention_period_permanent')) {
                $table->renameColumn('is_retention_period_active', 'is_retention_period_permanent');
            } elseif (!Schema::hasColumn('rdp_record_series', 'is_retention_period_permanent')) {
                $table->boolean('is_retention_period_permanent')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rdp_record_series', function (Blueprint $table) {
            if (Schema::hasColumn('rdp_record_series', 'is_retention_period_permanent')) {
                $table->renameColumn('is_retention_period_permanent', 'is_retention_period_active');
            }
        });
    }
};
