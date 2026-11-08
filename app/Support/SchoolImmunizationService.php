<?php

namespace App\Support;

use App\Models\Resident;
use App\Models\SchoolImmunization;
use App\Models\SchoolImmunizationDose;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DB-09 Phase 2/3/4 — School-Based Immunization read/write for persisted residents.
 *
 * Canonical identity is residents.id.
 *
 * Phase 4 derivation rule (one-way):
 *   DATE EXISTS → corresponding Vaccines Type checkbox key is selected.
 * A manual checkbox alone does NOT invent a date.
 * Browser clear-date unchecks the mapped box so the key is omitted on submit;
 * empty date fields alone do not strip a manually posted checkbox key.
 */
final class SchoolImmunizationService
{
    public const INELIGIBLE_TITLE = 'Not eligible for School-Based Immunization.';

    public const INELIGIBLE_DETAIL = 'This resident is still within the Child Care age range and cannot yet record school-based immunization entries.';

    public const INELIGIBLE_UNKNOWN_DETAIL = 'School-based immunization cannot be recorded because the date of birth is missing or invalid.';

    /**
     * Read contract for Phase 3/4 form hydration. Does not require DB rows when empty.
     *
     * @return array{
     *     persisted: bool,
     *     selected_vaccine_types: list<string>,
     *     vaccines: array<string, array<string, string>>
     * }
     */
    public function forResident(Resident $resident): array
    {
        if ((int) $resident->id <= 0) {
            abort(404, 'Resident was not found.');
        }

        if (SchoolImmunizationErdMode::isActive()) {
            return $this->toReadContractFromErd((int) $resident->getKey());
        }

        if (! SchoolImmunizationErdMode::usesLaravelSchema()) {
            return $this->emptyState();
        }

        $record = $resident->schoolImmunization()
            ->with('doses')
            ->first();

        if ($record === null) {
            return $this->emptyState();
        }

        return $this->toReadContract($record);
    }

