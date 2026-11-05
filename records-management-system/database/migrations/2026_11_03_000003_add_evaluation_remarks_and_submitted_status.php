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
        // 1. Add evaluation_remarks to rdp_pending_record_series
        if (Schema::hasTable('rdp_pending_record_series')) {
            Schema::table('rdp_pending_record_series', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_pending_record_series', 'evaluation_remarks')) {
                    $table->text('evaluation_remarks')->nullable()->after('status_id');
                }
            });
        }

        // 2. Add evaluation_remarks to rdp_pending_record
        if (Schema::hasTable('rdp_pending_record')) {
            Schema::table('rdp_pending_record', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_pending_record', 'evaluation_remarks')) {
                    $table->text('evaluation_remarks')->nullable()->after('status_id');
                }
            });
        }

        // 3. Ensure status 5 'Submitted' exists in rdp_pending_status
        if (Schema::hasTable('rdp_pending_status')) {
            $exists = DB::table('rdp_pending_status')->where('id', 5)->exists();
            if (!$exists) {
                DB::table('rdp_pending_status')->insert([
                    'id'          => 5,
                    'status_name' => 'Submitted',
                    'is_active'   => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_pending_record_series')) {
            Schema::table('rdp_pending_record_series', function (Blueprint $table) {
                if (Schema::hasColumn('rdp_pending_record_series', 'evaluation_remarks')) {
                    $table->dropColumn('evaluation_remarks');
                }
            });
        }

        if (Schema::hasTable('rdp_pending_record')) {
            Schema::table('rdp_pending_record', function (Blueprint $table) {
                if (Schema::hasColumn('rdp_pending_record', 'evaluation_remarks')) {
                    $table->dropColumn('evaluation_remarks');
                }
            });
        }

        if (Schema::hasTable('rdp_pending_status')) {
            DB::table('rdp_pending_status')->where('id', 5)->delete();
        }
    }
};
