<?php

namespace App\Support;

use App\Models\Resident;
use App\Models\RiskAssessment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * DB-12 Phase 2 — Risk Assessment persistence for Household Profiling members.
 */
final class RiskAssessmentService
{
    public const INELIGIBLE_MESSAGE = 'Available for residents 19 years old and above.';

    /**
     * @param  Resident|array<string, mixed>|null  $subject
     */
    public static function isEligibleForRiskAssessment(Resident|array|null $subject, ?Carbon $on = null): bool
    {
        if ($subject instanceof Resident) {
            return HealthRecordsRiskAssessment::isEligibleResident(
                self::memberPayloadFromResident($subject),
                $on
            );
        }

        if (is_array($subject)) {
            return HealthRecordsRiskAssessment::isEligibleResident($subject, $on);
        }

        return false;
    }

    public static function rejectIfIneligible(Resident $resident): void
    {
        if (self::isEligibleForRiskAssessment($resident)) {
            return;
        }

        throw ValidationException::withMessages([
            'assessment' => self::INELIGIBLE_MESSAGE,
        ]);
    }

    /**
     * @return array{birthday: string}
     */
    public static function memberPayloadFromResident(Resident $resident): array
    {
        $birthday = $resident->birthday;
        $iso = $birthday instanceof Carbon
            ? $birthday->format('Y-m-d')
            : trim((string) $birthday);

        return ['birthday' => $iso];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return RiskAssessment|array<string, mixed>
     */
    public function createForResident(Resident $resident, array $payload): RiskAssessment|array
    {
        HouseholdProfilingWriteGuard::rejectRiskAssessmentWrite();
        self::rejectIfIneligible($resident);

        if (RiskAssessmentErdMode::isActive()) {
            return $this->createForResidentErd($resident, $payload);
        }

        $attributes = $this->normalizeStorePayload($payload);
        $attributes['conducted_at'] = Carbon::today()->toDateString();

        return DB::transaction(function () use ($resident, $attributes): RiskAssessment {
            /** @var RiskAssessment $assessment */
            $assessment = $resident->riskAssessments()->make($attributes);
            // Temporary unique value (≤16 chars) until primary key is known.
            $assessment->assessment_no = 'T'.strtoupper(bin2hex(random_bytes(7)));
            $assessment->save();

            $assessment->assessment_no = sprintf('RA-%03d', $assessment->id);
            $assessment->save();

            $assessment = $assessment->refresh();
            $this->syncTimbangFromPhysical(
                $resident,
                $assessment->created_at instanceof Carbon
                    ? $assessment->created_at->toDateString()
                    : Carbon::today()->toDateString(),
                $assessment->weight_kg,
                $assessment->height_cm,
            );

            return $assessment;
        });
    }

    /**
     * @param  array<string, mixed>  $sectionPayload
     */
    public function updateSectionForResident(
        Resident $resident,
        string $assessmentNo,
        string $section,
        array $sectionPayload
    ): void {
        HouseholdProfilingWriteGuard::rejectRiskAssessmentWrite();
        self::rejectIfIneligible($resident);

        if (RiskAssessmentErdMode::isActive()) {
            $this->updateSectionForResidentErd($resident, $assessmentNo, $section, $sectionPayload);

            return;
        }

        $assessment = $this->findForResident($resident, $assessmentNo);
        if ($assessment === null) {
            abort(404);
        }

        $this->updateSection($assessment, $section, $sectionPayload);
    }

    /**
     * @param  array<string, mixed>  $sectionPayload
     */
    public function updateSection(
        RiskAssessment $assessment,
        string $section,
        array $sectionPayload
    ): RiskAssessment {
        HouseholdProfilingWriteGuard::rejectRiskAssessmentWrite();

        $owner = $assessment->resident;
        if (! $owner instanceof Resident) {
            $owner = Resident::query()->find($assessment->resident_id);
        }
        if ($owner instanceof Resident) {
            self::rejectIfIneligible($owner);
        } else {
            throw ValidationException::withMessages([
                'assessment' => self::INELIGIBLE_MESSAGE,
            ]);
        }

        $patch = DemoRiskAssessment::sectionPayloadToPatch($section, $sectionPayload);
        if ($patch === null) {
            return $assessment;
        }

        // Physical derivation only for the physical section. Non-physical patches
        // must not inject null BMI/BP fields that would wipe existing values.
        if ($section === DemoRiskAssessment::SECTION_PHYSICAL) {
            $patch = $this->normalizePhysicalScalars($patch);
        } elseif ($section === DemoRiskAssessment::SECTION_LIFESTYLE) {
            $patch = $this->normalizeLifestyleScalars($patch);
        }

        if ($section !== DemoRiskAssessment::SECTION_PHYSICAL) {
            $assessment->fill($patch);
            $assessment->save();

            return $assessment->refresh();
        }

        return DB::transaction(function () use ($assessment, $patch, $owner): RiskAssessment {
            $oldWeight = $assessment->weight_kg;
            $oldHeight = $assessment->height_cm;
            $assessment->fill($patch);
            $assessment->save();
            $assessment = $assessment->refresh();

            $this->syncTimbangFromPhysical(
                $owner,
                Carbon::today()->toDateString(),
                $assessment->weight_kg,
                $assessment->height_cm,
                $oldWeight,
                $oldHeight,
                true,
            );

            return $assessment;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyRowsForResident(Resident $resident): array
    {
        if (Schema::hasTable('risk_assessments')) {
            return $resident->riskAssessments()
                ->orderByDesc('conducted_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (RiskAssessment $row): array => $this->toPresentation($row))
                ->all();
        }

        if (Schema::hasTable('risk_assessment')) {
            return $this->historyRowsFromErd($resident);
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyRowsFromErd(Resident $resident): array
    {
        $rows = DB::table('risk_assessment')
            ->where('resident_id', $resident->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('risk_assessment_id')
            ->get();

        return $rows->map(function (object $row): array {
            $row = AtRestRecord::openRow('risk_assessment', $row);
            $systolic = $row->systolic_blood_pressure ?? null;
            $diastolic = $row->diastolic_blood_pressure ?? null;
            $bpReading = ($systolic !== null && $diastolic !== null)
                ? ((string) $systolic).'/'.((string) $diastolic)
                : '';

            $bmiDisplay = $this->erdBmiDisplay($row);
            $bmiStatus = RiskAssessmentClinicalValues::calculateBmiStatus(
                $row->height_cm ?? null,
                $row->weight_kg ?? null
            );

            $conductedAt = '';
            if (! empty($row->created_at)) {
                try {
                    $conductedAt = Carbon::parse((string) $row->created_at)->toDateString();
                } catch (\Throwable) {
                    $conductedAt = '';
                }
            }

            return [
                'id' => sprintf('RA-%03d', (int) ($row->risk_assessment_id ?? 0)),
                'conducted_at' => $conductedAt,
                'bp_reading' => $bpReading,
                'bmi_label' => $bmiDisplay,
                'bmi' => $bmiDisplay,
                'bmi_status' => $bmiStatus ?? '',
            ];
        })->all();
    }

    public function findForResident(Resident $resident, string $assessmentNo): ?RiskAssessment
    {
        if (! Schema::hasTable('risk_assessments')) {
            return null;
        }

        $id = strtoupper(trim($assessmentNo));

        return $resident->riskAssessments()
            ->where('assessment_no', $id)
            ->first();
    }

    /**
     * Detail/show contract for Blade. Reads authoritative ERD when legacy table absent.
     *
     * @return array<string, mixed>|null
     */
    public function findPresentationForResident(Resident $resident, string $assessmentNo): ?array
    {
        if (Schema::hasTable('risk_assessments')) {
            $model = $this->findForResident($resident, $assessmentNo);

            return $model !== null ? $this->toPresentation($model) : null;
        }

        if (Schema::hasTable('risk_assessment')) {
            return $this->findPresentationFromErd($resident, $assessmentNo);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPresentationFromErd(Resident $resident, string $assessmentNo): ?array
    {
        $assessmentId = $this->parseRiskAssessmentId($assessmentNo);
        if ($assessmentId === null) {
            return null;
        }

        $row = DB::table('risk_assessment')
            ->where('risk_assessment_id', $assessmentId)
            ->where('resident_id', $resident->getKey())
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->presentationFromErdRow(AtRestRecord::openRow('risk_assessment', $row));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentationFromErdRow(object $row): array
    {
        $systolic = $row->systolic_blood_pressure ?? null;
        $diastolic = $row->diastolic_blood_pressure ?? null;
        $bpReading = ($systolic !== null && $diastolic !== null)
            ? ((string) $systolic).'/'.((string) $diastolic)
            : '';

        $bmiDisplay = $this->erdBmiDisplay($row);
        $bmiLabel = $bmiDisplay;
        $bmiStatus = RiskAssessmentClinicalValues::calculateBmiStatus(
            $row->height_cm ?? null,
            $row->weight_kg ?? null
        ) ?? '';

        $conductedAt = '';
        if (! empty($row->created_at)) {
            try {
                $conductedAt = Carbon::parse((string) $row->created_at)->toDateString();
            } catch (\Throwable) {
                $conductedAt = '';
            }
        }

        $dietary = RiskAssessmentErdMode::usesSingleSelectDietary()
            ? (RiskAssessmentErdMode::dietaryLabelToKey((string) ($row->dietary_habits ?? '')) ?? '')
            : $this->erdDietaryPresentation($row);

        return [
            'id' => sprintf('RA-%03d', (int) ($row->risk_assessment_id ?? 0)),
            'conducted_at' => $conductedAt,
            'recorded_date_label' => RiskAssessmentErdMode::recordedDateLabel(),
            'bp_reading' => $bpReading,
            'bmi_label' => $bmiLabel,
            'red_flags' => $this->erdHistoryKeysFromChild((int) ($row->risk_assessment_id ?? 0), 'red_flags'),
            'past_medical' => $this->erdHistoryKeysFromChild((int) ($row->risk_assessment_id ?? 0), 'past_medical'),
            'family_history' => $this->erdHistoryKeysFromChild((int) ($row->risk_assessment_id ?? 0), 'family_history'),
            'tobacco' => RiskAssessmentErdMode::tobaccoLabelToKey((string) ($row->tobacco_vape_usage ?? '')) ?? '',
            'alcohol' => RiskAssessmentErdMode::alcoholLabelToKey((string) ($row->alcohol_intake ?? '')) ?? '',
            'dietary' => $dietary,
            'physical_activity' => RiskAssessmentErdMode::physicalActivityLabelToKey((string) ($row->physical_activity ?? '')) ?? '',
            'height_cm' => $this->scalarString($row->height_cm ?? null),
            'weight_kg' => $this->scalarString($row->weight_kg ?? null),
            'bmi' => $bmiDisplay,
            'bmi_status' => $bmiStatus,
            'waist_cm' => $this->scalarString($row->waist_circum_cm ?? null),
            'systolic' => $this->scalarString($systolic),
            'diastolic' => $this->scalarString($diastolic),
            'bp_status' => RiskAssessmentErdMode::bloodPressureStatusLabel($systolic, $diastolic) ?? '',
            'visual_no_screening' => false,
            'visual_blurred' => false,
            'visual_blurred_note' => '',
        ];
    }

    private function parseRiskAssessmentId(string $assessmentNo): ?int
    {
        $token = strtoupper(trim($assessmentNo));
        if (preg_match('/^RA-(\d+)$/i', $token, $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return $id > 0 ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPresentation(RiskAssessment $assessment): array
    {
        $bmi = $assessment->bmi;
        $bmiDisplay = $this->bmiDisplayString($bmi);
        if ($assessment->bmi_label !== null && trim((string) $assessment->bmi_label) !== '') {
            $bmiDisplay = trim((string) $assessment->bmi_label);
        }

        return [
            'id' => (string) $assessment->assessment_no,
            'conducted_at' => $assessment->conducted_at?->toDateString() ?? '',
            'bp_reading' => (string) ($assessment->bp_reading ?? ''),
            'bmi_label' => $bmiDisplay,
            'red_flags' => $assessment->red_flags ?? [],
            'past_medical' => $assessment->past_medical ?? [],
            'family_history' => $assessment->family_history ?? [],
            'tobacco' => (string) ($assessment->tobacco ?? ''),
            'alcohol' => (string) ($assessment->alcohol ?? ''),
            'dietary' => $this->scalarDietary($assessment->dietary),
            'physical_activity' => (string) ($assessment->physical_activity ?? ''),
            'height_cm' => $this->scalarString($assessment->height_cm),
            'weight_kg' => $this->scalarString($assessment->weight_kg),
            'bmi' => $this->bmiDisplayString($assessment->bmi),
            'bmi_status' => RiskAssessmentClinicalValues::calculateBmiStatus(
                $assessment->height_cm,
                $assessment->weight_kg
            ) ?? '',
            'waist_cm' => $this->scalarString($assessment->waist_cm),
            'systolic' => $this->scalarString($assessment->systolic),
            'diastolic' => $this->scalarString($assessment->diastolic),
            'bp_status' => (string) ($assessment->bp_status ?? ''),
            'visual_no_screening' => (bool) $assessment->visual_no_screening,
            'visual_blurred' => (bool) $assessment->visual_blurred,
            'visual_blurred_note' => (string) ($assessment->visual_blurred_note ?? ''),
        ];
    }

    private function erdBmiDisplay(object $row): string
    {
        return $this->bmiDisplayString(
            RiskAssessmentClinicalValues::calculateBmi($row->height_cm ?? null, $row->weight_kg ?? null)
        );
    }

    private function bmiDisplayString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! is_numeric($value)) {
            return trim((string) $value);
        }

        return number_format(round((float) $value, 1), 1, '.', '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeStorePayload(array $payload): array
    {
        $redFlags = DemoRiskAssessment::applyNoneExclusive(
            is_array($payload['red_flags'] ?? null) ? $payload['red_flags'] : []
        );
        $pastMedical = DemoRiskAssessment::applyNoneExclusive(
            is_array($payload['past_medical'] ?? null) ? $payload['past_medical'] : []
        );
        $familyHistory = DemoRiskAssessment::applyNoneExclusive(
            is_array($payload['family_history'] ?? null) ? $payload['family_history'] : []
        );
        $dietary = $this->nullableYesNo($payload['dietary'] ?? null);

        $heightCm = $this->nullableDecimal($payload['height_cm'] ?? null);
        $weightKg = $this->nullableDecimal($payload['weight_kg'] ?? null);
        $systolic = $this->nullableTrimmedString($payload['systolic'] ?? null);
        $diastolic = $this->nullableTrimmedString($payload['diastolic'] ?? null);
        $systolicInt = $this->nullableInt($systolic);
        $diastolicInt = $this->nullableInt($diastolic);

        // Authoritative derived values — never trust browser-supplied bmi / bp_status.
        $bmi = RiskAssessmentClinicalValues::calculateBmi($heightCm, $weightKg);
        $bpStatus = RiskAssessmentClinicalValues::calculateBpStatus($systolicInt, $diastolicInt);

        $attrs = [
            'red_flags' => $redFlags !== [] ? $redFlags : null,
            'past_medical' => $pastMedical !== [] ? $pastMedical : null,
            'family_history' => $familyHistory !== [] ? $familyHistory : null,
            'dietary' => $dietary,
            'tobacco' => $this->nullableTrimmedString($payload['tobacco'] ?? null),
            'alcohol' => $this->nullableTrimmedString($payload['alcohol'] ?? null),
            'physical_activity' => $this->nullableTrimmedString($payload['physical_activity'] ?? null),
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            'bmi' => $bmi,
            'waist_cm' => $this->nullableDecimal($payload['waist_cm'] ?? null),
            'systolic' => $systolicInt,
            'diastolic' => $diastolicInt,
            'bp_status' => $bpStatus,
            'bmi_label' => null,
            'visual_no_screening' => ! empty($payload['visual_no_screening']),
            'visual_blurred' => ! empty($payload['visual_blurred']),
            'visual_blurred_note' => $this->nullableTrimmedString($payload['visual_blurred_note'] ?? null),
        ];

        if ($systolicInt !== null && $diastolicInt !== null) {
            $attrs['bp_reading'] = $systolicInt.'/'.$diastolicInt;
        } else {
            $attrs['bp_reading'] = null;
        }

        return $attrs;
    }

    /**
     * Normalize lifestyle scalars without touching physical/clinical columns.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function normalizeLifestyleScalars(array $patch): array
    {
        foreach (['tobacco', 'alcohol', 'physical_activity', 'dietary'] as $field) {
            if (array_key_exists($field, $patch) && $patch[$field] === '') {
                $patch[$field] = null;
            }
        }

        return $patch;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function normalizePhysicalScalars(array $patch): array
    {
        // Strip client-supplied derived fields before applying measurements.
        unset($patch['bmi'], $patch['bp_status']);

        if (array_key_exists('height_cm', $patch)) {
            $patch['height_cm'] = $this->nullableDecimal($patch['height_cm']);
        }
        if (array_key_exists('weight_kg', $patch)) {
            $patch['weight_kg'] = $this->nullableDecimal($patch['weight_kg']);
        }
        if (array_key_exists('waist_cm', $patch)) {
            $patch['waist_cm'] = $this->nullableDecimal($patch['waist_cm']);
        }
        if (array_key_exists('systolic', $patch)) {
            $patch['systolic'] = $this->nullableInt($patch['systolic']);
        }
        if (array_key_exists('diastolic', $patch)) {
            $patch['diastolic'] = $this->nullableInt($patch['diastolic']);
        }
        if (array_key_exists('visual_blurred_note', $patch) && $patch['visual_blurred_note'] === '') {
            $patch['visual_blurred_note'] = null;
        }

        $height = $patch['height_cm'] ?? null;
        $weight = $patch['weight_kg'] ?? null;
        $systolic = $patch['systolic'] ?? null;
        $diastolic = $patch['diastolic'] ?? null;

        $patch['bmi'] = RiskAssessmentClinicalValues::calculateBmi($height, $weight);
        $patch['bp_status'] = RiskAssessmentClinicalValues::calculateBpStatus($systolic, $diastolic);

        if ($systolic !== null && $diastolic !== null) {
            $patch['bp_reading'] = $systolic.'/'.$diastolic;
        } else {
            $patch['bp_reading'] = null;
        }

        return $patch;
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $scalar = trim((string) $value);

        return $scalar === '' ? null : $scalar;
    }

    private function nullableYesNo(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        $scalar = $this->nullableTrimmedString($value);
        if ($scalar === null) {
            return null;
        }

        return in_array($scalar, ['yes', 'no'], true) ? $scalar : null;
    }

    private function scalarDietary(mixed $value): string
    {
        return $this->nullableYesNo($value) ?? '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertErdWritable(array $payload): void
    {
        foreach ([
            'tobacco_vape_usage',
            'alcohol_intake',
            'physical_activity',
            'dietary_habits',
        ] as $column) {
            if (! array_key_exists($column, $payload) || $payload[$column] === null) {
                continue;
            }

            $value = trim((string) $payload[$column]);
            if ($value === '') {
                continue;
            }

            if (! RiskAssessmentErdMode::isValidEnumValue($column, $value)) {
                throw ValidationException::withMessages([
                    'assessment' => 'Risk assessment values are not valid for the current database configuration.',
                ]);
            }
        }
    }

    private function nullableDecimal(mixed $value): ?string
    {
        $scalar = $this->nullableTrimmedString($value);
        if ($scalar === null) {
            return null;
        }

        return is_numeric($scalar) ? $scalar : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        $scalar = $this->nullableTrimmedString($value);
        if ($scalar === null || ! is_numeric($scalar)) {
            return null;
        }

        return (int) $scalar;
    }

    private function scalarString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_float($value) || is_int($value)) {
            return rtrim(rtrim(sprintf('%.2f', (float) $value), '0'), '.') ?: '0';
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createForResidentErd(Resident $resident, array $payload): array
    {
        return DB::transaction(function () use ($resident, $payload): array {
            $userId = RiskAssessmentStaffResolver::resolveUserIdOrFail();
            $row = $this->erdInsertPayloadFromStore($payload, $resident, $userId);
            $row = RiskAssessmentErdMode::filterWritablePayload($row);
            $this->assertErdWritable($row);

            $createdAt = now();
            $assessmentId = DB::table('risk_assessment')->insertGetId(array_merge(AtRestRecord::sealRow('risk_assessment', $row), [
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]));

            $this->syncErdHistoryChildren((int) $assessmentId, $payload);
            $this->syncTimbangFromPhysical(
                $resident,
                $createdAt->toDateString(),
                $row['weight_kg'] ?? null,
                $row['height_cm'] ?? null,
            );

            $presentation = $this->findPresentationFromErd($resident, sprintf('RA-%03d', $assessmentId));
            if ($presentation === null) {
                abort(500, 'Risk assessment record could not be loaded after save.');
            }

            return $presentation;
        });
    }

    /**
     * @param  array<string, mixed>  $sectionPayload
     */
    private function updateSectionForResidentErd(
        Resident $resident,
        string $assessmentNo,
        string $section,
        array $sectionPayload
    ): void {
        if (! RiskAssessmentErdMode::sectionSupported($section)) {
            throw ValidationException::withMessages([
                'section' => RiskAssessmentErdMode::UNSUPPORTED_SECTION_MESSAGE,
            ]);
        }

        $assessmentId = $this->parseRiskAssessmentId($assessmentNo);
        if ($assessmentId === null) {
            abort(404);
        }

        $owned = DB::table('risk_assessment')
            ->where('risk_assessment_id', $assessmentId)
            ->where('resident_id', $resident->getKey())
            ->exists();

        if (! $owned) {
            abort(404);
        }

        $group = match ($section) {
            DemoRiskAssessment::SECTION_RED_FLAGS => 'red_flags',
            DemoRiskAssessment::SECTION_PAST_MEDICAL => 'past_medical',
            DemoRiskAssessment::SECTION_FAMILY_HISTORY => 'family_history',
            default => null,
        };

        if ($group !== null) {
            DB::transaction(function () use ($assessmentId, $group, $sectionPayload): void {
                $this->syncErdHistoryChild(
                    $assessmentId,
                    $group,
                    is_array($sectionPayload[$group] ?? null) ? $sectionPayload[$group] : []
                );
                DB::table('risk_assessment')
                    ->where('risk_assessment_id', $assessmentId)
                    ->update(['updated_at' => now()]);
            });

            return;
        }

        $patch = match ($section) {
            DemoRiskAssessment::SECTION_LIFESTYLE => $this->erdLifestylePatch($sectionPayload),
            DemoRiskAssessment::SECTION_PHYSICAL => $this->erdPhysicalPatch($sectionPayload),
            default => throw ValidationException::withMessages([
                'section' => RiskAssessmentErdMode::UNSUPPORTED_SECTION_MESSAGE,
            ]),
        };

        if ($section === DemoRiskAssessment::SECTION_LIFESTYLE) {
            $this->assertErdWritable($patch);
            $updated = DB::table('risk_assessment')
                ->where('risk_assessment_id', $assessmentId)
                ->where('resident_id', $resident->getKey())
                ->update(array_merge(AtRestRecord::sealRow('risk_assessment', $patch), [
                    'updated_at' => now(),
                ]));

            if ($updated === 0) {
                abort(404);
            }

            return;
        }

        DB::transaction(function () use ($resident, $assessmentId, $patch): void {
            $current = DB::table('risk_assessment')
                ->where('risk_assessment_id', $assessmentId)
                ->where('resident_id', $resident->getKey())
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                abort(404);
            }
            $current = AtRestRecord::openRow('risk_assessment', $current);

            $updated = DB::table('risk_assessment')
                ->where('risk_assessment_id', $assessmentId)
                ->where('resident_id', $resident->getKey())
                ->update(array_merge(AtRestRecord::sealRow('risk_assessment', $patch), [
                    'updated_at' => now(),
                ]));

            if ($updated === 0) {
                abort(404);
            }

            $this->syncTimbangFromPhysical(
                $resident,
                Carbon::today()->toDateString(),
                $patch['weight_kg'] ?? null,
                $patch['height_cm'] ?? null,
                $current->weight_kg ?? null,
                $current->height_cm ?? null,
                true,
            );
        });
    }

    /**
     * Option B: copy RA height/weight into timbang_records only when a
     * measurement is present (create) or actually changed (physical update).
     */
    private function syncTimbangFromPhysical(
        Resident $resident,
        string $measurementDate,
        mixed $weightKg,
        mixed $heightCm,
        mixed $oldWeightKg = null,
        mixed $oldHeightCm = null,
        bool $comparePrevious = false
    ): void {
        if ($comparePrevious && ! TimbangRecordService::physicalMeasurementsChanged(
            $oldWeightKg,
            $oldHeightCm,
            $weightKg,
            $heightCm
        )) {
            return;
        }

        (new TimbangRecordService)->createFromRiskAssessmentPhysical(
            $resident,
            $measurementDate,
            $weightKg,
            $heightCm
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function erdInsertPayloadFromStore(array $payload, Resident $resident, int $userId): array
    {
        $row = [
            'resident_id' => $resident->getKey(),
            'user_id' => $userId,
        ];

        $row = array_merge($row, $this->erdLifestylePatch($payload));
        $row = array_merge($row, $this->erdPhysicalPatch($payload));

        return $row;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function erdLifestylePatch(array $payload): array
    {
        $patch = [];

        foreach ([
            'tobacco' => ['column' => 'tobacco_vape_usage', 'mapper' => [RiskAssessmentErdMode::class, 'tobaccoKeyToLabel']],
            'alcohol' => ['column' => 'alcohol_intake', 'mapper' => [RiskAssessmentErdMode::class, 'alcoholKeyToLabel']],
            'physical_activity' => ['column' => 'physical_activity', 'mapper' => [RiskAssessmentErdMode::class, 'physicalActivityKeyToLabel']],
        ] as $uiKey => $config) {
            $key = trim((string) ($payload[$uiKey] ?? ''));
            $patch[$config['column']] = $key === '' ? null : ($config['mapper'])($key);
        }

        $dietaryRaw = $payload['dietary'] ?? '';
        $dietaryKey = is_array($dietaryRaw)
            ? trim((string) ($dietaryRaw[0] ?? ''))
            : trim((string) $dietaryRaw);
        $patch['dietary_habits'] = $dietaryKey === ''
            ? null
            : RiskAssessmentErdMode::dietaryKeyToLabel($dietaryKey);

        return $patch;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function erdPhysicalPatch(array $payload): array
    {
        $patch = [
            'height_cm' => $this->nullableDecimal($payload['height_cm'] ?? null),
            'weight_kg' => $this->nullableDecimal($payload['weight_kg'] ?? null),
            'waist_circum_cm' => $this->nullableDecimal($payload['waist_cm'] ?? null),
            'systolic_blood_pressure' => $this->nullableInt($payload['systolic'] ?? null),
            'diastolic_blood_pressure' => $this->nullableInt($payload['diastolic'] ?? null),
        ];

        if (RiskAssessmentErdMode::writesBloodPressureStatus()) {
            $patch['blood_pressure_status'] = RiskAssessmentErdMode::bloodPressureStatusLabel(
                $patch['systolic_blood_pressure'],
                $patch['diastolic_blood_pressure']
            );
        }

        return $patch;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncErdHistoryChildren(int $assessmentId, array $payload): void
    {
        foreach (['red_flags', 'past_medical', 'family_history'] as $group) {
            $this->syncErdHistoryChild(
                $assessmentId,
                $group,
                is_array($payload[$group] ?? null) ? $payload[$group] : []
            );
        }
    }

    /**
     * @param  list<mixed>  $keys
     */
    private function syncErdHistoryChild(int $assessmentId, string $group, array $keys): void
    {
        $table = RiskAssessmentErdMode::childTableForGroup($group);
        $map = RiskAssessmentErdMode::uiKeyToColumnForGroup($group);
        if ($table === null || $map === []) {
            return;
        }

        $selected = DemoRiskAssessment::applyNoneExclusive(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $keys
        ));
        $selected = array_values(array_filter(
            $selected,
            static fn (string $key): bool => array_key_exists($key, $map)
        ));

        if (! Schema::hasTable($table)) {
            if ($selected !== []) {
                throw ValidationException::withMessages([
                    $group => RiskAssessmentErdMode::UNSUPPORTED_SECTION_MESSAGE,
                ]);
            }

            return;
        }

        $flags = [];
        foreach ($map as $uiKey => $column) {
            if (! Schema::hasColumn($table, $column)) {
                if (in_array($uiKey, $selected, true)) {
                    throw ValidationException::withMessages([
                        $group => RiskAssessmentErdMode::UNSUPPORTED_SECTION_MESSAGE,
                    ]);
                }

                continue;
            }

            $flags[$column] = in_array($uiKey, $selected, true) ? 1 : 0;
        }

        $existing = DB::table($table)->where('risk_assessment_id', $assessmentId)->first();

        if ($selected === []) {
            if ($existing !== null) {
                DB::table($table)->where('risk_assessment_id', $assessmentId)->delete();
            }

            return;
        }

        $flags = AtRestRecord::sealRow($table, $flags);
        $now = now();
        if ($existing !== null) {
            DB::table($table)
                ->where('risk_assessment_id', $assessmentId)
                ->update(array_merge($flags, ['updated_at' => $now]));

            return;
        }

        DB::table($table)->insert(array_merge($flags, [
            'risk_assessment_id' => $assessmentId,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /**
     * @return list<string>
     */
    private function erdHistoryKeysFromChild(int $assessmentId, string $group): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $table = RiskAssessmentErdMode::childTableForGroup($group);
        $map = RiskAssessmentErdMode::uiKeyToColumnForGroup($group);
        if ($table === null || $map === [] || ! Schema::hasTable($table)) {
            return [];
        }

        $row = AtRestRecord::openRow($table, DB::table($table)->where('risk_assessment_id', $assessmentId)->first());
        if ($row === null) {
            return [];
        }

        $selected = [];
        foreach ($map as $uiKey => $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $value = $row->{$column} ?? 0;
            if ($value === true || $value === 1 || $value === '1') {
                $selected[] = $uiKey;
            }
        }

        return DemoRiskAssessment::applyNoneExclusive($selected);
    }

    /**
     * @return string|list<string>
     */
    private function erdDietaryPresentation(object $row): string|array
    {
        if (! isset($row->dietary_habits) || trim((string) $row->dietary_habits) === '') {
            return RiskAssessmentErdMode::usesSingleSelectDietary() ? '' : [];
        }

        $key = RiskAssessmentErdMode::dietaryLabelToKey((string) $row->dietary_habits) ?? '';

        return RiskAssessmentErdMode::usesSingleSelectDietary() ? $key : [$key];
    }
}
