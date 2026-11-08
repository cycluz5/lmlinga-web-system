<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Authoritative ERD occupation lookup (occupation.occupation_id / occupation_name).
 */
class Occupation extends Model
{
    protected $table = 'occupation';

    protected $primaryKey = 'occupation_id';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'occupation_name',
    ];

    /**
     * @return HasMany<Resident, $this>
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class, 'occupation_id', 'occupation_id');
    }
}
