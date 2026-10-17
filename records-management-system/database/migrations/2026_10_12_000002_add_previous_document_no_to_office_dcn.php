<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the registered document number a DCN is revising when the office
 * chooses a new number for the next revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn') || Schema::hasColumn('dcs_office_intake_dcn', 'previous_document_no')) {
            return;
        }

        Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
            $table->string('previous_document_no', 150)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn') || ! Schema::hasColumn('dcs_office_intake_dcn', 'previous_document_no')) {
            return;
        }

        Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
            $table->dropColumn('previous_document_no');
        });
    }
};
