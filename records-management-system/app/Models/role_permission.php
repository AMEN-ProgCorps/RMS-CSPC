<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;


class role_permission extends Model
{
    use HasFactory, Notifiable;

    protected $table = 'condition_details';
    protected $primaryKey = 'key_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'key_id',
        'is_sadm',
        'is_admin',
        'can_access_dts',
        'can_access_rdp',
        'can_access_dcs',
        'can_dts_modify_docflow',
        'can_sadm_modify_accountlist',
        'can_sadm_modify_pass',
        'can_sadm_modify_account',
        'can_dts_view_all_list',
        'can_dts_view_all_archive',
        'can_dts_view_all_current_trans',
        'can_dts_create_own_flow',
        'can_dts_use_internal',
        'can_dts_use_external',
        'can_dts_use_application',
        'can_dts_use_issuance',
        'can_dts_user_received',
        'can_dts_modify_transaction',
        'can_dts_modify_control_no',
        'can_access_activity_logs',
        'can_access_subsystems',
        'can_access_dts_admin',
        'can_access_rdp_admin',
        'can_access_settings',
        'can_access_recycle_bin',
    ];
    /*
     * The attributes here will be change in the future if there's more permissions to be added, 
     * but for now these are the only permissions that we have in the system.
     */
    protected $casts = [
        'key_id' => 'integer',
        'is_sadm' => 'boolean',
        'is_admin' => 'boolean',
        'can_access_dts' => 'boolean',
        'can_access_rdp' => 'boolean',
        'can_access_dcs' => 'boolean',
        'can_dts_modify_docflow' => 'boolean',
        'can_sadm_modify_accountlist' => 'boolean',
        'can_sadm_modify_pass' => 'boolean',
        'can_sadm_modify_account' => 'boolean',
        'can_dts_view_all_list' => 'boolean',
        'can_dts_view_all_archive' => 'boolean',
        'can_dts_view_all_current_trans' => 'boolean',
        'can_dts_create_own_flow' => 'boolean',
        'can_dts_use_internal' => 'boolean',
        'can_dts_use_external' => 'boolean',
        'can_dts_use_application' => 'boolean',
        'can_dts_use_issuance' => 'boolean',
        'can_dts_user_received' => 'boolean',
        'can_dts_modify_transaction' => 'boolean',
        'can_dts_modify_control_no' => 'boolean',
        'can_access_activity_logs' => 'boolean',
        'can_access_subsystems' => 'boolean',
        'can_access_dts_admin' => 'boolean',
        'can_access_rdp_admin' => 'boolean',
        'can_access_settings' => 'boolean',
        'can_access_recycle_bin' => 'boolean',
    ];
}
