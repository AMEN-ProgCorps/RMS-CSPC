<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_document_requests')) {
            return;
        }

        Schema::table('dcs_document_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_document_requests', 'deleted_reason')) {
                $table->text('deleted_reason')->nullable()->after('deleted_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_document_requests')) {
            return;
        }

        Schema::table('dcs_document_requests', function (Blueprint $table) {
            if (Schema::hasColumn('dcs_document_requests', 'deleted_reason')) {
                $table->dropColumn('deleted_reason');
            }
        });
    }
};
