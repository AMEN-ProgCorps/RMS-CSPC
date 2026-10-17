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
        'enable_popout_notifications',
        'enable_top_tabs',
        'theme',
        'modal_close_key',
        'sidebar_toggle_key',
        'action_toggle_key',
        'notification_toggle_key',
        'chatify_toggle_key',
        'dcs_db_visible_groups',
        'follow_scroll_enabled',
        'follow_scroll_side',
        'follow_scroll_vertical',
        'muted_notification_types',
    ];

    protected $casts = [
        'auto_open_chat' => 'boolean',
        'notification_sound_alert' => 'boolean',
        'enable_popout_notifications' => 'boolean',
        'enable_top_tabs' => 'boolean',
        'dcs_db_visible_groups' => 'array',
        'follow_scroll_enabled' => 'boolean',
        'follow_scroll_side' => 'boolean',
        'follow_scroll_vertical' => 'boolean',
        'muted_notification_types' => 'array',
    ];

    /**
     * Relationship back to the User/Account model.
     */
    public function userAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user', 'id');
    }
}
