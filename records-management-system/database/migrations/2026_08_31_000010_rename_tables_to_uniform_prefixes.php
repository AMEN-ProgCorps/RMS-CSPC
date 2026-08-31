<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The table mapping for renaming: [old_name => new_name].
     */
    protected array $tableMappings = [
        // System Domain (sys_)
        'account' => 'sys_account',
        'account_details' => 'sys_account_details',
        'admin_logs' => 'sys_admin_logs',
        'cluster' => 'sys_cluster',
        'condition_defaults' => 'sys_condition_defaults',
        'condition_details' => 'sys_condition_details',
        'condition_key' => 'sys_condition_key',
        'document_data' => 'sys_document_data',
        'folder_data' => 'sys_folder_data',
        'notif_content' => 'sys_notif_content',
        'notification_div' => 'sys_notification_div',
        'notifications' => 'sys_notifications',
        'office' => 'sys_office',
        'personal_settings' => 'sys_personal_settings',
        'security_logs' => 'sys_security_logs',
        'security_status' => 'sys_security_status',
        'subsystems' => 'sys_subsystems',
        'subsystem_versions_log' => 'sys_subsystem_versions_log',
        'system_settings' => 'sys_system_settings',
        'tracking_devices_log' => 'sys_tracking_devices_log',

        // DTS Domain (dts_)
        'hub_flow_datas' => 'dts_hub_flow_datas',
        'sub_document_tracking_system_logs' => 'dts_transaction_logs',
        'sub_document_tracking_system_logs_types' => 'dts_transaction_log_types',

        // RDP Domain (rdp_)
        'main_pending_id' => 'rdp_main_pending_id',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tableMappings as $oldTable => $newTable) {
            if (Schema::hasTable($oldTable) && !Schema::hasTable($newTable)) {
                Schema::rename($oldTable, $newTable);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tableMappings) as $oldTable => $newTable) {
            if (Schema::hasTable($newTable) && !Schema::hasTable($oldTable)) {
                Schema::rename($newTable, $oldTable);
            }
        }
    }
};
