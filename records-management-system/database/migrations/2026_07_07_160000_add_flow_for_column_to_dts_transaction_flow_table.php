<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dts_transaction_flow', function (Blueprint $table) {
            $table->enum('flow_for', ['system', 'user', 'office'])->default('system')->after('flow_use');
        });

        // Ensure all existing flows default to system
        DB::table('dts_transaction_flow')->update(['flow_for' => 'system']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dts_transaction_flow', function (Blueprint $table) {
            $table->dropColumn('flow_for');
        });
    }
};
