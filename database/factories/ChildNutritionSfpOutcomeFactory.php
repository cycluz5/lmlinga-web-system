<?php

namespace Database\Factories;

use App\Models\ChildNutrition;
use App\Models\ChildNutritionSfpOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChildNutritionSfpOutcome>
 */
class ChildNutritionSfpOutcomeFactory extends Factory
{
    protected $model = ChildNutritionSfpOutcome::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'child_nutrition_id' => ChildNutrition::factory(),
            'program' => 'mam',
            'outcome' => 'identified',
            'outcome_date' => null,
            'action_yes_no' => null,
        ];
    }
}
