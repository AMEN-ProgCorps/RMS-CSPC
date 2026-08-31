<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalSetting extends Model
{
    use HasFactory;

    protected $table = 'sys_personal_settings';

    protected $fillable = [
        'user',
        'auto_open_chat',
        'notification_sound_alert',
        'enable_top_tabs',
        'theme',
        'modal_close_key',
        'sidebar_toggle_key',
        'action_toggle_key',
        'notification_toggle_key',
        'chatify_toggle_key',
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
