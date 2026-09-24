<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'message' => fake()->text(180),
            'event_date' => fake()->dateTimeBetween('-1 month', '+2 months')->format('Y-m-d'),
            'event_time' => null,
            'place' => fake()->optional()->company(),
            'target_group' => Announcement::TARGET_ALL,
            'age_presets' => null,
            'age_min_months' => null,
            'age_max_months' => null,
            'zone_mode' => Announcement::ZONE_ALL,
            'zones' => null,
            'audience_label' => 'All Residents',
            'estimated_reach' => fake()->numberBetween(10, 500),
            'posted_by_user_id' => null,
            'posted_by_name' => 'Admin User',
            'posted_by_role' => 'admin',
            'posted_at' => now(),
        ];
    }

    public function forUser(?User $user = null): static
    {
        return $this->state(function () use ($user): array {
            $user ??= User::factory()->create();

            return [
                'posted_by_user_id' => $user->id,
                'posted_by_name' => $user->composeDisplayName(),
                'posted_by_role' => $user->role ?? 'admin',
            ];
        });
    }
}
