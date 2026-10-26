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
        Schema::table('rdp_record', function (Blueprint $table) {
            if (!Schema::hasColumn('rdp_record', 'action_date')) {
                $table->date('action_date')->nullable()->after('transferred_to_nap3_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rdp_record', function (Blueprint $table) {
            if (Schema::hasColumn('rdp_record', 'action_date')) {
                $table->dropColumn('action_date');
            }
        });
    }
};
