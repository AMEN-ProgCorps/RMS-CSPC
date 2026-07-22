<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migration to drop chat_conversations table if it exists.
     * All chat messages (global + DMs) are unified in chat_messages table.
     */
    public function up(): void
    {
        Schema::dropIfExists('chat_conversations');
    }

    public function down(): void
    {
        // No-op: chat_conversations table is permanently removed in favor of unified chat_messages table
    }
};
