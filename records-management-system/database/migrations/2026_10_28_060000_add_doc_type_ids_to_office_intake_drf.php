<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist catalog Document Type / sub-type chosen on office DRF create
 * so RFIO register can prefill subtype and signatory templates can match.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_office_intake_drf')) {
            return;
        }

        Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_office_intake_drf', 'doc_type_id')) {
                $table->unsignedBigInteger('doc_type_id')->nullable()->after('doc_type_kind')->index();
            }
            if (! Schema::hasColumn('dcs_office_intake_drf', 'sub_type_id')) {
                $table->unsignedBigInteger('sub_type_id')->nullable()->after('doc_type_id')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_office_intake_drf')) {
            return;
        }

        Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
            if (Schema::hasColumn('dcs_office_intake_drf', 'sub_type_id')) {
                $table->dropColumn('sub_type_id');
            }
            if (Schema::hasColumn('dcs_office_intake_drf', 'doc_type_id')) {
                $table->dropColumn('doc_type_id');
            }
        });
    }
};
