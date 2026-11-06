<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messify Chat Backup Table Migration
 * ============================================================
 * Creates the messify_chat_backup table used to archive messages
 * when an administrator clears or deletes conversations.
 *
 * Rows are NEVER hard-deleted from this table — they are only
 * marked inactive (status='inactive', is_active=false) so that
 * an audit trail is always preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messify_chat_backup', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Conversation bucket — mirrors messify_chat_messages.conv_id
            $table->string('conv_id', 30)->index();

            // Sender (nullable — no FK so backup survives user deletion)
            $table->unsignedInteger('sender_id')->nullable();

            // Receiver: NULL for global, set for private DMs
            $table->unsignedInteger('receiver_id')->nullable();

            // Encrypted ciphertext (AES-256-GCM, same as messify_chat_messages)
            $table->text('message');

            // 'text' or 'upload'
            $table->string('msg_type', 10)->default('text');

            // Original timestamps from messify_chat_messages (microsecond precision)
            $table->timestampTz('created_at', 6)->nullable();
            $table->timestampTz('updated_at', 6)->nullable();

            // Original UUID from messify_chat_messages (no unique constraint — same
            // message could theoretically be backed up more than once)
            $table->string('msg_uuid', 32)->index();

            // Backup metadata
            $table->string('status', 10)->default('inactive');  // always 'inactive'
            $table->boolean('is_active')->default(false);       // always false

            // When was this row archived?
            $table->timestampTz('archived_at')->useCurrent();

            // Who triggered the archive? (admin account_id)
            $table->unsignedInteger('archived_by')->nullable();
        });

        DB::statement('CREATE INDEX idx_backup_conv_id ON messify_chat_backup (conv_id)');
        DB::statement('CREATE INDEX idx_backup_msg_uuid ON messify_chat_backup (msg_uuid)');
        DB::statement('CREATE INDEX idx_backup_archived_at ON messify_chat_backup (archived_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('messify_chat_backup');
    }
};
