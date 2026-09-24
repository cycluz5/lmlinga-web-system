<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Religion extends Model
{
    protected $table = 'religion';

    protected $primaryKey = 'religion_id';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'religion_name',
    ];

    /**
     * @return HasMany<Resident, $this>
     */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class, 'religion_id', 'religion_id');
    }
}
