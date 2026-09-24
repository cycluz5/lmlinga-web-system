<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employment assigned-zone vocabulary and list normalization.
 *
 * Labels match the existing scalar worker_appointments.assigned_zone values.
 * First selected zone is the denormalized primary.
 */
final class WorkerAssignedZones
{
    /** @var list<string> */
    public const ALLOWED = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'];

    /**
     * @return list<string>
     */
    public static function normalize(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        if (is_string($input) || is_int($input) || is_float($input)) {
            $input = [(string) $input];
        }

        if (! is_array($input)) {
            return [];
        }

        $seen = [];
        $zones = [];

        foreach ($input as $value) {
            if (is_array($value)) {
                continue;
            }

            $zone = trim((string) $value);
            if ($zone === '' || isset($seen[$zone])) {
                continue;
            }

            $seen[$zone] = true;
            $zones[] = $zone;
        }

        return $zones;
    }

    /**
     * @param  list<string>  $zones
     */
    public static function primary(array $zones): ?string
    {
        $normalized = self::normalize($zones);

        return $normalized[0] ?? null;
    }

    /**
     * @param  list<string>  $zones
     */
    public static function display(array $zones): string
    {
        return implode(', ', self::normalize($zones));
    }

    /**
     * Copy non-blank worker_appointments.assigned_zone values into the pivot.
     */
    public static function backfillPivotFromScalarAppointments(): int
    {
        if (! Schema::hasTable('worker_appointments') || ! Schema::hasTable('worker_appointment_zones')) {
            return 0;
        }

        $appointmentKey = Schema::hasColumn('worker_appointments', 'appointment_id')
            ? 'appointment_id'
            : 'id';

        $now = now();
        $inserted = 0;
        $appointments = DB::table('worker_appointments')
            ->select([$appointmentKey, 'assigned_zone'])
            ->whereNotNull('assigned_zone')
            ->where('assigned_zone', '!=', '')
            ->get();

        foreach ($appointments as $appointment) {
            $zone = trim((string) ($appointment->assigned_zone ?? ''));
            if ($zone === '') {
                continue;
            }

            $wasInserted = DB::table('worker_appointment_zones')->insertOrIgnore([
                'appointment_id' => $appointment->{$appointmentKey},
                'assigned_zone' => $zone,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($wasInserted) {
                $inserted++;
            }
        }

        return $inserted;
    }
}
