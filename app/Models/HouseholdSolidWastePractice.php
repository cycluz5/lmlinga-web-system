<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdSolidWastePractice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'household_environmental_profile_id',
        'waste_segregation',
        'backyard_composting',
        'recycling_reuse',
        'municipal_collection',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waste_segregation' => 'boolean',
            'backyard_composting' => 'boolean',
            'recycling_reuse' => 'boolean',
            'municipal_collection' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<HouseholdEnvironmentalProfile, $this>
     */
    public function environmentalProfile(): BelongsTo
    {
        return $this->belongsTo(HouseholdEnvironmentalProfile::class, 'household_environmental_profile_id');
    }
}
