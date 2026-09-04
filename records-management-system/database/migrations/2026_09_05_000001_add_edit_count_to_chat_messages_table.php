<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_messages', 'edit_count')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->unsignedSmallInteger('edit_count')->default(0);
            });

            // Backfill existing edited messages to count 1
            DB::table('chat_messages')
                ->where('is_edited', true)
                ->where('edit_count', 0)
                ->update(['edit_count' => 1]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'edit_count')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('edit_count');
            });
        }
    }
};
