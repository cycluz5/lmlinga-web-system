<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HouseholdEnvironmentalProfile extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'household_id',
        'household_type',
        'water_supply_status',
        'specify_water_source',
        'water_source_location',
        'water_availability',
        'basic_safe_water_status',
        'microbiological_test_date',
        'microbiological_result',
        'physicochemical_test_date',
        'physicochemical_result',
        'toilet_type',
        'toilet_status',
        'open_defecation_practiced',
        'shared_toilet',
        'sewage_disposal_method',
        'management_status',
        'solid_waste_status',
        'completed_step',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'microbiological_test_date' => 'date',
            'physicochemical_test_date' => 'date',
            'completed_step' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return HasOne<HouseholdSolidWastePractice, $this>
     */
    public function solidWastePractices(): HasOne
    {
        return $this->hasOne(HouseholdSolidWastePractice::class);
    }
}
