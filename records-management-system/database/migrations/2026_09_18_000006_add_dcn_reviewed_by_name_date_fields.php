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
            if (! Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_name')) {
                $table->string('reviewed_by_name')->nullable()->after('reviewed_by_date');
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_on')) {
                $table->date('reviewed_by_on')->nullable()->after('reviewed_by_name');
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_name_2')) {
                $after = Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_date_2')
                    ? 'reviewed_by_date_2'
                    : 'reviewed_by_on';
                $table->string('reviewed_by_name_2')->nullable()->after($after);
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_on_2')) {
                $table->date('reviewed_by_on_2')->nullable()->after('reviewed_by_name_2');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_document_change_notice')) {
            return;
        }

        Schema::table('dcs_document_change_notice', function (Blueprint $table) {
            foreach (['reviewed_by_on_2', 'reviewed_by_name_2', 'reviewed_by_on', 'reviewed_by_name'] as $col) {
                if (Schema::hasColumn('dcs_document_change_notice', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
