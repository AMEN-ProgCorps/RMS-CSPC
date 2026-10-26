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
        if (Schema::hasTable('rdp_duplication_section')) {
            Schema::table('rdp_duplication_section', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_duplication_section', 'can_view_file')) {
                    $table->boolean('can_view_file')->default(false);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_duplication_section')) {
            Schema::table('rdp_duplication_section', function (Blueprint $table) {
                if (Schema::hasColumn('rdp_duplication_section', 'can_view_file')) {
                    $table->dropColumn('can_view_file');
                }
            });
        }
    }
};
