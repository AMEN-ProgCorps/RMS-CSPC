<?php
/**
 * App\Models\Office
 * 
 * Represents an office entity in the database schema.
 * Defines database attributes, mappings, and relationships for the 'office' table.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class office extends Model
{
    use HasFactory, Notifiable;

    /** @var string Explicitly maps to the custom 'office' table */
    protected $table = 'sys_office';

    /** @var string Primary key override - matches increments('id') in migration */
    protected $primaryKey = 'id';

    /** @var bool Disables standard auto-incrementing if custom keys are used (set to true) */
    public $incrementing = true;

    /** @var string Primary key type specification */
    protected $keyType = 'int';

    /** @var bool Disables standard Eloquent timestamps (created_at/updated_at) */
    public $timestamps = false;

    /**
     * Mass-assignable columns in the 'office' table.
     */
    protected $fillable = [
        'office_name',
        'office_code',
        'is_active',
        'cluster',
    ];

    /**
     * Column type casting configuration.
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the cluster associated with this office.
     */
    public function clusterRelation()
    {
        return $this->belongsTo(Cluster::class, 'cluster', 'cluster_code');
    }
}

