<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\StaffRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

/**
 * @extends Factory<WorkerAppointment>
 */
class WorkerAppointmentFactory extends Factory
{
    protected $model = WorkerAppointment::class;

    public function configure(): static
    {
        return $this->afterCreating(function (WorkerAppointment $appointment): void {
            if (! Schema::hasTable('worker_appointment_zones')) {
                return;
            }

            $zone = trim((string) ($appointment->assigned_zone ?? ''));
            if ($zone === '' || $appointment->assignedZones()->exists()) {
                return;
            }

            $appointment->assignedZones()->create([
                'assigned_zone' => $zone,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => fake()->randomElement(StaffRole::ALL),
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => fake()->randomElement(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5']),
            'date_appointed' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'end_of_appointment' => null,
            'is_current' => true,
        ];
    }

    public function current(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_current' => true,
            'end_of_appointment' => null,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_current' => false,
            'end_of_appointment' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
        ]);
    }

    public function role(string $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => StaffRole::normalize($role) ?? StaffRole::BHW,
        ]);
    }
}
