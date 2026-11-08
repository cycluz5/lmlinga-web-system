<?php

namespace Database\Factories;

use App\Models\OperationTimbangMeasurement;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationTimbangMeasurement>
 */
class OperationTimbangMeasurementFactory extends Factory
{
    protected $model = OperationTimbangMeasurement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'weighed_at' => fake()->date(),
            'weight_kg' => fake()->randomFloat(2, 3, 18),
            'height_cm' => fake()->randomFloat(2, 45, 95),
            'muac_cm' => fake()->randomFloat(2, 10, 18),
            'remarks' => null,
        ];
    }
}
