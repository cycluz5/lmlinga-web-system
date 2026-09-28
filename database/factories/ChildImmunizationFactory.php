<?php

namespace Database\Factories;

use App\Models\ChildImmunization;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChildImmunization>
 */
class ChildImmunizationFactory extends Factory
{
    protected $model = ChildImmunization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'remarks' => null,
        ];
    }
}
