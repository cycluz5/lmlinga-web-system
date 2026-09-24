<?php

namespace App\Support;

use App\Models\Resident;
use App\Models\TimbangRecord;
use App\Services\NutritionAssessmentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * FR-12 — resident-scoped timbang_records history (new measurement events).
 *
 * Does not query risk_assessment, maternal_care, prenatal_visits, or
 * child_nutrition. Trusted callers pass already-resolved measurements.
 */
final class TimbangRecordService
{
    public function __construct(
        private readonly NutritionAssessmentService $assessment = new NutritionAssessmentService,
    ) {}

    public static function persistenceAvailable(): bool
    {
        return Schema::hasTable('timbang_records');
    }

    /**
     * @return array{weight: string, height: string, mode: 'bmi'|'status', bmi: string, status: string, muac_applicable: bool, muac: string, muac_status: string}
     */
    public function emptyCardState(string $mode = 'bmi'): array
    {
        return [
            'weight' => '—',
            'height' => '—',
            'mode' => $mode,
            'bmi' => '—',
            'status' => '—',
            'muac_applicable' => false,
            'muac' => '—',
            'muac_status' => '—',
        ];
    }

    /**
     * Compact member-view card, age-adaptive to match the household member
     * profile requirement:
     *   - 0-5 months (baby, MUAC not applicable): Weight, Height, and the
     *     latest measurement's Overall Nutritional Status.
     *   - 6-59 months (baby, MUAC band): Weight, Height, Overall Nutritional
     *     Status, plus MUAC (value + status).
     *   - 5 years and up (BMI applies): Weight, Height, and BMI shown as
     *     "20.8 (Normal)" — the numeric value with its status alongside.
     * Demo catalog values are not used for DB residents. Age is taken from
     * the latest measurement's own date if one exists (never "today"), so
     * the card matches what was actually recorded — falling back to the
     * resident's current age only when there is no measurement yet.
     *
     * @return array{weight: string, height: string, mode: 'bmi'|'status', bmi: string, status: string, muac_applicable: bool, muac: string, muac_status: string}
     */
    public function cardStateForResident(?Resident $resident): array
    {
        if ($resident === null || (int) $resident->getKey() <= 0) {
            return $this->emptyCardState();
        }

        $latest = $this->latestForResident($resident);
        if ($latest === null) {
            $band = $this->assessment->ageBand($resident->birthday, Carbon::now());
            $mode = $band !== null && $this->assessment->bmiApplicable($band) ? 'bmi' : 'status';

            return $this->emptyCardState($mode);
        }

        $context = $this->assessment->displayContextForRecord($resident, $latest);
        $mode = $context['bmi_applicable'] ? 'bmi' : 'status';

        return [
            'weight' => $this->formatDecimal($latest->weight_kg, 2),
            'height' => $this->formatDecimal($latest->height_cm, 2),
            'mode' => $mode,
            'bmi' => $context['bmi_display'] !== null ? $this->formatDecimal($context['bmi_display'], 1) : '—',
            'status' => $mode === 'bmi'
                ? ($latest->bmi_status ?? '—')
                : ($latest->overall_nutritional_status ?? '—'),
            'muac_applicable' => $context['muac_applicable'],
            'muac' => $this->formatDecimal($latest->muac_cm, 1),
            'muac_status' => $latest->muac_status ?? '—',
        ];
    }

    public function latestForResident(Resident $resident): ?TimbangRecord
    {
        if (! self::persistenceAvailable()) {
            return null;
        }

        return TimbangRecord::query()
            ->where('resident_id', $resident->getKey())
            ->orderByDesc('measurement_date')
            ->orderByDesc('timbang_id')
            ->first();
    }

    /**
     * @return list<TimbangRecord>
     */
    public function historyForResident(Resident $resident): array
    {
        if (! self::persistenceAvailable()) {
            return [];
        }

        return TimbangRecord::query()
            ->where('resident_id', $resident->getKey())
            ->orderByDesc('measurement_date')
            ->orderByDesc('timbang_id')
            ->get()
            ->all();
    }

