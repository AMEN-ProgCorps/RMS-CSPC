<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the registered document number a standalone office DRF is recurring,
 * so RFIO registration opens as Recurring (same as admin DCS Recur).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_office_intake_drf') || Schema::hasColumn('dcs_office_intake_drf', 'previous_document_no')) {
            return;
        }

        Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
            $table->string('previous_document_no', 150)->nullable()->after('doc_title');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_office_intake_drf') || ! Schema::hasColumn('dcs_office_intake_drf', 'previous_document_no')) {
            return;
        }

        Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
            $table->dropColumn('previous_document_no');
        });
    }
};
