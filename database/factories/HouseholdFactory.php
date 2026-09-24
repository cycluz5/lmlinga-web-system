<?php

namespace Database\Factories;

use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    protected $model = Household::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seq = fake()->unique()->numberBetween(100, 999);

        return [
            'household_no' => 'HH-'.$seq,
            'zone' => fake()->randomElement(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5']),
            'street' => fake()->randomElement(['Layuan St.', 'Dalipay St.', 'Cateel Bay St.']),
            'date_registered' => fake()->date(),
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'accomplished_by' => null,
        ];
    }
}
