<?php

namespace Database\Factories;

use App\Models\ChildImmunization;
use App\Models\ImmunizationDose;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImmunizationDose>
 */
class ImmunizationDoseFactory extends Factory
{
    protected $model = ImmunizationDose::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'child_immunization_id' => ChildImmunization::factory(),
            'vaccine_type' => 'bcg',
            'dose_index' => 0,
            'date_given' => fake()->optional()->date(),
        ];
    }
}
