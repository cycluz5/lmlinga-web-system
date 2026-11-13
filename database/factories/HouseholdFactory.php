<?php

namespace Database\Factories;

use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

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
            'date_registered' => fake()->date(),
            'latitude' => null,
            'longitude' => null,
            'accomplished_by' => null,
        ];
    }

    /**
     * Legacy fixtures still pass street/address, which were dropped from households
     * (2026_09_28_140000). Skip them only when the current schema has no such column,
     * so tests that build a legacy schema with street still write it.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Household $household): void {
            foreach (['street', 'address'] as $column) {
                if (array_key_exists($column, $household->getAttributes())
                    && ! Schema::hasColumn($household->getTable(), $column)) {
                    $household->offsetUnset($column);
                }
            }
        });
    }
}
