<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DcsDbCustomColumn extends Model
{
    use SoftDeletes;

    protected $table = 'dcs_db_custom_columns';

    protected $fillable = [
        'label',
        'group_key',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_by' => 'integer',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(DcsDbCustomColumnValue::class, 'custom_column_id');
    }
}
