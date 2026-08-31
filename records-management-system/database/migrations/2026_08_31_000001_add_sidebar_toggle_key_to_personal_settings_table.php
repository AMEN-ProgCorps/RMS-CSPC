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
        Schema::table('personal_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('personal_settings', 'sidebar_toggle_key')) {
                $table->string('sidebar_toggle_key', 50)->nullable()->default(null)->after('modal_close_key');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_settings', function (Blueprint $table) {
            if (Schema::hasColumn('personal_settings', 'sidebar_toggle_key')) {
                $table->dropColumn('sidebar_toggle_key');
            }
        });
    }
};
