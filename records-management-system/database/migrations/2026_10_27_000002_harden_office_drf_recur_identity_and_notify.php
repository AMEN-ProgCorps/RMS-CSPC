<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring office DRF must remember the exact masterlist row (same doc_no can
 * belong to different titles). Also defer "Your DRF/DCN was registered" until
 * appraisal finishes when Save and Appraise was used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_office_intake_drf')) {
            Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_office_intake_drf', 'previous_document_title')) {
                    $table->text('previous_document_title')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'previous_originator')) {
                    $table->text('previous_originator')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'previous_masterlist_id')) {
                    $table->unsignedBigInteger('previous_masterlist_id')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'previous_request_id')) {
                    $table->unsignedBigInteger('previous_request_id')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'registered_notify_pending')) {
                    $table->boolean('registered_notify_pending')->default(false);
                }
            });
        }

        if (Schema::hasTable('dcs_office_intake_dcn') && ! Schema::hasColumn('dcs_office_intake_dcn', 'registered_notify_pending')) {
            Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
                $table->boolean('registered_notify_pending')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dcs_office_intake_drf')) {
            Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
                foreach ([
                    'previous_document_title',
                    'previous_originator',
                    'previous_masterlist_id',
                    'previous_request_id',
                    'registered_notify_pending',
                ] as $col) {
                    if (Schema::hasColumn('dcs_office_intake_drf', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('dcs_office_intake_dcn') && Schema::hasColumn('dcs_office_intake_dcn', 'registered_notify_pending')) {
            Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
                $table->dropColumn('registered_notify_pending');
            });
        }
    }
};