    /**
     * Atomic upsert of SBI header + dose slots for a persisted resident.
     *
     * Dose-row policy (DB-08 precedent): only slots present in the submitted
     * vaccines payload are upserted. Clearing a date requires resubmitting that
     * slot with an empty/null value. Unsubmitted slots are left unchanged.
     *
     * selected_vaccine_types always includes keys for non-null dated slots.
     * Cleared dates rely on the browser omitting the checkbox key on submit.
     *
     * @param  array{
     *     vaccines?: array<string, array<string, mixed>>,
     *     vaccine_types?: list<mixed>|null
     * }  $payload
     */
    public function saveForResident(Resident $resident, array $payload): ?SchoolImmunization
    {
        HouseholdProfilingWriteGuard::rejectSchoolImmunizationWrite();
        self::rejectIfIneligibleForSchoolImmunization($resident);

        if ((int) $resident->id <= 0) {
            abort(404, 'Resident was not found.');
        }

        if (SchoolImmunizationErdMode::isActive()) {
            $this->saveErdForResident($resident, $payload);

            return null;
        }

        $selectedTypes = self::normalizeSelectedVaccineTypes($payload['vaccine_types'] ?? []);
        $doseSlots = $this->normalizeSubmittedDoseSlots($payload['vaccines'] ?? []);
        $selectedTypes = self::applySubmittedDateSelectionRules($selectedTypes, $doseSlots);

        try {
            return DB::transaction(function () use ($resident, $selectedTypes, $doseSlots): SchoolImmunization {
                $record = SchoolImmunization::query()->updateOrCreate(
                    ['resident_id' => $resident->id],
                    [
                        'selected_vaccine_types' => $selectedTypes,
                    ]
                );

                foreach ($doseSlots as $slot) {
                    $this->upsertDoseSlot(
                        $record,
                        $slot['slot_group'],
                        $slot['slot_key'],
                        $slot['date_given'],
                    );
                }

                $record = $record->fresh(['doses']);
                $finalTypes = self::mergeDateDerivedSelections(
                    self::normalizeSelectedVaccineTypes($record->selected_vaccine_types ?? []),
                    $record->doses
                );

                if ($finalTypes !== self::normalizeSelectedVaccineTypes($record->selected_vaccine_types ?? [])) {
                    $record->selected_vaccine_types = $finalTypes;
                    $record->save();
                }

                return $record->fresh(['doses']);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateDoseViolation($e)) {
                throw ValidationException::withMessages([
                    'vaccines' => 'A duplicate school-based immunization dose slot was submitted.',
                ]);
            }

            throw $e;
        }
    }

    /**
     * @param  list<mixed>|null  $input
     * @return list<string>
     */
    public static function normalizeSelectedVaccineTypes(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $allowed = array_fill_keys(SchoolImmunization::SELECTABLE_TYPE_KEYS, true);
        $selected = [];

        foreach ($input as $value) {
            $key = strtolower(trim((string) $value));
            if ($key === '' || ! isset($allowed[$key])) {
                continue;
            }

            $selected[$key] = true;
        }

        return self::orderedTypeKeys($selected);
    }

    /**
     * Apply Phase 4 rules for slots present in this request payload.
     *
     * Non-empty dates force the corresponding checkbox key on.
     * Empty/cleared dates do NOT remove a manually submitted checkbox key
     * (the frozen form always posts blank date fields). Browser clear-date
     * uncheck is handled in JS so the key is omitted from vaccine_types.
     *
     * @param  list<string>  $selectedTypes
     * @param  list<array{slot_group: string, slot_key: string, date_given: ?string}>  $doseSlots
     * @return list<string>
     */
    public static function applySubmittedDateSelectionRules(array $selectedTypes, array $doseSlots): array
    {
        $selected = array_fill_keys($selectedTypes, true);

        foreach ($doseSlots as $slot) {
            $typeKey = SchoolImmunization::typeKeyForSlot($slot['slot_group'], $slot['slot_key']);
            if ($typeKey === null) {
                continue;
            }

            if ($slot['date_given'] !== null && $slot['date_given'] !== '') {
                $selected[$typeKey] = true;
            }
        }

        return self::orderedTypeKeys($selected);
    }

    /**
     * Ensure every non-null dated dose contributes its checkbox key.
     *
     * @param  list<string>  $selectedTypes
     * @param  iterable<int, SchoolImmunizationDose|array{slot_group?: string, slot_key?: string, date_given?: mixed}>  $doses
     * @return list<string>
     */
    public static function mergeDateDerivedSelections(array $selectedTypes, iterable $doses): array
    {
        $selected = array_fill_keys($selectedTypes, true);

        foreach ($doses as $dose) {
            if ($dose instanceof SchoolImmunizationDose) {
                $group = (string) $dose->slot_group;
                $key = (string) $dose->slot_key;
                $dateGiven = $dose->date_given;
            } else {
                $group = (string) ($dose['slot_group'] ?? '');
                $key = (string) ($dose['slot_key'] ?? '');
                $dateGiven = $dose['date_given'] ?? null;
            }

            if ($dateGiven === null || $dateGiven === '') {
                continue;
            }

            $typeKey = SchoolImmunization::typeKeyForSlot($group, $key);
            if ($typeKey !== null) {
                $selected[$typeKey] = true;
            }
        }

        return self::orderedTypeKeys($selected);
    }

    /**
     * @param  array<string, true>  $selected
     * @return list<string>
     */
    private static function orderedTypeKeys(array $selected): array
    {
        $ordered = [];
        foreach (SchoolImmunization::SELECTABLE_TYPE_KEYS as $key) {
            if (isset($selected[$key])) {
                $ordered[] = $key;
            }
        }

        return $ordered;
    }

    /**
     * @return array{
     *     persisted: bool,
     *     selected_vaccine_types: list<string>,
     *     vaccines: array<string, array<string, string>>
     * }
     */
    public function toReadContract(SchoolImmunization $record): array
    {
        $record->loadMissing('doses');

        $vaccines = self::emptyVaccineForm();
        foreach ($record->doses as $dose) {
            $group = (string) $dose->slot_group;
            $key = (string) $dose->slot_key;

            if (! isset($vaccines[$group][$key])) {
                continue;
            }

            $vaccines[$group][$key] = $dose->date_given instanceof Carbon
                ? $dose->date_given->format('Y-m-d')
                : (string) ($dose->date_given ?? '');
        }

        return [
            'persisted' => true,
            'selected_vaccine_types' => self::mergeDateDerivedSelections(
                self::normalizeSelectedVaccineTypes($record->selected_vaccine_types ?? []),
                $record->doses
            ),
            'vaccines' => $vaccines,
        ];
    }

    /**
     * @return array{
     *     persisted: bool,
     *     selected_vaccine_types: list<string>,
     *     vaccines: array<string, array<string, string>>
     * }
     */
    public function emptyState(): array
    {
        return [
            'persisted' => false,
            'selected_vaccine_types' => [],
            'vaccines' => self::emptyVaccineForm(),
        ];
    }

    /**
     * Nested form shape matching frozen Blade:
     * name="vaccines[{slot_group}][{slot_key}]"
     *
     * @return array<string, array<string, string>>
     */
    public static function emptyVaccineForm(): array
    {
        $form = [];

        foreach (SchoolImmunization::DOSE_SLOTS as $slot) {
            $form[$slot['slot_group']][$slot['slot_key']] = '';
        }

        return $form;
    }

    /**
     * @return array<string, true>
     */
    public static function allowedSlotLookup(): array
    {
        $lookup = [];

        foreach (SchoolImmunization::DOSE_SLOTS as $slot) {
            $lookup[$slot['slot_group']."\0".$slot['slot_key']] = true;
        }

        return $lookup;
    }

    public static function isAllowedSlot(string $slotGroup, string $slotKey): bool
    {
        return isset(self::allowedSlotLookup()[$slotGroup."\0".$slotKey]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $vaccines
     * @return list<array{slot_group: string, slot_key: string, date_given: ?string}>
     */
    public function normalizeSubmittedDoseSlots(array $vaccines): array
    {
        $slots = [];

        foreach ($vaccines as $slotGroupRaw => $doseKeys) {
            $slotGroup = strtolower(trim((string) $slotGroupRaw));

            if (! is_array($doseKeys)) {
                throw ValidationException::withMessages([
                    "vaccines.{$slotGroupRaw}" => 'Vaccine dose group must be an array.',
                ]);
            }

            foreach ($doseKeys as $slotKeyRaw => $dateValue) {
                $slotKey = strtolower(trim((string) $slotKeyRaw));

                if (! self::isAllowedSlot($slotGroup, $slotKey)) {
                    throw ValidationException::withMessages([
                        "vaccines.{$slotGroupRaw}.{$slotKeyRaw}" => 'School-based immunization dose slot is invalid.',
                    ]);
                }

                $slots[] = [
                    'slot_group' => $slotGroup,
                    'slot_key' => $slotKey,
                    'date_given' => $this->nullableDate($dateValue),
                ];
            }
        }

        return $slots;
    }

    protected function upsertDoseSlot(
        SchoolImmunization $record,
        string $slotGroup,
        string $slotKey,
        ?string $dateGiven,
    ): SchoolImmunizationDose {
        return SchoolImmunizationDose::query()->updateOrCreate(
            [
                'school_immunization_id' => $record->id,
                'slot_group' => $slotGroup,
                'slot_key' => $slotKey,
            ],
            [
                'date_given' => $dateGiven,
            ]
        );
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $trimmed)->format('Y-m-d');
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'vaccines' => 'One or more school-based immunization dates are invalid.',
            ]);
        }
    }

    private function isDuplicateDoseViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return (str_contains($message, 'UNIQUE constraint failed') && (
            str_contains($message, 'school_immunization_doses')
            || str_contains($message, 'uq_schoolimm_grade')
            || str_contains($message, 'uq_hpvimm_dose')
        ))
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'school_imm_doses_record_slot_unique')
            || str_contains($message, 'uq_schoolimm_grade')
            || str_contains($message, 'uq_hpvimm_dose')
            || (string) $e->getCode() === '23000';
    }

    /**
     * Child Care age-floor check only. Unknown DOB is not "below floor";
     * use {@see isEligibleForSchoolImmunization} for write/GET gating.
     */
    public static function isBelowSchoolImmunizationAgeFloor(Resident $resident): bool
    {
        $months = HealthRecordsChildCare::ageInMonths(self::memberAgePayload($resident));

        return $months !== null && $months <= HealthRecordsChildCare::MAX_AGE_MONTHS;
    }

    /**
     * Server-authoritative SBI eligibility for persisted residents.
     *
     * Unknown/unparseable birthday → ineligible.
     * 0–59 months (inclusive) → ineligible.
     * 60+ months → schedule form may open (not Grade 1/7 membership).
     */
    public static function isEligibleForSchoolImmunization(Resident $resident): bool
    {
        $months = HealthRecordsChildCare::ageInMonths(self::memberAgePayload($resident));

        return $months !== null && $months > HealthRecordsChildCare::MAX_AGE_MONTHS;
    }

    public static function ineligibleDetailFor(Resident $resident): string
    {
        $months = HealthRecordsChildCare::ageInMonths(self::memberAgePayload($resident));

        return $months === null ? self::INELIGIBLE_UNKNOWN_DETAIL : self::INELIGIBLE_DETAIL;
    }

    public static function rejectIfIneligibleForSchoolImmunization(Resident $resident): void
    {
        if (self::isEligibleForSchoolImmunization($resident)) {
            return;
        }

        throw ValidationException::withMessages([
            'immunization' => [
                self::INELIGIBLE_TITLE,
                self::ineligibleDetailFor($resident),
            ],
        ]);
    }

    /**
     * Birthday only — never request age or educational_attainment.
     *
     * @return array{birthday: string}
     */
    public static function memberAgePayload(Resident $resident): array
    {
        $raw = $resident->getAttributes()['birthday'] ?? null;

        if ($raw instanceof \DateTimeInterface) {
            return ['birthday' => Carbon::parse($raw)->format('Y-m-d')];
        }

        return ['birthday' => trim((string) $raw)];
    }

    /**
     * @param  array{
     *     vaccines?: array<string, array<string, mixed>>,
     *     vaccine_types?: list<mixed>|null
     * }  $payload
     */
    private function saveErdForResident(Resident $resident, array $payload): void
    {
        $doseSlots = $this->normalizeSubmittedDoseSlots($payload['vaccines'] ?? []);
        $residentId = (int) $resident->getKey();

        try {
            DB::transaction(function () use ($residentId, $doseSlots): void {
                $gradeUpdates = [];
                $hpvUpdates = [];

                foreach ($doseSlots as $slot) {
                    $group = $slot['slot_group'];
                    $key = $slot['slot_key'];
                    $date = $slot['date_given'];

                    if (isset(SchoolImmunizationErdMode::UI_GROUP_TO_GRADE[$group], SchoolImmunizationErdMode::GRADE_DATE_COLUMNS[$key])) {
                        $grade = SchoolImmunizationErdMode::UI_GROUP_TO_GRADE[$group];
                        $column = SchoolImmunizationErdMode::GRADE_DATE_COLUMNS[$key];
                        $gradeUpdates[$grade][$column] = $date;
                        continue;
                    }

                    if ($group === 'hpv' && isset(SchoolImmunizationErdMode::UI_HPV_KEY_TO_DOSE[$key])) {
                        $hpvUpdates[SchoolImmunizationErdMode::UI_HPV_KEY_TO_DOSE[$key]] = $date;
                    }
                }

                foreach ($gradeUpdates as $grade => $columns) {
                    $this->upsertErdGradeRow($residentId, $grade, $columns);
                }

                foreach ($hpvUpdates as $doseNumber => $dateGiven) {
                    $this->upsertErdHpvRow($residentId, $doseNumber, $dateGiven);
                }
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateDoseViolation($e)) {
                throw ValidationException::withMessages([
                    'vaccines' => 'A duplicate school-based immunization dose slot was submitted.',
                ]);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, ?string>  $columns
     */
    private function upsertErdGradeRow(int $residentId, string $grade, array $columns): void
    {
        $now = now();
        $existing = DB::table('school_immunization')
            ->where('resident_id', $residentId)
            ->where('grade_level', $grade)
            ->first();

        if ($existing !== null) {
            DB::table('school_immunization')
                ->where('school_immunization_id', $existing->school_immunization_id)
                ->update($columns + ['updated_at' => $now]);

            return;
        }

        DB::table('school_immunization')->insert([
            'resident_id' => $residentId,
            'grade_level' => $grade,
            'td_date' => $columns['td_date'] ?? null,
            'mr_date' => $columns['mr_date'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertErdHpvRow(int $residentId, string $doseNumber, ?string $dateGiven): void
    {
        $now = now();
        $existing = DB::table('hpv_immunization')
            ->where('resident_id', $residentId)
            ->where('dose_number', $doseNumber)
            ->first();

        if ($existing !== null) {
            DB::table('hpv_immunization')
                ->where('hpv_immunization_id', $existing->hpv_immunization_id)
                ->update([
                    'date_given' => $dateGiven,
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('hpv_immunization')->insert([
            'resident_id' => $residentId,
            'dose_number' => $doseNumber,
            'date_given' => $dateGiven,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{
     *     persisted: bool,
     *     selected_vaccine_types: list<string>,
     *     vaccines: array<string, array<string, string>>
     * }
     */
    private function toReadContractFromErd(int $residentId): array
    {
        $vaccines = self::emptyVaccineForm();
        $doseSlots = [];

        $gradeRows = DB::table('school_immunization')
            ->where('resident_id', $residentId)
            ->get();

        foreach ($gradeRows as $row) {
            $group = array_search((string) $row->grade_level, SchoolImmunizationErdMode::UI_GROUP_TO_GRADE, true);
            if ($group === false) {
                continue;
            }

            foreach (SchoolImmunizationErdMode::GRADE_DATE_COLUMNS as $slotKey => $column) {
                $date = $this->formatStoredDate($row->{$column} ?? null);
                $vaccines[$group][$slotKey] = $date;
                $doseSlots[] = [
                    'slot_group' => $group,
                    'slot_key' => $slotKey,
                    'date_given' => $date !== '' ? $date : null,
                ];
            }
        }

        $hpvRows = DB::table('hpv_immunization')
            ->where('resident_id', $residentId)
            ->get();

        foreach ($hpvRows as $row) {
            $slotKey = array_search((string) $row->dose_number, SchoolImmunizationErdMode::UI_HPV_KEY_TO_DOSE, true);
            if ($slotKey === false) {
                continue;
            }

            $date = $this->formatStoredDate($row->date_given ?? null);
            $vaccines['hpv'][$slotKey] = $date;
            $doseSlots[] = [
                'slot_group' => 'hpv',
                'slot_key' => $slotKey,
                'date_given' => $date !== '' ? $date : null,
            ];
        }

        $hasRows = $gradeRows->isNotEmpty() || $hpvRows->isNotEmpty();

        return [
            'persisted' => $hasRows,
            'selected_vaccine_types' => self::mergeDateDerivedSelections([], $doseSlots),
            'vaccines' => $vaccines,
        ];
    }

    private function formatStoredDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }
}
