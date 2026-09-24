<?php

namespace App\Support;

use App\Models\Household;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use Illuminate\Support\Carbon;

/**
 * DB21 — read-only MySQL aggregates for the main Dashboard home.
 *
 * Presentation labels/icons live in DashboardUiData. This class never writes,
 * never falls back to DemoCatalog, and never invents deferred clinical rules.
 */
final class DashboardStatistics
{
    /** Neutral unavailable marker for deferred / no-authority metrics. */
    public const UNAVAILABLE = '—';

    /** @var list<string> */
    private const ZONES = HouseholdZoneResolver::DISPLAY_ZONES;

    /**
     * @return array{
     *     totalHouseholds: int,
     *     totalResidents: int,
     *     nhts: int,
     *     nonNhts: int,
     *     teenagePregnant: int,
     *     pregnant: int,
     *     fpCurrentUser: int,
     *     normalWeight: int,
     *     underweight: int,
     *     overweight: int,
     *     infants011: int,
     *     hhLargeFamily: int,
     *     hhPotableWater: int,
     *     hhSanitaryToilet: int
     * }
     */
    public static function summary(): array
    {
        $nutrition = self::nutritionStatusCounts();

        return [
            'totalHouseholds' => self::totalHouseholds(),
            'totalResidents' => self::totalResidents(),
            'nhts' => self::nhtsHouseholds(),
            'nonNhts' => self::nonNhtsHouseholds(),
            'teenagePregnant' => self::teenagePregnantResidents(),
            'pregnant' => self::pregnantResidents(),
            'fpCurrentUser' => self::fpCurrentUsers(),
            'normalWeight' => $nutrition['normalWeight'],
            'underweight' => $nutrition['underweight'],
            'overweight' => $nutrition['overweight'],
            'infants011' => self::infantsZeroToElevenMonths(),
            'hhLargeFamily' => self::largeFamilyHouseholds(),
            'hhPotableWater' => self::potableWaterHouseholds(),
            'hhSanitaryToilet' => self::sanitaryToiletHouseholds(),
        ];
    }

    public static function totalHouseholds(): int
    {
        if (! self::tableReady('households')) {
            return 0;
        }

        return self::householdQuery()->count();
    }

    public static function totalResidents(): int
    {
        if (! self::tableReady('residents')) {
            return 0;
        }

        return self::residentQueryExcludingSentinel()->count();
    }

    public static function nhtsHouseholds(): int
    {
        return self::countHouseholdsByType(DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS);
    }

    public static function nonNhtsHouseholds(): int
    {
        return self::countHouseholdsByType(DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS);
    }

    public static function pregnantResidents(): int
    {
        return self::activePregnancyResidentIds()->count();
    }

    /**
     * Adolescent pregnancy (WHO: under 20 years old) among the same active
     * pregnancy population {@see pregnantResidents()} counts.
     */
    public static function teenagePregnantResidents(): int
    {
        $ids = self::activePregnancyResidentIds();
        if ($ids->isEmpty() || ! self::tableReady('residents')) {
            return 0;
        }

        $today = Carbon::now()->startOfDay();
        $key = (new Resident)->getKeyName();

        return Resident::query()
            ->whereIn($key, $ids)
            ->get(['birthday', $key])
            ->filter(function (Resident $resident) use ($today): bool {
                $ageMonths = self::safeAgeInMonths($resident->birthday, $today);

                return $ageMonths !== null && $ageMonths < (20 * 12);
            })
            ->count();
    }

