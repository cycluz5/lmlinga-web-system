<?php

namespace App\Support;

use App\Models\Household;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Authoritative environmental_sanitation writes when legacy profiles table is absent.
 */
final class EnvironmentalSanitationWriteService
{
    /**
     * Persist Step 1 fields on an existing environmental_sanitation row.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function saveStep1(Household $household, array $validated): array
    {
        $householdId = (int) $household->getKey();

        return DB::transaction(function () use ($household, $householdId, $validated): array {
            $row = DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $draft = DemoHouseholdWaterSupply::mergeWizardSession(
                    (string) $household->household_no,
                    $validated,
                    1
                );

                return $this->presentationFromWizardDraft($household, $draft);
            }

            $waterSupplyKey = strtolower(trim((string) ($validated['water_supply_status'] ?? '')));
            $waterSupplyLabel = DemoHouseholdWaterSupply::waterSupplyLevelLabel($waterSupplyKey);
            if ($waterSupplyLabel === 'Not yet determined') {
                throw new RuntimeException('Invalid water_supply_status for ERD write.');
            }

            $updates = [
                'water_supply_status' => $waterSupplyLabel,
                'water_availability' => EnvironmentalSanitationErdMode::yesNoToBooleanFlag(
                    (string) ($validated['water_availability'] ?? '')
                ),
                'updated_at' => now(),
            ];

            // water_source_location stores descriptive ERD text; UI yes/no cannot map faithfully.
            // Preserve existing descriptive values and never overwrite with yes/no.
            if (! self::shouldPreserveWaterSourceLocation($row->water_source_location ?? null)) {
                // No faithful mapping — omit column from UPDATE.
            }

            DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->update($updates);

            $fresh = DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->first();

            return app(EnvironmentalSanitationReadService::class)
                ->presentationFromRow($fresh, $household);
        });
    }

    /**
     * Descriptive ERD location text must not be replaced by UI yes/no values.
     */
    public static function shouldPreserveWaterSourceLocation(mixed $existing): bool
    {
        $existingTrimmed = trim((string) $existing);

        return $existingTrimmed !== ''
            && ! in_array(strtolower($existingTrimmed), ['yes', 'no'], true);
    }

