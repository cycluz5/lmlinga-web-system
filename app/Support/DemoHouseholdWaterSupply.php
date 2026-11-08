<?php

namespace App\Support;

use App\Models\Household;
use App\Services\HouseholdEnvironmentalProfileService;
use App\Services\SpotMappingHandoffService;
use Illuminate\Support\Facades\Schema;

/**
 * Session-backed demo store for Household Water Supply wizard records.
 * Households enter the wizard only after a verified Spot Mapping handoff token
 * is consumed, or when already linked/saved for the current actor.
 *
 * DB-17 Phase 2B: when a household exists in MySQL, find/save/step completion
 * prefer HouseholdEnvironmentalProfileService. Session is used only for
 * non-persisted (demo) households and must never overwrite a DB profile.
 */
final class DemoHouseholdWaterSupply
{
    public const SESSION_KEY = 'lml.demo.household_water_supply.v1';

    public const LINKED_KEY = 'lml.demo.household_water_supply.linked.v1';

    public const NOT_FOUND_MESSAGE = 'Household record not found. Please plot or select a valid household from Spot Mapping.';

    public const WATER_LEVEL_I = 'level_i';

    public const WATER_LEVEL_II = 'level_ii';

    public const WATER_LEVEL_III = 'level_iii';

    public const WATER_LEVEL_OTHERS = 'others';

    public const BASIC_SAFE_WATER_WITH = 'with_basic_safe_water';

    public const BASIC_SAFE_WATER_WITHOUT = 'without_basic_safe_water';

    public const BASIC_SAFE_WATER_PENDING = 'not_yet_determined';

    public const TOILET_TYPE_WITHOUT = 'without_toilet';

    public const TOILET_STATUS_SANITARY = 'sanitary';

    public const TOILET_STATUS_UNSANITARY = 'unsanitary';

    public const MANAGEMENT_STATUS_PENDING = 'not_yet_determined';

    public const MANAGEMENT_STATUS_SAFELY_MANAGED = 'safely_managed';

    public const MANAGEMENT_STATUS_NOT_SAFELY_MANAGED = 'not_safely_managed';

    public const SEWAGE_ON_SITE = 'on_site_safely_managed';

    public const SEWAGE_OFF_SITE = 'off_site_collected_and_treated';

    public const SOLID_WASTE_WASTE_SEGREGATION = 'waste_segregation';

    public const SOLID_WASTE_BACKYARD_COMPOSTING = 'backyard_composting';

    public const SOLID_WASTE_RECYCLING_REUSE = 'recycling_reuse';

    public const SOLID_WASTE_MUNICIPAL_COLLECTION = 'municipal_collection';

    /** Canonical persisted socioeconomic values (DB19-C). */
    public const HOUSEHOLD_TYPE_NHTS = 'NHTS';

    public const HOUSEHOLD_TYPE_NON_NHTS = 'Non-NHTS';

    /** @var list<string> */
    public const SANITARY_TOILET_TYPES = [
        'pour_flush_with_septic_tank',
        'pour_flush_connected_to_septic_or_sewer',
        'ventilated_improved_pit_latrine',
    ];

    /** @var list<string> */
    public const UNSANITARY_TOILET_TYPES = [
        'water_sealed_without_septic_tank',
        'overhung_latrine',
        'open_pit_latrine',
        self::TOILET_TYPE_WITHOUT,
    ];

    public static function normalizeHouseholdNo(string $householdNo): string
    {
        return DemoCatalog::normalizeHouseholdNo($householdNo);
    }

    public static function isValidHouseholdNo(string $householdNo): bool
    {
        $normalized = self::normalizeHouseholdNo($householdNo);

        return $normalized !== '' && (bool) preg_match('/^[A-Za-z0-9\-]{1,64}$/', $normalized);
    }

    /**
     * Normalize Spot Mapping UI / legacy spellings to canonical EH persisted values.
     * HHTS → NHTS, Non-HHTS → Non-NHTS. Returns null when unrecognized.
     */
    public static function normalizeCanonicalHouseholdType(mixed $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        if (strcasecmp($value, 'HHTS') === 0 || strcasecmp($value, self::HOUSEHOLD_TYPE_NHTS) === 0) {
            return self::HOUSEHOLD_TYPE_NHTS;
        }

        if (strcasecmp($value, 'Non-HHTS') === 0 || strcasecmp($value, self::HOUSEHOLD_TYPE_NON_NHTS) === 0) {
            return self::HOUSEHOLD_TYPE_NON_NHTS;
        }

        return null;
    }

