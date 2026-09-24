<?php

namespace App\Support;

use App\Models\Household;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-aware household shell persistence.
 *
 * Maps UI/request field names onto the columns that actually exist:
 * - location: households.zone (legacy) or households.purok (authoritative ERD)
 * - optional street/address/accomplished_by only when those columns exist
 *
 * Never emits nonexistent columns into Eloquent create/update payloads.
 */
final class HouseholdShellWriteAdapter
{
    public static function isSupported(): bool
    {
        if (! Schema::hasTable((new Household)->getTable())) {
            return false;
        }

        return HouseholdZoneResolver::locationColumn() !== null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function persistableAttributes(array $validated): array
    {
        $guard = app(DatabaseSchemaGuard::class);
        $table = (new Household)->getTable();
        $payload = [];

        $locationColumn = HouseholdZoneResolver::locationColumn();
        if ($locationColumn !== null && self::hasAny($validated, ['zone', 'purok'])) {
            $raw = trim((string) ($validated['zone'] ?? $validated['purok'] ?? ''));
            $payload[$locationColumn] = self::normalizeLocationForColumn($locationColumn, $raw);
        }

        if ($guard->columnExists($table, 'date_registered') && array_key_exists('date_registered', $validated)) {
            $payload['date_registered'] = (string) $validated['date_registered'];
        }

        if ($guard->columnExists($table, 'latitude') && array_key_exists('latitude', $validated)) {
            $latitude = $validated['latitude'];
            $payload['latitude'] = $latitude === null || $latitude === '' ? null : $latitude;
        }

        if ($guard->columnExists($table, 'longitude') && array_key_exists('longitude', $validated)) {
            $longitude = $validated['longitude'];
            $payload['longitude'] = $longitude === null || $longitude === '' ? null : $longitude;
        }

        if ($guard->columnExists($table, 'household_type') && array_key_exists('household_type', $validated)) {
            $canonical = DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType($validated['household_type']);
            $payload['household_type'] = $canonical;
        }

        if ($guard->columnExists($table, 'street') && array_key_exists('street', $validated)) {
            $payload['street'] = trim((string) $validated['street']);
        }

        if ($guard->columnExists($table, 'address') && array_key_exists('address', $validated)) {
            $address = trim((string) ($validated['address'] ?? ''));
            $payload['address'] = $address === '' ? null : $address;
        }

        if ($guard->columnExists($table, 'accomplished_by') && array_key_exists('accomplished_by', $validated)) {
            $accomplished = trim((string) ($validated['accomplished_by'] ?? ''));
            $payload['accomplished_by'] = $accomplished === '' ? null : $accomplished;
        }

        return $payload;
    }

    /**
     * Authoritative purok stores the zone number ("1").
     * Legacy zone stores the UI label ("Zone 1").
     */
    public static function normalizeLocationForColumn(string $column, string $raw): string
    {
        $number = HouseholdZoneResolver::zoneNumberFromLabel($raw)
            ?? HouseholdZoneResolver::zoneNumberFromStoredValue($raw);

        if ($number === null) {
            return trim($raw);
        }

        return $column === 'purok' ? (string) $number : 'Zone '.$number;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $keys
     */
    private static function hasAny(array $validated, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $validated)) {
                return true;
            }
        }

        return false;
    }
}
