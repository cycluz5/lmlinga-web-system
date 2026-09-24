<?php

namespace App\Support;

use App\Models\User;

/**
 * Test-provisioner placeholders for user_management / worker_appointments.
 *
 * Used only by ClientTestingProvisioner seed data. Slim Create Account must
 * not call this class — unfilled profile/employment fields stay empty when the
 * schema permits, and otherwise fail with a schema-conflict message.
 */
final class ErdStaffProfileDefaults
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function applyToUser(User $user, array $overrides = []): void
    {
        if (! UserManagementErdMode::isActive()) {
            return;
        }

        $defaults = array_merge([
            'sex' => 'Female',
            'date_of_birth' => '1990-01-15',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'house_no' => '123',
            'street' => 'Rizal Street',
            'purok_zone' => 'Zone 1',
            'barangay' => 'La Medalla',
            'municipality_city' => 'Iriga City',
            'province' => 'Camarines Sur',
            'zip_code' => '4431',
        ], $overrides);

        foreach ($defaults as $key => $value) {
            if ($user->getAttribute($key) === null || $user->getAttribute($key) === '') {
                $user->setAttribute($key, $value);
            }
        }
    }

    /**
     * @return array{assigned_barangay: string, assigned_zone: string, date_appointed: string}
     */
    public static function appointmentDefaults(string $zone = 'Zone 1'): array
    {
        return [
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => $zone,
            'date_appointed' => now()->toDateString(),
        ];
    }
}
