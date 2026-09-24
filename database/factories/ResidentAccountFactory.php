<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\ResidentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<ResidentAccount>
 */
class ResidentAccountFactory extends Factory
{
    protected $model = ResidentAccount::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resident_id' => null,
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'zone' => fake()->randomElement(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5']),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password123'),
        ];
    }

    public function linkedTo(Resident $resident): static
    {
        return $this->state(fn (): array => [
            'resident_id' => $resident->id,
        ]);
    }
}
