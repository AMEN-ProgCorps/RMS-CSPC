<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('rdp_pending_record')) {
            Schema::table('rdp_pending_record', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_pending_record', 'is_printed')) {
                    $table->boolean('is_printed')->default(false)->after('is_verified');
                }
                if (!Schema::hasColumn('rdp_pending_record', 'is_downloaded')) {
                    $table->boolean('is_downloaded')->default(false)->after('is_printed');
                }
            });
        }

        if (Schema::hasTable('rdp_pending_record_series')) {
            Schema::table('rdp_pending_record_series', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_pending_record_series', 'is_printed')) {
                    $table->boolean('is_printed')->default(false)->after('is_verified');
                }
                if (!Schema::hasColumn('rdp_pending_record_series', 'is_downloaded')) {
                    $table->boolean('is_downloaded')->default(false)->after('is_printed');
                }
            });
        }

        // For any pre-existing verified/approved clusters, mark them as printed/downloaded so they remain visible in approval history
        if (Schema::hasTable('rdp_pending_record')) {
            DB::table('rdp_pending_record')->where('status_id', '!=', 1)->update([
                'is_printed' => true,
                'is_downloaded' => true,
            ]);
        }
        if (Schema::hasTable('rdp_pending_record_series')) {
            DB::table('rdp_pending_record_series')->where('status_id', '!=', 1)->update([
                'is_printed' => true,
                'is_downloaded' => true,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_pending_record')) {
            Schema::table('rdp_pending_record', function (Blueprint $table) {
                if (Schema::hasColumn('rdp_pending_record', 'is_downloaded')) {
                    $table->dropColumn('is_downloaded');
                }
                if (Schema::hasColumn('rdp_pending_record', 'is_printed')) {
                    $table->dropColumn('is_printed');
                }
            });
        }

        if (Schema::hasTable('rdp_pending_record_series')) {
            Schema::table('rdp_pending_record_series', function (Blueprint $table) {
                if (Schema::hasColumn('rdp_pending_record_series', 'is_downloaded')) {
                    $table->dropColumn('is_downloaded');
                }
                if (Schema::hasColumn('rdp_pending_record_series', 'is_printed')) {
                    $table->dropColumn('is_printed');
                }
            });
        }
    }
};
