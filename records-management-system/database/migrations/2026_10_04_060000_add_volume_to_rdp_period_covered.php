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
        if (Schema::hasTable('rdp_period_covered') && !Schema::hasColumn('rdp_period_covered', 'volume')) {
            Schema::table('rdp_period_covered', function (Blueprint $table) {
                $table->string('volume')->nullable()->after('date_covered_end');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_period_covered') && Schema::hasColumn('rdp_period_covered', 'volume')) {
            Schema::table('rdp_period_covered', function (Blueprint $table) {
                $table->dropColumn('volume');
            });
        }
    }
};
