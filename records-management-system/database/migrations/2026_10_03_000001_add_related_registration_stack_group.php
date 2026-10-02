<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Related same-number copies share stack_group without becoming revisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_masterlist_registration')
            && ! Schema::hasColumn('dcs_masterlist_registration', 'stack_group')) {
            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->string('stack_group', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dcs_masterlist_registration')
            && Schema::hasColumn('dcs_masterlist_registration', 'stack_group')) {
            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->dropColumn('stack_group');
            });
        }
    }
};
