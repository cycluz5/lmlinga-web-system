<?php

namespace App\Support;

use App\Models\ChildImmunization;
use App\Models\ImmunizationDose;
use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * DB-08 Phase 2 — Child Immunization read/write for persisted residents.
 *
 * Canonical identity is residents.id. FIC/CIC completion is derived from
 * persisted dose dates using the frozen completion rules (not stored rows).
 */
final class ChildImmunizationService
{
    /**
     * Frozen FIC completion requirements (dated doses per vaccine key).
     *
     * @var array<string, int>
     */
    public const FIC_DOSE_REQUIREMENTS = [
        'bcg' => 1,
        'opv' => 3,
        'dpt-hib-hepb' => 3,
        'mmr' => 1,
    ];

    /**
     * Frozen CIC completion requirements (dated doses per vaccine key).
     *
     * @var array<string, int>
     */
    public const CIC_DOSE_REQUIREMENTS = [
        'bcg' => 1,
        'opv' => 3,
        'dpt-hib-hepb' => 3,
        'mmr' => 2,
    ];

    /**
     * Read contract for Phase 3 form hydration. Does not require DB rows when empty.
     *
     * @return array{
     *     persisted: bool,
     *     selected_vaccine_types: list<string>,
     *     remarks: string,
     *     vaccines: array<string, list<string>>,
     *     fic: array{
     *         completed: bool,
     *         requirements: array<string, int>,
     *         counts: array<string, int>
     *     },
     *     cic: array{
     *         completed: bool,
     *         requirements: array<string, int>,
     *         counts: array<string, int>
     *     }
     * }
     */
    public function forResident(Resident $resident): array
    {
        if ((int) $resident->id <= 0) {
            abort(404, 'Resident was not found.');
        }

        if (! ChildImmunizationErdMode::isActive() && ! Schema::hasTable('child_immunizations')) {
            return $this->emptyState();
        }

        $record = $resident->childImmunization()
            ->with('doses')
            ->first();

        if ($record === null) {
            return $this->emptyState();
        }

        return $this->toReadContract($record);
    }

