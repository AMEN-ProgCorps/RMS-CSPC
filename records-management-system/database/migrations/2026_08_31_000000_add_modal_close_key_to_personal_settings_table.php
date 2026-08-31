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
        if (Schema::hasTable('personal_settings') && !Schema::hasColumn('personal_settings', 'modal_close_key')) {
            Schema::table('personal_settings', function (Blueprint $table) {
                $table->string('modal_close_key', 50)->default('Escape')->after('theme');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('personal_settings') && Schema::hasColumn('personal_settings', 'modal_close_key')) {
            Schema::table('personal_settings', function (Blueprint $table) {
                $table->dropColumn('modal_close_key');
            });
        }
    }
};
