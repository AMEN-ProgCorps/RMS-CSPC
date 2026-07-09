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
        Schema::table('dts_sequence_list', function (Blueprint $table) {
            $table->timestamp('date_in')->nullable()->after('office_code');
            $table->timestamp('date_out')->nullable()->after('date_in');
            $table->string('action_needed')->nullable()->after('date_out');
            $table->string('note')->nullable()->after('action_needed');
            $table->string('total_time_completed')->nullable()->after('note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dts_sequence_list', function (Blueprint $table) {
            $table->dropColumn('date_in');
            $table->dropColumn('date_out');
            $table->dropColumn('action_needed');
            $table->dropColumn('note');
            $table->dropColumn('total_time_completed');
        });
    }
};