    /**
     * Persist Step 2 validation / testing fields on an existing environmental_sanitation row.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function saveStep2(Household $household, array $validated): array
    {
        $householdId = (int) $household->getKey();

        return DB::transaction(function () use ($household, $householdId, $validated): array {
            $row = DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $draft = DemoHouseholdWaterSupply::mergeWizardSession(
                    (string) $household->household_no,
                    $validated,
                    2
                );

                return $this->presentationFromWizardDraft($household, $draft);
            }

            $updates = [
                'updated_at' => now(),
            ];

            if ($this->hasSubmittedDate($validated['microbiological_test_date'] ?? null)) {
                $updates['microbiological_validation_date'] = trim(
                    (string) $validated['microbiological_test_date']
                );
                $updates['microbio_result'] = EnvironmentalSanitationErdMode::testResultKeyToErdLabel(
                    (string) ($validated['microbiological_result'] ?? '')
                );
            }

            if ($this->hasSubmittedDate($validated['physicochemical_test_date'] ?? null)) {
                $updates['physico_chem_test_date'] = trim(
                    (string) $validated['physicochemical_test_date']
                );
                $updates['physico_chem_result'] = EnvironmentalSanitationErdMode::testResultKeyToErdLabel(
                    (string) ($validated['physicochemical_result'] ?? '')
                );
            }

            DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->update($updates);

            $fresh = DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->first();

            return app(EnvironmentalSanitationReadService::class)
                ->presentationFromRow($fresh, $household);
        });
    }

    private function hasSubmittedDate(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Persist Step 3 basic sanitation fields on an existing environmental_sanitation row.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function saveStep3(Household $household, array $validated): array
    {
        $householdId = (int) $household->getKey();

        return DB::transaction(function () use ($household, $householdId, $validated): array {
            $row = DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $draft = DemoHouseholdWaterSupply::mergeWizardSession(
                    (string) $household->household_no,
                    $validated,
                    3
                );

                return $this->presentationFromWizardDraft($household, $draft);
            }

            $toiletType = strtolower(trim((string) ($validated['toilet_type'] ?? '')));
            $withoutToilet = DemoHouseholdWaterSupply::isWithoutToilet($toiletType);

            $updates = [
                'toilet_type' => $toiletType,
                'open_defecation_place' => EnvironmentalSanitationErdMode::yesNoToBooleanFlag(
                    (string) ($validated['open_defecation_practiced'] ?? '')
                ),
                'shared_toilet' => $withoutToilet
                    ? 0
                    : EnvironmentalSanitationErdMode::yesNoToBooleanFlag(
                        (string) ($validated['shared_toilet'] ?? '')
                    ),
                'updated_at' => now(),
            ];

            if ($withoutToilet) {
                $updates['sewage_disposal_method'] = null;
            } else {
                $rawSewage = trim((string) ($validated['sewage_disposal_method'] ?? ''));
                if ($rawSewage === '') {
                    $updates['sewage_disposal_method'] = null;
                } else {
                    $sewageLabel = EnvironmentalSanitationErdMode::sewageKeyToErdLabel($rawSewage);
                    if ($sewageLabel === null) {
                        throw new RuntimeException('Invalid sewage_disposal_method for ERD write.');
                    }
                    $updates['sewage_disposal_method'] = $sewageLabel;
                }
            }

            DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->update($updates);

            $fresh = DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->first();

            return app(EnvironmentalSanitationReadService::class)
                ->presentationFromRow($fresh, $household);
        });
    }

    /**
     * Persist Step 4 solid waste flags on waste_management_practices linked to environmental_sanitation.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function saveStep4(Household $household, array $validated): array
    {
        $householdId = (int) $household->getKey();
        $practices = $this->normalizeSolidWastePractices($validated['solid_waste_practices'] ?? []);

        return DB::transaction(function () use ($household, $householdId, $practices, $validated): array {
            $environmentalRow = DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->lockForUpdate()
                ->first();

            if ($environmentalRow === null) {
                $draft = DemoHouseholdWaterSupply::mergeWizardSession(
                    (string) $household->household_no,
                    $validated,
                    4
                );

                $inserted = $this->insertAuthoritativeRowFromDraft($household, $draft);
                if ($inserted !== null) {
                    return $inserted;
                }

                return $this->presentationFromWizardDraft($household, $draft);
            }

            if (! EnvironmentalSanitationErdMode::wasteManagementTableActive()) {
                throw new RuntimeException(
                    'Cannot update Step 4: waste_management_practices table is not available.'
                );
            }

            $envAssessmentId = (int) ($environmentalRow->{EnvironmentalSanitationErdMode::primaryKey()} ?? 0);
            if ($envAssessmentId <= 0) {
                throw new RuntimeException('Cannot update Step 4: missing env_assessment_id.');
            }

            $flags = EnvironmentalSanitationErdMode::solidWastePracticeKeysToErdFlags($practices);
            $now = now();

            $existingWaste = DB::table('waste_management_practices')
                ->where('env_assessment_id', $envAssessmentId)
                ->lockForUpdate()
                ->first();

            if ($existingWaste !== null) {
                DB::table('waste_management_practices')
                    ->where('env_assessment_id', $envAssessmentId)
                    ->update(array_merge($flags, [
                        'updated_at' => $now,
                    ]));
            } else {
                DB::table('waste_management_practices')->insert(array_merge([
                    'env_assessment_id' => $envAssessmentId,
                ], $flags, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }

            $fresh = DB::table('environmental_sanitation')
                ->where('household_id', $householdId)
                ->first();

            return app(EnvironmentalSanitationReadService::class)
                ->presentationFromRow($fresh, $household);
        });
    }

    /**
     * @return list<string>
     */
    private function normalizeSolidWastePractices(mixed $submitted): array
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
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function presentationFromWizardDraft(Household $household, array $draft): array
    {
        $record = $draft;
        $record['household_id'] = (int) $household->getKey();
        $record['household_no'] = (string) $household->household_no;
        $record['source'] = 'session';
        $record['complete_sanitation_status'] = DemoHouseholdWaterSupply::deriveCompleteSanitationFacilityStatus($record);

        return $record;
    }

