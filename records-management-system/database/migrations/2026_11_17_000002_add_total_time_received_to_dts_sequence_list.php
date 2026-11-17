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
        if (Schema::hasTable('dts_sequence_list')) {
            Schema::table('dts_sequence_list', function (Blueprint $table) {
                if (!Schema::hasColumn('dts_sequence_list', 'total_time_received')) {
                    $table->string('total_time_received')->nullable()->after('note');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('dts_sequence_list')) {
            Schema::table('dts_sequence_list', function (Blueprint $table) {
                if (Schema::hasColumn('dts_sequence_list', 'total_time_received')) {
                    $table->dropColumn('total_time_received');
                }
            });
        }
    }
};
