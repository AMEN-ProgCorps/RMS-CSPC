<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DcsDbCustomColumnValue extends Model
{
    protected $table = 'dcs_db_custom_column_values';

    protected $fillable = [
        'custom_column_id',
        'request_id',
        'value',
        'updated_by',
    ];

    protected $casts = [
        'custom_column_id' => 'integer',
        'request_id' => 'integer',
        'updated_by' => 'integer',
    ];

    public function column(): BelongsTo
    {
        return $this->belongsTo(DcsDbCustomColumn::class, 'custom_column_id');
    }
}
