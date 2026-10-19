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
        if (Schema::hasTable('rdp_record')) {
            Schema::table('rdp_record', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_record', 'is_request')) {
                    $table->boolean('is_request')->default(false)->index();
                }
                if (!Schema::hasColumn('rdp_record', 'request_group_id')) {
                    $table->string('request_group_id', 64)->nullable()->index();
                }
                if (!Schema::hasColumn('rdp_record', 'request_status')) {
                    $table->string('request_status', 32)->nullable()->index();
                }
                if (!Schema::hasColumn('rdp_record', 'request_origin_office')) {
                    $table->string('request_origin_office', 64)->nullable()->index();
                }
                if (!Schema::hasColumn('rdp_record', 'request_target_office')) {
                    $table->string('request_target_office', 64)->nullable()->index();
                }
                if (!Schema::hasColumn('rdp_record', 'request_decline_reason')) {
                    $table->text('request_decline_reason')->nullable();
                }
                if (!Schema::hasColumn('rdp_record', 'request_actioned_by')) {
                    $table->unsignedBigInteger('request_actioned_by')->nullable();
                }
                if (!Schema::hasColumn('rdp_record', 'request_actioned_at')) {
                    $table->timestamp('request_actioned_at')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_record')) {
            Schema::table('rdp_record', function (Blueprint $table) {
                $cols = [
                    'is_request',
                    'request_group_id',
                    'request_status',
                    'request_origin_office',
                    'request_target_office',
                    'request_decline_reason',
                    'request_actioned_by',
                    'request_actioned_at',
                ];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('rdp_record', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
