<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds `chatify_reaction_emojis` to `account_details` (or `sys_account_details`)
     * to store each user's custom 3 reaction emojis as a JSON array (defaulting to
     * ["😆","❤️","👍"]), and widens `messify_chat_reactions.emoji` to VARCHAR(32)
     * so any multi-codepoint emoji from Chatify's independent emoji catalog can be stored.
     */
    public function up(): void
    {
        foreach (['account_details', 'sys_account_details'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'chatify_reaction_emojis')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->text('chatify_reaction_emojis')
                        ->nullable()
                        ->default('["😆","❤️","👍"]');
                });

                DB::table($table)
                    ->whereNull('chatify_reaction_emojis')
                    ->update(['chatify_reaction_emojis' => '["😆","❤️","👍"]']);
            }
        }

        foreach (['messify_chat_reactions', 'chat_reactions'] as $reactionTable) {
            if (Schema::hasTable($reactionTable) && Schema::hasColumn($reactionTable, 'emoji')) {
                try {
                    DB::statement("ALTER TABLE {$reactionTable} ALTER COLUMN emoji TYPE VARCHAR(32)");
                } catch (\Throwable $e) {
                    // Ignore if already widened or driver syntax differs
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['account_details', 'sys_account_details'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'chatify_reaction_emojis')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('chatify_reaction_emojis');
                });
            }
        }
    }
};
