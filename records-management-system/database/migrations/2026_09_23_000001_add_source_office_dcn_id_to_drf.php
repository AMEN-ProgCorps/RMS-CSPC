<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_document_request_form')) {
            return;
        }

        if (Schema::hasColumn('dcs_document_request_form', 'source_office_dcn_id')) {
            return;
        }

        Schema::table('dcs_document_request_form', function (Blueprint $table) {
            $after = Schema::hasColumn('dcs_document_request_form', 'is_office_intake')
                ? 'is_office_intake'
                : null;
            $col = $table->unsignedBigInteger('source_office_dcn_id')->nullable();
            if ($after) {
                $col->after($after);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_document_request_form')) {
            return;
        }

        if (! Schema::hasColumn('dcs_document_request_form', 'source_office_dcn_id')) {
            return;
        }

        Schema::table('dcs_document_request_form', function (Blueprint $table) {
            $table->dropColumn('source_office_dcn_id');
        });
    }
};
