<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\role_permission;
use App\Models\role_list;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Explicitly point to your custom table name.
     */
    protected $table = 'account';

    /**
     * Since your schema uses 'id' via increments(), it defaults to auto-incrementing.
     * However, we disable standard 'created_at' and 'updated_at' timestamps 
     * because you have custom 'date_created' and 'date_updated' columns.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     * Match these exactly with your 'account' table columns.
     */
    protected $fillable = [
        'username',
        'password',
        'account_status',
        'account_role',
        'account_active',
        'date_created',
        'date_updated',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'password' => 'hashed',
        'account_active' => 'boolean',
        'account_status' => 'integer',
        'account_role' => 'integer',
        'date_created' => 'datetime',
        'date_updated' => 'datetime',
    ];
    /**
     * Accessor for account_id alias (maps to the primary key id).
     */
    public function getAccountIdAttribute()
    {
        return $this->id;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->details?->email;
    }

    public function getFirstNameAttribute(): ?string
    {
        return $this->details?->first_name;
    }

    public function getLastNameAttribute(): ?string
    {
        return $this->details?->last_name;
    }

    public function details(): HasOne
    {
        return $this->hasOne(AccountDetail::class, 'account_id');
    }

    public function personalSetting(): HasOne
    {
        return $this->hasOne(PersonalSetting::class, 'user', 'id');
    }

    public function autoOpenChat(): bool
    {
        $setting = $this->personalSetting;
        return $setting ? (bool)$setting->auto_open_chat : true;
    }

    public function notificationSoundAlert(): bool
    {
        $setting = $this->personalSetting;
        return $setting ? (bool)($setting->notification_sound_alert ?? true) : true;
    }

    public function enableTopTabs(): bool
    {
        $setting = $this->personalSetting;
        return $setting ? (bool)($setting->enable_top_tabs ?? true) : true;
    }

    public function permissions(): HasOneThrough
    {
        return $this->hasOneThrough(
            role_permission::class,
            role_list::class,
            'id',           // Foreign key on condition_key table (condition_key.id)
            'key_id',       // Foreign key on condition_details table (condition_details.key_id)
            'account_role', // Local key on account table (account.account_role)
            'modifier_key'  // Local key on condition_key table (condition_key.modifier_key)
        );
    }
}