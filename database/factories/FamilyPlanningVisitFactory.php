<?php

namespace Database\Factories;

use App\Models\FamilyPlanningVisit;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyPlanningVisit>
 */
class FamilyPlanningVisitFactory extends Factory
{
    protected $model = FamilyPlanningVisit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'visit_no' => 'FP-'.fake()->unique()->numerify('###'),
            'visited_at' => fake()->date(),
            'remarks' => fake()->optional()->sentence(),
            'commodities' => [
                ['name' => 'Pills', 'quantity' => 3],
            ],
        ];
    }
}
