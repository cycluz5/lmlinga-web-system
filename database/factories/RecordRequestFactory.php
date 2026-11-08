<?php

namespace Database\Factories;

use App\Models\RecordRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordRequest>
 */
class RecordRequestFactory extends Factory
{
    protected $model = RecordRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => null,
            'household_no_submitted' => 'HH-'.fake()->unique()->numberBetween(900, 999),
            'zone_submitted' => 'Zone 1',
            'relationship_submitted' => 'Head',
            'first_name_submitted' => fake()->firstName(),
            'middle_name_submitted' => fake()->optional()->firstName(),
            'last_name_submitted' => fake()->lastName(),
            'mobile_number_submitted' => '09'.fake()->numerify('#########'),
            'email_submitted' => fake()->optional()->safeEmail(),
            'submitter_ip' => fake()->ipv4(),
            'matched_resident_id' => null,
            'status' => 'Approved',
            'decision_reason' => 'The submitted household information passed the required completeness and validation checks.',
            'evaluated_at' => now(),
            'approved_at' => now(),
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => 'Rejected',
            'decision_reason' => 'No matching household record was found.',
            'approved_at' => null,
        ]);
    }
}
