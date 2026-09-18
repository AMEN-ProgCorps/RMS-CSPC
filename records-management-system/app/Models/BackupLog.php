<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    use HasFactory;

    protected $table = 'sys_backup_logs';

    protected $fillable = [
        'name',
        'snap_type',
        'filename',
        'file_size',
        'tables_count',
        'total_records',
        'categories_included',
        'is_saved_on_cloud',
        'cloud_service_name',
        'is_saved_on_local',
        'status',
        'created_by',
    ];

    protected $casts = [
        'is_saved_on_cloud' => 'boolean',
        'is_saved_on_local' => 'boolean',
        'categories_included' => 'array',
        'tables_count' => 'integer',
        'total_records' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
