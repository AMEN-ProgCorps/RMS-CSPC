<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_signatory_templates')) {
            return;
        }

        if (! Schema::hasColumn('dcs_signatory_templates', 'college_id')) {
            Schema::table('dcs_signatory_templates', function (Blueprint $table) {
                $table->unsignedBigInteger('college_id')->nullable()->after('doc_type_id')->index();
            });
        }

        if (Schema::hasTable('dcs_colleges')) {
            try {
                Schema::table('dcs_signatory_templates', function (Blueprint $table) {
                    $table->foreign('college_id', 'dcs_sig_templates_college_fk')
                        ->references('id')
                        ->on('dcs_colleges')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_signatory_templates')) {
            return;
        }

        try {
            Schema::table('dcs_signatory_templates', function (Blueprint $table) {
                $table->dropForeign('dcs_sig_templates_college_fk');
            });
        } catch (\Throwable) {
        }

        if (Schema::hasColumn('dcs_signatory_templates', 'college_id')) {
            Schema::table('dcs_signatory_templates', function (Blueprint $table) {
                $table->dropColumn('college_id');
            });
        }
    }
};
