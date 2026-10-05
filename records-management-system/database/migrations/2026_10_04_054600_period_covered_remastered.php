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
        if (Schema::hasTable('rdp_period_covered')) {
            Schema::table('rdp_period_covered', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_period_covered', 'date_covered_end')) {
                    $table->timestamp('date_covered_end')->nullable()->after('date_covered');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_period_covered')) {
            Schema::table('rdp_period_covered', function (Blueprint $table) {
                if (Schema::hasColumn('rdp_period_covered', 'date_covered_end')) {
                    $table->dropColumn('date_covered_end');
                }
            });
        }
    }
};