    /**
     * Establish actor-scoped navigation context for an active Household after handoff.
     * Stores server-selected household identity and optional transient canonical household_type.
     * Does not store coordinates or water/sanitation field values.
     *
     * @param  string|null  $canonicalHouseholdType  Already-normalized NHTS / Non-NHTS, or null.
     */
    public static function linkFromHousehold(Household $household, ?string $canonicalHouseholdType = null): string
    {
        $householdNo = self::normalizeHouseholdNo((string) $household->household_no);
        if (! self::isValidHouseholdNo($householdNo)) {
            return '';
        }

        $type = self::normalizeCanonicalHouseholdType($canonicalHouseholdType);

        $linked = self::linkedHouseholds();
        $entry = [
            'household_id' => (int) $household->id,
            'household_no' => $householdNo,
            'actor_id' => SpotMappingHandoffService::actorKey(),
            'linked_at' => now()->toIso8601String(),
            'source' => 'spot_mapping_handoff',
        ];
        if ($type !== null) {
            $entry['household_type'] = $type;
        }
        $linked[$householdNo] = $entry;
        session([self::LINKED_KEY => $linked]);

        return $householdNo;
    }

    /**
     * Resolve an active linked Household for the current actor from MySQL.
     */
    public static function resolveLinkedHousehold(string $householdNo): ?Household
    {
        if (! self::isValidHouseholdNo($householdNo)) {
            return null;
        }

        $key = self::normalizeHouseholdNo($householdNo);
        $entry = self::findLinkedForActor($key);
        if ($entry === null) {
            return null;
        }

        $householdId = (int) ($entry['household_id'] ?? 0);
        if ($householdId <= 0) {
            return null;
        }

        $householdQuery = Household::query()->whereKey($householdId);
        if (Schema::hasColumn('households', 'deleted_at')) {
            $householdQuery->whereNull('deleted_at');
        }

        $household = $householdQuery->first();
        if ($household === null) {
            return null;
        }

        if (self::normalizeHouseholdNo((string) $household->household_no) !== $key) {
            return null;
        }

        return $household;
    }

    /**
     * Actor-scoped Spot Mapping link context for a household (if present).
     *
     * @return array<string, mixed>|null
     */
    public static function findLinkedForActor(string $householdNo): ?array
    {
        if (! self::isValidHouseholdNo($householdNo)) {
            return null;
        }

        $key = self::normalizeHouseholdNo($householdNo);
        $entry = self::linkedHouseholds()[$key] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        return (string) ($entry['actor_id'] ?? '') === SpotMappingHandoffService::actorKey()
            ? $entry
            : null;
    }

    /**
     * Socioeconomic status label for amenities context strip.
     * Prefers DB profile value, then trusted transient link (canonical), then catalog.
     *
     * @param  array<string, mixed>|null  $record
     * @param  array<string, mixed>|null  $household
     */
    public static function socioeconomicStatusLabel(?array $record, ?array $household = null): string
    {
        $raw = '';

        if (is_array($record)) {
            $raw = trim((string) ($record['household_type'] ?? ''));
        }

        if ($raw === '' && is_array($record)) {
            $linked = self::findLinkedForActor((string) ($record['household_no'] ?? ''));
            $raw = trim((string) ($linked['household_type'] ?? ''));
        }

        if ($raw === '' && is_array($household)) {
            $raw = trim((string) ($household['householdType'] ?? $household['socioeconomic_status'] ?? ''));
        }

        $canonical = self::normalizeCanonicalHouseholdType($raw);
        if ($canonical !== null) {
            return $canonical;
        }

        return $raw !== '' ? $raw : 'Not Yet Determined';
    }

    /**
     * True when this actor already linked the household via a consumed handoff token.
     */
    public static function isLinkedForActor(string $householdNo): bool
    {
        if (! self::isValidHouseholdNo($householdNo)) {
            return false;
        }

        $key = self::normalizeHouseholdNo($householdNo);
        $entry = self::linkedHouseholds()[$key] ?? null;
        if (! is_array($entry)) {
            return false;
        }

        return (string) ($entry['actor_id'] ?? '') === SpotMappingHandoffService::actorKey();
    }

    /**
     * Recognized for wizard store / navigation.
     * Linked persisted household for the current actor only.
     * Catalog-only numbers are never recognized and never seed session records.
     *
     * DB completed_step alone must NOT grant wizard access without a link —
     * that would allow cross-actor IDOR via household_no.
     */
    public static function isRecognized(string $householdNo): bool
    {
        if (! self::isValidHouseholdNo($householdNo)) {
            return false;
        }

        return self::resolveLinkedHousehold($householdNo) !== null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        /** @var array<string, array<string, mixed>> $records */
        $records = session(self::SESSION_KEY, []);

        return is_array($records) ? $records : [];
    }

