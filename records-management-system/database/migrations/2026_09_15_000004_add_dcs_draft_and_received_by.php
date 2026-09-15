<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_document_requests') && ! Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            Schema::table('dcs_document_requests', function (Blueprint $table) {
                $table->boolean('is_draft')->default(false)->after('approval_status');
                $table->index('is_draft');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dcs_document_requests') && Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            Schema::table('dcs_document_requests', function (Blueprint $table) {
                $table->dropIndex(['is_draft']);
                $table->dropColumn('is_draft');
            });
        }
    }
};