    /**
     * Atomic upsert of immunization header + dose slots for a persisted resident.
     *
     * @param  array{
     *     vaccines?: array<string, array<int|string, mixed>>,
     *     vaccine_types?: list<mixed>|null,
     *     remarks?: string|null
     * }  $payload
     */
    public function saveForResident(Resident $resident, array $payload): ChildImmunization
    {
        HouseholdProfilingWriteGuard::rejectChildImmunizationWrite();

        if ((int) $resident->id <= 0) {
            abort(404, 'Resident was not found.');
        }

        // Status-only UI no longer posts vaccine_types[]. Preserve legacy
        // selected_vaccine_types when the key is absent so dose-only saves
        // do not wipe historical checkbox selections.
        $updateSelectedTypes = array_key_exists('vaccine_types', $payload);
        $selectedTypes = $updateSelectedTypes
            ? self::normalizeSelectedVaccineTypes($payload['vaccine_types'])
            : null;
        $remarks = $this->nullableString($payload['remarks'] ?? null);
        $doseSlots = $this->normalizeSubmittedDoseSlots($payload['vaccines'] ?? []);

        try {
            return DB::transaction(function () use ($resident, $updateSelectedTypes, $selectedTypes, $remarks, $doseSlots): ChildImmunization {
                if (ChildImmunizationErdMode::isActive()) {
                    $record = ChildImmunization::query()->updateOrCreate(
                        ['resident_id' => $resident->id]
                    );
                } else {
                    $attributes = ['remarks' => $remarks];
                    if ($updateSelectedTypes) {
                        $attributes['selected_vaccine_types'] = $selectedTypes;
                    }

                    $record = ChildImmunization::query()->updateOrCreate(
                        ['resident_id' => $resident->id],
                        $attributes
                    );
                }

                foreach ($doseSlots as $slot) {
                    $this->upsertDoseSlot(
                        $record,
                        $slot['vaccine_type'],
                        $slot['dose_index'],
                        $slot['date_given'],
                    );
                }

                if ($updateSelectedTypes && ChildImmunizationErdMode::usesFicCicStatusTable()) {
                    $this->syncFicCicStatus($record, $selectedTypes ?? []);
                }

                return $record->fresh(['doses']);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateDoseViolation($e)) {
                throw ValidationException::withMessages([
                    'vaccines' => 'A duplicate vaccine dose slot was submitted.',
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

        $allowed = array_fill_keys(ChildImmunization::SELECTABLE_TYPE_KEYS, true);
        $selected = [];

        foreach ($input as $value) {
            $key = strtolower(trim((string) $value));
            if ($key === '' || ! isset($allowed[$key])) {
                continue;
            }

            $selected[$key] = true;
        }

        $ordered = [];
        foreach (ChildImmunization::SELECTABLE_TYPE_KEYS as $key) {
            if (isset($selected[$key])) {
                $ordered[] = $key;
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, list<int>>  $counts
     */
    public static function ficCompleted(array $counts): bool
    {
        return self::meetsRequirements($counts, self::FIC_DOSE_REQUIREMENTS);
    }

    /**
     * @param  array<string, list<int>>  $counts
     */
    public static function cicCompleted(array $counts): bool
    {
        return self::meetsRequirements($counts, self::CIC_DOSE_REQUIREMENTS);
    }

    /**
     * Count unique dated dose slots per FIC/CIC vaccine key.
     *
     * Null dates and indexes outside DOSE_SLOT_COUNTS are ignored.
     *
     * @param  iterable<int, array{vaccine_type: string, dose_index: int, date_given: ?string}>|iterable<int, ImmunizationDose>  $slots
     * @return array<string, int>
     */
    public static function countDatedDoses(iterable $slots): array
    {
        $seen = [];
        $counts = array_fill_keys(array_keys(self::FIC_DOSE_REQUIREMENTS), 0);

        foreach (self::datedConfiguredSlots($slots) as [$vaccineType, $doseIndex]) {
            if (! array_key_exists($vaccineType, $counts)) {
                continue;
            }

            $key = $vaccineType.'.'.$doseIndex;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $counts[$vaccineType]++;
        }

        return $counts;
    }

    /**
     * Per-vaccine UI progress from unique dated slots vs DOSE_SLOT_COUNTS.
     *
     * @param  iterable<int, array{vaccine_type: string, dose_index: int, date_given: ?string}>|iterable<int, ImmunizationDose>  $slots
     * @return array<string, array{given: int, required: int, complete: bool, display: string}>
     */
    public static function vaccineProgress(iterable $slots = []): array
    {
        $seen = [];

        foreach (self::datedConfiguredSlots($slots) as [$vaccineType, $doseIndex]) {
            $seen[$vaccineType][$doseIndex] = true;
        }

        $progress = [];

        foreach (ChildImmunization::DOSE_SLOT_COUNTS as $vaccineType => $required) {
            $given = count($seen[$vaccineType] ?? []);
            $displayGiven = min($given, $required);
            $progress[$vaccineType] = [
                'given' => $displayGiven,
                'required' => $required,
                'complete' => $given >= $required,
                'display' => $displayGiven.'/'.$required,
            ];
        }

        return $progress;
    }

    /**
     * @param  iterable<int, array{vaccine_type: string, dose_index: int, date_given: ?string}>|iterable<int, ImmunizationDose>  $slots
     * @return list<array{0: string, 1: int}>
     */
    private static function datedConfiguredSlots(iterable $slots): array
    {
        $dated = [];

        foreach ($slots as $slot) {
            if ($slot instanceof ImmunizationDose) {
                $vaccineType = (string) $slot->vaccine_type;
                $doseIndex = (int) $slot->dose_index;
                $dateGiven = $slot->date_given;
            } else {
                $vaccineType = (string) ($slot['vaccine_type'] ?? '');
                $doseIndex = (int) ($slot['dose_index'] ?? -1);
                $dateGiven = $slot['date_given'] ?? null;
            }

            if ($dateGiven === null || $dateGiven === '') {
                continue;
            }

            $required = ChildImmunization::DOSE_SLOT_COUNTS[$vaccineType] ?? null;
            if ($required === null || $doseIndex < 0 || $doseIndex >= $required) {
                continue;
            }

            $dated[] = [$vaccineType, $doseIndex];
        }

        return $dated;
    }

    /**
     * @return array{
     *     persisted: bool,
     *     selected_vaccine_types: list<string>,
     *     remarks: string,
     *     vaccines: array<string, list<string>>,
     *     progress: array<string, array{given: int, required: int, complete: bool, display: string}>,
     *     fic: array{
     *         completed: bool,
     *         requirements: array<string, int>,
     *         counts: array<string, int>
     *     },
     *     cic: array{
     *         completed: bool,
     *         requirements: array<string, int>,
     *         counts: array<string, int>
     *     }
     * }
     */
    public function toReadContract(ChildImmunization $record): array
    {
        $record->loadMissing('doses');

        $vaccines = self::emptyVaccineForm();
        foreach ($record->doses as $dose) {
            $type = (string) $dose->vaccine_type;
            $index = (int) $dose->dose_index;

            if (! isset($vaccines[$type][$index])) {
                continue;
            }

            $vaccines[$type][$index] = $dose->date_given instanceof Carbon
                ? $dose->date_given->format('Y-m-d')
                : (string) ($dose->date_given ?? '');
        }

        $counts = self::countDatedDoses($record->doses);

        return [
            'persisted' => true,
            'selected_vaccine_types' => $this->selectedVaccineTypesForRead($record),
            'remarks' => ChildImmunizationErdMode::isActive()
                ? ''
                : (string) ($record->remarks ?? ''),
            'vaccines' => $vaccines,
            'progress' => self::vaccineProgress($record->doses),
            'fic' => [
                'completed' => self::ficCompleted($counts),
                'requirements' => self::FIC_DOSE_REQUIREMENTS,
                'counts' => $counts,
            ],
            'cic' => [
                'completed' => self::cicCompleted($counts),
                'requirements' => self::CIC_DOSE_REQUIREMENTS,
                'counts' => $counts,
            ],
        ];
    }

    /**
     * @return array{
     *     persisted: bool,
     *     selected_vaccine_types: list<string>,
     *     remarks: string,
     *     vaccines: array<string, list<string>>,
     *     progress: array<string, array{given: int, required: int, complete: bool, display: string}>,
     *     fic: array{
     *         completed: bool,
     *         requirements: array<string, int>,
     *         counts: array<string, int>
     *     },
     *     cic: array{
     *         completed: bool,
     *         requirements: array<string, int>,
     *         counts: array<string, int>
     *     }
     * }
     */
    public function emptyState(): array
    {
        $counts = array_fill_keys(array_keys(self::FIC_DOSE_REQUIREMENTS), 0);

        return [
            'persisted' => false,
            'selected_vaccine_types' => [],
            'remarks' => '',
            'vaccines' => self::emptyVaccineForm(),
            'progress' => self::vaccineProgress([]),
            'fic' => [
                'completed' => false,
                'requirements' => self::FIC_DOSE_REQUIREMENTS,
                'counts' => $counts,
            ],
            'cic' => [
                'completed' => false,
                'requirements' => self::CIC_DOSE_REQUIREMENTS,
                'counts' => $counts,
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function emptyVaccineForm(): array
    {
        $form = [];

        foreach (ChildImmunization::DOSE_SLOT_COUNTS as $vaccineType => $slotCount) {
            $form[$vaccineType] = array_fill(0, $slotCount, '');
        }

        return $form;
    }

    /**
     * @param  array<string, array<int|string, mixed>>  $vaccines
     * @return list<array{vaccine_type: string, dose_index: int, date_given: ?string}>
     */
    public function normalizeSubmittedDoseSlots(array $vaccines): array
    {
        $slots = [];

        foreach ($vaccines as $vaccineType => $doses) {
            $vaccineKey = strtolower(trim((string) $vaccineType));

            if (! in_array($vaccineKey, ChildImmunization::VACCINE_TYPES, true)) {
                throw ValidationException::withMessages([
                    "vaccines.{$vaccineType}" => 'Vaccine type is invalid.',
                ]);
            }

            if (! is_array($doses)) {
                throw ValidationException::withMessages([
                    "vaccines.{$vaccineKey}" => 'Vaccine doses must be an array.',
                ]);
            }

            $maxIndex = ChildImmunization::DOSE_SLOT_COUNTS[$vaccineKey] - 1;

            foreach ($doses as $index => $dateValue) {
                if (! is_numeric($index) || (int) $index != $index) {
                    throw ValidationException::withMessages([
                        "vaccines.{$vaccineKey}.{$index}" => 'Dose index is invalid.',
                    ]);
                }

                $doseIndex = (int) $index;
                if ($doseIndex < 0 || $doseIndex > $maxIndex) {
                    throw ValidationException::withMessages([
                        "vaccines.{$vaccineKey}.{$index}" => 'Dose index is out of range.',
                    ]);
                }

                $slots[] = [
                    'vaccine_type' => $vaccineKey,
                    'dose_index' => $doseIndex,
                    'date_given' => $this->nullableDate($dateValue),
                ];
            }
        }

        return $slots;
    }

    protected function upsertDoseSlot(
        ChildImmunization $record,
        string $vaccineType,
        int $doseIndex,
        ?string $dateGiven,
    ): ImmunizationDose {
        return ImmunizationDose::query()->updateOrCreate(
            ChildImmunizationErdMode::doseMatchAttributes(
                $record->getKey(),
                $vaccineType,
                $doseIndex,
            ),
            [
                'date_given' => $dateGiven,
            ]
        );
    }

    /**
     * @param  list<string>  $selectedTypes
     */
    private function syncFicCicStatus(ChildImmunization $record, array $selectedTypes): void
    {
        if (! ChildImmunizationErdMode::usesFicCicStatusTable()) {
            return;
        }

        $now = now();
        $headerId = $record->getKey();
        $payload = [
            'fic_completed' => in_array('fic', $selectedTypes, true) ? 1 : 0,
            'cic_completed' => in_array('cic', $selectedTypes, true) ? 1 : 0,
            'updated_at' => $now,
        ];

        $existing = DB::table('fic_cic_status')
            ->where('child_immunization_id', $headerId)
            ->first();

        if ($existing !== null) {
            DB::table('fic_cic_status')
                ->where('child_immunization_id', $headerId)
                ->update($payload);

            return;
        }

        DB::table('fic_cic_status')->insert($payload + [
            'child_immunization_id' => $headerId,
            'created_at' => $now,
        ]);
    }

    /**
     * @return list<string>
     */
    private function selectedVaccineTypesForRead(ChildImmunization $record): array
    {
        $selected = [];

        if (! ChildImmunizationErdMode::isActive()) {
            $selected = self::normalizeSelectedVaccineTypes($record->selected_vaccine_types ?? []);
        } else {
            foreach ($record->doses as $dose) {
                $selected[] = (string) $dose->vaccine_type;
            }
        }

        if (ChildImmunizationErdMode::usesFicCicStatusTable()) {
            $status = DB::table('fic_cic_status')
                ->where('child_immunization_id', $record->getKey())
                ->first();

            if ($status !== null) {
                if ((int) ($status->fic_completed ?? 0) === 1) {
                    $selected[] = 'fic';
                }
                if ((int) ($status->cic_completed ?? 0) === 1) {
                    $selected[] = 'cic';
                }
            }
        }

        return self::normalizeSelectedVaccineTypes($selected);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $requirements
     */
    private static function meetsRequirements(array $counts, array $requirements): bool
    {
        foreach ($requirements as $vaccineType => $requiredCount) {
            if (($counts[$vaccineType] ?? 0) < $requiredCount) {
                return false;
            }
        }

        return true;
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
                'vaccines' => 'One or more vaccine dates are invalid.',
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isDuplicateDoseViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'immunization_doses'))
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'immunization_doses_record_vaccine_dose_unique')
            || str_contains($message, 'uq_immdose')
            || (string) $e->getCode() === '23000';
    }
}