    /**
     * Prefer DB profile for persisted households; session only for demo-only keys.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $householdNo): ?array
    {
        if (! self::isValidHouseholdNo($householdNo)) {
            return null;
        }

        $key = self::normalizeHouseholdNo($householdNo);
        $dbHousehold = self::findDbHousehold($key);
        if ($dbHousehold !== null) {
            return app(HouseholdEnvironmentalProfileService::class)->findPresentation($dbHousehold);
        }

        return self::findSessionRecord($key);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findSessionRecord(string $householdNo): ?array
    {
        $key = self::normalizeHouseholdNo($householdNo);
        $records = self::all();

        return $records[$key] ?? null;
    }

    public static function findDbHousehold(string $householdNo): ?Household
    {
        if (! self::isValidHouseholdNo($householdNo)) {
            return null;
        }

        $query = Household::query()
            ->excludingNonResidentSentinel()
            ->where('household_no', self::normalizeHouseholdNo($householdNo));

        if (Schema::hasColumn('households', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->first();
    }

    /**
     * Returns the record only when it belongs to the current actor.
     * Production staff routes never materialize DemoCatalog amenities into session.
     * Never returns another actor's saved EH / Spot Mapping record.
     *
     * @return array<string, mixed>|null
     */
    public static function findForActor(string $householdNo): ?array
    {
        $key = self::normalizeHouseholdNo($householdNo);
        if (! self::isValidHouseholdNo($key)) {
            return null;
        }

        $dbHousehold = self::findDbHousehold($key);
        if ($dbHousehold !== null) {
            return app(HouseholdEnvironmentalProfileService::class)->findPresentation($dbHousehold);
        }

        $actorId = SpotMappingHandoffService::actorKey();
        $record = self::findSessionRecord($key);

        if (is_array($record) && (string) ($record['actor_id'] ?? '') === $actorId) {
            return $record;
        }

        return null;
    }

    /**
     * Canonical Household Profiling list household numbers with amenities UI demos.
     *
     * @return list<string>
     */
    public static function profilingDemoHouseholdNos(): array
    {
        return ['HH-151', 'HH-152', 'HH-153', 'HH-154', 'HH-155', 'HH-156'];
    }

