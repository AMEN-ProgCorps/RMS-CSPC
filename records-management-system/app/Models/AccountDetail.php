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
    protected $table = 'account_details';

    /**
     * Since your schema uses 'id' via increments(), it defaults to auto-incrementing.
     * However, we disable standard 'created_at' and 'updated_at' timestamps 
     * because you have custom 'date_created' and 'date_updated' columns.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     * Match these exactly with your 'account_detail' table columns.
     */
    protected $fillable = [
        'account_id',
        'first_name',
        'last_name',
        'email',
        'contact_number',
        'date_created',
        'date_updated',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'date_created' => 'datetime',
        'date_updated' => 'datetime',
    ];
}
