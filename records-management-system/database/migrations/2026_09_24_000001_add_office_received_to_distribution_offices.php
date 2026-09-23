<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_distribution_offices')) {
            return;
        }

        Schema::table('dcs_distribution_offices', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_distribution_offices', 'office_received_at')) {
                $table->timestamp('office_received_at')->nullable();
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'office_received_by')) {
                $table->unsignedBigInteger('office_received_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_distribution_offices')) {
            return;
        }

        Schema::table('dcs_distribution_offices', function (Blueprint $table) {
            foreach (['office_received_by', 'office_received_at'] as $col) {
                if (Schema::hasColumn('dcs_distribution_offices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
