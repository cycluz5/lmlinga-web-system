<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\TimbangRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimbangRecord>
 */
class TimbangRecordFactory extends Factory
{
    protected $model = TimbangRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'measurement_date' => fake()->date(),
            'weight_kg' => fake()->randomFloat(2, 3, 80),
            'height_cm' => fake()->randomFloat(2, 45, 180),
            'muac_cm' => null,
            'weight_for_age' => null,
            'height_for_age' => null,
            'weight_for_height' => null,
            'remarks' => null,
        ];
    }
}
