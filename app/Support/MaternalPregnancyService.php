<?php

namespace App\Support;

use App\Models\MaternalPregnancy;
use App\Models\Resident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * DB-14 Phase 2 — Maternal Care pregnancy persistence for Household Profiling members.
 */
final class MaternalPregnancyService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createForResident(Resident $resident, array $payload): MaternalPregnancy|array
    {
        self::rejectIfWorkflowIneligible($resident);

        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)->createForResident($resident, $payload);
        }

        HouseholdProfilingWriteGuard::rejectMaternalCareWrite();

        return DB::transaction(function () use ($resident, $payload): MaternalPregnancy {
            // Serialize concurrent registrations for the same resident.
            $locked = Resident::query()->whereKey($resident->id)->lockForUpdate()->firstOrFail();

            $hasActive = $locked->maternalPregnancies()
                ->where('status', MaternalPregnancy::STATUS_ACTIVE)
                ->exists();

            if ($hasActive) {
                throw ValidationException::withMessages([
                    'lmp' => 'This resident already has an active pregnancy.',
                ]);
            }

            $sequence = ((int) $locked->maternalPregnancies()->max('pregnancy_number')) + 1;
            $row = DemoMaternalCare::buildRegistrationPregnancy($payload, $sequence);
            $attrs = $this->attributesFromPregnancyRow($row, $sequence, MaternalPregnancy::STATUS_ACTIVE);

            /** @var MaternalPregnancy $pregnancy */
            $pregnancy = $locked->maternalPregnancies()->make(
                $this->clinicalAttributes($attrs)
            );
            // Server-owned lifecycle fields — never mass-assignable.
            $pregnancy->pregnancy_number = (int) $attrs['pregnancy_number'];
            $pregnancy->status = (string) $attrs['status'];
            $pregnancy->registered_at = $attrs['registered_at'];
            // Temporary unique value (≤16 chars) until primary key is known.
            $pregnancy->pregnancy_no = 'T'.strtoupper(bin2hex(random_bytes(7)));
            $pregnancy->save();

            $pregnancy->pregnancy_no = sprintf('MC-%03d', $pregnancy->id);
            $pregnancy->save();

            $this->syncTimbangFromMaternalPhysical(
                $locked,
                $pregnancy->registered_at?->toDateString() ?? now()->toDateString(),
                $pregnancy->weight,
                $pregnancy->height,
            );

            return $pregnancy->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateSectionForResident(
        Resident $resident,
        string $section,
        array $payload
    ): MaternalPregnancy|array|null {
        self::rejectIfWorkflowIneligible($resident);

        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)
                ->updateActiveSection($resident, $section, $payload);
        }

        HouseholdProfilingWriteGuard::rejectMaternalCareWrite();

        return DB::transaction(function () use ($resident, $section, $payload) {
            $target = $this->findWritableForResident($resident, $section);
            if ($target === null) {
                return null;
            }

            $current = $this->toRawPregnancyArray($target);
            $result = DemoMaternalCare::applySectionToPregnancy($current, $section, $payload);
            if ($result === null) {
                return null;
            }

            $updated = $result['pregnancy'];
            $nextStatus = (string) $target->status;
            if ($result['transferred']) {
                $nextStatus = MaternalPregnancy::STATUS_TRANSFERRED_OUT;
            } elseif ($result['completed'] ?? false) {
                if ($nextStatus !== MaternalPregnancy::STATUS_TRANSFERRED_OUT) {
                    $nextStatus = MaternalPregnancy::STATUS_COMPLETED;
                }
            }

            $attrs = $this->attributesFromPregnancyRow(
                $updated,
                (int) ($updated['number'] ?? $target->pregnancy_number),
                $nextStatus
            );

            $target->fill($this->clinicalAttributes($attrs));
            $target->status = $nextStatus;
            $target->save();

            if ($section === 'prenatal') {
                $this->syncTimbangFromLegacyPrenatal(
                    $resident,
                    is_array($current['prenatal'] ?? null) ? $current['prenatal'] : [],
                    is_array($updated['prenatal'] ?? null) ? $updated['prenatal'] : [],
                );
            }

            return $target->refresh();
        });
    }

    public static function rejectIfWorkflowIneligible(Resident $resident): void
    {
        if (MaternalCareEligibility::allowsWorkflow($resident->sex ?? null, $resident->birthday ?? null)) {
            return;
        }

        throw ValidationException::withMessages([
            'lmp' => MaternalCareEligibility::WORKFLOW_INELIGIBLE_MESSAGE,
        ]);
    }

    public function hasAnyEpisode(Resident $resident): bool
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)->hasAny($resident->getKey());
        }

        if (Schema::hasTable('maternal_pregnancies')) {
            return $resident->maternalPregnancies()->exists();
        }

        if (Schema::hasTable('maternal_care')) {
            return DB::table('maternal_care')
                ->where('resident_id', $resident->getKey())
                ->exists();
        }

        return false;
    }

    public function hasClosedEpisodeForResident(Resident $resident): bool
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)->hasClosed($resident->getKey());
        }

        if (Schema::hasTable('maternal_pregnancies')) {
            return $resident->maternalPregnancies()
                ->where('status', '!=', MaternalPregnancy::STATUS_ACTIVE)
                ->exists();
        }

        if (Schema::hasTable('maternal_care')) {
            return DB::table('maternal_care')
                ->where('resident_id', $resident->getKey())
                ->where('pregnancy_status', '!=', 'Active')
                ->exists();
        }

        return false;
    }

    public function hasActiveForResident(Resident $resident): bool
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)->hasActive($resident->getKey());
        }

        return $this->findActiveForResident($resident) !== null;
    }

    public function hasWritableSectionForResident(Resident $resident, string $section): bool
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)
                ->hasWritableSection($resident->getKey(), $section);
        }

        return $this->findWritableForResident($resident, $section) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function writableSectionPresentationForResident(Resident $resident, string $section): ?array
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)
                ->writableSectionPresentation($resident, $section);
        }

        $row = $this->findWritableForResident($resident, $section);

        return $row ? $this->toPresentation($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function historicalPresentationForResident(Resident $resident, string $pregnancyId): ?array
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)
                ->historicalPresentation($resident, $pregnancyId);
        }

        if (Schema::hasTable('maternal_pregnancies')) {
            $row = $this->findForResident($resident, $pregnancyId);
            if ($row === null || $row->status === MaternalPregnancy::STATUS_ACTIVE) {
                return null;
            }

            $presented = $this->toPresentation($row);

            return DemoMaternalCare::isVisibleInPregnancyHistory($presented) ? $presented : null;
        }

        return null;
    }

    public function findActiveForResident(Resident $resident): ?MaternalPregnancy
    {
        if (! Schema::hasTable('maternal_pregnancies')) {
            return null;
        }

        return $resident->maternalPregnancies()
            ->where('status', MaternalPregnancy::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
    }

    public function findLatestCompletedForResident(Resident $resident): ?MaternalPregnancy
    {
        if (! Schema::hasTable('maternal_pregnancies')) {
            return null;
        }

        return $resident->maternalPregnancies()
            ->where('status', MaternalPregnancy::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->first();
    }

    public function findWritableForResident(Resident $resident, string $section): ?MaternalPregnancy
    {
        $active = $this->findActiveForResident($resident);
        if (DemoMaternalCare::sectionAllowsCompletedEpisode($section)) {
            return $active ?? $this->findLatestCompletedForResident($resident);
        }

        return $active;
    }

    public function findForResident(Resident $resident, string $pregnancyNo): ?MaternalPregnancy
    {
        $id = strtoupper(trim($pregnancyNo));

        return $resident->maternalPregnancies()
            ->where('pregnancy_no', $id)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activePresentationForResident(Resident $resident): ?array
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)->activePresentation($resident);
        }

        if (Schema::hasTable('maternal_pregnancies')) {
            $active = $this->findActiveForResident($resident);

            return $active ? $this->toPresentation($active) : null;
        }

        if (Schema::hasTable('maternal_care')) {
            return $this->activePresentationFromMaternalCare($resident);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeOrContinuingPresentationForResident(Resident $resident): ?array
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)->activeOrContinuingPresentation($resident);
        }

        return $this->activePresentationForResident($resident);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyRowsForResident(Resident $resident): array
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return app(MaternalCareErdPersistence::class)->historyPresentations($resident);
        }

        if (Schema::hasTable('maternal_pregnancies')) {
            return DemoMaternalCare::visiblePregnancyHistory(
                $resident->maternalPregnancies()
                    ->where('status', '!=', MaternalPregnancy::STATUS_ACTIVE)
                    ->orderByDesc('pregnancy_number')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (MaternalPregnancy $row): array => $this->toPresentation($row))
                    ->all()
            );
        }

        if (Schema::hasTable('maternal_care')) {
            return $this->historyRowsFromMaternalCare($resident);
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activePresentationFromMaternalCare(Resident $resident): ?array
    {
        $row = DB::table('maternal_care')
            ->where('resident_id', $resident->getKey())
            ->where('pregnancy_status', 'Active')
            ->orderByDesc('maternal_care_id')
            ->first();

        if ($row === null) {
            return null;
        }

        return DemoMaternalCare::present($this->erdMaternalRowToRawPregnancy($row, 1, 'active'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyRowsFromMaternalCare(Resident $resident): array
    {
        $rows = DB::table('maternal_care')
            ->where('resident_id', $resident->getKey())
            ->where('pregnancy_status', '!=', 'Active')
            ->orderByDesc('maternal_care_id')
            ->get();

        $history = [];
        $sequence = 1;
        foreach ($rows as $row) {
            $history[] = DemoMaternalCare::present(
                $this->erdMaternalRowToRawPregnancy($row, $sequence, (string) ($row->pregnancy_status ?? 'completed'))
            );
            $sequence++;
        }

        return DemoMaternalCare::visiblePregnancyHistory($history);
    }

    /**
     * @return array<string, mixed>
     */
    private function erdMaternalRowToRawPregnancy(object $row, int $sequence, string $status): array
    {
        $systolic = $row->bp_systolic ?? null;
        $diastolic = $row->bp_diastolic ?? null;
        $bp = ($systolic !== null && $diastolic !== null)
            ? ((string) $systolic).'/'.((string) $diastolic)
            : '';

        return [
            'id' => sprintf('MC-%03d', (int) ($row->maternal_care_id ?? 0)),
            'number' => $sequence,
            'status' => match (strtolower(str_replace('_', '-', $status))) {
                'active' => MaternalPregnancy::STATUS_ACTIVE,
                'trans-out', 'transferred-out', 'transferred out' => MaternalPregnancy::STATUS_TRANSFERRED_OUT,
                default => 'completed',
            },
            'lmp' => (string) ($row->lmp_date ?? ''),
            'edd' => (string) ($row->edd ?? ''),
            'gravida' => isset($row->gravida) ? (string) $row->gravida : '',
            'parity' => isset($row->parity) ? (string) $row->parity : '',
            'weight' => isset($row->weight_kg) ? (string) $row->weight_kg : '',
            'height' => isset($row->height_cm) ? (string) $row->height_cm : '',
            'bmi' => RiskAssessmentClinicalValues::calculateBmi(
                $row->height_cm ?? null,
                $row->weight_kg ?? null
            ) ?? '',
            'blood_pressure' => $bp,
            'registered_at' => isset($row->created_at) ? (string) $row->created_at : '',
            'prenatal' => [],
            'immunizations' => [],
            'supplementations' => [],
            'laboratory' => [],
            'delivery' => [],
            'postnatal' => [],
            'trans_out' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPresentation(MaternalPregnancy $pregnancy): array
    {
        return DemoMaternalCare::present($this->toRawPregnancyArray($pregnancy));
    }

    /**
     * @return array<string, mixed>
     */
    private function toRawPregnancyArray(MaternalPregnancy $pregnancy): array
    {
        return [
            'id' => (string) $pregnancy->pregnancy_no,
            'number' => (int) $pregnancy->pregnancy_number,
            'status' => (string) $pregnancy->status,
            'lmp' => $pregnancy->lmp?->toDateString() ?? '',
            'edd' => $pregnancy->edd?->toDateString() ?? '',
            'gravida' => $pregnancy->gravida === null ? '' : (string) $pregnancy->gravida,
            'parity' => $pregnancy->parity === null ? '' : (string) $pregnancy->parity,
            'weight' => $this->decimalString($pregnancy->weight),
            'height' => $this->decimalString($pregnancy->height),
            'bmi' => RiskAssessmentClinicalValues::calculateBmi($pregnancy->height, $pregnancy->weight) ?? '',
            'blood_pressure' => (string) ($pregnancy->blood_pressure ?? ''),
            'registered_at' => $pregnancy->registered_at?->toDateString() ?? '',
            'prenatal' => is_array($pregnancy->prenatal) ? $pregnancy->prenatal : [],
            'immunizations' => is_array($pregnancy->immunizations) ? $pregnancy->immunizations : [],
            'supplementations' => is_array($pregnancy->supplementations) ? $pregnancy->supplementations : [],
            'laboratory' => is_array($pregnancy->laboratory) ? $pregnancy->laboratory : [],
            'delivery' => is_array($pregnancy->delivery) ? $pregnancy->delivery : [],
            'postnatal' => is_array($pregnancy->postnatal) ? $pregnancy->postnatal : [],
            'trans_out' => is_array($pregnancy->trans_out) ? $pregnancy->trans_out : [],
        ];
    }

    /**
     * Clinical / section attributes only (mass-assignable).
     *
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function clinicalAttributes(array $attrs): array
    {
        unset($attrs['pregnancy_number'], $attrs['status'], $attrs['registered_at']);

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function attributesFromPregnancyRow(array $row, int $sequence, string $status): array
    {
        return [
            'pregnancy_number' => $sequence,
            'status' => $status,
            'registered_at' => $this->nullableDate($row['registered_at'] ?? null),
            'lmp' => $this->nullableDate($row['lmp'] ?? null),
            'gravida' => $this->nullableInt($row['gravida'] ?? null),
            'parity' => $this->nullableInt($row['parity'] ?? null),
            'edd' => $this->nullableDate($row['edd'] ?? null),
            'weight' => $this->nullableDecimal($row['weight'] ?? null),
            'height' => $this->nullableDecimal($row['height'] ?? null),
            'bmi' => $this->nullableDecimal($row['bmi'] ?? null),
            'blood_pressure' => $this->nullableText($row['blood_pressure'] ?? null),
            'prenatal' => is_array($row['prenatal'] ?? null) ? $row['prenatal'] : [],
            'immunizations' => is_array($row['immunizations'] ?? null) ? $row['immunizations'] : [],
            'supplementations' => is_array($row['supplementations'] ?? null) ? $row['supplementations'] : [],
            'laboratory' => is_array($row['laboratory'] ?? null) ? $row['laboratory'] : [],
            'delivery' => is_array($row['delivery'] ?? null) ? $row['delivery'] : [],
            'postnatal' => is_array($row['postnatal'] ?? null) ? $row['postnatal'] : [],
            'trans_out' => is_array($row['trans_out'] ?? null) ? $row['trans_out'] : [],
        ];
    }

    private function decimalString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        $raw = is_string($value) ? trim($value) : '';

        return $raw === '' ? null : $raw;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    private function syncTimbangFromMaternalPhysical(
        Resident $resident,
        mixed $measurementDate,
        mixed $weightKg,
        mixed $heightCm,
        mixed $oldWeightKg = null,
        mixed $oldHeightCm = null,
        bool $comparePrevious = false
    ): void {
        if ($measurementDate === null || $measurementDate === '') {
            return;
        }

        if ($comparePrevious && ! TimbangRecordService::physicalMeasurementsChanged(
            $oldWeightKg,
            $oldHeightCm,
            $weightKg,
            $heightCm
        )) {
            return;
        }

        (new TimbangRecordService)->createFromMaternalPhysical(
            $resident,
            $measurementDate,
            $weightKg,
            $heightCm
        );
    }

    /**
     * @param  array<string, mixed>  $oldPrenatal
     * @param  array<string, mixed>  $newPrenatal
     */
    private function syncTimbangFromLegacyPrenatal(
        Resident $resident,
        array $oldPrenatal,
        array $newPrenatal
    ): void {
        foreach (DemoMaternalCare::prenatalSchedule() as $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $key = $visit['key'];
                $old = is_array($oldPrenatal[$key] ?? null) ? $oldPrenatal[$key] : [];
                $new = is_array($newPrenatal[$key] ?? null) ? $newPrenatal[$key] : [];
                $oldWeight = $old['weight'] ?? null;
                $oldHeight = $old['height'] ?? null;
                $newWeight = $new['weight'] ?? null;
                $newHeight = $new['height'] ?? null;
                $oldHadMeasurements = TimbangRecordService::normalizedMeasurement($oldWeight) !== null
                    || TimbangRecordService::normalizedMeasurement($oldHeight) !== null;
                $eventDate = trim((string) ($new['date'] ?? ''))
                    ?: trim((string) ($old['date'] ?? ''));

                $this->syncTimbangFromMaternalPhysical(
                    $resident,
                    $eventDate !== '' ? $eventDate : null,
                    $newWeight,
                    $newHeight,
                    $oldWeight,
                    $oldHeight,
                    $oldHadMeasurements,
                );
            }
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $raw = is_string($value) ? trim($value) : '';

        return $raw === '' ? null : $raw;
    }
}