    /**
     * Distinct resident IDs with an active pregnancy (legacy status column
     * or ERD pregnancy_status). Shared by {@see pregnantResidents()} and
     * {@see teenagePregnantResidents()} so both stay in exact agreement.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private static function activePregnancyResidentIds(): \Illuminate\Support\Collection
    {
        if (self::tableReady('maternal_pregnancies')) {
            $guard = app(DatabaseSchemaGuard::class);

            if ($guard->columnExists('maternal_pregnancies', 'status')
                && $guard->columnExists('maternal_pregnancies', 'resident_id')) {
                return MaternalPregnancy::query()
                    ->where('status', MaternalPregnancy::STATUS_ACTIVE)
                    ->distinct()
                    ->pluck('resident_id');
            }
        }

        if (! self::tableReady('maternal_care')) {
            return collect();
        }

        return \Illuminate\Support\Facades\DB::table('maternal_care')
            ->where('pregnancy_status', 'Active')
            ->distinct()
            ->pluck('resident_id');
    }

    /**
     * Weight-for-Age classification (Normal/Underweight/Overweight) from
     * each Child Care–eligible resident's latest {@see TimbangRecord}. Uses
     * the pre-computed weight_for_age column — the same classification
     * TimbangRecordService stores at write time — never re-derived here.
     *
     * @return array{normalWeight: int, underweight: int, overweight: int}
     */
    public static function nutritionStatusCounts(): array
    {
        $counts = ['normalWeight' => 0, 'underweight' => 0, 'overweight' => 0];

        if (! self::tableReady('residents') || ! self::tableReady('timbang_records')) {
            return $counts;
        }

        $residents = self::residentQueryExcludingSentinel()
            ->with(['timbangRecords' => static function ($query): void {
                $query->orderByDesc('measurement_date')->orderByDesc('timbang_id');
            }])
            ->get();

        foreach ($residents as $resident) {
            $member = HouseholdProfilingPresenter::memberFromModel($resident);
            if (! HealthRecordsChildCare::isChildCarePopulation($member)) {
                continue;
            }

            $latest = $resident->timbangRecords->first();
            if ($latest === null) {
                continue;
            }

            match (trim((string) ($latest->weight_for_age ?? ''))) {
                'Normal' => $counts['normalWeight']++,
                'Underweight', 'Severely Underweight' => $counts['underweight']++,
                'Overweight' => $counts['overweight']++,
                default => null,
            };
        }

        return $counts;
    }

    /**
     * Households on an "improved" water source (DOH Levels I–III); only
     * "Others" (unimproved/unclassified) is treated as non-potable. Reuses
     * {@see EnvironmentalHealthDashboard}'s own water-level aggregate
     * rather than re-deriving it.
     */
    public static function potableWaterHouseholds(): int
    {
        if (! self::tableReady('households')) {
            return 0;
        }

        $waterSupply = EnvironmentalHealthDashboard::statistics(
            EnvironmentalHealthDashboard::rows()
        )['water_supply'] ?? [];

        return (int) ($waterSupply['level_i'] ?? 0)
            + (int) ($waterSupply['level_ii'] ?? 0)
            + (int) ($waterSupply['level_iii'] ?? 0);
    }

    public static function fpCurrentUsers(): int
    {
        if (! self::tableReady('residents')) {
            return 0;
        }

        $query = self::residentQueryExcludingSentinel();

        if (! app(DatabaseSchemaGuard::class)->applyResidentFpCurrentUserScope($query)) {
            return 0;
        }

        return $query->count();
    }

    public static function infantsZeroToElevenMonths(): int
    {
        if (! self::tableReady('residents')) {
            return 0;
        }

        $birthdayColumn = app(DatabaseSchemaGuard::class)->residentBirthdayColumn();

        if ($birthdayColumn === null) {
            return 0;
        }

        $today = Carbon::now()->startOfDay();

        return self::residentQueryExcludingSentinel()
            ->whereNotNull($birthdayColumn)
            ->whereDate($birthdayColumn, '<=', $today->toDateString())
            ->get([$birthdayColumn])
            ->filter(static function (Resident $resident) use ($today, $birthdayColumn): bool {
                $raw = $resident->getAttributes()[$birthdayColumn] ?? $resident->birthday;
                $ageMonths = self::safeAgeInMonths($raw, $today);

                return $ageMonths !== null && $ageMonths >= 0 && $ageMonths <= 11;
            })
            ->count();
    }

