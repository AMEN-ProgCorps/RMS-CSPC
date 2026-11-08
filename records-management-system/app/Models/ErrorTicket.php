<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorTicket extends Model
{
    use HasFactory;

    public const SOURCE_USER = 'user';
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_USER_AUTO = 'user_auto';

    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_USER => 'User Added',
            self::SOURCE_AUTO => 'Automatically Detected',
            self::SOURCE_USER_AUTO => 'User Added | Auto',
        ];
    }

    public function getSourceLabelAttribute(): string
    {
        return self::sourceLabels()[$this->source] ?? 'Automatically Detected';
    }

    protected $table = 'sys_error_tickets';

    protected $fillable = [
        'ticket_number',
        'source',
        'reference',
        'fingerprint',
        'title',
        'description',
        'kind',
        'http_status',
        'url',
        'subsystem',
        'exception_class',
        'file',
        'line',
        'context',
        'status',
        'priority',
        'occurrence_count',
        'last_seen_at',
        'reported_by',
        'assigned_to',
        'admin_notes',
        'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'http_status' => 'integer',
        'line' => 'integer',
        'occurrence_count' => 'integer',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
