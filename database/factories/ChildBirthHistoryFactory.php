<?php

namespace Database\Factories;

use App\Models\ChildBirthHistory;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChildBirthHistory>
 */
class ChildBirthHistoryFactory extends Factory
{
    protected $model = ChildBirthHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => Resident::factory(),
            'birth_weight_kg' => '3.20',
            'birth_length_cm' => '49.00',
            'status' => 'Normal',
            'pcab' => 'at_least_2_doses_1_month_prior',
            'breastfeeding_date' => '2024-01-15',
        ];
    }
}