    /**
     * Newest-first history with derived display-only weight/height progress,
     * plus recomputed age-adaptive display context per row (age at that
     * measurement date, which indicators applied — never persisted, never
     * mutates history).
     *
     * @return list<array{
     *     record: TimbangRecord,
     *     bmi: string|null,
     *     bmi_label: string,
     *     weight_change: float|null,
     *     weight_change_label: string,
     *     is_weight_baseline: bool,
     *     height_change: float|null,
     *     height_change_label: string,
     *     is_height_baseline: bool,
     *     assessment: array{
     *         age_band: string|null,
     *         age_label: string,
     *         muac_applicable: bool,
     *         bmi_applicable: bool,
     *         weight_for_age_applicable: bool,
     *         bmi_display: float|null
     *     }
     * }>
     */
    public function historyPresentationForResident(Resident $resident): array
    {
        $rows = $this->withDerivedProgress($this->historyForResident($resident));

        foreach ($rows as &$row) {
            $row['assessment'] = $this->assessment->displayContextForRecord($resident, $row['record']);
        }

        return $rows;
    }

    /**
     * @param  list<TimbangRecord>  $newestFirst
     * @return list<array{
     *     record: TimbangRecord,
     *     bmi: string|null,
     *     bmi_label: string,
     *     weight_change: float|null,
     *     weight_change_label: string,
     *     is_weight_baseline: bool,
     *     height_change: float|null,
     *     height_change_label: string,
     *     is_height_baseline: bool
     * }>
     */
    public function withDerivedProgress(array $newestFirst): array
    {
        $chronological = array_reverse($newestFirst);
        $previousWeight = null;
        $previousHeight = null;
        $byKey = [];

        foreach ($chronological as $row) {
            $weight = self::normalizedMeasurement($row->weight_kg);
            $height = self::normalizedMeasurement($row->height_cm);

            $weightChange = null;
            $isWeightBaseline = false;
            if ($weight !== null) {
                if ($previousWeight !== null) {
                    $weightChange = round((float) $weight - (float) $previousWeight, 2);
                } else {
                    $isWeightBaseline = true;
                }
                $previousWeight = $weight;
            }

            $heightChange = null;
            $isHeightBaseline = false;
            if ($height !== null) {
                if ($previousHeight !== null) {
                    $heightChange = round((float) $height - (float) $previousHeight, 2);
                } else {
                    $isHeightBaseline = true;
                }
                $previousHeight = $height;
            }

            $byKey[(int) $row->getKey()] = [
                'weight_change' => $weightChange,
                'weight_change_label' => $isWeightBaseline
                    ? '—'
                    : ($weightChange === null ? '—' : self::formatSignedDelta($weightChange, 'kg')),
                'is_weight_baseline' => $isWeightBaseline,
                'height_change' => $heightChange,
                'height_change_label' => $isHeightBaseline
                    ? '—'
                    : ($heightChange === null ? '—' : self::formatSignedDelta($heightChange, 'cm')),
                'is_height_baseline' => $isHeightBaseline,
            ];
        }

        $presented = [];
        foreach ($newestFirst as $row) {
            $progress = $byKey[(int) $row->getKey()] ?? [
                'weight_change' => null,
                'weight_change_label' => '—',
                'is_weight_baseline' => false,
                'height_change' => null,
                'height_change_label' => '—',
                'is_height_baseline' => false,
            ];
            $bmi = RiskAssessmentClinicalValues::calculateBmi($row->height_cm, $row->weight_kg);
            $presented[] = array_merge(['record' => $row], $progress, [
                'bmi' => $bmi,
                'bmi_label' => $bmi ?? '—',
            ]);
        }

        return $presented;
    }

    public static function formatSignedDelta(float $delta, string $unit): string
    {
        if (abs($delta) < 0.00001) {
            return '0 '.$unit;
        }

        $trimmed = rtrim(rtrim(number_format(abs($delta), 2, '.', ''), '0'), '.');
        if ($trimmed === '') {
            $trimmed = '0';
        }

        return ($delta > 0 ? '+' : '-').$trimmed.' '.$unit;
    }

