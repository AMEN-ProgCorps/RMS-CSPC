<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fingerprint for generated reports so identical re-exports reuse Manage Files entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_generated_reports')) {
            return;
        }

        if (! Schema::hasColumn('dcs_generated_reports', 'content_fingerprint')) {
            Schema::table('dcs_generated_reports', function (Blueprint $table) {
                $table->string('content_fingerprint', 64)->nullable()->after('period');
                $table->index(['office_code', 'content_fingerprint'], 'dcs_generated_reports_office_fp_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_generated_reports')) {
            return;
        }

        if (Schema::hasColumn('dcs_generated_reports', 'content_fingerprint')) {
            Schema::table('dcs_generated_reports', function (Blueprint $table) {
                $table->dropIndex('dcs_generated_reports_office_fp_idx');
                $table->dropColumn('content_fingerprint');
            });
        }
    }
};
