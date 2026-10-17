<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A DCN saved without "also create DRF" cannot gain a DRF later.
 * DCNs that already have a linked DRF keep that path open.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            return;
        }

        if (! Schema::hasColumn('dcs_office_intake_dcn', 'expects_drf')) {
            Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
                $table->boolean('expects_drf')->default(false);
            });
        }

        if (Schema::hasTable('dcs_office_intake_drf') && Schema::hasColumn('dcs_office_intake_drf', 'source_office_dcn_id')) {
            DB::table('dcs_office_intake_dcn')
                ->whereIn('id', function ($q) {
                    $q->select('source_office_dcn_id')
                        ->from('dcs_office_intake_drf')
                        ->whereNotNull('source_office_dcn_id');
                })
                ->update(['expects_drf' => true]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn') || ! Schema::hasColumn('dcs_office_intake_dcn', 'expects_drf')) {
            return;
        }

        Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
            $table->dropColumn('expects_drf');
        });
    }
};
