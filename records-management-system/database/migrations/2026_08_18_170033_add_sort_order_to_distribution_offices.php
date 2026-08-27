<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dcs_distribution_offices', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('copies');
        });
    }

    public function down(): void
    {
        Schema::table('dcs_distribution_offices', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
