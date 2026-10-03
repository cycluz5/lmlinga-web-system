<?php

namespace App\Support;

use App\Models\Household;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only adapter from authoritative environmental_sanitation to amenities/dashboard presentation.
 */
final class EnvironmentalSanitationReadService
{
    /**
     * Batched rows for the current bulk run (see preloadForHouseholds()). Null = per-household queries.
     *
     * @var array{env: array<int, object>, waste: array<int, object>}|null
     */
    private ?array $preloaded = null;

    /**
     * Resolve sanitation and waste rows for many households in a constant number of queries,
     * so findPresentationForHousehold() does no database work per household. Call clearPreload() when done.
     *
     * @param  list<int|string>  $householdIds
     */
    public function preloadForHouseholds(array $householdIds): void
    {
        $env = [];
        $waste = [];

        if (EnvironmentalSanitationErdMode::isActive()) {
            $primaryKey = EnvironmentalSanitationErdMode::primaryKey();
            foreach (array_chunk($householdIds, 1000) as $chunk) {
                foreach (DB::table('environmental_sanitation')->whereIn('household_id', $chunk)->get() as $row) {
                    $householdId = (int) ($row->household_id ?? 0);
                    if ($householdId > 0) {
                        $env[$householdId] ??= $row;
                    }
                }
            }

            if (EnvironmentalSanitationErdMode::wasteManagementTableActive()) {
                $assessmentIds = [];
                foreach ($env as $row) {
                    $assessmentIds[] = (int) ($row->{$primaryKey} ?? 0);
                }
                foreach (array_chunk(array_filter($assessmentIds), 1000) as $chunk) {
                    foreach (DB::table('waste_management_practices')->whereIn('env_assessment_id', $chunk)->get() as $row) {
                        $waste[(int) $row->env_assessment_id] ??= $row;
                    }
                }
            }
        }

        $this->preloaded = ['env' => $env, 'waste' => $waste];
    }

    public function clearPreload(): void
    {
        $this->preloaded = null;
    }