    /**
     * Insert a new history row. Never updates an existing measurement.
     * Computed-assessment keys (weight_for_age, height_for_age, muac_status,
     * bmi_value, bmi_status, overall_nutritional_status) are trusted here
     * only because callers must have produced them via
     * NutritionAssessmentService::assess() — never from raw request input.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createForResident(Resident $resident, array $payload): TimbangRecord
    {
        HouseholdProfilingWriteGuard::rejectTimbangWrite();

        if ((int) $resident->getKey() <= 0) {
            abort(404, 'Resident was not found.');
        }

        return TimbangRecord::query()->create([
            'resident_id' => $resident->getKey(),
            'measurement_date' => $payload['measurement_date'],
            'weight_kg' => $payload['weight_kg'] ?? null,
            'height_cm' => $payload['height_cm'] ?? null,
            'muac_cm' => $payload['muac_cm'] ?? null,
            'muac_status' => $payload['muac_status'] ?? null,
            'weight_for_age' => $payload['weight_for_age'] ?? null,
            'height_for_age' => $payload['height_for_age'] ?? null,
            'weight_for_height' => $payload['weight_for_height'] ?? null,
            'bmi_value' => $payload['bmi_value'] ?? null,
            'bmi_status' => $payload['bmi_status'] ?? null,
            'overall_nutritional_status' => $payload['overall_nutritional_status'] ?? null,
            'remarks' => $payload['remarks'] ?? null,
        ]);
    }

    /**
     * Trusted physical sync. Copies only resident_id, measurement_date,
     * weight_kg, and height_cm. Does not store BMI, MUAC, or classifications.
     */
    public function createFromTrustedPhysical(
        Resident $resident,
        Carbon|string $measurementDate,
        mixed $weightKg,
        mixed $heightCm
    ): ?TimbangRecord {
        $weight = $this->nullableSyncedDecimal($weightKg);
        $height = $this->nullableSyncedDecimal($heightCm);
        if ($weight === null && $height === null) {
            return null;
        }

        $date = $measurementDate instanceof Carbon
            ? $measurementDate->toDateString()
            : Carbon::parse((string) $measurementDate)->toDateString();

        return $this->createForResident($resident, [
            'measurement_date' => $date,
            'weight_kg' => $weight,
            'height_cm' => $height,
            'muac_cm' => null,
            'weight_for_age' => null,
            'height_for_age' => null,
            'weight_for_height' => null,
            'remarks' => null,
        ]);
    }

    public function createFromRiskAssessmentPhysical(
        Resident $resident,
        Carbon|string $measurementDate,
        mixed $weightKg,
        mixed $heightCm
    ): ?TimbangRecord {
        return $this->createFromTrustedPhysical($resident, $measurementDate, $weightKg, $heightCm);
    }

    public function createFromMaternalPhysical(
        Resident $resident,
        Carbon|string $measurementDate,
        mixed $weightKg,
        mixed $heightCm
    ): ?TimbangRecord {
        return $this->createFromTrustedPhysical($resident, $measurementDate, $weightKg, $heightCm);
    }

    public static function physicalMeasurementsChanged(
        mixed $oldWeightKg,
        mixed $oldHeightCm,
        mixed $newWeightKg,
        mixed $newHeightCm
    ): bool {
        return self::normalizedMeasurement($oldWeightKg) !== self::normalizedMeasurement($newWeightKg)
            || self::normalizedMeasurement($oldHeightCm) !== self::normalizedMeasurement($newHeightCm);
    }

    public static function normalizedMeasurement(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return number_format((float) $trimmed, 2, '.', '');
    }

    /**
     * Raw, user-entered measurement fields only. weight_for_age,
     * height_for_age, muac_status, bmi_value, bmi_status, and
     * overall_nutritional_status are never taken from request input — they
     * are added afterward from NutritionAssessmentService::assess().
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeValidated(array $validated): array
    {
        return [
            'measurement_date' => Carbon::parse((string) $validated['measurement_date'])->format('Y-m-d'),
            'weight_kg' => $this->nullablePositiveDecimal($validated['weight_kg'] ?? null, 'weight_kg'),
            'height_cm' => $this->nullablePositiveDecimal($validated['height_cm'] ?? null, 'height_cm'),
            'muac_cm' => $this->nullablePositiveDecimal($validated['muac_cm'] ?? null, 'muac_cm'),
            'remarks' => $this->nullableString($validated['remarks'] ?? null),
        ];
    }

    private function nullablePositiveDecimal(mixed $value, string $errorKey): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (! is_numeric($trimmed) || (float) $trimmed <= 0) {
            throw ValidationException::withMessages([
                $errorKey => 'Measurement values must be greater than zero.',
            ]);
        }

        return $trimmed;
    }

    private function nullableSyncedDecimal(mixed $value): ?string
    {
        $normalized = self::normalizedMeasurement($value);
        if ($normalized === null || (float) $normalized <= 0) {
            return null;
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function formatDecimal(mixed $value, int $scale): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $formatted = number_format((float) $value, $scale, '.', '');
        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
