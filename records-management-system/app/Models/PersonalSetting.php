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
    ];

    protected $casts = [
        'auto_open_chat' => 'boolean',
    ];

    /**
     * Relationship back to the User/Account model.
     */
    public function userAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user', 'id');
    }
}