    /**
     * @return array<int, object>
     */
    public function rowsIndexedByHouseholdId(): array
    {
        if (! EnvironmentalSanitationErdMode::isActive()) {
            return [];
        }

        $indexed = [];
        foreach (DB::table('environmental_sanitation')->get() as $row) {
            $householdId = (int) ($row->household_id ?? 0);
            if ($householdId > 0) {
                $indexed[$householdId] = $row;
            }
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentationFromRow(object $row, Household $household): array
    {
        $waterSupplyStatus = EnvironmentalSanitationErdMode::waterSupplyLabelToKey(
            EnvironmentalSanitationErdMode::waterSupplyLabelFromRow($row)
        );
        $toiletType = strtolower(trim((string) ($row->toilet_type ?? '')));
        $sewageKey = EnvironmentalSanitationErdMode::sewageLabelToKey($row->sewage_disposal_method ?? null);

        $microDate = $this->dateString($row->microbiological_validation_date ?? null);
        $physicoDate = $this->dateString($row->physico_chem_test_date ?? null);
        $microResult = $this->resultString($row->microbio_result ?? null);
        $physicoResult = $this->resultString($row->physico_chem_result ?? null);

        $wasteRow = $this->findWasteRowForAssessment($row);
        $solidWastePractices = EnvironmentalSanitationErdMode::solidWastePracticesFromWasteRow($wasteRow);
        $solidWasteStatus = EnvironmentalSanitationErdMode::solidWasteStatusFromWasteRow($wasteRow);

        $record = [
            'id' => (int) ($row->{EnvironmentalSanitationErdMode::primaryKey()} ?? 0),
            'household_id' => (int) ($row->household_id ?? 0),
            'household_no' => (string) $household->household_no,
            'house_head' => '',
            'household_type' => $this->canonicalHouseholdTypeFromHousehold($household),
            'water_supply_status' => $waterSupplyStatus,
            'specify_water_source' => null,
            'water_source_location' => EnvironmentalSanitationErdMode::erdWaterSourceLocationToFormValue(
                $row->water_source_location ?? null
            ),
            'water_availability' => EnvironmentalSanitationErdMode::booleanFlagToYesNo($row->water_availability ?? null),
            'basic_safe_water_status' => DemoHouseholdWaterSupply::deriveBasicSafeWaterStatus($waterSupplyStatus),
            'microbiological_test_date' => $microDate,
            'microbiological_result' => $microResult,
            'physicochemical_test_date' => $physicoDate,
            'physicochemical_result' => $physicoResult,
            'toilet_type' => $toiletType,
            'toilet_status' => DemoHouseholdWaterSupply::deriveToiletStatus($toiletType),
            'open_defecation_practiced' => EnvironmentalSanitationErdMode::booleanFlagToYesNo($row->open_defecation_place ?? null),
            'shared_toilet' => EnvironmentalSanitationErdMode::booleanFlagToYesNo($row->shared_toilet ?? null),
            'sewage_disposal_method' => $sewageKey,
            'management_status' => DemoHouseholdWaterSupply::deriveManagementStatus($toiletType, $sewageKey),
            'solid_waste_practices' => $solidWastePractices,
            'solid_waste_status' => $solidWasteStatus,
            'step' => self::derivedCompletedStep($row),
            'source' => 'erd',
        ];

        $record['complete_sanitation_status'] = DemoHouseholdWaterSupply::deriveCompleteSanitationFacilityStatus($record);

        return $record;
    }

    private function canonicalHouseholdTypeFromHousehold(Household $household): string
    {
        if (! app(DatabaseSchemaGuard::class)->householdTypeUsesHouseholdColumn()) {
            return '';
        }

        return DemoHouseholdWaterSupply::normalizeCanonicalHouseholdType(
            $household->getAttributes()['household_type'] ?? null
        ) ?? '';
    }

    /**
     * Read-only Step 1+ wizard presentation for one household from environmental_sanitation.
     *
     * @return array<string, mixed>|null
     */
    public function findPresentationForHousehold(Household $household): ?array
    {
        if (! EnvironmentalSanitationErdMode::isActive()) {
            return null;
        }

        $row = $this->preloaded !== null
            ? ($this->preloaded['env'][(int) $household->getKey()] ?? null)
            : DB::table('environmental_sanitation')
                ->where('household_id', $household->getKey())
                ->first();

        if ($row === null) {
            return null;
        }

        return $this->presentationFromRow($row, $household);
    }

    public function recordStatusForRow(?object $row): string
    {
        if ($row === null) {
            return EnvironmentalHealthDashboard::RECORD_STATUS_PENDING;
        }

        $toiletType = trim((string) ($row->toilet_type ?? ''));

        return $toiletType !== ''
            ? EnvironmentalHealthDashboard::RECORD_STATUS_COMPLETED
            : EnvironmentalHealthDashboard::RECORD_STATUS_PENDING;
    }

    public function completedStepForRow(?object $row): int
    {
        return self::derivedCompletedStep($row);
    }

    private static function derivedCompletedStep(?object $row): int
    {
        if ($row === null) {
            return 0;
        }

        return trim((string) ($row->toilet_type ?? '')) !== '' ? 4 : 0;
    }

    private function findWasteRowForAssessment(object $environmentalRow): ?object
    {
        if (! EnvironmentalSanitationErdMode::wasteManagementTableActive()) {
            return null;
        }

        $envAssessmentId = (int) ($environmentalRow->{EnvironmentalSanitationErdMode::primaryKey()} ?? 0);
        if ($envAssessmentId <= 0) {
            return null;
        }

        if ($this->preloaded !== null) {
            return $this->preloaded['waste'][$envAssessmentId] ?? null;
        }

        return DB::table('waste_management_practices')
            ->where('env_assessment_id', $envAssessmentId)
            ->first();
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function resultString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = strtolower(trim((string) $value));

        return in_array($trimmed, ['passed', 'failed'], true) ? $trimmed : null;
    }
}
