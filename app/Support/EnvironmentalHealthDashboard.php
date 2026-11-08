<?php

namespace App\Support;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Models\Resident;
use App\Services\HouseholdEnvironmentalProfileService;

/**
 * DB19-F — Environmental Health monitoring dashboard.
 *
 * Authoritative population: all MySQL households with a households row.
 * Optional EH profile + solid-waste practices are eager-loaded and mapped
 * for presentation. Dashboard GET is read-only (no profile/session writes).
 */
final class EnvironmentalHealthDashboard
{
    public const RECORD_STATUS_COMPLETED = 'completed';

    public const RECORD_STATUS_PENDING = 'pending';

    /**
     * Build dashboard rows for every active household.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public static function rows(array $filters = []): array
    {
        return self::filterRows(self::loadRowsFromDatabase(), $filters);
    }

    /**
     * Apply normalized filters to an already-built row set (same dataset as export).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public static function filterRows(array $rows, array $filters = []): array
    {
        if ($filters === [] || ! self::hasActiveFilters($filters)) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (self::matchesFilters($row, $filters)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * Compute summary statistics from an already-filtered row set.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function statistics(array $rows): array
    {
        $water = [
            DemoHouseholdWaterSupply::WATER_LEVEL_I => 0,
            DemoHouseholdWaterSupply::WATER_LEVEL_II => 0,
            DemoHouseholdWaterSupply::WATER_LEVEL_III => 0,
            DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS => 0,
        ];

        $sanitation = [
            DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY => 0,
            DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY => 0,
            'not_yet_determined' => 0,
        ];

        $toiletPresence = [
            'with_toilet' => 0,
            'without_toilet' => 0,
            'unknown' => 0,
        ];

        $total = count($rows);
        $completed = 0;
        $pending = 0;
        $validatedWater = 0;
        $goodSolidWaste = 0;

        foreach ($rows as $row) {
            $level = (string) ($row['water_supply_status'] ?? '');
            if ($level !== '' && array_key_exists($level, $water)) {
                $water[$level]++;
            }

            $toiletStatus = (string) ($row['toilet_status'] ?? '');
            if ($toiletStatus === DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY
                || $toiletStatus === DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY) {
                $sanitation[$toiletStatus]++;
            } else {
                $sanitation['not_yet_determined']++;
            }

            $presence = (string) ($row['toilet_presence'] ?? 'unknown');
            if (array_key_exists($presence, $toiletPresence)) {
                $toiletPresence[$presence]++;
            } else {
                $toiletPresence['unknown']++;
            }

            if (($row['record_status'] ?? '') === self::RECORD_STATUS_COMPLETED) {
                $completed++;
            } else {
                $pending++;
            }

            if (($row['validation_status'] ?? '') === 'completed') {
                $validatedWater++;
            }

            if (($row['solid_waste_status'] ?? '') === 'good_practice') {
                $goodSolidWaste++;
            }
        }

        return [
            'water_supply' => [
                'level_i' => $water[DemoHouseholdWaterSupply::WATER_LEVEL_I],
                'level_ii' => $water[DemoHouseholdWaterSupply::WATER_LEVEL_II],
                'level_iii' => $water[DemoHouseholdWaterSupply::WATER_LEVEL_III],
                'others' => $water[DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS],
            ],
            'sanitation' => [
                'sanitary' => $sanitation[DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY],
                'unsanitary' => $sanitation[DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY],
                'not_yet_determined' => $sanitation['not_yet_determined'],
            ],
            'toilet_presence' => $toiletPresence,
            'overview' => [
                'total_households' => $total,
                'completed_amenities' => $completed,
                'pending_assessment' => $pending,
                'validated_water_sources' => $validatedWater,
                'good_solid_waste' => $goodSolidWaste,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function zonesFromRows(array $rows): array
    {
        return self::uniqueSortedColumn($rows, 'zone');
    }

    /**
     * @return list<string>
     */
    public static function streetsFromRows(array $rows): array
    {
        return self::uniqueSortedColumn($rows, 'street');
    }

