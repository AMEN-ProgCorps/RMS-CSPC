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
        if (Schema::hasTable('rdp_record_series')) {
            Schema::table('rdp_record_series', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_record_series', 'is_superseding')) {
                    $table->boolean('is_superseding')->default(false)->after('is_active');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_record_series') && Schema::hasColumn('rdp_record_series', 'is_superseding')) {
            Schema::table('rdp_record_series', function (Blueprint $table) {
                $table->dropColumn('is_superseding');
            });
        }
    }
};
