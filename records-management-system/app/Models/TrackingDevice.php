<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingDevice extends Model
{
    protected $fillable = [
        'duid',
        'date_created',
    ];

    protected function casts(): array
    {
        return [
            'date_created' => 'date',
        ];
    }
}
