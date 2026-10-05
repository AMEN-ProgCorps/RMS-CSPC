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
        Schema::table('dts_transaction_details', function (Blueprint $table) {
            $table->string('apply_to_office')->nullable()->after('originated_fromn');
            $table->foreign('apply_to_office')->references('office_code')->on('dts_offices');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('') && Schema::hasColumn('rdp_period_covered', 'volume')) {
            Schema::table('rdp_period_covered', function (Blueprint $table) {
                $table->dropColumn('volume');
            });
        }
    }
};