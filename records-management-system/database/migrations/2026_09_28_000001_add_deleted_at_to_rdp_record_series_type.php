<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a soft-delete marker to rdp_record_series_type so that:
     *  - "Deactivate" only flips is_active (type stays listed, NOT in Recycle Bin)
     *  - "Remove" sets deleted_at (type is soft deleted and listed in Recycle Bin)
     */
    public function up(): void
    {
        if (Schema::hasTable('rdp_record_series_type') && ! Schema::hasColumn('rdp_record_series_type', 'deleted_at')) {
            Schema::table('rdp_record_series_type', function (Blueprint $table) {
                $table->timestamp('deleted_at')->nullable()->after('is_active');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_record_series_type') && Schema::hasColumn('rdp_record_series_type', 'deleted_at')) {
            Schema::table('rdp_record_series_type', function (Blueprint $table) {
                $table->dropColumn('deleted_at');
            });
        }
    }
};
