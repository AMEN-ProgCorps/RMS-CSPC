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
        if (Schema::hasTable('personal_settings') && !Schema::hasColumn('personal_settings', 'theme')) {
            Schema::table('personal_settings', function (Blueprint $table) {
                $table->string('theme', 20)->default('light')->after('enable_top_tabs');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('personal_settings') && Schema::hasColumn('personal_settings', 'theme')) {
            Schema::table('personal_settings', function (Blueprint $table) {
                $table->dropColumn('theme');
            });
        }
    }
};
