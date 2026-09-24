<?php

namespace Database\Factories;

use App\Models\ChildNutrition;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChildNutrition>
 */
class ChildNutritionFactory extends Factory
{
    protected $model = ChildNutrition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'newborn_length_cm' => null,
            'newborn_weight_kg' => null,
            'newborn_breastfeeding_date' => null,
            'iron_1st_date' => null,
            'iron_2nd_date' => null,
            'iron_3rd_date' => null,
            'vitamin_a_va_6_11_date' => null,
            'vitamin_a_va_12_59_1_date' => null,
            'vitamin_a_va_12_59_2_date' => null,
            'mnp_6_11_date' => null,
            'mnp_12_23_date' => null,
            'lns_sq_6_11_date' => null,
            'lns_sq_12_23_date' => null,
        ];
    }
}
