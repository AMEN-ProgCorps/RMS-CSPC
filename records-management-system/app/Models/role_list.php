<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class role_list extends Model
{
    use HasFactory, Notifiable;

    protected $table = 'condition_key';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'key_name',
        'key_description',
        'modifier_key',
        'date_created',
        'date_updated',
    ];

    protected $casts = [
        'id' => 'integer',
        'key_name' => 'string',
        'key_description' => 'string',
        'modifier_key' => 'integer',
        'date_created' => 'datetime',
        'date_updated' => 'datetime',
    ];
}