    /**
     * Insert environmental_sanitation only when every required column has a real collected value.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function insertAuthoritativeRowFromDraft(Household $household, array $draft): ?array
    {
        $values = $this->mapDraftToInsertValues($household, $draft);
        $missing = $this->missingRequiredInsertColumns($values);
        if ($missing !== []) {
            return null;
        }

        DB::table('environmental_sanitation')->insert($values);

        $fresh = DB::table('environmental_sanitation')
            ->where('household_id', (int) $household->getKey())
            ->first();

        if ($fresh === null) {
            return null;
        }

        if (EnvironmentalSanitationErdMode::wasteManagementTableActive()) {
            $practices = $this->normalizeSolidWastePractices($draft['solid_waste_practices'] ?? []);
            $envAssessmentId = (int) ($fresh->{EnvironmentalSanitationErdMode::primaryKey()} ?? 0);
            if ($envAssessmentId > 0) {
                $now = now();
                DB::table('waste_management_practices')->insert(array_merge([
                    'env_assessment_id' => $envAssessmentId,
                ], EnvironmentalSanitationErdMode::solidWastePracticeKeysToErdFlags($practices), [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        DemoHouseholdWaterSupply::forgetSessionEnvironmentalRecord((string) $household->household_no);

        return app(EnvironmentalSanitationReadService::class)
            ->presentationFromRow($fresh, $household);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function mapDraftToInsertValues(Household $household, array $draft): array
    {
        $now = now();
        $values = [
            'household_id' => (int) $household->getKey(),
        ];

        if (Schema::hasColumn('environmental_sanitation', 'created_at')) {
            $values['created_at'] = $now;
        }
        if (Schema::hasColumn('environmental_sanitation', 'updated_at')) {
            $values['updated_at'] = $now;
        }

        $waterSupplyKey = strtolower(trim((string) ($draft['water_supply_status'] ?? '')));
        $waterSupplyLabel = DemoHouseholdWaterSupply::waterSupplyLevelLabel($waterSupplyKey);
        if (
            Schema::hasColumn('environmental_sanitation', 'water_supply_status')
            && $waterSupplyLabel !== 'Not yet determined'
        ) {
            $values['water_supply_status'] = $waterSupplyLabel;
        }

        $location = strtolower(trim((string) ($draft['water_source_location'] ?? '')));
        if (
            Schema::hasColumn('environmental_sanitation', 'water_source_location')
            && in_array($location, ['yes', 'no'], true)
        ) {
            $values['water_source_location'] = $location;
        }

        $availability = strtolower(trim((string) ($draft['water_availability'] ?? '')));
        if (
            Schema::hasColumn('environmental_sanitation', 'water_availability')
            && in_array($availability, ['yes', 'no'], true)
        ) {
            $values['water_availability'] = EnvironmentalSanitationErdMode::yesNoToBooleanFlag($availability);
        }

        if (
            Schema::hasColumn('environmental_sanitation', 'microbiological_validation_date')
            && $this->hasSubmittedDate($draft['microbiological_test_date'] ?? null)
        ) {
            $values['microbiological_validation_date'] = trim((string) $draft['microbiological_test_date']);
            if (Schema::hasColumn('environmental_sanitation', 'microbio_result')) {
                $microResult = EnvironmentalSanitationErdMode::testResultKeyToErdLabel(
                    (string) ($draft['microbiological_result'] ?? '')
                );
                if ($microResult !== null) {
                    $values['microbio_result'] = $microResult;
                }
            }
        }

        if (
            Schema::hasColumn('environmental_sanitation', 'physico_chem_test_date')
            && $this->hasSubmittedDate($draft['physicochemical_test_date'] ?? null)
        ) {
            $values['physico_chem_test_date'] = trim((string) $draft['physicochemical_test_date']);
            if (Schema::hasColumn('environmental_sanitation', 'physico_chem_result')) {
                $physicoResult = EnvironmentalSanitationErdMode::testResultKeyToErdLabel(
                    (string) ($draft['physicochemical_result'] ?? '')
                );
                if ($physicoResult !== null) {
                    $values['physico_chem_result'] = $physicoResult;
                }
            }
        }

        $toiletType = strtolower(trim((string) ($draft['toilet_type'] ?? '')));
        if (Schema::hasColumn('environmental_sanitation', 'toilet_type') && $toiletType !== '') {
            $values['toilet_type'] = $toiletType;
            $withoutToilet = DemoHouseholdWaterSupply::isWithoutToilet($toiletType);

            if (Schema::hasColumn('environmental_sanitation', 'open_defecation_place')) {
                $values['open_defecation_place'] = EnvironmentalSanitationErdMode::yesNoToBooleanFlag(
                    (string) ($draft['open_defecation_practiced'] ?? '')
                );
            }

            if (Schema::hasColumn('environmental_sanitation', 'shared_toilet')) {
                $values['shared_toilet'] = $withoutToilet
                    ? 0
                    : EnvironmentalSanitationErdMode::yesNoToBooleanFlag(
                        (string) ($draft['shared_toilet'] ?? '')
                    );
            }

            if (Schema::hasColumn('environmental_sanitation', 'sewage_disposal_method')) {
                if ($withoutToilet) {
                    $values['sewage_disposal_method'] = null;
                } else {
                    $rawSewage = trim((string) ($draft['sewage_disposal_method'] ?? ''));
                    if ($rawSewage === '') {
                        $values['sewage_disposal_method'] = null;
                    } else {
                        $sewageLabel = EnvironmentalSanitationErdMode::sewageKeyToErdLabel($rawSewage);
                        if ($sewageLabel !== null) {
                            $values['sewage_disposal_method'] = $sewageLabel;
                        }
                    }
                }
            }
        }

        $authId = Auth::id();
        if (
            Schema::hasColumn('environmental_sanitation', 'user_id')
            && $authId !== null
        ) {
            $values['user_id'] = (int) $authId;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function missingRequiredInsertColumns(array $values): array
    {
        $missing = [];

        foreach (Schema::getColumns('environmental_sanitation') as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (($column['nullable'] ?? true) === true) {
                continue;
            }

            if (in_array($name, [
                EnvironmentalSanitationErdMode::primaryKey(),
                'id',
                'created_at',
                'updated_at',
            ], true)) {
                continue;
            }

            if (! array_key_exists($name, $values) || $values[$name] === null || $values[$name] === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