    public static function largeFamilyHouseholds(): int
    {
        if (! self::tableReady('households') || ! self::tableReady('residents')) {
            return 0;
        }

        return self::householdQuery()
            ->has('residents', '>=', 6)
            ->count();
    }

    /**
     * Reuse DB19 Environmental Health sanitary definition exactly.
     */
    public static function sanitaryToiletHouseholds(): int
    {
        if (! self::tableReady('households')) {
            return 0;
        }

        if (self::tableReady('environmental_sanitation')) {
            $sanitaryTypes = DemoHouseholdWaterSupply::SANITARY_TOILET_TYPES;

            $query = \Illuminate\Support\Facades\DB::table('environmental_sanitation')
                ->whereIn('toilet_type', $sanitaryTypes);

            $sentinelId = Household::nonResidentSentinelKey();
            if ($sentinelId !== null) {
                $query->where('household_id', '!=', $sentinelId);
            }

            return (int) $query
                ->distinct('household_id')
                ->count('household_id');
        }

        $stats = EnvironmentalHealthDashboard::statistics(
            EnvironmentalHealthDashboard::rows()
        );

        return (int) ($stats['sanitation']['sanitary'] ?? 0);
    }

    /**
     * Fixed barangay zones for dashboard zone summary (Zone 1–5).
     *
     * @return list<string>
     */
    public static function zones(): array
    {
        return self::ZONES;
    }

    /**
     * Per-zone household and population counts (active rows only).
     *
     * @return list<array{
     *     zone: string,
     *     zoneNumber: int,
     *     households: int,
     *     population: int
     * }>
     */
    public static function zoneSummary(): array
    {
        $rows = [];

        foreach (self::zones() as $index => $zone) {
            $rows[] = [
                'zone' => $zone,
                'zoneNumber' => $index + 1,
                'households' => self::zoneHouseholdCount($zone),
                'population' => self::zonePopulationCount($zone),
            ];
        }

        return $rows;
    }

    public static function zoneHouseholdCount(string $zone): int
    {
        if (! self::tableReady('households') || HouseholdZoneResolver::locationColumn() === null) {
            return 0;
        }

        return self::householdQuery()
            ->tap(static function ($query) use ($zone): void {
                HouseholdZoneResolver::applyZoneLabelScope($query, $zone);
            })
            ->count();
    }

    public static function zonePopulationCount(string $zone): int
    {
        if (! self::tableReady('residents') || ! self::tableReady('households')) {
            return 0;
        }

        if (HouseholdZoneResolver::locationColumn() === null) {
            return 0;
        }

        return self::residentQueryExcludingSentinel()
            ->whereHas(
                'household',
                static function ($query) use ($zone): void {
                    app(DatabaseSchemaGuard::class)->withoutSoftDeletesWhenUnsupported($query, 'households');
                    $query->excludingNonResidentSentinel();
                    HouseholdZoneResolver::applyZoneLabelScope($query, $zone);
                }
            )
            ->count();
    }

    /**
     * Latest active households for the Dashboard snapshot table.
     *
     * @return list<array{hhNo: string, hhHead: string, zone: string, street: string, members: int}>
     */
    public static function householdSnapshot(): array
    {
        if (! self::tableReady('households')) {
            return [];
        }

        $householdKey = (new Household)->getKeyName();
        $residentKey = (new Resident)->getKeyName();

        $households = self::householdQuery()
            ->with(['residents' => static function ($query) use ($residentKey): void {
                $query->orderBy($residentKey);
            }])
            ->orderByDesc('date_registered')
            ->orderByDesc($householdKey)
            ->limit(4)
            ->get();

        $rows = [];
        foreach ($households as $household) {
            $residents = $household->residents;
            $head = $residents->first(
                static fn (Resident $resident): bool => self::isHouseholdHead($resident)
            );

            $rows[] = [
                'hhNo' => (string) $household->household_no,
                'hhHead' => $head !== null ? self::fullName($head) : self::UNAVAILABLE,
                'zone' => self::householdZoneLabel($household),
                'street' => self::householdStreetLabel($household),
                'members' => $residents->count(),
            ];
        }

        return $rows;
    }

