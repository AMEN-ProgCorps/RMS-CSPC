<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_document_change_notice')) {
            return;
        }

        Schema::table('dcs_document_change_notice', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_date_2')) {
                $table->string('reviewed_by_date_2')->nullable()->after('reviewed_by_date');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_document_change_notice')) {
            return;
        }

        Schema::table('dcs_document_change_notice', function (Blueprint $table) {
            if (Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_date_2')) {
                $table->dropColumn('reviewed_by_date_2');
            }
        });
    }
};
