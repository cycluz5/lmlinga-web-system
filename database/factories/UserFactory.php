<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\StaffAccountStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        $attributes = [
            'first_name' => $first,
            'middle_name' => fake()->optional(0.6)->firstName(),
            'last_name' => $last,
            'suffix' => null,
            'sex' => fake()->randomElement(['Male', 'Female']),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-20 years')->format('Y-m-d'),
            'civil_status' => fake()->randomElement(['Single', 'Married', 'Widowed', 'Separated', 'Annulled']),
            'nationality' => 'Filipino',
            'mobile_number' => '09'.fake()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'house_no' => (string) fake()->numberBetween(1, 200),
            'street' => fake()->streetName(),
            'purok_zone' => fake()->randomElement(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5']),
            'barangay' => 'La Medalla',
            'municipality_city' => 'Iriga City',
            'province' => 'Camarines Sur',
            'zip_code' => '4431',
            'status' => StaffAccountStatus::ACTIVE,
            'must_change_password' => false,
            'created_by' => null,
            'photo_path' => null,
            'name' => trim($first.' '.$last),
        ];

        $table = (new User)->getTable();
        $columns = array_flip(Schema::getColumnListing($table));

        return array_intersect_key($attributes, $columns);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StaffAccountStatus::INACTIVE,
        ]);
    }

    public function adminCreator(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'name' => 'System Administrator',
            'email' => 'admin@lmlinga.test',
            'username' => 'admin.lmlinga',
            'must_change_password' => false,
        ]);
    }
}
