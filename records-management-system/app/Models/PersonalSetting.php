<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalSetting extends Model
{
    use HasFactory;

    protected $table = 'personal_settings';

    protected $fillable = [
        'user',
        'auto_open_chat',
        'notification_sound_alert',
        'enable_top_tabs',
    ];

    protected $casts = [
        'auto_open_chat' => 'boolean',
        'notification_sound_alert' => 'boolean',
        'enable_top_tabs' => 'boolean',
    ];

    /**
     * Relationship back to the User/Account model.
     */
    public function userAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user', 'id');
    }
}
