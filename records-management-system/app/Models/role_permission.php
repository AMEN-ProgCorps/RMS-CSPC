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
        'dcs_view_all_documents',
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
        'can_access_dcs_admin',
        'can_access_settings',
        'can_access_recycle_bin',
        'rdp_view_all_files',
        'is_rdp_view_all_pending_list',
        'can_rdp_modify_series',
        'can_rdp_generate_reports',
        // Per-form clearances
        'can_rdp_access_form_1',
        'can_rdp_access_form_2',
        'can_rdp_access_form_3',
        'can_rdp_modify_form_1',
        'can_rdp_modify_form_2',
        'can_rdp_modify_form_3',
        'can_rdp_print_form_1',
        'can_rdp_print_form_2',
        'can_rdp_print_form_3',
        'can_rdp_view_others_form_1',
        'can_rdp_view_others_form_2',
        'can_rdp_view_others_form_3',
        'can_rdp_edit_others_form_1',
        'can_rdp_edit_others_form_2',
        'can_rdp_edit_others_form_3',
        'can_rdp_print_others_form_1',
        'can_rdp_print_others_form_2',
        'can_rdp_print_others_form_3',
    ];

    protected $casts = [
        'key_id' => 'integer',
        'is_sadm' => 'boolean',
        'is_admin' => 'boolean',
        'can_access_dts' => 'boolean',
        'can_access_rdp' => 'boolean',
        'can_access_dcs' => 'boolean',
        'dcs_view_all_documents' => 'boolean',
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
        'can_access_dcs_admin' => 'boolean',
        'can_access_settings' => 'boolean',
        'can_access_recycle_bin' => 'boolean',
        'rdp_view_all_files'               => 'boolean',
        'is_rdp_view_all_pending_list'     => 'boolean',
        'can_rdp_modify_series'             => 'boolean',
        'can_rdp_generate_reports'          => 'boolean',
        // Per-form clearances
        'can_rdp_access_form_1'             => 'boolean',
        'can_rdp_access_form_2'             => 'boolean',
        'can_rdp_access_form_3'             => 'boolean',
        'can_rdp_modify_form_1'             => 'boolean',
        'can_rdp_modify_form_2'             => 'boolean',
        'can_rdp_modify_form_3'             => 'boolean',
        'can_rdp_print_form_1'              => 'boolean',
        'can_rdp_print_form_2'              => 'boolean',
        'can_rdp_print_form_3'              => 'boolean',
        'can_rdp_view_others_form_1'        => 'boolean',
        'can_rdp_view_others_form_2'        => 'boolean',
        'can_rdp_view_others_form_3'        => 'boolean',
        'can_rdp_edit_others_form_1'        => 'boolean',
        'can_rdp_edit_others_form_2'        => 'boolean',
        'can_rdp_edit_others_form_3'        => 'boolean',
        'can_rdp_print_others_form_1'       => 'boolean',
        'can_rdp_print_others_form_2'       => 'boolean',
        'can_rdp_print_others_form_3'       => 'boolean',
    ];
}
