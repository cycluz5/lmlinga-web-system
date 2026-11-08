<?php

namespace Database\Factories;

use App\Models\MaternalPregnancy;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaternalPregnancy>
 */
class MaternalPregnancyFactory extends Factory
{
    protected $model = MaternalPregnancy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'pregnancy_no' => 'MC-'.fake()->unique()->numerify('###'),
            'pregnancy_number' => 1,
            'status' => MaternalPregnancy::STATUS_ACTIVE,
            'registered_at' => fake()->date(),
            'lmp' => fake()->optional()->date(),
            'gravida' => fake()->optional()->numberBetween(0, 8),
            'parity' => fake()->optional()->numberBetween(0, 8),
            'edd' => fake()->optional()->date(),
            'weight' => fake()->optional()->randomFloat(1, 40, 90),
            'height' => fake()->optional()->randomFloat(1, 140, 180),
            'bmi' => fake()->optional()->randomFloat(1, 18, 35),
            'blood_pressure' => fake()->optional()->numerify('###/##'),
            'prenatal' => null,
            'immunizations' => null,
            'supplementations' => null,
            'laboratory' => null,
            'delivery' => null,
            'postnatal' => null,
            'trans_out' => null,
        ];
    }
}
