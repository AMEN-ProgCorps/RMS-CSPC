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
        'can_access_dts',
        'can_access_archv',
        'can_access_dcs',
        'can_modify_docflow',
        'can_modify_accountlist',
        'can_modify_pass',
        'can_modify_user',
        'can_view_all_list',
        'can_view_all_archive',
    ];
    /*
     * The attributes here will be change in the future if there's more permissions to be added, 
     * but for now these are the only permissions that we have in the system.
    */
    protected $casts = [
        'key_id' => 'integer',
        'is_sadm' => 'boolean',
        'can_access_dts' => 'boolean',
        'can_access_archv' => 'boolean',
        'can_access_dcs' => 'boolean',
        'can_modify_docflow' => 'boolean',
        'can_modify_accountlist' => 'boolean',
        'can_modify_pass' => 'boolean',
        'can_modify_user' => 'boolean',
        'can_view_all_list' => 'boolean',
        'can_view_all_archive' => 'boolean',
    ];
}
