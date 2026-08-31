<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class AccountDetail extends Model
{
    use HasFactory, Notifiable;

    /**
     * Explicitly point to your custom table name.
     */
    protected $table = 'sys_account_details';

    protected $primaryKey = 'account_id';
    public $incrementing = false;
    protected $keyType = 'int';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     * Match these exactly with your 'account_detail' table columns.
     */
    protected $fillable = [
        'account_id',
        'first_name',
        'last_name',
        'office_id',
        'email',
        'contact_number',
        'is_currently_online',
        'last_online_time',
        'date_created',
        'date_updated',
    ];

    protected $casts = [
        'is_currently_online' => 'boolean',
        'last_online_time'    => 'datetime',
        'date_created'        => 'datetime',
        'date_updated'        => 'datetime',
    ];

    public function office(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(office::class, 'office_id');
    }
}