    /**
     * Normalize incoming request filters.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function normalizeFilters(array $input): array
    {
        return [
            'household_no' => trim((string) ($input['household_no'] ?? '')),
            'house_head' => trim((string) ($input['house_head'] ?? '')),
            'zone' => self::filterValue((string) ($input['zone'] ?? 'all')),
            'street' => self::filterValue((string) ($input['street'] ?? 'all')),
            'water_supply' => self::filterValue((string) ($input['water_supply'] ?? 'all')),
            'sanitation' => self::filterValue((string) ($input['sanitation'] ?? 'all')),
            'validation' => self::filterValue((string) ($input['validation'] ?? 'all')),
            'record_status' => self::filterValue((string) ($input['record_status'] ?? 'all')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function loadRowsFromDatabase(): array
    {
        $guard = app(DatabaseSchemaGuard::class);

        // Schema absent (e.g. smoke tests without RefreshDatabase): empty set, not demo fallback.
        if (! $guard->tableExists('households')) {
            return [];
        }

        $service = app(HouseholdEnvironmentalProfileService::class);
        $erdReader = app(EnvironmentalSanitationReadService::class);
        $erdActive = EnvironmentalSanitationErdMode::isActive();
        $erdRowsByHousehold = $erdActive ? $erdReader->rowsIndexedByHouseholdId() : [];

        $with = ['residents'];

        if ($guard->tableExists('household_environmental_profiles')) {
            if ($guard->tableExists('household_solid_waste_practices')) {
                $with[] = 'environmentalProfile.solidWastePractices';
            } else {
                $with[] = 'environmentalProfile';
            }
        }

        $households = $guard->householdQuery()
            ->excludingNonResidentSentinel()
            ->with($with)
            ->orderBy('household_no')
            ->get();

        $rows = [];
        foreach ($households as $household) {
            $erdRow = $erdRowsByHousehold[(int) $household->getKey()] ?? null;
            $rows[] = self::buildRowFromHousehold($household, $service, $erdReader, $erdRow);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildRowFromHousehold(
        Household $household,
        HouseholdEnvironmentalProfileService $service,
        EnvironmentalSanitationReadService $erdReader,
        ?object $erdRow = null,
    ): array {
        $key = DemoCatalog::normalizeHouseholdNo((string) $household->household_no);
        /** @var HouseholdEnvironmentalProfile|null $profile */
        $profile = app(DatabaseSchemaGuard::class)->tableExists('household_environmental_profiles')
            ? $household->environmentalProfile
            : null;

        $record = null;
        if ($profile !== null) {
            $record = $service->toPresentation($household, $profile);
        } elseif ($erdRow !== null) {
            $record = $erdReader->presentationFromRow($erdRow, $household);
        }

        $waterSupplyStatus = strtolower(trim((string) ($record['water_supply_status'] ?? '')));
        $toiletType = strtolower(trim((string) ($record['toilet_type'] ?? '')));
        $toiletStatus = strtolower(trim((string) ($record['toilet_status'] ?? '')));
        if ($toiletStatus === '' && $toiletType !== '') {
            $toiletStatus = (string) (DemoHouseholdWaterSupply::deriveToiletStatus($toiletType) ?? '');
        }

        $managementStatus = strtolower(trim((string) ($record['management_status'] ?? '')));
        if ($managementStatus === '' && $record !== null) {
            $managementStatus = DemoHouseholdWaterSupply::deriveManagementStatus(
                $toiletType !== '' ? $toiletType : null,
                isset($record['sewage_disposal_method']) ? (string) $record['sewage_disposal_method'] : null
            );
        } elseif ($managementStatus === '') {
            $managementStatus = DemoHouseholdWaterSupply::MANAGEMENT_STATUS_PENDING;
        }

        $validationStatus = DemoHouseholdWaterSupply::validationTestingStatus($record);
        $completeStatus = DemoHouseholdWaterSupply::deriveCompleteSanitationFacilityStatus($record);
        $solidWasteStatus = strtolower(trim((string) (
            $record['solid_waste_status'] ?? 'not_yet_determined'
        )));
        if ($solidWasteStatus === '') {
            $solidWasteStatus = 'not_yet_determined';
        }

        $completedStep = $profile !== null
            ? (int) $profile->completed_step
            : app(EnvironmentalSanitationReadService::class)->completedStepForRow($erdRow);
        $recordStatus = $profile !== null
            ? self::deriveRecordStatus($profile)
            : app(EnvironmentalSanitationReadService::class)->recordStatusForRow($erdRow);
        $toiletPresence = self::deriveToiletPresence($toiletType);
        $actionMode = $recordStatus === self::RECORD_STATUS_COMPLETED ? 'edit' : 'add';