    public static function isUnavailable(mixed $value): bool
    {
        return $value === self::UNAVAILABLE || $value === '—';
    }

    private static function safeAgeInMonths(mixed $birthday, Carbon $today): ?int
    {
        if ($birthday === null || $birthday === '') {
            return null;
        }

        try {
            $born = $birthday instanceof Carbon
                ? $birthday->copy()->startOfDay()
                : Carbon::parse((string) $birthday)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($born->greaterThan($today)) {
            return null;
        }

        return (int) $born->diffInMonths($today);
    }

    private static function fullName(Resident $resident): string
    {
        return trim(implode(' ', array_filter([
            (string) $resident->first_name,
            (string) ($resident->middle_name ?? ''),
            (string) $resident->last_name,
        ], static fn (string $part): bool => $part !== '')));
    }

    private static function householdZoneLabel(Household $household): string
    {
        $column = HouseholdZoneResolver::locationColumn();

        if ($column === null) {
            return self::UNAVAILABLE;
        }

        $raw = trim((string) ($household->getAttributes()[$column] ?? ''));

        if ($raw === '') {
            return self::UNAVAILABLE;
        }

        $label = HouseholdZoneResolver::displayLabelFromStoredValue($raw);

        return $label !== '' ? $label : self::UNAVAILABLE;
    }

    private static function householdStreetLabel(Household $household): string
    {
        $column = app(DatabaseSchemaGuard::class)->householdStreetColumn();

        if ($column === null) {
            return self::UNAVAILABLE;
        }

        $value = trim((string) ($household->getAttributes()[$column] ?? ''));

        return $value !== '' ? $value : self::UNAVAILABLE;
    }

    private static function isHouseholdHead(Resident $resident): bool
    {
        $guard = app(DatabaseSchemaGuard::class);

        if ($guard->columnExists('residents', 'is_household_head')) {
            return (bool) ($resident->getAttributes()['is_household_head'] ?? false);
        }

        return strcasecmp((string) $resident->relation, 'Head') === 0;
    }

    private static function countHouseholdsByType(string $canonicalType): int
    {
        if (! self::tableReady('households')) {
            return 0;
        }

        $guard = app(DatabaseSchemaGuard::class);

        if ($guard->householdTypeUsesEnvironmentalProfile()) {
            return self::householdQuery()
                ->whereHas(
                    'environmentalProfile',
                    static fn ($query) => $query->where('household_type', $canonicalType)
                )
                ->count();
        }

        if (! $guard->householdTypeUsesHouseholdColumn()) {
            return 0;
        }

        $values = $canonicalType === DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS
            ? ['NHTS', 'HHTS', 'nhts', 'hhts']
            : ['Non-NHTS', 'Non-HHTS', 'non-nhts', 'non-hhts'];

        return self::householdQuery()
            ->whereIn('household_type', $values)
            ->count();
    }

    private static function householdQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return app(DatabaseSchemaGuard::class)->householdQuery()->excludingNonResidentSentinel();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Resident>
     */
    private static function residentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return app(DatabaseSchemaGuard::class)->residentQuery();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Resident>
     */
    private static function residentQueryExcludingSentinel(): \Illuminate\Database\Eloquent\Builder
    {
        $query = self::residentQuery();
        $sentinelId = Household::nonResidentSentinelKey();
        if ($sentinelId !== null) {
            $query->where('household_id', '!=', $sentinelId);
        }

        return $query;
    }

    private static function tableReady(string $table): bool
    {
        return app(DatabaseSchemaGuard::class)->tableExists($table);
    }
}
