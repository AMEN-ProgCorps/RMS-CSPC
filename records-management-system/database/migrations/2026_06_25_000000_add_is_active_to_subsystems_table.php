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
        if (Schema::hasTable('subsystems')) {
            Schema::table('subsystems', function (Blueprint $table) {
                if (!Schema::hasColumn('subsystems', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('subsystem_version');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('subsystems')) {
            Schema::table('subsystems', function (Blueprint $table) {
                if (Schema::hasColumn('subsystems', 'is_active')) {
                    $table->dropColumn('is_active');
                }
            });
        }
    }
};
