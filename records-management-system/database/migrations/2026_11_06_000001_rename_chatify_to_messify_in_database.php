<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table mappings for renaming Chatify tables to Messify: [old_table => new_table]
     */
    protected array $tableMappings = [
        'chat_messages'              => 'messify_chat_messages',
        'chat_reactions'             => 'messify_chat_reactions',
        'chat_read_markers'          => 'messify_chat_read_markers',
        'chatify_chat_backup'        => 'messify_chat_backup',
        'chat_conversations'         => 'messify_chat_conversations',
        'chatify_legal_agreements'   => 'messify_chat_legal_agreements',
        'messify_legal_agreements'   => 'messify_chat_legal_agreements',
        'chatify_audit_logs'         => 'messify_chat_audit_logs',
        'messify_audit_logs'         => 'messify_chat_audit_logs',
        'chat_message_mentions'      => 'messify_chat_message_mentions',
        'chat_custom_groups'         => 'messify_chat_custom_groups',
        'chat_custom_group_members'  => 'messify_chat_custom_group_members',
        'chatify_backup_jobs'        => 'messify_chat_backup_jobs',
        'chat_office_gc_reads'       => 'messify_chat_office_gc_reads',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Rename tables from Chatify to Messify
        foreach ($this->tableMappings as $oldTable => $newTable) {
            if (Schema::hasTable($oldTable) && !Schema::hasTable($newTable)) {
                Schema::rename($oldTable, $newTable);
            }
        }

        // 2. Rename columns in account_details (or sys_account_details)
        foreach (['account_details', 'sys_account_details'] as $table) {
            if (Schema::hasTable($table)) {
                if (Schema::hasColumn($table, 'is_chatify_verified') && !Schema::hasColumn($table, 'is_messify_verified')) {
                    Schema::table($table, function (Blueprint $tableBlueprint) {
                        $tableBlueprint->renameColumn('is_chatify_verified', 'is_messify_verified');
                    });
                }
            }
        }

        // 3. Rename columns in personal_settings (or sys_personal_settings)
        foreach (['personal_settings', 'sys_personal_settings'] as $table) {
            if (Schema::hasTable($table)) {
                if (Schema::hasColumn($table, 'chatify_toggle_key') && !Schema::hasColumn($table, 'messify_toggle_key')) {
                    Schema::table($table, function (Blueprint $tableBlueprint) {
                        $tableBlueprint->renameColumn('chatify_toggle_key', 'messify_toggle_key');
                    });
                }
            }
        }

        // 4. Update subsystems entry from Chatify to Messify
        foreach (['subsystems', 'sys_subsystems'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)
                    ->where('subsystem_name', 'Chatify')
                    ->update([
                        'subsystem_name' => 'Messify',
                        'update_at'      => now(),
                    ]);

                $messifyExists = DB::table($table)->where('subsystem_name', 'Messify')->exists();
                if (!$messifyExists) {
                    $insertData = [
                        'subsystem_name'    => 'Messify',
                        'subsystem_version' => '1.0.0',
                        'created_at'        => now(),
                        'update_at'         => now(),
                    ];
                    if (Schema::hasColumn($table, 'is_active')) {
                        $insertData['is_active'] = true;
                    }
                    DB::table($table)->insert($insertData);
                }
            }
        }

        // 5. Update or recreate view_user_unread_chats to point to messify_chat_conversations
        if (Schema::hasTable('messify_chat_conversations')) {
            DB::statement("
                CREATE OR REPLACE VIEW view_user_unread_chats AS
                SELECT 
                    u.account_id,
                    COALESCE(SUM(CASE WHEN cc.user_1 = u.account_id THEN cc.unread_user_1 ELSE cc.unread_user_2 END), 0)::BIGINT AS total_unread
                FROM (
                    SELECT user_1 AS account_id FROM messify_chat_conversations WHERE is_active = true
                    UNION
                    SELECT user_2 AS account_id FROM messify_chat_conversations WHERE is_active = true
                ) u
                JOIN messify_chat_conversations cc 
                    ON (cc.user_1 = u.account_id OR cc.user_2 = u.account_id)
                   AND cc.is_active = true
                GROUP BY u.account_id;
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Recreate view to use chat_conversations if reverting
        if (Schema::hasTable('messify_chat_conversations') || Schema::hasTable('chat_conversations')) {
            $convTable = Schema::hasTable('chat_conversations') ? 'chat_conversations' : 'messify_chat_conversations';
            DB::statement("
                CREATE OR REPLACE VIEW view_user_unread_chats AS
                SELECT 
                    u.account_id,
                    COALESCE(SUM(CASE WHEN cc.user_1 = u.account_id THEN cc.unread_user_1 ELSE cc.unread_user_2 END), 0)::BIGINT AS total_unread
                FROM (
                    SELECT user_1 AS account_id FROM {$convTable} WHERE is_active = true
                    UNION
                    SELECT user_2 AS account_id FROM {$convTable} WHERE is_active = true
                ) u
                JOIN {$convTable} cc 
                    ON (cc.user_1 = u.account_id OR cc.user_2 = u.account_id)
                   AND cc.is_active = true
                GROUP BY u.account_id;
            ");
        }

        // 2. Revert column names in personal_settings (or sys_personal_settings)
        foreach (['personal_settings', 'sys_personal_settings'] as $table) {
            if (Schema::hasTable($table)) {
                if (Schema::hasColumn($table, 'messify_toggle_key') && !Schema::hasColumn($table, 'chatify_toggle_key')) {
                    Schema::table($table, function (Blueprint $tableBlueprint) {
                        $tableBlueprint->renameColumn('messify_toggle_key', 'chatify_toggle_key');
                    });
                }
            }
        }

        // 3. Revert column names in account_details (or sys_account_details)
        foreach (['account_details', 'sys_account_details'] as $table) {
            if (Schema::hasTable($table)) {
                if (Schema::hasColumn($table, 'is_messify_verified') && !Schema::hasColumn($table, 'is_chatify_verified')) {
                    Schema::table($table, function (Blueprint $tableBlueprint) {
                        $tableBlueprint->renameColumn('is_messify_verified', 'is_chatify_verified');
                    });
                }
            }
        }

        // 4. Revert subsystems table entry
        foreach (['subsystems', 'sys_subsystems'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)
                    ->where('subsystem_name', 'Messify')
                    ->update([
                        'subsystem_name' => 'Chatify',
                        'update_at'      => now(),
                    ]);
            }
        }

        // 5. Revert tables from Messify to Chatify
        $reversalMappings = [
            'messify_chat_office_gc_reads'      => 'chat_office_gc_reads',
            'messify_chat_backup_jobs'          => 'chatify_backup_jobs',
            'messify_chat_custom_group_members' => 'chat_custom_group_members',
            'messify_chat_custom_groups'        => 'chat_custom_groups',
            'messify_chat_message_mentions'     => 'chat_message_mentions',
            'messify_chat_audit_logs'           => 'chatify_audit_logs',
            'messify_chat_legal_agreements'     => 'chatify_legal_agreements',
            'messify_chat_conversations'        => 'chat_conversations',
            'messify_chat_backup'               => 'chatify_chat_backup',
            'messify_chat_read_markers'         => 'chat_read_markers',
            'messify_chat_reactions'            => 'chat_reactions',
            'messify_chat_messages'             => 'chat_messages',
        ];

        foreach ($reversalMappings as $currentTable => $previousTable) {
            if (Schema::hasTable($currentTable) && !Schema::hasTable($previousTable)) {
                Schema::rename($currentTable, $previousTable);
            }
        }
    }
};
