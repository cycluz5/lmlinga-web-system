<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Database\Seeder;

/**
 * Development/test staff seeds only.
 * Production Admin provisioning must use environment-supplied credentials — never commit real passwords.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DatabaseSeeder skipped outside local/testing. Provision staff accounts via a secure deployment process.');

            return;
        }

        // Plaintext — User model hashes via the `hashed` cast. Never log this value.
        $password = (string) env('SEED_STAFF_PASSWORD', 'ChangeMeLocally!123');

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@lmlinga.test'],
            [
                'name' => 'Demo Administrator',
                'first_name' => 'Demo',
                'last_name' => 'Administrator',
                'username' => 'admin.lmlinga',
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

        if ($admin->currentAppointment === null) {
            $admin->assignCurrentAppointment([
                'role' => StaffRole::ADMIN,
                'assigned_barangay' => 'La Medalla',
                'assigned_zone' => 'Zone 1',
                'date_appointed' => now()->toDateString(),
                'end_of_appointment' => null,
            ]);
        }

        foreach (
            [
                ['email' => 'bhw@lmlinga.test', 'username' => 'bhw.lmlinga', 'role' => StaffRole::BHW, 'first' => 'Bayani', 'last' => 'Worker'],
                ['email' => 'bns@lmlinga.test', 'username' => 'bns.lmlinga', 'role' => StaffRole::BNS, 'first' => 'Nora', 'last' => 'Scholar'],
                ['email' => 'bspo@lmlinga.test', 'username' => 'bspo.lmlinga', 'role' => StaffRole::BSPO, 'first' => 'Paolo', 'last' => 'Officer'],
            ] as $row
        ) {
            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['first'].' '.$row['last'],
                    'first_name' => $row['first'],
                    'last_name' => $row['last'],
                    'username' => $row['username'],
                    'password' => $password,
                    'status' => StaffAccountStatus::ACTIVE,
                    'must_change_password' => true,
                    'created_by' => $admin->id,
                    'barangay' => 'La Medalla',
                    'municipality_city' => 'Iriga City',
                    'province' => 'Camarines Sur',
                    'nationality' => 'Filipino',
                    'sex' => 'Female',
                ]
            );

            if ($user->currentAppointment === null) {
                $user->assignCurrentAppointment([
                    'role' => $row['role'],
                    'assigned_barangay' => 'La Medalla',
                    'assigned_zone' => 'Zone 1',
                    'date_appointed' => now()->toDateString(),
                    'end_of_appointment' => null,
                ]);
            }
        }
    }
}
