<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\SchoolImmunization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolImmunization>
 */
class SchoolImmunizationFactory extends Factory
{
    protected $model = SchoolImmunization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'selected_vaccine_types' => ['grade1_td', 'grade7_mr'],
        ];
    }
}
