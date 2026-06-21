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
        if (Schema::hasTable('condition_key')) {
            Schema::table('condition_key', function (Blueprint $table) {
                if (!Schema::hasColumn('condition_key', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('modifier_key');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('condition_key')) {
            Schema::table('condition_key', function (Blueprint $table) {
                if (Schema::hasColumn('condition_key', 'is_active')) {
                    $table->dropColumn('is_active');
                }
            });
        }
    }
};
