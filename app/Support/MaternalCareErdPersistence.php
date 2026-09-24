<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Normalized ERD maternal_care persistence (one row = one pregnancy).
 * Never writes generated BMI columns. Never pregnancy-scopes td_immunization.
 */
final class MaternalCareErdPersistence
{
    /**
     * @var array<string, array{0: string, 1: int}>
     */
    private const PRENATAL_KEYS = [
        't1_v1' => ['1st', 1],
        't2_v1' => ['2nd', 1],
        't2_v2' => ['2nd', 2],
        't3_v1' => ['3rd', 1],
        't3_v2' => ['3rd', 2],
        't3_v3' => ['3rd', 3],
        't3_v4' => ['3rd', 4],
        't3_v5' => ['3rd', 5],
    ];

    private const PLACE_TO_ERD = [
        'public' => 'Public Health Facility',
        'private' => 'Private Health Facility',
        'non_health' => 'Non-Health Facility',
    ];

    private const PLACE_FROM_ERD = [
        'Public Health Facility' => 'public',
        'Private Health Facility' => 'private',
        'Non-Health Facility' => 'non_health',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createForResident(Resident $resident, array $payload): array
    {
        return DB::transaction(function () use ($resident, $payload): array {
            $locked = Resident::query()->whereKey($resident->getKey())->lockForUpdate()->firstOrFail();
            $residentId = $locked->getKey();

            if ($this->hasActive($residentId)) {
                throw ValidationException::withMessages([
                    'lmp' => 'This resident already has an active pregnancy.',
                ]);
            }

            $now = now();
            $insert = MaternalCareErdMode::filterWritablePayload([
                'resident_id' => $residentId,
                'lmp_date' => $this->nullableDate($payload['lmp'] ?? null),
                'gravida' => $this->nullableInt($payload['gravida'] ?? null),
                'parity' => $this->nullableInt($payload['parity'] ?? null),
                'edd' => $this->nullableDate($payload['edd'] ?? null) ?: $this->estimateEdd($payload['lmp'] ?? null),
                'weight_kg' => $this->nullableDecimal($payload['weight'] ?? null),
                'height_cm' => $this->nullableDecimal($payload['height'] ?? null),
                'pregnancy_status' => MaternalCareErdMode::STATUS_ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            unset($insert['bmi']);

            $bp = $this->parseBp((string) ($payload['blood_pressure'] ?? ''));
            $insert['bp_systolic'] = $bp[0];
            $insert['bp_diastolic'] = $bp[1];

            $id = (int) DB::table('maternal_care')->insertGetId($insert, 'maternal_care_id');
            $this->initializeFirstPrenatalVisit($id, $insert, $now);
            $this->syncTimbangFromMaternalPhysical(
                $locked,
                $now,
                $insert['weight_kg'] ?? null,
                $insert['height_cm'] ?? null,
            );

            return $this->presentationById($residentId, $id);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function updateActiveSection(Resident $resident, string $section, array $payload): ?array
    {
        return DB::transaction(function () use ($resident, $section, $payload): ?array {
            $locked = Resident::query()->whereKey($resident->getKey())->lockForUpdate()->firstOrFail();
            $residentId = $locked->getKey();
            $target = $this->writableRow($residentId, $section);
            if ($target === null) {
                return null;
            }

            $maternalCareId = (int) $target->maternal_care_id;
            $section = strtolower(trim($section));

            match ($section) {
                'prenatal' => $this->upsertPrenatal($maternalCareId, $payload, $locked),
                'supplementations' => $this->upsertSupplementations($maternalCareId, $payload),
                'laboratory' => $this->upsertLaboratory($maternalCareId, $payload),
                'delivery' => $this->upsertDeliveryAndComplete($maternalCareId, $payload),
                'postnatal' => $this->upsertPostnatal($maternalCareId, $payload),
                'trans-out' => $this->markTransferredOut($maternalCareId),
                'immunizations' => null,
                default => null,
            };

            return $this->presentationById($residentId, $maternalCareId);
        });
    }

    public function hasWritableSection(int|string $residentId, string $section): bool
    {
        return $this->writableRow($residentId, $section) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function writableSectionPresentation(Resident $resident, string $section): ?array
    {
        $row = $this->writableRow($resident->getKey(), $section);
        if ($row === null) {
            return null;
        }

        return $this->toPresentation($row, $this->sequenceMap($resident->getKey()));
    }

    public function hasActive(int|string $residentId): bool
    {
        return $this->activeRow($residentId) !== null;
    }

    public function hasAny(int|string $residentId): bool
    {
        return DB::table('maternal_care')
            ->where('resident_id', $residentId)
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activePresentation(Resident $resident): ?array
    {
        $row = $this->activeRow($resident->getKey());
        if ($row === null) {
            return null;
        }

        return $this->toPresentation($row, $this->sequenceMap($resident->getKey()));
    }

    /**
     * Active pregnancy, or the most recently completed one if it hasn't
     * crossed the Pregnancy History visibility gate yet — postnatal care
     * (and delivery corrections) must remain reachable through the normal
     * journey until then, not only via a direct section URL.
     *
     * @return array<string, mixed>|null
     */
    public function activeOrContinuingPresentation(Resident $resident): ?array
    {
        $row = $this->activeRow($resident->getKey());
        if ($row !== null) {
            return $this->toPresentation($row, $this->sequenceMap($resident->getKey()));
        }

        $completed = $this->latestCompletedRow($resident->getKey());
        if ($completed === null) {
            return null;
        }

        $presentation = $this->toPresentation($completed, $this->sequenceMap($resident->getKey()));

        return DemoMaternalCare::isVisibleInPregnancyHistory($presentation) ? null : $presentation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyPresentations(Resident $resident): array
    {
        $sequences = $this->sequenceMap($resident->getKey());
        $rows = DB::table('maternal_care')
            ->where('resident_id', $resident->getKey())
            ->whereIn('pregnancy_status', [
                MaternalCareErdMode::STATUS_COMPLETED,
                MaternalCareErdMode::STATUS_TRANS_OUT,
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('maternal_care_id')
            ->get();

        $history = [];
        foreach ($rows as $row) {
            $presented = $this->toPresentation($row, $sequences);
            if (DemoMaternalCare::isVisibleInPregnancyHistory($presented)) {
                $history[] = $presented;
            }
        }

        return $history;
    }

    public function hasClosed(int|string $residentId): bool
    {
        return DB::table('maternal_care')
            ->where('resident_id', $residentId)
            ->whereIn('pregnancy_status', [
                MaternalCareErdMode::STATUS_COMPLETED,
                MaternalCareErdMode::STATUS_TRANS_OUT,
            ])
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function historicalPresentation(Resident $resident, string $pregnancyId): ?array
    {
        $id = $this->parsePregnancyId($pregnancyId);
        if ($id === null) {
            return null;
        }

        $row = DB::table('maternal_care')
            ->where('maternal_care_id', $id)
            ->where('resident_id', $resident->getKey())
            ->whereIn('pregnancy_status', [
                MaternalCareErdMode::STATUS_COMPLETED,
                MaternalCareErdMode::STATUS_TRANS_OUT,
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        $presented = $this->toPresentation($row, $this->sequenceMap($resident->getKey()));

        return DemoMaternalCare::isVisibleInPregnancyHistory($presented) ? $presented : null;
    }

    public function parsePregnancyId(string $pregnancyId): ?int
    {
        if (preg_match('/^MC-(\d+)$/i', strtoupper(trim($pregnancyId)), $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return $id > 0 ? $id : null;
    }

    private function activeRow(int|string $residentId): ?object
    {
        return DB::table('maternal_care')
            ->where('resident_id', $residentId)
            ->where('pregnancy_status', MaternalCareErdMode::STATUS_ACTIVE)
            ->orderByDesc('maternal_care_id')
            ->first();
    }

    private function latestCompletedRow(int|string $residentId): ?object
    {
        return DB::table('maternal_care')
            ->where('resident_id', $residentId)
            ->where('pregnancy_status', MaternalCareErdMode::STATUS_COMPLETED)
            ->orderByDesc('maternal_care_id')
            ->first();
    }

    private function writableRow(int|string $residentId, string $section): ?object
    {
        $section = strtolower(trim($section));
        $active = $this->activeRow($residentId);
        if (DemoMaternalCare::sectionAllowsCompletedEpisode($section)) {
            return $active ?? $this->latestCompletedRow($residentId);
        }

        return $active;
    }

    /**
     * @return array<int, int>
     */
    private function sequenceMap(int|string $residentId): array
    {
        $ids = DB::table('maternal_care')
            ->where('resident_id', $residentId)
            ->orderBy('created_at')
            ->orderBy('maternal_care_id')
            ->pluck('maternal_care_id');

        $map = [];
        $n = 1;
        foreach ($ids as $id) {
            $map[(int) $id] = $n;
            $n++;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentationById(int|string $residentId, int $maternalCareId): array
    {
        $row = DB::table('maternal_care')
            ->where('resident_id', $residentId)
            ->where('maternal_care_id', $maternalCareId)
            ->first();

        if ($row === null) {
            return DemoMaternalCare::present(DemoMaternalCare::buildRegistrationPregnancy([], 1, sprintf('MC-%03d', $maternalCareId)));
        }

        return $this->toPresentation($row, $this->sequenceMap($residentId));
    }

    /**
     * @param  array<int, int>  $sequences
     * @return array<string, mixed>
     */
    private function toPresentation(object $row, array $sequences): array
    {
        $id = (int) ($row->maternal_care_id ?? 0);
        $status = $this->presentationStatus((string) ($row->pregnancy_status ?? ''));
        $systolic = $row->bp_systolic ?? null;
        $diastolic = $row->bp_diastolic ?? null;
        $bp = ($systolic !== null && $diastolic !== null)
            ? ((string) $systolic).'/'.((string) $diastolic)
            : '';

        $raw = DemoMaternalCare::buildRegistrationPregnancy([
            'lmp' => (string) ($row->lmp_date ?? ''),
            'edd' => (string) ($row->edd ?? ''),
            'gravida' => $row->gravida ?? '',
            'parity' => $row->parity ?? '',
            'weight' => $row->weight_kg ?? '',
            'height' => $row->height_cm ?? '',
            'blood_pressure' => $bp,
        ], $sequences[$id] ?? 1, sprintf('MC-%03d', $id));

        $raw['status'] = $status;
        $raw['registered_at'] = $this->dateString($row->created_at ?? null);
        $raw['bmi'] = $this->bmiDisplay($raw['weight'], $raw['height']);
        $raw['prenatal'] = $this->loadPrenatal($id, is_array($raw['prenatal'] ?? null) ? $raw['prenatal'] : []);
        $raw['supplementations'] = $this->loadSupplementations($id, is_array($raw['supplementations'] ?? null) ? $raw['supplementations'] : []);
        $raw['laboratory'] = $this->loadLaboratory($id, is_array($raw['laboratory'] ?? null) ? $raw['laboratory'] : []);
        $raw['delivery'] = $this->loadDelivery($id, is_array($raw['delivery'] ?? null) ? $raw['delivery'] : []);
        $raw['postnatal'] = $this->loadPostnatal($id, is_array($raw['postnatal'] ?? null) ? $raw['postnatal'] : []);
        $raw['immunizations'] = is_array($raw['immunizations'] ?? null) ? $raw['immunizations'] : [];
        $raw['trans_out'] = is_array($raw['trans_out'] ?? null) ? $raw['trans_out'] : [];

        return DemoMaternalCare::present($raw);
    }

    private function presentationStatus(string $erdStatus): string
    {
        return match ($erdStatus) {
            MaternalCareErdMode::STATUS_ACTIVE => 'active',
            MaternalCareErdMode::STATUS_TRANS_OUT => 'transferred_out',
            MaternalCareErdMode::STATUS_COMPLETED => 'completed',
            default => 'completed',
        };
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function loadPrenatal(int $maternalCareId, array $current): array
    {
        if (! Schema::hasTable('prenatal_visits')) {
            return $current;
        }

        $rows = DB::table('prenatal_visits')
            ->where('maternal_care_id', $maternalCareId)
            ->orderBy('prenatal_visit_id')
            ->get();

        foreach ($rows as $row) {
            $key = $this->prenatalKey((string) ($row->trimester ?? ''), (int) ($row->visit_number ?? 0));
            if ($key === null || ! array_key_exists($key, $current)) {
                continue;
            }
            $height = $this->decimalDisplay($row->height_cm ?? null);
            $weight = $this->decimalDisplay($row->weight_kg ?? null);
            $current[$key] = [
                'date' => $this->dateString($row->visit_date ?? null),
                'height' => $height,
                'weight' => $weight,
                'bmi' => $this->bmiDisplay($weight, $height),
                'bp' => $this->formatBp($row->bp_systolic ?? null, $row->bp_diastolic ?? null),
            ];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function loadSupplementations(int $maternalCareId, array $current): array
    {
        if (Schema::hasTable('deworming_supplementation')) {
            $deworming = DB::table('deworming_supplementation')
                ->where('maternal_care_id', $maternalCareId)
                ->orderBy('deworming_supp_id')
                ->first();
            $current['deworming_date'] = $deworming
                ? $this->dateString($deworming->date_given ?? null)
                : ($current['deworming_date'] ?? '');
        }

        $current['ifa'] = $this->loadVisitTable(
            'ifa_supplementation',
            $maternalCareId,
            is_array($current['ifa'] ?? null) ? $current['ifa'] : [],
            6
        );
        $current['mms'] = $this->loadVisitTable(
            'mms_supplementation',
            $maternalCareId,
            is_array($current['mms'] ?? null) ? $current['mms'] : [],
            6
        );
        $current['calcium'] = $this->loadVisitTable(
            'cc_supplementation',
            $maternalCareId,
            is_array($current['calcium'] ?? null) ? $current['calcium'] : [],
            3
        );

        $current['rusf'] = [];
        if (Schema::hasTable('rusf_supplementation')) {
            $rows = DB::table('rusf_supplementation')
                ->where('maternal_care_id', $maternalCareId)
                ->orderBy('date_given')
                ->orderBy('rusf_supp_id')
                ->get();
            foreach ($rows as $row) {
                $current['rusf'][] = [
                    'id' => (int) $row->rusf_supp_id,
                    'date' => $this->dateString($row->date_given ?? null),
                ];
            }
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function loadVisitTable(string $table, int $maternalCareId, array $current, int $max): array
    {
        if (! Schema::hasTable($table)) {
            return $current;
        }

        $rows = DB::table($table)->where('maternal_care_id', $maternalCareId)->get();
        foreach ($rows as $row) {
            $n = (int) ($row->visit_number ?? 0);
            if ($n < 1 || $n > $max) {
                continue;
            }
            $key = 'v'.$n;
            $current[$key] = [
                'date' => $this->dateString($row->date_given ?? null),
                'tablets' => isset($row->tablets_given) && $row->tablets_given !== null
                    ? (string) (int) $row->tablets_given
                    : '',
            ];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function loadLaboratory(int $maternalCareId, array $current): array
    {
        $dateResult = [
            'hepatitis_b' => 'hepatitis_b_screening',
            'cbc' => 'cbc_hgb_hct_screening',
            'gdm' => 'gdm_screening',
            'syphilis' => 'syphilis_screening',
            'hiv' => 'hiv_screening',
        ];
        foreach ($dateResult as $key => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $row = DB::table($table)->where('maternal_care_id', $maternalCareId)->first();
            if ($row === null) {
                continue;
            }
            if (AtRestColumns::isEncrypted($table, 'result')) {
                $row = AtRestRecord::openRow($table, $row);
            }
            $current[$key] = [
                'date' => $this->dateString($row->date_screened ?? null),
                'result' => (string) ($row->result ?? ''),
            ];
        }

        $dateOnly = [
            'urinalysis' => 'urinalysis_screening',
            'ultrasound' => 'ultrasound_screening',
        ];
        foreach ($dateOnly as $key => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $row = DB::table($table)->where('maternal_care_id', $maternalCareId)->first();
            if ($row === null) {
                continue;
            }
            $current[$key] = [
                'date' => $this->dateString($row->date_screened ?? null),
            ];
        }

        $numeric = [
            'cvc' => 'cvc_screening',
            'gestational' => 'gestational_screening',
        ];
        foreach ($numeric as $key => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $row = DB::table($table)->where('maternal_care_id', $maternalCareId)->first();
            if ($row === null) {
                continue;
            }
            $current[$key] = [
                'value' => $this->decimalDisplay($row->value ?? null),
            ];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function loadDelivery(int $maternalCareId, array $current): array
    {
        $row = $this->deliveryRow($maternalCareId);
        if ($row === null) {
            return $current;
        }

        $datetime = $this->dateTimeLocal($row->date_time_of_delivery ?? null);
        $bemonc = $row->bemonc_cemonc_capable ?? null;

        $outcome = (string) ($row->outcome ?? '');
        $terminated = $this->dateString($row->date_terminated ?? null);

        $current['outcome'] = $outcome;
        $current['delivery_type'] = (string) ($row->delivery_type ?? '');
        $current['birth_weight'] = $this->decimalDisplay($row->birth_weight_kg ?? null);
        $current['status'] = (string) ($row->status ?? '');
        $current['datetime'] = $datetime;
        $current['date_terminated'] = $terminated;
        $current['birth_attendant'] = (string) ($row->birth_attendant ?? '');
        $current['birth_attendant_other'] = (string) ($row->birth_attendant_other ?? '');
        $current['place'] = self::PLACE_FROM_ERD[(string) ($row->place_of_delivery ?? '')] ?? '';
        $current['facility_name'] = (string) ($row->facility_name ?? '');
        $current['bemonc_cemonc'] = $bemonc === null || $bemonc === ''
            ? ''
            : ((int) $bemonc === 1 ? 'Yes' : 'No');
        unset($current['fetal_death_date'], $current['abortion_date']);
        if (Schema::hasColumn('delivery_outcomes', 'newborn_sex')) {
            $current['newborn_sex'] = (string) ($row->newborn_sex ?? '');
        }
        if (Schema::hasColumn('delivery_outcomes', 'plurality')) {
            $current['plurality'] = (string) ($row->plurality ?? '');
        }
        if (Schema::hasColumn('delivery_outcomes', 'plurality_number')) {
            $number = $row->plurality_number ?? null;
            $current['plurality_number'] = $number === null || $number === '' ? '' : (string) (int) $number;
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function loadPostnatal(int $maternalCareId, array $current): array
    {
        $vitaminA = is_array($current['vitamin_a'] ?? null) ? $current['vitamin_a'] : ['date' => ''];
        if (Schema::hasTable('postpartum_vitamin_a_supplementation')) {
            $vitaminARow = DB::table('postpartum_vitamin_a_supplementation')
                ->where('maternal_care_id', $maternalCareId)
                ->first();
            $vitaminA['date'] = $vitaminARow !== null
                ? $this->dateString($vitaminARow->date_given ?? null)
                : '';
        }
        $current['vitamin_a'] = $vitaminA;

        $delivery = $this->deliveryRow($maternalCareId);
        if ($delivery === null) {
            return $current;
        }

        $deliveryId = (int) $delivery->delivery_outcome_id;
        $contacts = is_array($current['contacts'] ?? null) ? $current['contacts'] : [];
        if (Schema::hasTable('postnatal_care_visits')) {
            $rows = DB::table('postnatal_care_visits')
                ->where('delivery_outcome_id', $deliveryId)
                ->get();
            foreach ($rows as $row) {
                $n = (int) ($row->contact_number ?? 0);
                if ($n < 1 || $n > 4) {
                    continue;
                }
                $contacts['c'.$n] = $this->dateString($row->contact_date ?? null);
            }
        }

        $supp = is_array($current['supplementation'] ?? null) ? $current['supplementation'] : [];
        if (Schema::hasTable('postpartum_ifa_supplementation')) {
            $rows = DB::table('postpartum_ifa_supplementation')
                ->where('delivery_outcome_id', $deliveryId)
                ->get();
            foreach ($rows as $row) {
                $n = (int) ($row->visit_number ?? 0);
                if ($n < 1 || $n > 3) {
                    continue;
                }
                $supp['v'.$n] = [
                    'date' => $this->dateString($row->date_given ?? null),
                    'tablets' => isset($row->tablets_given) && $row->tablets_given !== null
                        ? (string) (int) $row->tablets_given
                        : '',
                ];
            }
        }

        $current['contacts'] = $contacts;
        $current['supplementation'] = $supp;

        return $current;
    }

    /**
     * Registration is the first prenatal visit (T1V1) for this maternal_care episode.
     * Does not write generated BMI. Does not create later visit slots.
     *
     * @param  array<string, mixed>  $parentInsert
     */
    private function initializeFirstPrenatalVisit(int $maternalCareId, array $parentInsert, \DateTimeInterface $registeredAt): void
    {
        if (! Schema::hasTable('prenatal_visits')) {
            throw new \RuntimeException(
                'prenatal_visits is required to initialize the first prenatal visit.'
            );
        }

        $existing = DB::table('prenatal_visits')
            ->where('maternal_care_id', $maternalCareId)
            ->where('trimester', '1st')
            ->where('visit_number', 1)
            ->orderBy('prenatal_visit_id')
            ->first();

        if ($existing !== null) {
            return;
        }

        $visitDate = \Carbon\Carbon::parse($registeredAt)->toDateString();
        $insert = [
            'maternal_care_id' => $maternalCareId,
            'trimester' => '1st',
            'visit_number' => 1,
            'visit_date' => $visitDate,
            'weight_kg' => $parentInsert['weight_kg'] ?? null,
            'height_cm' => $parentInsert['height_cm'] ?? null,
            'bp_systolic' => $parentInsert['bp_systolic'] ?? null,
            'bp_diastolic' => $parentInsert['bp_diastolic'] ?? null,
            'created_at' => $registeredAt,
            'updated_at' => $registeredAt,
        ];
        unset($insert['bmi']);

        DB::table('prenatal_visits')->insert($insert);
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
     * @param  array<string, mixed>  $payload
     */
    private function upsertPrenatal(int $maternalCareId, array $payload, Resident $resident): void
    {
        if (! Schema::hasTable('prenatal_visits')) {
            return;
        }

        $visits = is_array($payload['visits'] ?? null) ? $payload['visits'] : $payload;
        if (! is_array($visits)) {
            return;
        }

        $lmp = DB::table('maternal_care')
            ->where('maternal_care_id', $maternalCareId)
            ->value('lmp_date');

        foreach (self::PRENATAL_KEYS as $key => [$trimester, $visitNumber]) {
            if (! array_key_exists($key, $visits) || ! is_array($visits[$key])) {
                continue;
            }
            $incoming = $visits[$key];
            $existing = DB::table('prenatal_visits')
                ->where('maternal_care_id', $maternalCareId)
                ->where('trimester', $trimester)
                ->where('visit_number', $visitNumber)
                ->orderBy('prenatal_visit_id')
                ->first();

            $bp = array_key_exists('bp', $incoming)
                ? $this->parseBp((string) $incoming['bp'])
                : [null, null];

            $fields = ['updated_at' => now()];
            if (array_key_exists('date', $incoming)) {
                $fields['visit_date'] = $this->nullableDate($incoming['date']);
            }
            if (array_key_exists('height', $incoming)) {
                $fields['height_cm'] = $this->nullableDecimal($incoming['height']);
            }
            if (array_key_exists('weight', $incoming)) {
                $fields['weight_kg'] = $this->nullableDecimal($incoming['weight']);
            }
            if (array_key_exists('bp', $incoming)) {
                $fields['bp_systolic'] = $bp[0];
                $fields['bp_diastolic'] = $bp[1];
            }
            unset($fields['bmi']);

            $oldWeight = $existing->weight_kg ?? null;
            $oldHeight = $existing->height_cm ?? null;
            $newWeight = array_key_exists('weight', $incoming)
                ? ($fields['weight_kg'] ?? null)
                : $oldWeight;
            $newHeight = array_key_exists('height', $incoming)
                ? ($fields['height_cm'] ?? null)
                : $oldHeight;
            $eventDate = array_key_exists('date', $incoming)
                ? ($fields['visit_date'] ?? null)
                : ($existing->visit_date ?? null);
            if ($eventDate === null && $existing !== null) {
                $eventDate = $this->dateString($existing->created_at ?? null) ?: null;
            }

            if ($existing === null) {
                if (! DemoMaternalCare::allowsNewPrenatalSlot(is_string($lmp) ? $lmp : null, $key)) {
                    if (DemoMaternalCare::prenatalIncomingHasContent($incoming)) {
                        throw ValidationException::withMessages([
                            "visits.{$key}" => DemoMaternalCare::futurePrenatalUnavailableMessage(
                                DemoMaternalCare::prenatalSlotTrimesterKey($key)
                            ),
                        ]);
                    }

                    continue;
                }
                if (! DemoMaternalCare::prenatalIncomingHasContent($incoming)) {
                    continue;
                }
                $insert = array_merge([
                    'maternal_care_id' => $maternalCareId,
                    'trimester' => $trimester,
                    'visit_number' => $visitNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $fields);
                unset($insert['bmi']);
                DB::table('prenatal_visits')->insert($insert);
                $this->syncTimbangFromMaternalPhysical(
                    $resident,
                    $eventDate ?? $insert['visit_date'] ?? now()->toDateString(),
                    $newWeight,
                    $newHeight,
                );

                continue;
            }

            DB::table('prenatal_visits')
                ->where('prenatal_visit_id', $existing->prenatal_visit_id)
                ->update($fields);

            $this->syncTimbangFromMaternalPhysical(
                $resident,
                $eventDate,
                $newWeight,
                $newHeight,
                $oldWeight,
                $oldHeight,
                true,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertSupplementations(int $maternalCareId, array $payload): void
    {
        if (array_key_exists('deworming_date', $payload) && Schema::hasTable('deworming_supplementation')) {
            $existing = DB::table('deworming_supplementation')
                ->where('maternal_care_id', $maternalCareId)
                ->orderBy('deworming_supp_id')
                ->first();
            $date = $this->nullableDate($payload['deworming_date']);
            if ($existing === null && $date !== null) {
                DB::table('deworming_supplementation')->insert([
                    'maternal_care_id' => $maternalCareId,
                    'date_given' => $date,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($existing !== null) {
                DB::table('deworming_supplementation')
                    ->where('deworming_supp_id', $existing->deworming_supp_id)
                    ->update(['date_given' => $date, 'updated_at' => now()]);
            }
        }

        $lmp = DB::table('maternal_care')
            ->where('maternal_care_id', $maternalCareId)
            ->value('lmp_date');

        $this->upsertSuppGroup('ifa_supplementation', $maternalCareId, $payload['ifa'] ?? null, 6, $lmp, 'ifa');
        $this->upsertSuppGroup('mms_supplementation', $maternalCareId, $payload['mms'] ?? null, 6, $lmp, 'mms');
        $this->upsertSuppGroup('cc_supplementation', $maternalCareId, $payload['calcium'] ?? null, 3, $lmp, 'calcium');

        if (array_key_exists('rusf', $payload)) {
            $this->upsertRusf($maternalCareId, $payload['rusf']);
        }
    }

    /**
     * @param  mixed  $group
     */
    private function upsertRusf(int $maternalCareId, mixed $group): void
    {
        if (! is_array($group) || ! Schema::hasTable('rusf_supplementation')) {
            return;
        }

        foreach ($group as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : 0;
            $date = array_key_exists('date', $item) ? $this->nullableDate($item['date']) : null;

            if ($id > 0) {
                $existing = DB::table('rusf_supplementation')
                    ->where('rusf_supp_id', $id)
                    ->where('maternal_care_id', $maternalCareId)
                    ->first();
                if ($existing === null || $date === null) {
                    continue;
                }

                $currentDate = $this->dateString($existing->date_given ?? null);
                if ($currentDate === $date) {
                    continue;
                }

                $duplicate = DB::table('rusf_supplementation')
                    ->where('maternal_care_id', $maternalCareId)
                    ->where('date_given', $date)
                    ->where('rusf_supp_id', '!=', $id)
                    ->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'rusf' => ['This RUSF date is already recorded for this pregnancy.'],
                    ]);
                }

                DB::table('rusf_supplementation')
                    ->where('rusf_supp_id', $id)
                    ->where('maternal_care_id', $maternalCareId)
                    ->update([
                        'date_given' => $date,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            if ($date === null) {
                continue;
            }

            $exists = DB::table('rusf_supplementation')
                ->where('maternal_care_id', $maternalCareId)
                ->where('date_given', $date)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('rusf_supplementation')->insert([
                'maternal_care_id' => $maternalCareId,
                'date_given' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function upsertSuppGroup(
        string $table,
        int $maternalCareId,
        mixed $group,
        int $max,
        mixed $lmp,
        string $groupKey
    ): void {
        if (! is_array($group) || ! Schema::hasTable($table)) {
            return;
        }

        $lmpDate = $lmp === null || $lmp === '' ? null : (string) $lmp;

        for ($n = 1; $n <= $max; $n++) {
            $key = 'v'.$n;
            if (! array_key_exists($key, $group) || ! is_array($group[$key])) {
                continue;
            }
            $incoming = $group[$key];
            $existing = DB::table($table)
                ->where('maternal_care_id', $maternalCareId)
                ->where('visit_number', $n)
                ->first();

            if ($existing === null
                && ! DemoMaternalCare::allowsNewSupplementationSlot($lmpDate, $groupKey, $key)
            ) {
                if (DemoMaternalCare::supplementationIncomingHasContent($incoming)) {
                    throw ValidationException::withMessages([
                        "{$groupKey}.{$key}" => DemoMaternalCare::futurePrenatalUnavailableMessage(
                            DemoMaternalCare::supplementationVisitTrimesterKey($groupKey, $key)
                        ),
                    ]);
                }

                continue;
            }

            if ($existing === null && ! DemoMaternalCare::supplementationIncomingHasContent($incoming)) {
                continue;
            }

            $fields = ['updated_at' => now()];
            if (array_key_exists('date', $incoming)) {
                $fields['date_given'] = $this->nullableDate($incoming['date']);
            }
            if (array_key_exists('tablets', $incoming)) {
                $fields['tablets_given'] = $this->nullableInt($incoming['tablets']);
            }

            if ($existing === null) {
                DB::table($table)->insert(array_merge([
                    'maternal_care_id' => $maternalCareId,
                    'visit_number' => $n,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $fields));

                continue;
            }

            $pk = match ($table) {
                'ifa_supplementation' => 'ifa_supp_id',
                'mms_supplementation' => 'mms_supp_id',
                default => 'cc_supp_id',
            };
            DB::table($table)->where($pk, $existing->{$pk})->update($fields);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertLaboratory(int $maternalCareId, array $payload): void
    {
        $screens = [
            'hepatitis_b' => [
                'table' => 'hepatitis_b_screening',
                'pk' => 'hep_b_screening_id',
                'shape' => 'date_result',
            ],
            'cbc' => [
                'table' => 'cbc_hgb_hct_screening',
                'pk' => 'cbc_screening_id',
                'shape' => 'date_result',
            ],
            'gdm' => [
                'table' => 'gdm_screening',
                'pk' => 'gdm_screening_id',
                'shape' => 'date_result',
            ],
            'urinalysis' => [
                'table' => 'urinalysis_screening',
                'pk' => 'urinalysis_screening_id',
                'shape' => 'date',
            ],
            'ultrasound' => [
                'table' => 'ultrasound_screening',
                'pk' => 'ultrasound_screening_id',
                'shape' => 'date',
            ],
            'syphilis' => [
                'table' => 'syphilis_screening',
                'pk' => 'syphilis_screening_id',
                'shape' => 'date_result',
            ],
            'hiv' => [
                'table' => 'hiv_screening',
                'pk' => 'hiv_screening_id',
                'shape' => 'date_result',
            ],
            'cvc' => [
                'table' => 'cvc_screening',
                'pk' => 'cvc_screening_id',
                'shape' => 'value',
            ],
            'gestational' => [
                'table' => 'gestational_screening',
                'pk' => 'gestational_screening_id',
                'shape' => 'value',
            ],
        ];

        foreach ($screens as $key => $meta) {
            $table = $meta['table'];
            $pk = $meta['pk'];
            if (! array_key_exists($key, $payload) || ! is_array($payload[$key]) || ! Schema::hasTable($table)) {
                continue;
            }
            $incoming = $payload[$key];
            $existing = DB::table($table)->where('maternal_care_id', $maternalCareId)->first();
            if ($existing === null && ! DemoMaternalCare::laboratoryIncomingHasContent($incoming)) {
                continue;
            }
            $fields = ['updated_at' => now()];
            if ($meta['shape'] === 'date' || $meta['shape'] === 'date_result') {
                if (array_key_exists('date', $incoming)) {
                    $fields['date_screened'] = $this->nullableDate($incoming['date']);
                }
            }
            if ($meta['shape'] === 'date_result' && array_key_exists('result', $incoming)) {
                $fields['result'] = $this->nullableText($incoming['result']);
                if (AtRestColumns::isEncrypted($table, 'result')) {
                    $fields['result'] = AtRestRecord::seal($fields['result'], $table, 'result');
                }
            }
            if ($meta['shape'] === 'value' && array_key_exists('value', $incoming)) {
                $fields['value'] = $this->nullableDecimal($incoming['value']);
            }

            if ($existing === null) {
                DB::table($table)->insert(array_merge([
                    'maternal_care_id' => $maternalCareId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $fields));

                continue;
            }

            DB::table($table)->where($pk, $existing->{$pk})->update($fields);
        }
    }

    /**
     * Sparse delivery update. Does not wipe unspecified columns or child postnatal rows.
     *
     * @param  array<string, mixed>  $payload
     */
    private function upsertDeliveryAndComplete(int $maternalCareId, array $payload): void
    {
        $this->upsertDelivery($maternalCareId, $payload);

        $row = $this->deliveryRow($maternalCareId);
        $outcome = (string) ($row->outcome ?? '');
        if (DemoMaternalCare::isTerminalOutcome($outcome)) {
            $this->markCompleted($maternalCareId);
        }
    }

    private function upsertDelivery(int $maternalCareId, array $payload): void
    {
        if (! Schema::hasTable('delivery_outcomes')) {
            return;
        }

        $fields = $this->deliveryFieldsFromPayload($payload);
        if ($fields === []) {
            return;
        }

        $existing = $this->deliveryRow($maternalCareId);
        if ($existing === null && ! self::deliveryFieldsHaveContent($fields)) {
            return;
        }
        $fields['updated_at'] = now();

        if ($existing === null) {
            DB::table('delivery_outcomes')->insert(array_merge([
                'maternal_care_id' => $maternalCareId,
                'created_at' => now(),
                'updated_at' => now(),
            ], $fields));

            return;
        }

        DB::table('delivery_outcomes')
            ->where('delivery_outcome_id', $existing->delivery_outcome_id)
            ->update($fields);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function deliveryFieldsFromPayload(array $payload): array
    {
        $fields = [];

        $outcome = null;
        if (array_key_exists('outcome', $payload)) {
            $outcome = trim((string) $payload['outcome']);
            $fields['outcome'] = array_key_exists($outcome, DemoMaternalCare::OUTCOMES) ? $outcome : null;
            $outcome = $fields['outcome'];
        }
        if (array_key_exists('delivery_type', $payload)) {
            $type = trim((string) $payload['delivery_type']);
            $fields['delivery_type'] = array_key_exists($type, DemoMaternalCare::DELIVERY_TYPES) ? $type : null;
        }
        if (array_key_exists('birth_weight', $payload)) {
            $fields['birth_weight_kg'] = $this->nullableDecimal($payload['birth_weight']);
        }
        if (array_key_exists('status', $payload)) {
            $fields['status'] = $this->nullableText($payload['status']);
        }
        if (array_key_exists('datetime', $payload)) {
            $fields['date_time_of_delivery'] = $this->nullableDateTime($payload['datetime']);
        }
        if (array_key_exists('date_terminated', $payload)) {
            $fields['date_terminated'] = $this->nullableDate($payload['date_terminated']);
        }
        if (array_key_exists('birth_attendant', $payload)) {
            $attendant = trim((string) $payload['birth_attendant']);
            $fields['birth_attendant'] = array_key_exists($attendant, DemoMaternalCare::BIRTH_ATTENDANTS)
                ? $attendant
                : null;
        }
        if (array_key_exists('birth_attendant_other', $payload)) {
            $fields['birth_attendant_other'] = $this->nullableText($payload['birth_attendant_other']);
        }
        if (array_key_exists('place', $payload)) {
            $place = trim((string) $payload['place']);
            $fields['place_of_delivery'] = self::PLACE_TO_ERD[$place] ?? null;
        }
        if (array_key_exists('facility_name', $payload)) {
            $fields['facility_name'] = $this->nullableText($payload['facility_name']);
        }
        if (array_key_exists('bemonc_cemonc', $payload)) {
            $raw = trim((string) $payload['bemonc_cemonc']);
            $fields['bemonc_cemonc_capable'] = $raw === 'Yes' ? 1 : ($raw === 'No' ? 0 : null);
        }
        if (Schema::hasColumn('delivery_outcomes', 'newborn_sex') && array_key_exists('newborn_sex', $payload)) {
            $sex = trim((string) $payload['newborn_sex']);
            $fields['newborn_sex'] = array_key_exists($sex, DemoMaternalCare::NEWBORN_SEXES) ? $sex : null;
        }
        if (Schema::hasColumn('delivery_outcomes', 'plurality') && array_key_exists('plurality', $payload)) {
            $plurality = trim((string) $payload['plurality']);
            $fields['plurality'] = array_key_exists($plurality, DemoMaternalCare::PLURALITIES) ? $plurality : null;
            if (Schema::hasColumn('delivery_outcomes', 'plurality_number')) {
                $fields['plurality_number'] = $fields['plurality'] === 'Multiple'
                    ? $this->nullableInt($payload['plurality_number'] ?? null)
                    : null;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function deliveryFieldsHaveContent(array $fields): bool
    {
        foreach ($fields as $value) {
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertPostnatal(int $maternalCareId, array $payload): void
    {
        $this->upsertPostpartumVitaminA($maternalCareId, $payload);

        $needsDelivery = array_key_exists('contacts', $payload) || array_key_exists('supplementation', $payload);
        if (! $needsDelivery || ! Schema::hasTable('delivery_outcomes')) {
            return;
        }

        $delivery = $this->deliveryRow($maternalCareId);
        if ($delivery === null) {
            if (! $this->postnatalPayloadHasMeaningfulChild($payload)) {
                return;
            }
            DB::table('delivery_outcomes')->insert([
                'maternal_care_id' => $maternalCareId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $delivery = $this->deliveryRow($maternalCareId);
        }
        if ($delivery === null) {
            return;
        }

        $deliveryId = (int) $delivery->delivery_outcome_id;

        if (array_key_exists('contacts', $payload) && is_array($payload['contacts']) && Schema::hasTable('postnatal_care_visits')) {
            foreach (DemoMaternalCare::postnatalContacts() as $index => $contact) {
                $key = $contact['key'];
                if (! array_key_exists($key, $payload['contacts'])) {
                    continue;
                }
                $n = $index + 1;
                $date = $this->nullableDate($payload['contacts'][$key]);
                $existing = DB::table('postnatal_care_visits')
                    ->where('delivery_outcome_id', $deliveryId)
                    ->where('contact_number', $n)
                    ->first();
                if ($existing === null) {
                    if ($date === null) {
                        continue;
                    }
                    DB::table('postnatal_care_visits')->insert([
                        'delivery_outcome_id' => $deliveryId,
                        'contact_number' => $n,
                        'contact_date' => $date,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('postnatal_care_visits')
                        ->where('pnc_visit_id', $existing->pnc_visit_id)
                        ->update(['contact_date' => $date, 'updated_at' => now()]);
                }
            }
        }

        if (array_key_exists('supplementation', $payload) && is_array($payload['supplementation']) && Schema::hasTable('postpartum_ifa_supplementation')) {
            foreach (DemoMaternalCare::postpartumSupplementationVisits() as $index => $visit) {
                $key = $visit['key'];
                if (! array_key_exists($key, $payload['supplementation']) || ! is_array($payload['supplementation'][$key])) {
                    continue;
                }
                $n = $index + 1;
                $incoming = $payload['supplementation'][$key];
                $existing = DB::table('postpartum_ifa_supplementation')
                    ->where('delivery_outcome_id', $deliveryId)
                    ->where('visit_number', $n)
                    ->first();
                $fields = ['updated_at' => now()];
                if (array_key_exists('date', $incoming)) {
                    $fields['date_given'] = $this->nullableDate($incoming['date']);
                }
                if (array_key_exists('tablets', $incoming)) {
                    $fields['tablets_given'] = $this->nullableInt($incoming['tablets']);
                }
                if ($existing === null) {
                    if (! DemoMaternalCare::supplementationIncomingHasContent($incoming)) {
                        continue;
                    }
                    DB::table('postpartum_ifa_supplementation')->insert(array_merge([
                        'delivery_outcome_id' => $deliveryId,
                        'visit_number' => $n,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $fields));
                } else {
                    DB::table('postpartum_ifa_supplementation')
                        ->where('postpartum_ifa_id', $existing->postpartum_ifa_id)
                        ->update($fields);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postnatalPayloadHasMeaningfulChild(array $payload): bool
    {
        if (array_key_exists('contacts', $payload) && is_array($payload['contacts'])) {
            foreach (DemoMaternalCare::postnatalContacts() as $contact) {
                $key = $contact['key'];
                if (! array_key_exists($key, $payload['contacts'])) {
                    continue;
                }
                if ($this->nullableDate($payload['contacts'][$key]) !== null) {
                    return true;
                }
            }
        }

        if (array_key_exists('supplementation', $payload) && is_array($payload['supplementation'])) {
            foreach (DemoMaternalCare::postpartumSupplementationVisits() as $visit) {
                $key = $visit['key'];
                if (! array_key_exists($key, $payload['supplementation']) || ! is_array($payload['supplementation'][$key])) {
                    continue;
                }
                if (DemoMaternalCare::supplementationIncomingHasContent($payload['supplementation'][$key])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertPostpartumVitaminA(int $maternalCareId, array $payload): void
    {
        if (! Schema::hasTable('postpartum_vitamin_a_supplementation')) {
            return;
        }
        if (! array_key_exists('vitamin_a', $payload) || ! is_array($payload['vitamin_a'])) {
            return;
        }

        $incoming = $payload['vitamin_a'];
        if (! array_key_exists('date', $incoming)) {
            return;
        }

        $date = $this->nullableDate($incoming['date'] ?? null);
        $existing = DB::table('postpartum_vitamin_a_supplementation')
            ->where('maternal_care_id', $maternalCareId)
            ->first();

        if ($existing === null) {
            if ($date === null) {
                return;
            }

            DB::table('postpartum_vitamin_a_supplementation')->insert([
                'maternal_care_id' => $maternalCareId,
                'date_given' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('postpartum_vitamin_a_supplementation')
            ->where('postpartum_vitamin_a_id', $existing->postpartum_vitamin_a_id)
            ->update([
                'date_given' => $date,
                'updated_at' => now(),
            ]);
    }

    private function markTransferredOut(int $maternalCareId): void
    {
        DB::table('maternal_care')
            ->where('maternal_care_id', $maternalCareId)
            ->where('pregnancy_status', MaternalCareErdMode::STATUS_ACTIVE)
            ->update([
                'pregnancy_status' => MaternalCareErdMode::STATUS_TRANS_OUT,
                'updated_at' => now(),
            ]);
    }

    private function markCompleted(int $maternalCareId): void
    {
        DB::table('maternal_care')
            ->where('maternal_care_id', $maternalCareId)
            ->whereIn('pregnancy_status', [
                MaternalCareErdMode::STATUS_ACTIVE,
                MaternalCareErdMode::STATUS_COMPLETED,
            ])
            ->update([
                'pregnancy_status' => MaternalCareErdMode::STATUS_COMPLETED,
                'updated_at' => now(),
            ]);
    }

    private function deliveryRow(int $maternalCareId): ?object
    {
        if (! Schema::hasTable('delivery_outcomes')) {
            return null;
        }

        return DB::table('delivery_outcomes')
            ->where('maternal_care_id', $maternalCareId)
            ->orderBy('delivery_outcome_id')
            ->first();
    }

    private function prenatalKey(string $trimester, int $visitNumber): ?string
    {
        foreach (self::PRENATAL_KEYS as $key => [$tri, $num]) {
            if ($tri === $trimester && $num === $visitNumber) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function parseBp(string $raw): array
    {
        if (! preg_match('/^\s*(\d+)\s*\/\s*(\d+)\s*$/', $raw, $m)) {
            return [null, null];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function formatBp(mixed $systolic, mixed $diastolic): string
    {
        if ($systolic === null || $diastolic === null || $systolic === '' || $diastolic === '') {
            return '';
        }

        return ((string) $systolic).'/'.((string) $diastolic);
    }

    /**
     * PHP display authority is RiskAssessmentClinicalValues::calculateBmi().
     * ERD generated BMI is never written; this does not invent a classification.
     */
    private function bmiDisplay(mixed $weight, mixed $height): string
    {
        return RiskAssessmentClinicalValues::calculateBmi($height, $weight) ?? '';
    }

    private function decimalDisplay(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return is_numeric($value) ? (string) $value : '';
    }

    private function dateString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }

    private function dateTimeLocal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private function estimateEdd(mixed $lmp): ?string
    {
        $raw = $this->nullableDate($lmp);
        if ($raw === null) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw)->addDays(280)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableDate(mixed $value): ?string
    {
        $raw = is_string($value) ? trim($value) : (is_numeric($value) ? (string) $value : '');
        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            return null;
        }

        return substr($raw, 0, 10);
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($raw)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (string) $value : null;
    }

    private function nullableText(mixed $value): ?string
    {
        $raw = is_string($value) ? trim($value) : '';

        return $raw === '' ? null : $raw;
    }
}
