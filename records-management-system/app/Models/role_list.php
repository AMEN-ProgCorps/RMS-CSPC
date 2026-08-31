<?php
/**
 * App\Models\RoleList
 * 
 * Represents a role configuration in the system (condition_key table).
 * Connects to permissions detail mappings (condition_details table).
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

class role_list extends Model
{
    use HasFactory, Notifiable;

    /** @var string Maps to the 'condition_key' table */
    protected $table = 'sys_condition_key';

    /** @var string Primary key configuration */
    protected $primaryKey = 'id';

    /** @var bool Enables standard auto-incrementing behaviour */
    public $incrementing = true;

    /** @var string Primary key column type */
    protected $keyType = 'int';

    /** @var bool Disables standard created_at/updated_at timestamps */
    public $timestamps = false;

    /**
     * Columns that are mass-assignable in condition_key table.
     */
    protected $fillable = [
        'id',
        'key_name',
        'key_description',
        'modifier_key',
        'is_active',
        'date_created',
        'date_updated',
    ];

    /**
     * Column type casting rules.
     */
    protected $casts = [
        'id' => 'integer',
        'key_name' => 'string',
        'key_description' => 'string',
        'modifier_key' => 'integer',
        'is_active' => 'boolean',
        'date_created' => 'datetime',
        'date_updated' => 'datetime',
    ];

    /**
     * Relationships: A role (condition_key) maps to a set of permission flags (condition_details).
     * 
     * @return BelongsTo The permissions details relation
     */
    public function permissions(): BelongsTo
    {
        return $this->belongsTo(role_permission::class, 'modifier_key', 'key_id');
    }
}
