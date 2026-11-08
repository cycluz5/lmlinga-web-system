<?php

namespace Database\Factories;

use App\Models\SchoolImmunization;
use App\Models\SchoolImmunizationDose;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolImmunizationDose>
 */
class SchoolImmunizationDoseFactory extends Factory
{
    protected $model = SchoolImmunizationDose::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_immunization_id' => SchoolImmunization::factory(),
            'slot_group' => 'grade-1',
            'slot_key' => 'td',
            'date_given' => fake()->optional()->date(),
        ];
    }
}
