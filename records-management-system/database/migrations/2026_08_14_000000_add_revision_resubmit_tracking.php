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
                if (!Schema::hasColumn('dts_transactions', 'revision_resubmit_type')) {
                    $table->string('revision_resubmit_type')->nullable()->after('revision_requested_by_office');
                }
                if (!Schema::hasColumn('dts_transactions', 'revision_count')) {
                    $table->integer('revision_count')->default(0)->after('revision_resubmit_type');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('dts_transactions')) {
            Schema::table('dts_transactions', function (Blueprint $table) {
                $columns = [];
                if (Schema::hasColumn('dts_transactions', 'revision_resubmit_type')) {
                    $columns[] = 'revision_resubmit_type';
                }
                if (Schema::hasColumn('dts_transactions', 'revision_count')) {
                    $columns[] = 'revision_count';
                }
                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
