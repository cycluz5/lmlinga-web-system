<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resident>
 */
class ResidentFactory extends Factory
{
    protected $model = Resident::class;

    /**
     * Non-head relations only — default must never randomly create Head (R02-C).
     * Tests that need a household head must use head() or an explicit override.
     *
     * @var list<string>
     */
    public const DEFAULT_RELATIONS = ['Spouse', 'Son', 'Daughter'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seq = fake()->unique()->numberBetween(1, 999);

        return [
            'household_id' => Household::factory(),
            'member_no' => 'MB-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'relation' => fake()->randomElement(self::DEFAULT_RELATIONS),
            'birthday' => fake()->date(),
            'sex' => fake()->randomElement(['Male', 'Female']),
            'relationship_status' => fake()->randomElement(['Single', 'Married', 'Widowed', 'Separated', 'Live-in']),
            'occupation' => fake()->randomElement(['Farmer', 'Nurse', 'None / N/A', 'Student']),
            'monthly_income' => fake()->randomElement(['None / N/A', 'Below 5,000', '30,000 – 49,999']),
            'religion' => fake()->randomElement(['Roman Catholic', 'Islam', 'None']),
            'education' => fake()->randomElement(['College Graduate', 'High School Graduate', 'No Formal Education']),
            'fp_user' => fake()->randomElement(['Yes', 'No', 'N/A']),
            'philhealth' => null,
            'disability' => null,
            'disability_others' => null,
            'medical_history' => null,
            'medical_others' => null,
        ];
    }

    /**
     * Deterministic household Head. Use intentionally; never the default.
     */
    public function head(): static
    {
        return $this->state(fn (array $attributes) => [
            'relation' => 'Head',
        ]);
    }
}
