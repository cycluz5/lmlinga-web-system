<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared read-only helpers for Health Records clinical summary listings.
 */
final class HealthRecordsClinicalListing
{
    public const EMPTY = '—';

    public static function residentTable(): string
    {
        return (new Resident)->getTable();
    }

    public static function residentPk(): string
    {
        return (new Resident)->getKeyName();
    }

    public static function householdTable(): string
    {
        return (new Household)->getTable();
    }

    public static function householdPk(): string
    {
        return (new Household)->getKeyName();
    }

    public static function tablesReady(string ...$tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<object>
     */
    public static function joinResidentsHouseholds(string $clinicalTable, string $clinicalAlias = 'c'): Collection
    {
        $residents = self::residentTable();
        $residentPk = self::residentPk();
        $households = self::householdTable();
        $householdPk = self::householdPk();
        $zoneColumn = HouseholdZoneResolver::locationColumn();
        $householdNoColumn = Schema::hasColumn($households, 'household_no') ? 'household_no' : null;

        $select = [
            $clinicalAlias.'.*',
            'r.first_name',
            'r.middle_name',
            'r.last_name',
            'r.birthday',
            'r.sex',
            'r.household_id as resident_household_id',
            $residentPk === 'id' ? 'r.id as resident_id' : 'r.'.$residentPk.' as resident_id',
        ];

        if (Schema::hasColumn($residents, 'member_no')) {
            $select[] = 'r.member_no';
        }

        if ($zoneColumn !== null) {
            $select[] = 'h.'.$zoneColumn.' as household_zone';
        }

        if ($householdNoColumn !== null) {
            $select[] = 'h.'.$householdNoColumn.' as household_no';
        }

        return DB::table($clinicalTable.' as '.$clinicalAlias)
            ->join($residents.' as r', 'r.'.$residentPk, '=', $clinicalAlias.'.resident_id')
            ->leftJoin($households.' as h', 'h.'.$householdPk, '=', 'r.household_id')
            ->select($select)
            ->get();
    }

    public static function fullName(object $row): string
    {
        $parts = array_filter([
            trim((string) ($row->first_name ?? '')),
            trim((string) ($row->middle_name ?? '')),
            trim((string) ($row->last_name ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return $parts !== [] ? implode(' ', $parts) : 'Unknown';
    }

    public static function memberId(object $row): string
    {
        if (Schema::hasColumn(self::residentTable(), 'member_no')) {
            $stored = trim((string) ($row->member_no ?? ''));
            if ($stored !== '') {
                return DemoCatalog::normalizeMemberId($stored);
            }
        }

        return ResidentMemberIdentity::syntheticMemberId((int) ($row->resident_id ?? 0));
    }

    public static function zoneLabel(object $row): string
    {
        $raw = trim((string) ($row->household_zone ?? ''));

        return $raw !== '' ? HouseholdZoneResolver::displayLabelFromStoredValue($raw) : '';
    }

    public static function yearFromDate(?string $isoDate): string
    {
        $raw = trim((string) $isoDate);
        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->format('Y');
        } catch (\Throwable) {
            return '';
        }
    }

    public static function formatFamilyPlanningDate(?string $isoDate): string
    {
        $raw = trim((string) $isoDate);
        if ($raw === '') {
            return self::EMPTY;
        }

        $formatted = DisplayDate::format($raw);

        return $formatted !== '' ? $formatted : self::EMPTY;
    }

    public static function formatMaternalDate(?string $isoDate): string
    {
        $raw = trim((string) $isoDate);
        if ($raw === '') {
            return self::EMPTY;
        }

        $formatted = DisplayDate::format($raw);

        return $formatted !== '' ? $formatted : self::EMPTY;
    }

    public static function ageInYearsFromBirthday(?string $birthday, ?Carbon $on = null): ?int
    {
        $raw = trim((string) $birthday);
        if ($raw === '') {
            return null;
        }

        try {
            $born = Carbon::parse($raw)->startOfDay();
            $asOf = ($on ?? Carbon::now())->copy()->startOfDay();

            if ($born->greaterThan($asOf)) {
                return 0;
            }

            $age = (int) $asOf->year - (int) $born->year;
            if ($asOf->lt($born->copy()->year($asOf->year))) {
                $age--;
            }

            return max(0, $age);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public static function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value
            ), static fn (string $item): bool => $item !== ''));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim(is_scalar($item) ? (string) $item : ''),
            $decoded
        ), static fn (string $item): bool => $item !== ''));
    }

    public static function gravidaParityLabel(mixed $gravida, mixed $parity): string
    {
        $g = trim((string) ($gravida ?? ''));
        $p = trim((string) ($parity ?? ''));
        if ($g === '' && $p === '') {
            return self::EMPTY;
        }

        return ($g !== '' ? $g : self::EMPTY).'-'.($p !== '' ? $p : self::EMPTY);
    }

    public static function shortTrimesterFromLmp(?string $lmp, ?Carbon $on = null): string
    {
        $raw = trim((string) $lmp);
        if ($raw === '') {
            return self::EMPTY;
        }

        try {
            $start = Carbon::parse($raw)->startOfDay();
            $weeks = (int) floor($start->diffInDays(($on ?? Carbon::now())->startOfDay()) / 7);

            return match (true) {
                $weeks < 14 => '1st',
                $weeks < 28 => '2nd',
                default => '3rd',
            };
        } catch (\Throwable) {
            return self::EMPTY;
        }
    }

    public static function methodFromCommodities(mixed $commodities): string
    {
        if (is_string($commodities) && trim($commodities) !== '') {
            try {
                $decoded = json_decode($commodities, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $commodities = $decoded;
                }
            } catch (\Throwable) {
                return self::EMPTY;
            }
        }

        if (! is_array($commodities)) {
            return self::EMPTY;
        }

        foreach ($commodities as $entry) {
            if (is_array($entry)) {
                $name = trim((string) ($entry['name'] ?? $entry['method'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return self::EMPTY;
    }
}
