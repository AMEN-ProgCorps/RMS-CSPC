<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Explicitly point to your custom table name.
     */
    protected $table = 'account';

    /**
     * Since your schema uses 'id' via increments(), it defaults to auto-incrementing.
     * However, we disable standard 'created_at' and 'updated_at' timestamps 
     * because you have custom 'date_created' and 'date_updated' columns.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     * Match these exactly with your 'account' table columns.
     */
    protected $fillable = [
        'username',
        'password',
        'account_status',
        'account_active',
        'date_created',
        'date_updated',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'password' => 'hashed',
        'account_active' => 'boolean',
        'account_status' => 'integer',
        'date_created' => 'datetime',
        'date_updated' => 'datetime',
    ];

    public function details(): HasOne
    {
        return $this->hasOne(AccountDetail::class, 'account_id');
    }
}