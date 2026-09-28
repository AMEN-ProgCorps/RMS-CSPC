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
        Schema::table('dts_transaction_flow', function (Blueprint $table) {
            $table->UnsignedBigInteger('contin_rdp_id')->nullable()->after('dts_transaction_flow_id');
            $table->foreign('contin_rdp_id')->references('id')->on('rdp_record_series_type')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dts_transaction_flow', function (Blueprint $table) {
            $table->dropForeign(['contin_rdp_id']);
            $table->dropColumn('contin_rdp_id');
        });
    }
};
