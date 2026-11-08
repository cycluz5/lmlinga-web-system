<?php

namespace Database\Factories;

use App\Models\DewormingRecord;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DewormingRecord>
 */
class DewormingRecordFactory extends Factory
{
    protected $model = DewormingRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'year' => (int) fake()->year(),
            'round' => fake()->randomElement([1, 2]),
            'se_status' => fake()->randomElement(['NHTS', 'Non-NHTS']),
            'date_given' => fake()->date(),
            'remarks' => 'No concerns reported',
        ];
    }
}
