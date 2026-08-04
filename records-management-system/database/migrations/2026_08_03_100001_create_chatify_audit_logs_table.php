<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chatify Audit Logs Migration
 * ============================================================
 * Records user actions performed inside Chatify for admin
 * audit trail purposes.
 *
 * Logged actions:
 *   send_message    — sent a global chat message
 *   send_dm         — sent a direct message
 *   edit_message    — edited an existing message
 *   delete_message  — deleted / cleared a conversation
 *   upload_file     — uploaded a file to global chat
 *   upload_dm_file  — uploaded a file to a DM conversation
 *
 * Table: chatify_audit_logs
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatify_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // The acting user
            $table->unsignedInteger('account_id');
            $table->foreign('account_id', 'fk_cal_account')
                  ->references('id')
                  ->on('account')
                  ->onDelete('cascade');

            // Action type (e.g. 'send_message', 'edit_message', etc.)
            $table->string('action', 40)->index();

            // Optional reference — msg_uuid, conv_id, filename, etc.
            $table->string('target_id', 100)->nullable();

            // Free-form metadata stored as JSON (e.g. recipient_id, file count)
            $table->jsonb('meta')->nullable();

            // Network info
            $table->string('ip_address', 45)->nullable();

            // When it happened — indexed for time-based queries
            $table->timestampTz('created_at')->useCurrent()->index();
        });

        // Composite index for per-user filtering (common admin query pattern)
        DB::statement('CREATE INDEX idx_cal_account_created ON chatify_audit_logs (account_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::table('chatify_audit_logs', function (Blueprint $table) {
            $table->dropForeign('fk_cal_account');
        });
        Schema::dropIfExists('chatify_audit_logs');
    }
};
