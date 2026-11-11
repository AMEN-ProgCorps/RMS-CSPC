<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SysPageManual extends Model
{
    protected $table = 'sys_page_manuals';

    protected $fillable = [
        'target_key',
        'target_name',
        'subsystem',
        'type',
        'relative_path',
        'title',
        'purpose',
        'line_count',
        'is_custom_mapped',
        'parsed_content',
    ];

    protected $casts = [
        'is_custom_mapped' => 'boolean',
        'parsed_content' => 'array',
        'line_count' => 'integer',
    ];
}
