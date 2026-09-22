<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published documents require HEAD Admin approval before Document Controllers can edit.
 * Drafts remain freely editable; HEAD Admin (dcs_can_recycle_bin) bypasses the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_document_requests')) {
            return;
        }

        Schema::table('dcs_document_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_document_requests', 'edit_request_status')) {
                $table->string('edit_request_status', 20)->nullable()->after('deleted_reason');
            }
            if (! Schema::hasColumn('dcs_document_requests', 'edit_request_reason')) {
                $table->text('edit_request_reason')->nullable()->after('edit_request_status');
            }
            if (! Schema::hasColumn('dcs_document_requests', 'edit_request_by')) {
                $table->unsignedBigInteger('edit_request_by')->nullable()->after('edit_request_reason');
            }
            if (! Schema::hasColumn('dcs_document_requests', 'edit_request_at')) {
                $table->timestamp('edit_request_at')->nullable()->after('edit_request_by');
            }
            if (! Schema::hasColumn('dcs_document_requests', 'edit_reviewed_by')) {
                $table->unsignedBigInteger('edit_reviewed_by')->nullable()->after('edit_request_at');
            }
            if (! Schema::hasColumn('dcs_document_requests', 'edit_reviewed_at')) {
                $table->timestamp('edit_reviewed_at')->nullable()->after('edit_reviewed_by');
            }
            if (! Schema::hasColumn('dcs_document_requests', 'edit_review_note')) {
                $table->text('edit_review_note')->nullable()->after('edit_reviewed_at');
            }
            if (! Schema::hasColumn('dcs_document_requests', 'edit_unlocked_at')) {
                $table->timestamp('edit_unlocked_at')->nullable()->after('edit_review_note');
            }
            if (! Schema::hasColumn('dcs_document_requests', 'edit_unlocked_by')) {
                $table->unsignedBigInteger('edit_unlocked_by')->nullable()->after('edit_unlocked_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_document_requests')) {
            return;
        }

        $cols = [
            'edit_request_status',
            'edit_request_reason',
            'edit_request_by',
            'edit_request_at',
            'edit_reviewed_by',
            'edit_reviewed_at',
            'edit_review_note',
            'edit_unlocked_at',
            'edit_unlocked_by',
        ];

        Schema::table('dcs_document_requests', function (Blueprint $table) use ($cols) {
            foreach ($cols as $col) {
                if (Schema::hasColumn('dcs_document_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
