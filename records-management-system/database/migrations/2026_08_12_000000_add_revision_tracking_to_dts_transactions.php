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
        if (Schema::hasTable('dts_transactions')) {
            Schema::table('dts_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('dts_transactions', 'revision_requested_by_sequence')) {
                    $table->integer('revision_requested_by_sequence')->nullable()->after('sequence');
                }
                if (!Schema::hasColumn('dts_transactions', 'revision_requested_by_office')) {
                    $table->string('revision_requested_by_office')->nullable()->after('revision_requested_by_sequence');
                }
            });
        }

        if (Schema::hasTable('dts_action_options')) {
            $exists = DB::table('dts_action_options')->where('option_name', 'For Revision')->exists();
            if (!$exists) {
                DB::table('dts_action_options')->insert([
                    'option_name' => 'For Revision',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('dts_transactions')) {
            Schema::table('dts_transactions', function (Blueprint $table) {
                if (Schema::hasColumn('dts_transactions', 'revision_requested_by_sequence')) {
                    $table->dropColumn(['revision_requested_by_sequence', 'revision_requested_by_office']);
                }
            });
        }
    }
};
