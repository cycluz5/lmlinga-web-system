<?php

namespace App\Support;

use App\Models\Household;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-aware household zone / purok resolution for read-only aggregates.
 *
 * Legacy SQLite/dev migrations use households.zone.
 * Authoritative ERD uses households.purok when zone is absent.
 */
final class HouseholdZoneResolver
{
    /** @var list<string> */
    public const DISPLAY_ZONES = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'];

    /**
     * Prefer legacy zone when present; fall back to authoritative ERD purok.
     */
    public static function locationColumn(): ?string
    {
        $table = (new Household)->getTable();

        if (Schema::hasColumn($table, 'zone')) {
            return 'zone';
        }

        if (Schema::hasColumn($table, 'purok')) {
            return 'purok';
        }

        return null;
    }

    /**
     * Stored zone/purok text for a household row.
     *
     * Prefer a non-empty legacy `zone` value when both columns exist; otherwise
     * use ERD `purok`. Callers should display this as Zone N, never as Purok.
     */
    public static function storedValueFromHousehold(Household $household): string
    {
        $table = $household->getTable();
        $attrs = $household->getAttributes();

        $zone = Schema::hasColumn($table, 'zone')
            ? trim((string) ($attrs['zone'] ?? ''))
            : '';

        if ($zone !== '') {
            return $zone;
        }

        if (Schema::hasColumn($table, 'purok')) {
            return trim((string) ($attrs['purok'] ?? ''));
        }

        return '';
    }

    /**
     * Extract zone number (1–5) from a dashboard display label such as "Zone 3".
     */
    public static function zoneNumberFromLabel(string $zoneLabel): ?int
    {
        $trimmed = trim($zoneLabel);

        if (preg_match('/^Zone\s+(\d+)$/i', $trimmed, $matches) !== 1) {
            return null;
        }

        return self::normalizeZoneNumber((int) $matches[1]);
    }

    /**
     * Extract zone number (1–5) from a stored households.zone|purok value.
     */
    public static function zoneNumberFromStoredValue(string $raw): ?int
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/(\d+)/', $trimmed, $matches) !== 1) {
            return null;
        }

        return self::normalizeZoneNumber((int) $matches[1]);
    }

    /**
     * @return list<string>
     */
    public static function storedValueCandidates(int $zoneNumber): array
    {
        if (self::normalizeZoneNumber($zoneNumber) === null) {
            return [];
        }

        return [
            (string) $zoneNumber,
            'Zone '.$zoneNumber,
            'Purok '.$zoneNumber,
        ];
    }

    /**
     * @param  Builder<Household>  $query
     */
    public static function applyZoneLabelScope(Builder $query, string $zoneLabel): void
    {
        $zoneNumber = self::zoneNumberFromLabel($zoneLabel);
        $column = self::locationColumn();

        if ($zoneNumber === null || $column === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(function (Builder $outer) use ($column, $zoneNumber): void {
            foreach (self::storedValueCandidates($zoneNumber) as $candidate) {
                $outer->orWhere($column, $candidate);
            }

            $qualified = $outer->qualifyColumn($column);

            $outer
                ->orWhereRaw('LOWER(TRIM('.$qualified.')) = ?', ['zone '.$zoneNumber])
                ->orWhereRaw('LOWER(TRIM('.$qualified.')) = ?', ['purok '.$zoneNumber]);
        });
    }

    /**
     * Display label for a stored zone/purok value; empty string when unknown.
     */
    public static function displayLabelFromStoredValue(string $raw): string
    {
        $zoneNumber = self::zoneNumberFromStoredValue($raw);

        return $zoneNumber !== null ? 'Zone '.$zoneNumber : trim($raw);
    }

    private static function normalizeZoneNumber(int $value): ?int
    {
        return ($value >= 1 && $value <= 5) ? $value : null;
    }
}
