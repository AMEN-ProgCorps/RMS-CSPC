<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Cluster extends Model
{
    use HasFactory, Notifiable;

    /** @var string Maps to the 'cluster' table */
    protected $table = 'sys_cluster';

    /** @var string Primary key */
    protected $primaryKey = 'id';

    /** @var bool Auto-incrementing primary key */
    public $incrementing = true;

    /** @var string Primary key type */
    protected $keyType = 'int';

    /** @var bool Disables standard Eloquent timestamps */
    public $timestamps = false;

    /**
     * Mass-assignable columns.
     */
    protected $fillable = [
        'cluster_name',
        'cluster_code',
        'cluster_head',
        'is_active',
    ];

    /**
     * Column type casting.
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the offices associated with this cluster.
     */
    public function offices()
    {
        return $this->hasMany(office::class, 'cluster', 'cluster_code');
    }

    /**
     * Get the office representing the head of this cluster.
     */
    public function headOffice()
    {
        return $this->belongsTo(office::class, 'cluster_head', 'office_code');
    }
}
