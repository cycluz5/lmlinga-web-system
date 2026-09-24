<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Adapts DB staff users into the frozen Health Worker UI shape.
 *
 * Production list/view authority: staff table + current appointment only.
 * Demo hw-* catalog rows are not served on production User Management routes.
 *
 * Route IDs:
 * - numeric string → database staff PK (mutable)
 * - hw-{n} → demo catalog preview only (not mutable, not listed)
 */
final class HealthWorkerUiCatalog
{
    /**
     * Production Admin list — database staff only (Active and Inactive).
     * Soft-deleted staff are excluded by User::query().
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::databaseWorkers();
    }

    /**
     * Resolve a staff row for View/Edit screens.
     * Numeric IDs use the staff table. Non-numeric (hw-*) IDs are not production records.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        $id = trim($id);

        if (! ctype_digit($id)) {
            return null;
        }

        $user = self::findUserById((int) $id);

        return $user !== null ? self::presentUser($user) : null;
    }

    public static function findUserById(int $id): ?User
    {
        if (UserManagementErdMode::isActive()) {
            if (! Schema::hasTable('user_management')) {
                return null;
            }

            return User::query()->with(self::appointmentEagerLoad())->find($id);
        }

        if (! Schema::hasTable('users')) {
            return null;
        }

        return User::query()->with(self::appointmentEagerLoad())->find($id);
    }

    /**
     * Resolve a mutable database user from a route id.
     * Demo hw-* ids are never mutable.
     */
    public static function findMutableUser(string $id): ?User
    {
        $id = trim($id);

        if (! ctype_digit($id)) {
            return null;
        }

        return self::findUserById((int) $id);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentUser(User $user): array
    {
        $appointment = $user->resolveCurrentAppointment();
        $zones = $appointment?->assignedZoneLabels() ?? [];
        $zoneDisplay = WorkerAssignedZones::display($zones);
        $primaryZone = WorkerAssignedZones::primary($zones) ?? (string) ($appointment?->assigned_zone ?? '');

        $roleMachine = StaffRole::normalize($appointment?->role);
        $roleLabel = StaffRole::label($roleMachine);
        if ($roleLabel === '' && $appointment?->role) {
            $roleLabel = (string) $appointment->role;
        }

        $dob = $user->date_of_birth;
        $dobString = $dob !== null ? $dob->format('Y-m-d') : '';

        $appointed = $appointment?->date_appointed;
        $ended = $appointment?->end_of_appointment;

        return [
            'id' => (string) $user->getKey(),
            'name' => $user->composeDisplayName(),
            'role' => $roleLabel !== '' ? $roleLabel : '',
            'zone' => $zoneDisplay,
            'status' => StaffAccountStatus::normalize($user->status) ?? '',
            'photo' => $user->profilePhotoUrl(),
            'first_name' => (string) ($user->first_name ?? ''),
            'last_name' => (string) ($user->last_name ?? ''),
            'middle_name' => (string) ($user->middle_name ?? ''),
            'suffix' => (string) ($user->suffix ?? ''),
            'sex' => (string) ($user->sex ?? ''),
            'date_of_birth' => $dobString,
            'civil_status' => (string) ($user->civil_status ?? ''),
            'nationality' => (string) ($user->nationality ?? ''),
            'mobile' => (string) ($user->mobile_number ?? ''),
            'email' => (string) ($user->email ?? ''),
            'house_no' => (string) ($user->house_no ?? ''),
            'street' => (string) ($user->street ?? ''),
            'purok_zone' => (string) ($user->purok_zone ?? ''),
            'barangay' => (string) ($user->barangay ?? ''),
            'municipality' => (string) ($user->municipality_city ?? ''),
            'province' => (string) ($user->province ?? ''),
            'zip_code' => (string) ($user->zip_code ?? ''),
            'assigned_barangay' => (string) ($appointment?->assigned_barangay ?? ''),
            'assigned_zone' => $primaryZone,
            'assigned_zones' => $zones,
            'assigned_zones_display' => $zoneDisplay,
            'date_appointed' => $appointed !== null ? $appointed->format('Y-m-d') : '',
            'end_of_appointment' => $ended !== null ? $ended->format('Y-m-d') : '',
            'username' => (string) ($user->username ?? ''),
            'source' => 'database',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function databaseWorkers(): array
    {
        if (UserManagementErdMode::isActive()) {
            if (! Schema::hasTable('user_management') || ! Schema::hasTable('worker_appointments')) {
                return [];
            }

            return User::query()
                ->with(self::appointmentEagerLoad())
                ->orderBy(UserManagementErdMode::staffKeyName())
                ->get()
                ->map(static fn (User $user): array => self::presentUser($user))
                ->all();
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('worker_appointments')) {
            return [];
        }

        return User::query()
            ->with(self::appointmentEagerLoad())
            ->orderBy('id')
            ->get()
            ->map(static fn (User $user): array => self::presentUser($user))
            ->all();
    }

    /**
     * Demo catalog file loader — not called by production list/view/edit.
     *
     * @return list<array<string, mixed>>
     */
    private static function demoWorkers(): array
    {
        /** @var list<array<string, mixed>> $catalog */
        $catalog = require resource_path('demo/health-workers.php');

        return array_map(static function (array $row): array {
            $row['source'] = 'demo';

            return $row;
        }, $catalog);
    }

    /**
     * @return list<string>
     */
    private static function appointmentEagerLoad(): array
    {
        $relations = ['currentAppointment'];
        if (Schema::hasTable('worker_appointment_zones')) {
            $relations[] = 'currentAppointment.assignedZones';
        }

        return $relations;
    }
}