    public static function hasProfilingDemo(string $householdNo): bool
    {
        $key = self::normalizeHouseholdNo($householdNo);

        return in_array($key, self::profilingDemoHouseholdNos(), true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function profilingDemoBlueprints(): array
    {
        /** @var array<string, array<string, mixed>> $catalog */
        $catalog = require resource_path('demo/household-amenities.php');

        return is_array($catalog) ? $catalog : [];
    }

    /**
     * Build a full amenities record from the profiling demo blueprint.
     * Test/preview helper only — production staff routes must not call this.
     *
     * @return array<string, mixed>|null
     */
    public static function materializeProfilingDemoRecord(string $householdNo, ?string $actorId = null): ?array
    {
        $key = self::normalizeHouseholdNo($householdNo);
        $blueprint = self::profilingDemoBlueprints()[$key] ?? null;
        if (! is_array($blueprint)) {
            return null;
        }

        $household = DemoCatalog::findHousehold($key);
        $waterSupplyStatus = strtolower(trim((string) ($blueprint['water_supply_status'] ?? '')));
        $toiletType = strtolower(trim((string) ($blueprint['toilet_type'] ?? '')));
        $sewage = $blueprint['sewage_disposal_method'] ?? null;
        $sewage = is_string($sewage) ? strtolower(trim($sewage)) : null;
        if ($sewage === '') {
            $sewage = null;
        }

        $practices = is_array($blueprint['solid_waste_practices'] ?? null)
            ? array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => strtolower(trim((string) $value)),
                $blueprint['solid_waste_practices']
            ), static fn (string $value): bool => $value !== '')))
            : [];

        $toiletStatus = $toiletType !== '' ? self::deriveToiletStatus($toiletType) : null;
        $managementStatus = self::deriveManagementStatus($toiletType !== '' ? $toiletType : null, $sewage);
        $solidWasteStatus = count($practices) > 0 ? 'good_practice' : 'not_yet_determined';

        return [
            'household_no' => $key,
            'actor_id' => $actorId ?? SpotMappingHandoffService::actorKey(),
            'house_head' => is_array($household) ? (string) ($household['houseHead'] ?? '') : '',
            'household_type' => (string) ($blueprint['household_type'] ?? ''),
            'water_supply_status' => $waterSupplyStatus,
            'specify_water_source' => $blueprint['specify_water_source'] ?? null,
            'water_source_location' => strtolower(trim((string) ($blueprint['water_source_location'] ?? ''))),
            'water_availability' => strtolower(trim((string) ($blueprint['water_availability'] ?? ''))),
            'basic_safe_water_status' => self::deriveBasicSafeWaterStatus($waterSupplyStatus),
            'microbiological_test_date' => $blueprint['microbiological_test_date'] ?? null,
            'microbiological_result' => $blueprint['microbiological_result'] ?? null,
            'physicochemical_test_date' => $blueprint['physicochemical_test_date'] ?? null,
            'physicochemical_result' => $blueprint['physicochemical_result'] ?? null,
            'toilet_type' => $toiletType,
            'toilet_status' => $toiletStatus,
            'open_defecation_practiced' => strtolower(trim((string) ($blueprint['open_defecation_practiced'] ?? ''))),
            'shared_toilet' => strtolower(trim((string) ($blueprint['shared_toilet'] ?? ''))),
            'sewage_disposal_method' => $sewage,
            'management_status' => $managementStatus,
            'solid_waste_practices' => $practices,
            'solid_waste_status' => $solidWasteStatus,
            'source' => 'household_profiling_demo',
            'saved_at' => now()->toIso8601String(),
            'step' => $toiletType !== '' || $waterSupplyStatus !== '' ? 4 : 0,
        ];
    }

    /**
     * Persist a profiling demo amenities record for the current actor when absent.
     * Test/preview helper only — production staff routes must not call this.
     *
     * @return array<string, mixed>|null
     */
    public static function ensureProfilingDemoRecordForActor(string $householdNo): ?array
    {
        $key = self::normalizeHouseholdNo($householdNo);
        $actorId = SpotMappingHandoffService::actorKey();

        $existing = self::findSessionRecord($key);
        if (is_array($existing) && (string) ($existing['actor_id'] ?? '') === $actorId) {
            return $existing;
        }

        if (is_array($existing) && (string) ($existing['actor_id'] ?? '') !== '' && (string) ($existing['actor_id'] ?? '') !== $actorId) {
            return self::materializeProfilingDemoRecord($key, $actorId);
        }

        $demo = self::materializeProfilingDemoRecord($key, $actorId);
        if ($demo === null) {
            return null;
        }

        $records = self::all();
        $records[$key] = $demo;
        session([self::SESSION_KEY => $records]);

        return $demo;
    }

    public static function hasCompletedStep1(string $householdNo): bool
    {
        $dbHousehold = self::findDbHousehold($householdNo);
        if ($dbHousehold !== null) {
            return app(HouseholdEnvironmentalProfileService::class)->hasCompletedStep($dbHousehold, 1);
        }

        $record = self::findSessionRecord($householdNo);

        return is_array($record)
            && (int) ($record['step'] ?? 0) >= 1
            && (string) ($record['actor_id'] ?? '') === SpotMappingHandoffService::actorKey();
    }

    public static function hasCompletedStep2(string $householdNo): bool
    {
        $dbHousehold = self::findDbHousehold($householdNo);
        if ($dbHousehold !== null) {
            return app(HouseholdEnvironmentalProfileService::class)->hasCompletedStep($dbHousehold, 2);
        }

        $record = self::findSessionRecord($householdNo);

        return is_array($record)
            && (int) ($record['step'] ?? 0) >= 2
            && (string) ($record['actor_id'] ?? '') === SpotMappingHandoffService::actorKey();
    }

    public static function hasCompletedStep3(string $householdNo): bool
    {
        $dbHousehold = self::findDbHousehold($householdNo);
        if ($dbHousehold !== null) {
            return app(HouseholdEnvironmentalProfileService::class)->hasCompletedStep($dbHousehold, 3);
        }

        $record = self::findSessionRecord($householdNo);

        return is_array($record)
            && (int) ($record['step'] ?? 0) >= 3
            && (string) ($record['actor_id'] ?? '') === SpotMappingHandoffService::actorKey();
    }

    public static function hasCompletedStep4(string $householdNo): bool
    {
        $dbHousehold = self::findDbHousehold($householdNo);
        if ($dbHousehold !== null) {
            return app(HouseholdEnvironmentalProfileService::class)->hasCompletedStep($dbHousehold, 4);
        }

        $record = self::findSessionRecord($householdNo);

        return is_array($record)
            && (int) ($record['step'] ?? 0) >= 4
            && (string) ($record['actor_id'] ?? '') === SpotMappingHandoffService::actorKey();
    }

    /**
     * Water supply level machine values accepted by Part 1.
     *
     * @return list<string>
     */
    public static function waterSupplyLevels(): array
    {
        return [
            self::WATER_LEVEL_I,
            self::WATER_LEVEL_II,
            self::WATER_LEVEL_III,
            self::WATER_LEVEL_OTHERS,
        ];
    }

    /**
     * Levels that map to WITH BASIC SAFE WATER.
     *
     * @return list<string>
     */
    public static function basicSafeWaterLevels(): array
    {
        return [
            self::WATER_LEVEL_I,
            self::WATER_LEVEL_II,
            self::WATER_LEVEL_III,
        ];
    }

    /**
     * Derive basic-safe-water status from the selected water supply level.
     * Never trust a browser-supplied status value.
     */
    public static function deriveBasicSafeWaterStatus(?string $waterSupplyStatus): string
    {
        $normalized = strtolower(trim((string) $waterSupplyStatus));

        if ($normalized === '') {
            return self::BASIC_SAFE_WATER_PENDING;
        }

        if (in_array($normalized, self::basicSafeWaterLevels(), true)) {
            return self::BASIC_SAFE_WATER_WITH;
        }

        if ($normalized === self::WATER_LEVEL_OTHERS) {
            return self::BASIC_SAFE_WATER_WITHOUT;
        }

        return self::BASIC_SAFE_WATER_PENDING;
    }

    public static function basicSafeWaterStatusLabel(string $status): string
    {
        return match ($status) {
            self::BASIC_SAFE_WATER_WITH => 'With Basic Safe Water',
            self::BASIC_SAFE_WATER_WITHOUT => 'Without Basic Safe Water',
            default => 'Not yet determined',
        };
    }

    public static function waterSupplyLevelLabel(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            self::WATER_LEVEL_I => 'Level I',
            self::WATER_LEVEL_II => 'Level II',
            self::WATER_LEVEL_III => 'Level III',
            self::WATER_LEVEL_OTHERS => 'Others',
            default => 'Not yet determined',
        };
    }

    public static function yesNoLabel(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'yes' => 'Yes',
            'no' => 'No',
            default => 'Not yet determined',
        };
    }

    public static function testResultLabel(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'passed' => 'Passed',
            'failed' => 'Failed',
            default => 'Not Conducted',
        };
    }

    /**
     * Toilet type values accepted by Part 2 (Basic Sanitation Facility).
     *
     * @return list<string>
     */
    public static function toiletTypes(): array
    {
        return array_merge(self::SANITARY_TOILET_TYPES, self::UNSANITARY_TOILET_TYPES);
    }

    /**
     * Central mapping: toilet_type → toilet_status (sanitary|unsanitary).
     * Never trust a browser-supplied status value.
     */
    public static function deriveToiletStatus(string $toiletType): ?string
    {
        $normalized = strtolower(trim($toiletType));

        if (in_array($normalized, self::SANITARY_TOILET_TYPES, true)) {
            return self::TOILET_STATUS_SANITARY;
        }

        if (in_array($normalized, self::UNSANITARY_TOILET_TYPES, true)) {
            return self::TOILET_STATUS_UNSANITARY;
        }

        return null;
    }

    /**
     * Derive facility management status from toilet type + disposal method.
     * Never trust a browser-supplied management/facility status value.
     *
     * Pending: no toilet, or sanitary toilet without a disposal method.
     * Safely Managed: sanitary toilet AND (in-site OR off-site) disposal.
     * Not Safely Managed: unsanitary toilet (disposal does not change the result).
     */
    public static function deriveManagementStatus(?string $toiletType, ?string $sewageDisposalMethod): string
    {
        $toilet = strtolower(trim((string) $toiletType));
        $sewage = strtolower(trim((string) $sewageDisposalMethod));

        if ($toilet === '') {
            return self::MANAGEMENT_STATUS_PENDING;
        }

        $toiletStatus = self::deriveToiletStatus($toilet);

        if ($toiletStatus === self::TOILET_STATUS_UNSANITARY) {
            return self::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED;
        }

        if ($toiletStatus === self::TOILET_STATUS_SANITARY) {
            if (in_array($sewage, self::sewageDisposalMethods(), true)) {
                return self::MANAGEMENT_STATUS_SAFELY_MANAGED;
            }

            return self::MANAGEMENT_STATUS_PENDING;
        }

        return self::MANAGEMENT_STATUS_PENDING;
    }

    public static function managementStatusBadgeLabel(string $status): string
    {
        return match ($status) {
            self::MANAGEMENT_STATUS_SAFELY_MANAGED => 'Safely Managed',
            self::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED => 'Not Safely Managed',
            default => 'Not Yet Determined',
        };
    }

    public static function managementStatusDisplayText(string $status): string
    {
        return match ($status) {
            self::MANAGEMENT_STATUS_SAFELY_MANAGED => 'SANITARY',
            self::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED => 'UNSANITARY',
            default => 'Not yet determined',
        };
    }

    public static function toiletTypeLabel(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'pour_flush_with_septic_tank' => 'Pour/Flush Type with Septic Tank',
            'pour_flush_connected_to_septic_or_sewer' => 'Pour/Flush Connected to Septic Tank or Sewerage System',
            'ventilated_improved_pit_latrine' => 'Pour/ Ventilated Pit (VIP) Latrine',
            'water_sealed_without_septic_tank' => 'Water-Sealed Toilet without Septic Tank',
            'overhung_latrine' => 'Overhung Latrine (Antipolo Type)',
            'open_pit_latrine' => 'Open Pit Latrine',
            self::TOILET_TYPE_WITHOUT => 'Without Toilet',
            default => 'Not yet determined',
        };
    }

    public static function sewageDisposalMethodLabel(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            self::SEWAGE_ON_SITE => 'In-site Disposed',
            self::SEWAGE_OFF_SITE => 'Off-site Disposed',
            default => 'Not yet determined',
        };
    }

    public static function isWithoutToilet(string $toiletType): bool
    {
        return strtolower(trim($toiletType)) === self::TOILET_TYPE_WITHOUT;
    }

    /**
     * @return list<string>
     */
    public static function sewageDisposalMethods(): array
    {
        return [
            self::SEWAGE_ON_SITE,
            self::SEWAGE_OFF_SITE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function solidWastePracticeValues(): array
    {
        return [
            self::SOLID_WASTE_WASTE_SEGREGATION,
            self::SOLID_WASTE_BACKYARD_COMPOSTING,
            self::SOLID_WASTE_RECYCLING_REUSE,
            self::SOLID_WASTE_MUNICIPAL_COLLECTION,
        ];
    }

    /**
     * Derived Part 1.2 status from stored nullable test fields.
     *
     * @param  array<string, mixed>|null  $record
     */
    public static function validationTestingStatus(?array $record): string
    {
        if (! is_array($record)) {
            return 'not_conducted';
        }

        $microComplete = self::isTestSectionComplete(
            $record['microbiological_test_date'] ?? null,
            $record['microbiological_result'] ?? null
        );
        $physicoComplete = self::isTestSectionComplete(
            $record['physicochemical_test_date'] ?? null,
            $record['physicochemical_result'] ?? null
        );

        if ($microComplete && $physicoComplete) {
            return 'completed';
        }

        if ($microComplete || $physicoComplete) {
            return 'partially_recorded';
        }

        return 'not_conducted';
    }

    public static function validationTestingStatusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Completed',
            'partially_recorded' => 'Partially Recorded',
            default => 'Not Conducted',
        };
    }

    public static function solidWasteStatusLabel(?string $status): string
    {
        return strtolower(trim((string) $status)) === 'good_practice'
            ? 'Good Practice'
            : 'Not Yet Determined';
    }

    public static function solidWastePracticeLabel(string $value): string
    {
        return match (strtolower(trim($value))) {
            self::SOLID_WASTE_WASTE_SEGREGATION => 'Waste Segregation',
            self::SOLID_WASTE_BACKYARD_COMPOSTING => 'Backyard Composting',
            self::SOLID_WASTE_RECYCLING_REUSE => 'Recycling / Reuse',
            self::SOLID_WASTE_MUNICIPAL_COLLECTION => 'Collected by Municipality / Municipal Collection and Disposal System',
            default => $value,
        };
    }

    public static function deriveCompleteSanitationFacilityStatus(?array $record): string
    {
        if (! is_array($record)) {
            return self::MANAGEMENT_STATUS_PENDING;
        }

        $managementStatus = strtolower(trim((string) ($record['management_status'] ?? '')));
        $solidWasteStatus = strtolower(trim((string) ($record['solid_waste_status'] ?? '')));

        if ($managementStatus === self::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED) {
            return self::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED;
        }

        if ($managementStatus === self::MANAGEMENT_STATUS_SAFELY_MANAGED && $solidWasteStatus === 'good_practice') {
            return self::MANAGEMENT_STATUS_SAFELY_MANAGED;
        }

        return self::MANAGEMENT_STATUS_PENDING;
    }

    public static function isTestSectionComplete(mixed $date, mixed $result): bool
    {
        $dateValue = is_string($date) ? trim($date) : '';
        $resultValue = is_string($result) ? trim($result) : '';

        return $dateValue !== '' && in_array($resultValue, ['passed', 'failed'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function saveStep1(string $householdNo, array $payload): array
    {
        $key = self::normalizeHouseholdNo($householdNo);
        $dbHousehold = self::findDbHousehold($key);
        if ($dbHousehold !== null) {
            $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep1($dbHousehold, $payload);
            self::forgetSessionIfErdPersisted($key, $presentation);

            return $presentation;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function mergeWizardSession(string $householdNo, array $payload, int $step): array
    {
        $key = self::normalizeHouseholdNo($householdNo);

        return match ($step) {
            1 => self::writeStep1Session($key, $payload),
            2 => self::writeStep2Session($key, $payload),
            3 => self::writeStep3Session($key, $payload),
            default => self::writeStep4Session($key, $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function writeStep1Session(string $key, array $payload): array
    {
        $records = self::all();
        $existing = is_array($records[$key] ?? null) ? $records[$key] : [];

        $waterSupplyStatus = strtolower(trim((string) ($payload['water_supply_status'] ?? '')));
        $specify = trim((string) ($payload['specify_water_source'] ?? ''));

        // Never persist a browser-supplied computed status.
        unset(
            $payload['basic_safe_water_status'],
            $payload['water_status'],
            $payload['safe_water_status']
        );

        if ($waterSupplyStatus !== self::WATER_LEVEL_OTHERS) {
            $specify = null;
        } elseif ($specify === '') {
            $specify = null;
        }

        $payload['water_supply_status'] = $waterSupplyStatus;
        $payload['specify_water_source'] = $specify;
        $payload['basic_safe_water_status'] = self::deriveBasicSafeWaterStatus($waterSupplyStatus);

        $linked = self::findLinkedForActor($key);
        if (is_array($linked)) {
            if (trim((string) ($payload['house_head'] ?? '')) === '' && trim((string) ($linked['house_head'] ?? '')) !== '') {
                $payload['house_head'] = trim((string) $linked['house_head']);
            }
            if (trim((string) ($payload['household_type'] ?? '')) === '' && trim((string) ($linked['household_type'] ?? '')) !== '') {
                $payload['household_type'] = trim((string) $linked['household_type']);
            }
        }

        $record = array_merge($existing, $payload, [
            'household_no' => $key,
            'actor_id' => SpotMappingHandoffService::actorKey(),
            'saved_at' => now()->toIso8601String(),
            'step' => max(1, (int) ($existing['step'] ?? 0)),
        ]);

        // Demo-only path: normalize type if present; never invent one.
        if (isset($record['household_type'])) {
            $canonical = self::normalizeCanonicalHouseholdType($record['household_type']);
            if ($canonical !== null) {
                $record['household_type'] = $canonical;
            } else {
                unset($record['household_type']);
            }
        }

        $records[$key] = $record;
        session([self::SESSION_KEY => $records]);

        return $record;
    }

    /**
     * Persist optional Part 1.2 validation / sampling fields (nullable sections).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function saveStep2(string $householdNo, array $payload): array
    {
        $key = self::normalizeHouseholdNo($householdNo);
        $dbHousehold = self::findDbHousehold($key);
        if ($dbHousehold !== null) {
            $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep2($dbHousehold, $payload);
            self::forgetSessionIfErdPersisted($key, $presentation);

            return $presentation;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function writeStep2Session(string $key, array $payload): array
    {
        $records = self::all();
        $existing = is_array($records[$key] ?? null) ? $records[$key] : [];

        $step2Fields = [
            'microbiological_test_date' => self::nullableDate($payload['microbiological_test_date'] ?? null),
            'microbiological_result' => self::nullableResult($payload['microbiological_result'] ?? null),
            'physicochemical_test_date' => self::nullableDate($payload['physicochemical_test_date'] ?? null),
            'physicochemical_result' => self::nullableResult($payload['physicochemical_result'] ?? null),
        ];

        $record = array_merge($existing, $step2Fields, [
            'household_no' => $key,
            'actor_id' => SpotMappingHandoffService::actorKey(),
            'step2_saved_at' => now()->toIso8601String(),
            'step' => max(2, (int) ($existing['step'] ?? 0)),
        ]);

        $records[$key] = $record;
        session([self::SESSION_KEY => $records]);

        return $record;
    }

    /**
     * Persist required Part 2 Basic Sanitation Facility fields.
     * toilet_status and management_status are always derived server-side.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function saveStep3(string $householdNo, array $payload): array
    {
        $key = self::normalizeHouseholdNo($householdNo);
        $dbHousehold = self::findDbHousehold($key);
        if ($dbHousehold !== null) {
            $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep3($dbHousehold, $payload);
            self::forgetSessionIfErdPersisted($key, $presentation);

            return $presentation;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function writeStep3Session(string $key, array $payload): array
    {
        $records = self::all();
        $existing = is_array($records[$key] ?? null) ? $records[$key] : [];

        // Never persist browser-supplied computed statuses.
        unset(
            $payload['toilet_status'],
            $payload['management_status'],
            $payload['facility_status'],
            $payload['safely_managed']
        );

        $toiletType = strtolower(trim((string) ($payload['toilet_type'] ?? '')));
        $withoutToilet = self::isWithoutToilet($toiletType);

        $openDefecation = strtolower(trim((string) ($payload['open_defecation_practiced'] ?? '')));
        $sharedToilet = $withoutToilet
            ? 'no'
            : strtolower(trim((string) ($payload['shared_toilet'] ?? '')));

        $sewageDisposal = $withoutToilet
            ? null
            : strtolower(trim((string) ($payload['sewage_disposal_method'] ?? '')));

        if ($sewageDisposal === '') {
            $sewageDisposal = null;
        }

        $step3Fields = [
            'toilet_type' => $toiletType,
            'toilet_status' => self::deriveToiletStatus($toiletType),
            'management_status' => self::deriveManagementStatus($toiletType, $sewageDisposal),
            'open_defecation_practiced' => $openDefecation,
            'shared_toilet' => $sharedToilet,
            'sewage_disposal_method' => $sewageDisposal,
        ];

        $record = array_merge($existing, $step3Fields, [
            'household_no' => $key,
            'actor_id' => SpotMappingHandoffService::actorKey(),
            'step3_saved_at' => now()->toIso8601String(),
            'step' => max(3, (int) ($existing['step'] ?? 0)),
        ]);
        $record['complete_sanitation_status'] = self::deriveCompleteSanitationFacilityStatus($record);

        $records[$key] = $record;
        session([self::SESSION_KEY => $records]);

        return $record;
    }

    /**
     * Persist Part 3 Solid Waste Management fields.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function saveStep4(string $householdNo, array $payload): array
    {
        $key = self::normalizeHouseholdNo($householdNo);
        $dbHousehold = self::findDbHousehold($key);
        if ($dbHousehold !== null) {
            $presentation = app(HouseholdEnvironmentalProfileService::class)->saveStep4($dbHousehold, $payload);
            self::forgetSessionIfErdPersisted($key, $presentation);

            return $presentation;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function writeStep4Session(string $key, array $payload): array
    {
        $records = self::all();
        $existing = is_array($records[$key] ?? null) ? $records[$key] : [];

        $submitted = $payload['solid_waste_practices'] ?? [];
        $practices = is_array($submitted) ? $submitted : [$submitted];

        $normalizedPractices = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $practices
        ), static fn (string $value): bool => in_array($value, self::solidWastePracticeValues(), true))));

        $record = array_merge($existing, [
            'solid_waste_practices' => $normalizedPractices,
            'solid_waste_status' => $normalizedPractices === [] ? 'not_yet_determined' : 'good_practice',
            'household_no' => $key,
            'actor_id' => SpotMappingHandoffService::actorKey(),
            'step4_saved_at' => now()->toIso8601String(),
            'step' => max(4, (int) ($existing['step'] ?? 0)),
        ]);
        $record['complete_sanitation_status'] = self::deriveCompleteSanitationFacilityStatus($record);

        $records[$key] = $record;
        session([self::SESSION_KEY => $records]);

        return $record;
    }

    private static function nullableDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableResult(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim((string) $value));

        if ($trimmed === '' || ! in_array($trimmed, ['passed', 'failed'], true)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function linkedHouseholds(): array
    {
        /** @var array<string, array<string, mixed>> $linked */
        $linked = session(self::LINKED_KEY, []);

        return is_array($linked) ? $linked : [];
    }

    /**
     * Drop wizard session only after an authoritative environmental_sanitation write.
     *
     * @param  array<string, mixed>  $presentation
     */
    public static function forgetSessionIfErdPersisted(string $householdNo, array $presentation): void
    {
        if (($presentation['source'] ?? '') === 'erd') {
            self::forgetSessionEnvironmentalRecord($householdNo);
        }
    }

    /**
     * DB19-D/E: after a real MySQL profile write, drop any stale session EH field
     * record so session cannot override DB on subsequent reads (wizard or Amenities).
     */
    public static function forgetSessionEnvironmentalRecord(string $householdNo): void
    {
        $key = self::normalizeHouseholdNo($householdNo);
        $records = self::all();
        if (! isset($records[$key])) {
            return;
        }

        unset($records[$key]);
        session([self::SESSION_KEY => $records]);
    }
}
