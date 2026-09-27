<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Database\Seeder;

/**
 * Seeds the single top-level administrator account (maria.santos).
 * StaffRole::ADMIN is the highest privilege role in the system.
 *
 * Run with: php artisan db:seed --class=SuperAdminSeeder --force
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Plaintext — User model hashes via the `hashed` cast. Never log this value.
        $password = (string) env('SUPER_ADMIN_PASSWORD', 'LMLinga@Admin2026!');

        $admin = User::query()->updateOrCreate(
            ['username' => 'maria.santos'],
            [
                'email' => 'maria.santos@lmlinga.test',
                'name' => 'Maria Santos',
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'password' => $password,
                'status' => StaffAccountStatus::ACTIVE,
                'must_change_password' => false,
                'barangay' => 'La Medalla',
                'municipality_city' => 'Iriga City',
                'province' => 'Camarines Sur',
                'nationality' => 'Filipino',
                'sex' => 'Female',
            ]
        );

        if (StaffRole::normalize($admin->currentAppointment?->role) !== StaffRole::ADMIN) {
            $admin->assignCurrentAppointment([
                'role' => StaffRole::ADMIN,
                'assigned_barangay' => 'La Medalla',
                'assigned_zone' => 'Zone 1',
                'date_appointed' => now()->toDateString(),
                'end_of_appointment' => null,
            ]);
        }

        $this->command?->info('Super admin account seeded: maria.santos');
    }
}
