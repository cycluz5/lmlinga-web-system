<?php

namespace App\Services;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Models\HouseholdSolidWastePractice;
use App\Support\DatabaseSchemaGuard;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\EnvironmentalSanitationReadService;
use App\Support\EnvironmentalSanitationWriteService;
use App\Support\HouseholdProfilingWriteGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DB-17 Phase 2B — durable household environmental / amenities persistence.
 *
 * One profile per household. Step saves upsert the same row transactionally.
 * Presentation arrays mirror the former session record shape for frozen Blade.
 */
final class HouseholdEnvironmentalProfileService
{
    public static function persistenceAvailable(): bool
    {
        return app(DatabaseSchemaGuard::class)->tableExists('household_environmental_profiles');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPresentation(Household $household): ?array
    {
        if (self::persistenceAvailable()) {
            $profile = $household->environmentalProfile()
                ->with('solidWastePractices')
                ->first();

            return $profile !== null ? $this->toPresentation($household, $profile) : null;
        }

        if (EnvironmentalSanitationErdMode::isActive()) {
            $fromDb = app(EnvironmentalSanitationReadService::class)
                ->findPresentationForHousehold($household);

            if ($fromDb !== null) {
                return $fromDb;
            }

            $session = DemoHouseholdWaterSupply::findSessionRecord((string) $household->household_no);
            if (
                is_array($session)
                && (string) ($session['actor_id'] ?? '') === SpotMappingHandoffService::actorKey()
            ) {
                $session['source'] = 'session';
                $session['household_id'] = (int) $household->getKey();

                return $session;
            }

            return null;
        }

        return null;
    }

    public function completedStep(Household $household): int
    {
        if (! self::persistenceAvailable()) {
            if (EnvironmentalSanitationErdMode::isActive()) {
                $row = DB::table('environmental_sanitation')
                    ->where('household_id', $household->getKey())
                    ->first();

                return app(EnvironmentalSanitationReadService::class)->completedStepForRow($row);
            }

            return 0;
        }

        $profile = $household->environmentalProfile()->first();

        return $profile !== null ? (int) $profile->completed_step : 0;
    }

    public function hasCompletedStep(Household $household, int $step): bool
    {
        if (! self::persistenceAvailable()) {
            if (EnvironmentalSanitationErdMode::isActive()) {
                $session = DemoHouseholdWaterSupply::findSessionRecord((string) $household->household_no);
                $sessionComplete = is_array($session)
                    && (string) ($session['actor_id'] ?? '') === SpotMappingHandoffService::actorKey()
                    && (int) ($session['step'] ?? 0) >= $step;

                if ($sessionComplete) {
                    return true;
                }

                $row = DB::table('environmental_sanitation')
                    ->where('household_id', $household->getKey())
                    ->first();

                if ($step === 1) {
                    if ($row === null) {
                        return false;
                    }

                    return trim(EnvironmentalSanitationErdMode::waterSupplyLabelFromRow($row)) !== '';
                }

                return $this->completedStep($household) >= $step;
            }

            return $this->completedStep($household) >= $step;
        }

        return $this->completedStep($household) >= $step;
    }

    /**
     * Amenities form: persist all four sections in one transaction.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function saveAll(Household $household, array $validated): array
    {
        HouseholdProfilingWriteGuard::rejectAmenitiesWrite();

        return DB::transaction(function () use ($household, $validated): array {
            $this->saveStep1($household, $validated, false);
            $this->saveStep2($household, $validated, false);
            $this->saveStep3($household, $validated, false);
            $this->saveStep4($household, $validated, false);

            $household->unsetRelation('environmentalProfile');

            if (! self::persistenceAvailable() && EnvironmentalSanitationErdMode::isActive()) {
                $persisted = DB::table('environmental_sanitation')
                    ->where('household_id', $household->getKey())
                    ->exists();

                if (! $persisted) {
                    DemoHouseholdWaterSupply::forgetSessionEnvironmentalRecord(
                        (string) $household->household_no
                    );

                    throw ValidationException::withMessages([
                        'amenities' => 'Household amenities details could not be saved because a complete environmental sanitation record could not be created.',
                    ]);
                }
            }

            return $this->findPresentation($household) ?? [];
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function saveStep1(Household $household, array $validated, bool $wrapTransaction = true): array
    {
        if (! self::persistenceAvailable() && EnvironmentalSanitationErdMode::isActive()) {
            $writer = fn (): array => app(EnvironmentalSanitationWriteService::class)
                ->saveStep1($household, $validated);

            return $wrapTransaction ? DB::transaction($writer) : $writer();
        }

        $writer = function () use ($household, $validated): array {
            $waterSupplyStatus = strtolower(trim((string) ($validated['water_supply_status'] ?? '')));
            $specify = trim((string) ($validated['specify_water_source'] ?? ''));
            if ($waterSupplyStatus !== DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS) {
                $specify = '';
            }

            // Never trust browser household_type. Fill only when the profile column is empty:
            // session/handoff first, then households.household_type when that column exists.
            $profile = $this->lockOrCreateProfile($household);
            $householdType = $this->resolveStep1HouseholdType($household, $profile);

            $profile->fill([
                'household_type' => $householdType,
                'water_supply_status' => $waterSupplyStatus,
                'specify_water_source' => $specify === '' ? null : $specify,
                'water_source_location' => strtolower(trim((string) ($validated['water_source_location'] ?? ''))),
                'water_availability' => strtolower(trim((string) ($validated['water_availability'] ?? ''))),
                'basic_safe_water_status' => DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus($waterSupplyStatus),
                'completed_step' => max(1, (int) $profile->completed_step),
            ]);
            $profile->save();

            return $this->toPresentation($household, $profile->fresh(['solidWastePractices']) ?? $profile);
        };

        return $wrapTransaction ? DB::transaction($writer) : $writer();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function saveStep2(Household $household, array $validated, bool $wrapTransaction = true): array
    {
        if (! self::persistenceAvailable() && EnvironmentalSanitationErdMode::isActive()) {
            $writer = fn (): array => app(EnvironmentalSanitationWriteService::class)
                ->saveStep2($household, $validated);

            return $wrapTransaction ? DB::transaction($writer) : $writer();
        }

        $writer = function () use ($household, $validated): array {
            $profile = $this->lockOrCreateProfile($household);
            $profile->fill([
                'microbiological_test_date' => $this->nullableDate($validated['microbiological_test_date'] ?? null),
                'microbiological_result' => $this->nullableResult($validated['microbiological_result'] ?? null),
                'physicochemical_test_date' => $this->nullableDate($validated['physicochemical_test_date'] ?? null),
                'physicochemical_result' => $this->nullableResult($validated['physicochemical_result'] ?? null),
                'completed_step' => max(2, (int) $profile->completed_step),
            ]);
            $profile->save();

            return $this->toPresentation($household, $profile->fresh(['solidWastePractices']) ?? $profile);
        };

        return $wrapTransaction ? DB::transaction($writer) : $writer();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function saveStep3(Household $household, array $validated, bool $wrapTransaction = true): array
    {
        if (! self::persistenceAvailable() && EnvironmentalSanitationErdMode::isActive()) {
            $writer = fn (): array => app(EnvironmentalSanitationWriteService::class)
                ->saveStep3($household, $validated);

            return $wrapTransaction ? DB::transaction($writer) : $writer();
        }

        $writer = function () use ($household, $validated): array {
            $toiletType = strtolower(trim((string) ($validated['toilet_type'] ?? '')));
            $withoutToilet = DemoHouseholdWaterSupply::isWithoutToilet($toiletType);
            $sewage = $withoutToilet
                ? null
                : strtolower(trim((string) ($validated['sewage_disposal_method'] ?? '')));
            if ($sewage === '') {
                $sewage = null;
            }

            $profile = $this->lockOrCreateProfile($household);
            $profile->fill([
                'toilet_type' => $toiletType,
                'toilet_status' => DemoHouseholdWaterSupply::deriveToiletStatus($toiletType),
                'management_status' => DemoHouseholdWaterSupply::deriveManagementStatus($toiletType, $sewage),
                'open_defecation_practiced' => strtolower(trim((string) ($validated['open_defecation_practiced'] ?? ''))),
                'shared_toilet' => $withoutToilet
                    ? 'no'
                    : strtolower(trim((string) ($validated['shared_toilet'] ?? ''))),
                'sewage_disposal_method' => $sewage,
                'completed_step' => max(3, (int) $profile->completed_step),
            ]);
            $profile->save();

            $fresh = $profile->fresh(['solidWastePractices']) ?? $profile;

            return $this->toPresentation($household, $fresh);
        };

        return $wrapTransaction ? DB::transaction($writer) : $writer();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function saveStep4(Household $household, array $validated, bool $wrapTransaction = true): array
    {
        if (! self::persistenceAvailable() && EnvironmentalSanitationErdMode::isActive()) {
            $writer = fn (): array => app(EnvironmentalSanitationWriteService::class)
                ->saveStep4($household, $validated);

            return $wrapTransaction ? DB::transaction($writer) : $writer();
        }

        $writer = function () use ($household, $validated): array {
            $practices = $this->normalizePractices($validated['solid_waste_practices'] ?? []);
            $flags = $this->practicesToFlags($practices);

            $profile = $this->lockOrCreateProfile($household);
            $profile->fill([
                'solid_waste_status' => $practices === [] ? 'not_yet_determined' : 'good_practice',
                'completed_step' => max(4, (int) $profile->completed_step),
            ]);
            $profile->save();

            HouseholdSolidWastePractice::query()->updateOrCreate(
                ['household_environmental_profile_id' => $profile->id],
                $flags
            );

            $fresh = $profile->fresh(['solidWastePractices']) ?? $profile;

            return $this->toPresentation($household, $fresh);
        };

        return $wrapTransaction ? DB::transaction($writer) : $writer();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPresentation(Household $household, HouseholdEnvironmentalProfile $profile): array
    {
        $profile->loadMissing('solidWastePractices');
        $practices = $this->flagsToPractices($profile->solidWastePractices);

        $record = [
            'id' => $profile->id,
            'household_id' => $household->id,
            'household_no' => (string) $household->household_no,
            'house_head' => $this->houseHeadName($household),
            'household_type' => (string) ($profile->household_type ?? ''),
            'water_supply_status' => (string) ($profile->water_supply_status ?? ''),
            'specify_water_source' => $profile->specify_water_source,
            'water_source_location' => (string) ($profile->water_source_location ?? ''),
            'water_availability' => (string) ($profile->water_availability ?? ''),
            'basic_safe_water_status' => (string) ($profile->basic_safe_water_status
                ?? DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus($profile->water_supply_status)),
            'microbiological_test_date' => $profile->microbiological_test_date?->format('Y-m-d'),
            'microbiological_result' => $profile->microbiological_result,
            'physicochemical_test_date' => $profile->physicochemical_test_date?->format('Y-m-d'),
            'physicochemical_result' => $profile->physicochemical_result,
            'toilet_type' => (string) ($profile->toilet_type ?? ''),
            'toilet_status' => $profile->toilet_status,
            'open_defecation_practiced' => (string) ($profile->open_defecation_practiced ?? ''),
            'shared_toilet' => (string) ($profile->shared_toilet ?? ''),
            'sewage_disposal_method' => $profile->sewage_disposal_method,
            'management_status' => $profile->management_status
                ?? DemoHouseholdWaterSupply::deriveManagementStatus(
                    $profile->toilet_type,
                    $profile->sewage_disposal_method
                ),
            'solid_waste_practices' => $practices,
            'solid_waste_status' => (string) ($profile->solid_waste_status ?? 'not_yet_determined'),
            'step' => (int) $profile->completed_step,
            'source' => 'db',
        ];

        $record['complete_sanitation_status'] = DemoHouseholdWaterSupply::deriveCompleteSanitationFacilityStatus($record);

        return $record;
    }

    private function lockOrCreateProfile(Household $household): HouseholdEnvironmentalProfile
    {
        $existing = HouseholdEnvironmentalProfile::query()
            ->where('household_id', $household->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return HouseholdEnvironmentalProfile::query()->create([
                'household_id' => $household->id,
                'completed_step' => 0,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $message = $e->getMessage();
            $isUnique = str_contains($message, 'UNIQUE constraint failed')
                || str_contains($message, 'household_id')
                || (string) $e->getCode() === '23000';

            if (! $isUnique) {
                throw $e;
            }

            return HouseholdEnvironmentalProfile::query()
                ->where('household_id', $household->id)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function houseHeadName(Household $household): string
    {
        $household->loadMissing('residents');
        $head = $household->residents->first(
            static fn ($r): bool => strcasecmp((string) $r->relation, 'Head') === 0
        );

        if ($head === null) {
            return '';
        }

        return trim(implode(' ', array_filter([
            (string) $head->first_name,
            (string) ($head->middle_name ?? ''),
            (string) $head->last_name,
        ], static fn (string $p): bool => $p !== '')));
    }

    /**
     * Precedence: existing profile type, then trusted session/handoff, then household row.
     */
    private function resolveStep1HouseholdType(
        Household $household,
        HouseholdEnvironmentalProfile $profile,
    ): ?string {
        $existing = trim((string) ($profile->household_type ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $fromLink = $this->linkedHouseholdType((string) $household->household_no);
        if ($fromLink !== null) {
            return $fromLink;
        }

        return $this->householdRowType($household);
    }

    private function linkedHouseholdType(string $householdNo): ?string
    {
        $linked = DemoHouseholdWaterSupply::findLinkedForActor($householdNo);
        if (! is_array($linked)) {
            return null;
        }

        return DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType(
            $linked['household_type'] ?? null
        );
    }

    private function householdRowType(Household $household): ?string
    {
        if (! app(DatabaseSchemaGuard::class)->householdTypeUsesHouseholdColumn()) {
            return null;
        }

        return DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType(
            $household->getAttributes()['household_type'] ?? null
        );
    }

    /**
     * @param  mixed  $submitted
     * @return list<string>
     */
    private function normalizePractices(mixed $submitted): array
    {
        $practices = is_array($submitted) ? $submitted : [$submitted];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $practices
        ), static fn (string $value): bool => in_array(
            $value,
            DemoHouseholdWaterSupply::solidWastePracticeValues(),
            true
        ))));
    }

    /**
     * @param  list<string>  $practices
     * @return array{waste_segregation: bool, backyard_composting: bool, recycling_reuse: bool, municipal_collection: bool}
     */
    private function practicesToFlags(array $practices): array
    {
        return [
            'waste_segregation' => in_array(DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION, $practices, true),
            'backyard_composting' => in_array(DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING, $practices, true),
            'recycling_reuse' => in_array(DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE, $practices, true),
            'municipal_collection' => in_array(DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION, $practices, true),
        ];
    }

    /**
     * @return list<string>
     */
    private function flagsToPractices(?HouseholdSolidWastePractice $row): array
    {
        if ($row === null) {
            return [];
        }

        $out = [];
        if ($row->waste_segregation) {
            $out[] = DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION;
        }
        if ($row->backyard_composting) {
            $out[] = DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING;
        }
        if ($row->recycling_reuse) {
            $out[] = DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE;
        }
        if ($row->municipal_collection) {
            $out[] = DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION;
        }

        return $out;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableResult(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim((string) $value));

        return in_array($trimmed, ['passed', 'failed'], true) ? $trimmed : null;
    }
}