        $houseHead = self::resolveHouseHead($household, $record);

        $practices = is_array($record['solid_waste_practices'] ?? null)
            ? array_values(array_map('strval', $record['solid_waste_practices']))
            : [];
        $practiceSet = array_fill_keys(array_map('strtolower', $practices), true);

        $dateSurveyedIso = '';
        if ($household->date_registered !== null) {
            try {
                $dateSurveyedIso = $household->date_registered->format('Y-m-d');
            } catch (\Throwable) {
                $dateSurveyedIso = '';
            }
        }

        $basicSafe = strtolower(trim((string) (
            $record['basic_safe_water_status']
            ?? DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus(
                $waterSupplyStatus !== '' ? $waterSupplyStatus : null
            )
        )));
        if ($basicSafe === '') {
            $basicSafe = DemoHouseholdWaterSupply::BASIC_SAFE_WATER_PENDING;
        }

        $microDate = isset($record['microbiological_test_date'])
            ? (string) $record['microbiological_test_date']
            : '';
        $physicoDate = isset($record['physicochemical_test_date'])
            ? (string) $record['physicochemical_test_date']
            : '';
        $microResult = isset($record['microbiological_result'])
            ? (string) $record['microbiological_result']
            : '';
        $physicoResult = isset($record['physicochemical_result'])
            ? (string) $record['physicochemical_result']
            : '';
        $waterLocation = strtolower(trim((string) ($record['water_source_location'] ?? '')));
        $waterAvailability = strtolower(trim((string) ($record['water_availability'] ?? '')));
        $openDefecation = strtolower(trim((string) ($record['open_defecation_practiced'] ?? '')));
        $sharedToilet = strtolower(trim((string) ($record['shared_toilet'] ?? '')));
        $sewageMethod = strtolower(trim((string) ($record['sewage_disposal_method'] ?? '')));

