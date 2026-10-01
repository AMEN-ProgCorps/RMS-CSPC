<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rdp_record_series') && !Schema::hasColumn('rdp_record_series', 'created_by')) {
            Schema::table('rdp_record_series', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->after('recorded_at_office');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rdp_record_series') && Schema::hasColumn('rdp_record_series', 'created_by')) {
            Schema::table('rdp_record_series', function (Blueprint $table) {
                $table->dropColumn('created_by');
            });
        }
    }
};
