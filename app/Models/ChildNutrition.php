<?php

namespace App\Models;

use App\Support\ChildNutritionErdMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChildNutrition extends Model
{
    /** @use HasFactory<\Database\Factories\ChildNutritionFactory> */
    use HasFactory;

    public function getTable(): string
    {
        return ChildNutritionErdMode::nutritionTable();
    }

    public function getKeyName(): string
    {
        return ChildNutritionErdMode::nutritionPrimaryKey();
    }

    /**
     * Frozen SFP program keys matching the Blade form.
     *
     * @var list<string>
     */
    public const SFP_PROGRAMS = ['mam', 'sam'];

    /**
     * Frozen SFP outcome keys matching the Blade form.
     *
     * @var list<string>
     */
    public const SFP_OUTCOMES = [
        'identified',
        'enrolled',
        'cured',
        'non-cured',
        'default',
        'died',
    ];

    /**
     * Allowed yes/no action values for MAM/SAM radios.
     *
     * @var list<string>
     */
    public const SFP_ACTIONS = ['yes', 'no'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'newborn_length_cm',
        'newborn_weight_kg',
        'newborn_breastfeeding_date',
        'iron_1st_date',
        'iron_2nd_date',
        'iron_3rd_date',
        'vitamin_a_va_6_11_date',
        'vitamin_a_va_12_59_1_date',
        'vitamin_a_va_12_59_2_date',
        'mnp_6_11_date',
        'mnp_12_23_date',
        'lns_sq_6_11_date',
        'lns_sq_12_23_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'newborn_length_cm' => 'decimal:2',
            'newborn_weight_kg' => 'decimal:2',
            'newborn_breastfeeding_date' => 'date',
            'iron_1st_date' => 'date',
            'iron_2nd_date' => 'date',
            'iron_3rd_date' => 'date',
            'vitamin_a_va_6_11_date' => 'date',
            'vitamin_a_va_12_59_1_date' => 'date',
            'vitamin_a_va_12_59_2_date' => 'date',
            'mnp_6_11_date' => 'date',
            'mnp_12_23_date' => 'date',
            'lns_sq_6_11_date' => 'date',
            'lns_sq_12_23_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', (new Resident)->getKeyName());
    }

    /**
     * @return HasMany<ChildNutritionSfpOutcome, $this>
     */
    public function sfpOutcomes(): HasMany
    {
        return $this->hasMany(ChildNutritionSfpOutcome::class);
    }
}