        return [
            'household_no' => $key,
            'house_head' => $houseHead,
            'zone' => self::householdZoneLabel($household),
            'street' => self::householdStreetLabel($household),
            'date_surveyed' => $dateSurveyedIso,
            'date_surveyed_label' => DisplayDate::format($dateSurveyedIso !== '' ? $dateSurveyedIso : null),
            'water_supply_status' => $waterSupplyStatus,
            'water_supply_label' => DemoHouseholdWaterSupply::waterSupplyLevelLabel(
                $waterSupplyStatus !== '' ? $waterSupplyStatus : null
            ),
            'water_supply_short' => self::waterSupplyShortLabel($waterSupplyStatus),
            'water_source_location' => $waterLocation,
            'water_source_location_label' => DemoHouseholdWaterSupply::yesNoLabel(
                $waterLocation !== '' ? $waterLocation : null
            ),
            'water_availability' => $waterAvailability,
            'water_availability_label' => DemoHouseholdWaterSupply::yesNoLabel(
                $waterAvailability !== '' ? $waterAvailability : null
            ),
            'microbiological_test_date' => $microDate,
            'microbiological_test_date_label' => DisplayDate::format($microDate !== '' ? $microDate : null),
            'microbiological_result' => $microResult,
            'microbiological_result_label' => DemoHouseholdWaterSupply::testResultLabel(
                $microResult !== '' ? $microResult : null
            ),
            'physicochemical_test_date' => $physicoDate,
            'physicochemical_test_date_label' => DisplayDate::format($physicoDate !== '' ? $physicoDate : null),
            'physicochemical_result' => $physicoResult,
            'physicochemical_result_label' => DemoHouseholdWaterSupply::testResultLabel(
                $physicoResult !== '' ? $physicoResult : null
            ),
            'toilet_type' => $toiletType,
            'toilet_type_label' => DemoHouseholdWaterSupply::toiletTypeLabel(
                $toiletType !== '' ? $toiletType : null
            ),
            'toilet_presence' => $toiletPresence,
            'toilet_presence_label' => self::toiletPresenceLabel($toiletPresence),
            'toilet_status' => $toiletStatus,
            'toilet_status_label' => HouseholdAmenitiesPresentation::toiletStatusLabel($toiletStatus),
            'toilet_status_modifier' => HouseholdAmenitiesPresentation::toiletStatusModifier($toiletStatus),
            'open_defecation_practiced' => $openDefecation,
            'open_defecation_label' => DemoHouseholdWaterSupply::yesNoLabel(
                $openDefecation !== '' ? $openDefecation : null
            ),
            'shared_toilet' => $sharedToilet,
            'shared_toilet_label' => DemoHouseholdWaterSupply::yesNoLabel(
                $sharedToilet !== '' ? $sharedToilet : null
            ),
            'sewage_disposal_method' => $sewageMethod,
            'sewage_disposal_label' => DemoHouseholdWaterSupply::sewageDisposalMethodLabel(
                $sewageMethod !== '' ? $sewageMethod : null
            ),
            'waste_segregation' => isset($practiceSet[DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION]),
            'waste_segregation_label' => isset($practiceSet[DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION]) ? 'Yes' : 'No',
            'backyard_composting' => isset($practiceSet[DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING]),
            'backyard_composting_label' => isset($practiceSet[DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING]) ? 'Yes' : 'No',
            'recycling_reuse' => isset($practiceSet[DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE]),
            'recycling_reuse_label' => isset($practiceSet[DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE]) ? 'Yes' : 'No',
            'municipal_collection' => isset($practiceSet[DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION]),
            'municipal_collection_label' => isset($practiceSet[DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION]) ? 'Yes' : 'No',
            'sanitation_status' => $managementStatus,
            'sanitation_label' => DemoHouseholdWaterSupply::managementStatusBadgeLabel($managementStatus),
            'sanitation_modifier' => HouseholdAmenitiesPresentation::managementStatusModifier($managementStatus),
            'validation_status' => $validationStatus,
            'validation_label' => DemoHouseholdWaterSupply::validationTestingStatusLabel($validationStatus),
            'validation_modifier' => HouseholdAmenitiesPresentation::validationStatusModifier($validationStatus),
            'basic_safe_water_status' => $basicSafe,
            'basic_safe_water_label' => DemoHouseholdWaterSupply::basicSafeWaterStatusLabel($basicSafe),
            'overall_status' => $completeStatus,
            'overall_label' => DemoHouseholdWaterSupply::managementStatusBadgeLabel($completeStatus),
            'overall_modifier' => HouseholdAmenitiesPresentation::managementStatusModifier($completeStatus),
            'record_status' => $recordStatus,
            'record_status_label' => $recordStatus === self::RECORD_STATUS_COMPLETED ? 'Completed' : 'Pending',
            'action_mode' => $actionMode,
            'completed_step' => $completedStep,
            'solid_waste_status' => $solidWasteStatus,
            'solid_waste_label' => DemoHouseholdWaterSupply::solidWasteStatusLabel($solidWasteStatus),
            'view_url' => route('household-profiling.amenities.show', ['householdNo' => $key]),
            'edit_url' => route('household-profiling.amenities.edit', ['householdNo' => $key]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    private static function resolveHouseHead(Household $household, ?array $record): string
    {
        $household->loadMissing('residents');
        $head = $household->residents->first(
            static fn (Resident $r): bool => self::isHouseholdHead($r)
        );

        if ($head !== null) {
            $name = self::formatHouseHeadName(
                (string) $head->first_name,
                (string) ($head->middle_name ?? ''),
                (string) $head->last_name
            );
            if ($name !== '') {
                return $name;
            }
        }

        $fromPresentation = trim((string) ($record['house_head'] ?? ''));

        return $fromPresentation !== '' ? $fromPresentation : 'Not available';
    }

    /**
     * "Last Name, First Name Middle Name" — the household-head name format
     * used across Environmental Health (dashboard, PDF, Excel, preview),
     * built directly from the resident's last_name/first_name/middle_name
     * columns rather than a pre-joined string, since a joined string can't
     * be reliably split back into its parts.
     */
    private static function formatHouseHeadName(string $first, string $middle, string $last): string
    {
        $first = trim($first);
        $middle = trim($middle);
        $last = trim($last);

        $given = trim(implode(' ', array_filter([$first, $middle], static fn (string $part): bool => $part !== '')));

        if ($last === '') {
            return $given;
        }

        return $given === '' ? $last : $last.', '.$given;
    }

    /**
     * Presentation-only toilet presence for Figma With/Without Toilet cards.
     * Does not alter stored toilet_type or toilet_status values.
     */
    public static function deriveToiletPresence(string $toiletType): string
    {
        $normalized = strtolower(trim($toiletType));

        if ($normalized === '') {
            return 'unknown';
        }

        if (DemoHouseholdWaterSupply::isWithoutToilet($normalized)) {
            return 'without_toilet';
        }

        return 'with_toilet';
    }

    public static function toiletPresenceLabel(string $presence): string
    {
        return match ($presence) {
            'with_toilet' => 'With Toilet',
            'without_toilet' => 'Without Toilet',
            default => '—',
        };
    }

    public static function waterSupplyShortLabel(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            DemoHouseholdWaterSupply::WATER_LEVEL_I => 'I',
            DemoHouseholdWaterSupply::WATER_LEVEL_II => 'II',
            DemoHouseholdWaterSupply::WATER_LEVEL_III => 'III',
            DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS => 'Others',
            default => '—',
        };
    }

    private static function deriveRecordStatus(?HouseholdEnvironmentalProfile $profile): string
    {
        if ($profile !== null && (int) $profile->completed_step >= 4) {
            return self::RECORD_STATUS_COMPLETED;
        }

        return self::RECORD_STATUS_PENDING;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function hasActiveFilters(array $filters): bool
    {
        if (trim((string) ($filters['household_no'] ?? '')) !== '') {
            return true;
        }

        if (trim((string) ($filters['house_head'] ?? '')) !== '') {
            return true;
        }

        foreach (['zone', 'street', 'water_supply', 'sanitation', 'validation', 'record_status'] as $key) {
            $value = (string) ($filters[$key] ?? 'all');
            if ($value !== '' && $value !== 'all') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private static function matchesFilters(array $row, array $filters): bool
    {
        if ($filters === []) {
            return true;
        }

        $hhQuery = strtolower(trim((string) ($filters['household_no'] ?? '')));
        if ($hhQuery !== '' && ! str_contains(strtolower((string) $row['household_no']), $hhQuery)) {
            return false;
        }

        $headQuery = strtolower(trim((string) ($filters['house_head'] ?? '')));
        if ($headQuery !== '' && ! str_contains(strtolower((string) $row['house_head']), $headQuery)) {
            return false;
        }

        $zone = (string) ($filters['zone'] ?? 'all');
        if ($zone !== 'all' && (string) $row['zone'] !== $zone) {
            return false;
        }

        $street = (string) ($filters['street'] ?? 'all');
        if ($street !== 'all' && (string) $row['street'] !== $street) {
            return false;
        }

        $water = (string) ($filters['water_supply'] ?? 'all');
        if ($water !== 'all' && (string) $row['water_supply_status'] !== $water) {
            return false;
        }

        $sanitation = (string) ($filters['sanitation'] ?? 'all');
        if ($sanitation !== 'all') {
            $presence = (string) ($row['toilet_presence'] ?? 'unknown');
            if ($presence !== $sanitation) {
                return false;
            }
        }

        $recordStatus = (string) ($filters['record_status'] ?? 'all');
        if ($recordStatus !== 'all' && (string) ($row['record_status'] ?? '') !== $recordStatus) {
            return false;
        }

        // validation query param retained for URL compatibility; ignored unless needed later.
        return true;
    }

    private static function filterValue(string $value): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? 'all' : $trimmed;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private static function uniqueSortedColumn(array $rows, string $column): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        $list = array_keys($values);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    private static function householdZoneLabel(Household $household): string
    {
        $column = HouseholdZoneResolver::locationColumn();

        if ($column === null) {
            return '';
        }

        $raw = trim((string) ($household->getAttributes()[$column] ?? ''));

        if ($raw === '') {
            return '';
        }

        return HouseholdZoneResolver::displayLabelFromStoredValue($raw);
    }

    private static function householdStreetLabel(Household $household): string
    {
        $column = app(DatabaseSchemaGuard::class)->householdStreetColumn();

        if ($column === null) {
            return '';
        }

        return trim((string) ($household->getAttributes()[$column] ?? ''));
    }

    private static function isHouseholdHead(Resident $resident): bool
    {
        $guard = app(DatabaseSchemaGuard::class);

        if ($guard->columnExists('residents', 'is_household_head')) {
            return (bool) ($resident->getAttributes()['is_household_head'] ?? false);
        }

        return strcasecmp((string) $resident->relation, 'Head') === 0;
    }
}
