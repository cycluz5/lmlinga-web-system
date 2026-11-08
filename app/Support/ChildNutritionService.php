<?php

namespace App\Support;

use App\Models\ChildNutrition;
use App\Models\ChildNutritionSfpOutcome;
use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * DB-10 Phase 2 — Child Nutrition worksheet read/write for persisted residents.
 *
 * Canonical identity is residents.id. One worksheet record per resident (1:1).
 * Does not touch child_birth_histories. Does not derive COMPLETED chips or
 * status-panel demo stamps.
 *
 * Persistence writes only meaningful submitted values: blank/untouched fields
 * do not create placeholder child rows and do not null-out existing data.
 */
final class ChildNutritionService
{
    /**
     * Form-key → model-column map for top-level date/number worksheet fields.
     *
     * @var array<string, array{column: string, type: 'decimal'|'date'}>
     */
    private const NEWBORN_FIELDS = [
        'length' => ['column' => 'newborn_length_cm', 'type' => 'decimal'],
        'weight' => ['column' => 'newborn_weight_kg', 'type' => 'decimal'],
        'breastfeeding_date' => ['column' => 'newborn_breastfeeding_date', 'type' => 'date'],
    ];

    /**
     * @var array<string, string>
     */
    private const IRON_FIELDS = [
        '1st' => 'iron_1st_date',
        '2nd' => 'iron_2nd_date',
        '3rd' => 'iron_3rd_date',
    ];

    /**
     * @var array<string, string>
     */
    private const VITAMIN_A_FIELDS = [
        'va-6-11' => 'vitamin_a_va_6_11_date',
        'va-12-59-1' => 'vitamin_a_va_12_59_1_date',
        'va-12-59-2' => 'vitamin_a_va_12_59_2_date',
    ];

    /**
     * @var array<string, string>
     */
    private const MNP_FIELDS = [
        'mnp-6-11' => 'mnp_6_11_date',
        'mnp-12-23' => 'mnp_12_23_date',
    ];

    /**
     * @var array<string, string>
     */
    private const LNS_FIELDS = [
        'lns-6-11' => 'lns_sq_6_11_date',
        'lns-12-23' => 'lns_sq_12_23_date',
    ];

    /**
     * Read contract for Blade hydration. Does not require DB rows when empty.
     *
     * @return array{
     *     persisted: bool,
     *     newborn: array{length: string, weight: string, breastfeeding_date: string},
     *     iron: array{1st: string, 2nd: string, 3rd: string},
     *     vitamin_a: array{va-6-11: string, va-12-59-1: string, va-12-59-2: string},
     *     mnp: array{mnp-6-11: string, mnp-12-23: string},
     *     lns_sq: array{lns-6-11: string, lns-12-23: string},
     *     mam: array<string, array{date: string, action: string}>,
     *     sam: array<string, array{date: string, action: string}>
     * }
     */
    public function forResident(Resident $resident): array
    {
        if ((int) $resident->id <= 0) {
            abort(404, 'Resident was not found.');
        }

        if (! Schema::hasTable('child_nutritions') && ! Schema::hasTable('child_nutrition')) {
            return $this->emptyState();
        }

        if (ChildNutritionErdMode::isActive()) {
            return $this->forResidentFromErd($resident);
        }

        $record = $resident->childNutrition()
            ->with('sfpOutcomes')
            ->first();

        if ($record === null) {
            return $this->emptyState();
        }

        return $this->toReadContract($record);
    }

    /**
     * Atomic upsert of nutrition worksheet + SFP outcome slots for a persisted resident.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveForResident(Resident $resident, array $payload): ?ChildNutrition
    {
        HouseholdProfilingWriteGuard::rejectChildNutritionWrite();

        if ((int) $resident->id <= 0) {
            abort(404, 'Resident was not found.');
        }

        if (ChildNutritionErdMode::isActive()) {
            $this->saveErdForResident($resident, $payload);

            return ChildNutrition::query()
                ->where(ChildNutritionErdMode::residentForeignKey(), $resident->getKey())
                ->orderBy(ChildNutritionErdMode::nutritionPrimaryKey())
                ->first();
        }

        $attributes = $this->meaningfulAttributes(
            $this->normalizeWorksheetAttributes($payload)
        );
        $sfpSlots = array_values(array_filter(
            $this->normalizeSfpSlots($payload),
            fn (array $slot): bool => $this->sfpSlotIsMeaningful($slot)
        ));

        if ($attributes === [] && $sfpSlots === []) {
            return ChildNutrition::query()->where('resident_id', $resident->id)->first();
        }

        try {
            return DB::transaction(function () use ($resident, $attributes, $sfpSlots): ChildNutrition {
                $record = ChildNutrition::query()->firstOrNew([
                    'resident_id' => $resident->id,
                ]);

                if ($attributes !== []) {
                    $record->fill($attributes);
                }

                // Persist header when creating, or when worksheet columns actually change.
                if (! $record->exists || $attributes !== []) {
                    $record->save();
                }

                foreach ($sfpSlots as $slot) {
                    $this->upsertSfpSlot(
                        $record,
                        $slot['program'],
                        $slot['outcome'],
                        $slot['outcome_date'],
                        $slot['action_yes_no'],
                    );
                }

                return $record->fresh(['sfpOutcomes']) ?? $record;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateSfpViolation($e)) {
                throw ValidationException::withMessages([
                    'mam' => 'A duplicate supplementary feeding program outcome was submitted.',
                ]);
            }

            throw $e;
        }
    }

    /**
     * @return array{
     *     persisted: bool,
     *     newborn: array{length: string, weight: string, breastfeeding_date: string},
     *     iron: array{1st: string, 2nd: string, 3rd: string},
     *     vitamin_a: array{va-6-11: string, va-12-59-1: string, va-12-59-2: string},
     *     mnp: array{mnp-6-11: string, mnp-12-23: string},
     *     lns_sq: array{lns-6-11: string, lns-12-23: string},
     *     mam: array<string, array{date: string, action: string}>,
     *     sam: array<string, array{date: string, action: string}>
     * }
     */
    public function emptyState(): array
    {
        return [
            'persisted' => false,
            'newborn' => [
                'length' => '',
                'weight' => '',
                'breastfeeding_date' => '',
            ],
            'iron' => [
                '1st' => '',
                '2nd' => '',
                '3rd' => '',
            ],
            'vitamin_a' => [
                'va-6-11' => '',
                'va-12-59-1' => '',
                'va-12-59-2' => '',
            ],
            'mnp' => [
                'mnp-6-11' => '',
                'mnp-12-23' => '',
            ],
            'lns_sq' => [
                'lns-6-11' => '',
                'lns-12-23' => '',
            ],
            'mam' => $this->emptySfpProgram(),
            'sam' => $this->emptySfpProgram(),
        ];
    }

    /**
     * Authoritative ERD child_nutrition read path.
     *
     * If multiple headers exist for the same resident, hydrate the earliest
     * header only. Duplicate headers are not merged or deleted.
     */
    private function forResidentFromErd(Resident $resident): array
    {
        $row = $this->erdHeaderRow($resident);

        if ($row === null) {
            return $this->emptyState();
        }

        $state = $this->emptyState();
        $state['persisted'] = true;
        $state['newborn'] = [
            'length' => $this->formatDecimal($row->length_at_birth_cm ?? null),
            'weight' => $this->formatDecimal($row->weight_at_birth_kg ?? null),
            'breastfeeding_date' => $this->formatDate($row->initiated_breastfeeding_date ?? null),
        ];

        $headerId = (int) $row->{ChildNutritionErdMode::nutritionPrimaryKey()};
        $state['iron'] = $this->hydrateErdIron($headerId, $state['iron']);
        $state = $this->hydrateErdSupplementation($headerId, $state);
        $state['mam'] = $this->hydrateErdMalnutrition($headerId, 'mam', $state['mam']);
        $state['sam'] = $this->hydrateErdMalnutrition($headerId, 'sam', $state['sam']);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveErdForResident(Resident $resident, array $payload): void
    {
        $this->assertSingleErdHeader($resident);

        $newborn = $this->meaningfulAttributes($this->normalizeErdNewborn($payload));
        $ironDates = $this->meaningfulAttributes(
            $this->normalizeDateGroup($payload['iron'] ?? null, self::IRON_FIELDS, 'iron')
        );
        $sfpSlots = array_values(array_filter(
            $this->normalizeSfpSlots($payload),
            fn (array $slot): bool => $this->sfpSlotIsMeaningful($slot)
        ));
        $supplementSlots = $this->meaningfulSupplementationSlots($payload);

        if (
            $newborn === []
            && $ironDates === []
            && $sfpSlots === []
            && $supplementSlots === []
        ) {
            return;
        }

        try {
            DB::transaction(function () use ($resident, $newborn, $ironDates, $sfpSlots, $supplementSlots): void {
                $headerId = $this->resolveOrCreateErdHeader($resident, $newborn);
                $this->upsertErdIronSlots($headerId, $ironDates);
                $this->upsertErdSupplementationSlots($headerId, $supplementSlots);
                $this->upsertErdMalnutritionSlots($headerId, $sfpSlots);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateErdViolation($e)) {
                throw ValidationException::withMessages([
                    'nutrition' => 'A duplicate child nutrition slot was submitted.',
                ]);
            }

            throw $e;
        }
    }

    private function assertSingleErdHeader(Resident $resident): void
    {
        $count = DB::table(ChildNutritionErdMode::nutritionTable())
            ->where(ChildNutritionErdMode::residentForeignKey(), $resident->getKey())
            ->count();

        if ($count > 1) {
            throw ValidationException::withMessages([
                'nutrition' => 'Multiple child nutrition records exist for this resident. Saving is blocked until the duplicate is resolved.',
            ]);
        }
    }

    /**
     * @param  array{length_at_birth_cm?: ?string, weight_at_birth_kg?: ?string, initiated_breastfeeding_date?: ?string}  $newborn
     */
    private function resolveOrCreateErdHeader(Resident $resident, array $newborn): int
    {
        $table = ChildNutritionErdMode::nutritionTable();
        $pk = ChildNutritionErdMode::nutritionPrimaryKey();
        $existing = $this->erdHeaderRow($resident);
        $now = now();

        if ($existing !== null) {
            if ($newborn !== []) {
                DB::table($table)
                    ->where($pk, $existing->{$pk})
                    ->update(array_merge($newborn, ['updated_at' => $now]));
            }

            return (int) $existing->{$pk};
        }

        return (int) DB::table($table)->insertGetId(array_merge($newborn, [
            ChildNutritionErdMode::residentForeignKey() => $resident->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]), $pk);
    }

    private function erdHeaderRow(Resident $resident): ?object
    {
        return DB::table(ChildNutritionErdMode::nutritionTable())
            ->where(ChildNutritionErdMode::residentForeignKey(), $resident->getKey())
            ->orderBy(ChildNutritionErdMode::nutritionPrimaryKey())
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{length_at_birth_cm: ?string, weight_at_birth_kg: ?string, initiated_breastfeeding_date: ?string}
     */
    private function normalizeErdNewborn(array $payload): array
    {
        $newborn = is_array($payload['newborn'] ?? null) ? $payload['newborn'] : [];

        return [
            'length_at_birth_cm' => $this->nullableDecimal($newborn['length'] ?? null, 'newborn.length'),
            'weight_at_birth_kg' => $this->nullableDecimal($newborn['weight'] ?? null, 'newborn.weight'),
            'initiated_breastfeeding_date' => $this->nullableDate(
                $newborn['breastfeeding_date'] ?? null,
                'newborn.breastfeeding_date'
            ),
        ];
    }

    /**
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    private function hydrateErdIron(int $headerId, array $current): array
    {
        if (! Schema::hasTable(ChildNutritionErdMode::IRON_TABLE)) {
            return $current;
        }

        $rows = DB::table(ChildNutritionErdMode::IRON_TABLE)
            ->where('child_nutrition_id', $headerId)
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->month_number);

        foreach (ChildNutritionErdMode::IRON_UI_TO_MONTH as $uiKey => $month) {
            $row = $rows->get($month);
            $current[$uiKey] = $this->formatDate($row?->date_given ?? null);
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function hydrateErdSupplementation(int $headerId, array $state): array
    {
        if (! ChildNutritionErdMode::usesSupplementationTable()) {
            return $state;
        }

        $rows = DB::table(ChildNutritionErdMode::supplementationTable())
            ->where('child_nutrition_id', $headerId)
            ->get();

        foreach ($this->supplementationSlots() as $slot) {
            $match = $rows->first(function (object $row) use ($slot): bool {
                return (string) $row->supplement_type === $slot['type']
                    && (string) $row->age_group === $slot['age']
                    && (int) $row->dose_number === $slot['dose'];
            });

            $state[$slot['group']][$slot['key']] = $this->formatDate($match->date_given ?? null);
        }

        return $state;
    }

    /**
     * @param  array<string, array{date: string, action: string}>  $current
     * @return array<string, array{date: string, action: string}>
     */
    private function hydrateErdMalnutrition(int $headerId, string $program, array $current): array
    {
        if (! Schema::hasTable(ChildNutritionErdMode::MALNUTRITION_TABLE)) {
            return $current;
        }

        $type = ChildNutritionErdMode::MALNUTRITION_UI_TO_TYPE[$program] ?? null;
        if ($type === null) {
            return $current;
        }

        $rows = DB::table(ChildNutritionErdMode::MALNUTRITION_TABLE)
            ->where('child_nutrition_id', $headerId)
            ->where('malnutrition_type', $type)
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->status_type);

        foreach (ChildNutritionErdMode::SFP_UI_TO_STATUS as $uiKey => $status) {
            $row = $rows->get($status);
            $current[$uiKey] = [
                'date' => $this->formatDate($row?->status_date ?? null),
                'action' => $this->formatErdAction($row?->action ?? null),
            ];
        }

        return $current;
    }

    /**
     * @param  array<string, ?string>  $ironDates  Column => date (meaningful only)
     */
    private function upsertErdIronSlots(int $headerId, array $ironDates): void
    {
        foreach (ChildNutritionErdMode::IRON_UI_TO_MONTH as $uiKey => $month) {
            $column = self::IRON_FIELDS[$uiKey];
            if (! array_key_exists($column, $ironDates)) {
                continue;
            }

            $this->upsertErdKeyedRow(
                ChildNutritionErdMode::IRON_TABLE,
                ChildNutritionErdMode::ironPrimaryKey(),
                [
                    'child_nutrition_id' => $headerId,
                    'month_number' => $month,
                ],
                [
                    'date_given' => $ironDates[$column],
                ]
            );
        }
    }

    /**
     * @param  list<array{type: string, age: string, dose: int, date_given: string}>  $slots
     */
    private function upsertErdSupplementationSlots(int $headerId, array $slots): void
    {
        foreach ($slots as $slot) {
            $this->upsertErdKeyedRow(
                ChildNutritionErdMode::supplementationTable(),
                ChildNutritionErdMode::supplementationPrimaryKey(),
                [
                    'child_nutrition_id' => $headerId,
                    'supplement_type' => $slot['type'],
                    'age_group' => $slot['age'],
                    'dose_number' => $slot['dose'],
                ],
                [
                    'date_given' => $slot['date_given'],
                ]
            );
        }
    }

    /**
     * @param  list<array{program: string, outcome: string, outcome_date: ?string, action_yes_no: ?string}>  $sfpSlots
     */
    private function upsertErdMalnutritionSlots(int $headerId, array $sfpSlots): void
    {
        foreach ($sfpSlots as $slot) {
            $type = ChildNutritionErdMode::MALNUTRITION_UI_TO_TYPE[$slot['program']] ?? null;
            $status = ChildNutritionErdMode::SFP_UI_TO_STATUS[$slot['outcome']] ?? null;
            if ($type === null || $status === null) {
                continue;
            }

            $values = [];
            if ($slot['outcome_date'] !== null && $slot['outcome_date'] !== '') {
                $values['status_date'] = $slot['outcome_date'];
            }
            $action = $this->erdActionInteger($slot['action_yes_no']);
            if ($action !== null) {
                $values['action'] = $action;
            }

            if ($values === []) {
                continue;
            }

            $this->upsertErdKeyedRow(
                ChildNutritionErdMode::MALNUTRITION_TABLE,
                ChildNutritionErdMode::malnutritionPrimaryKey(),
                [
                    'child_nutrition_id' => $headerId,
                    'malnutrition_type' => $type,
                    'status_type' => $status,
                ],
                $values
            );
        }
    }

    /**
     * Insert or update only when values contain meaningful data.
     * Blank submissions do not create placeholder rows and do not null-out existing rows.
     *
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     */
    private function upsertErdKeyedRow(string $table, string $pk, array $keys, array $values): void
    {
        $meaningful = $this->meaningfulAttributes($values);
        if ($meaningful === []) {
            return;
        }

        $existing = DB::table($table)->where($keys)->first();
        $now = now();

        if ($existing !== null) {
            DB::table($table)
                ->where($pk, $existing->{$pk})
                ->update(array_merge($meaningful, ['updated_at' => $now]));

            return;
        }

        DB::table($table)->insert(array_merge($keys, $meaningful, [
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /**
     * @return list<array{group: string, key: string, type: string, age: string, dose: int}>
     */
    private function supplementationSlots(): array
    {
        return [
            [
                'group' => 'vitamin_a',
                'key' => 'va-6-11',
                'type' => NutritionSupplementationErdMode::vitaminASupplementType(),
                'age' => NutritionSupplementationErdMode::ageGroup6to11Months(),
                'dose' => 1,
            ],
            [
                'group' => 'vitamin_a',
                'key' => 'va-12-59-1',
                'type' => NutritionSupplementationErdMode::vitaminASupplementType(),
                'age' => NutritionSupplementationErdMode::ageGroup12to59Months(),
                'dose' => 1,
            ],
            [
                'group' => 'vitamin_a',
                'key' => 'va-12-59-2',
                'type' => NutritionSupplementationErdMode::vitaminASupplementType(),
                'age' => NutritionSupplementationErdMode::ageGroup12to59Months(),
                'dose' => 2,
            ],
            [
                'group' => 'mnp',
                'key' => 'mnp-6-11',
                'type' => NutritionSupplementationErdMode::mnpSupplementType(),
                'age' => NutritionSupplementationErdMode::ageGroup6to11Months(),
                'dose' => 1,
            ],
            [
                'group' => 'mnp',
                'key' => 'mnp-12-23',
                'type' => NutritionSupplementationErdMode::mnpSupplementType(),
                'age' => NutritionSupplementationErdMode::ageGroup12to23Months(),
                'dose' => 1,
            ],
            [
                'group' => 'lns_sq',
                'key' => 'lns-6-11',
                'type' => NutritionSupplementationErdMode::lnsSqSupplementType(),
                'age' => NutritionSupplementationErdMode::ageGroup6to11Months(),
                'dose' => 1,
            ],
            [
                'group' => 'lns_sq',
                'key' => 'lns-12-23',
                'type' => NutritionSupplementationErdMode::lnsSqSupplementType(),
                'age' => NutritionSupplementationErdMode::ageGroup12to23Months(),
                'dose' => 1,
            ],
        ];
    }

    private function formatErdAction(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value === true || $value === 1 || $value === '1') {
            return 'yes';
        }

        if ($value === false || $value === 0 || $value === '0') {
            return 'no';
        }

        return '';
    }

    private function erdActionInteger(?string $action): ?int
    {
        if ($action === 'yes') {
            return 1;
        }

        if ($action === 'no') {
            return 0;
        }

        return null;
    }

    private function isDuplicateErdViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'uq_nutrsupp')
            || str_contains($message, 'uq_malnutrmgmt')
            || str_contains($message, 'uq_ironsupp_month');
    }

    /**
     * @return array{
     *     persisted: bool,
     *     newborn: array{length: string, weight: string, breastfeeding_date: string},
     *     iron: array{1st: string, 2nd: string, 3rd: string},
     *     vitamin_a: array{va-6-11: string, va-12-59-1: string, va-12-59-2: string},
     *     mnp: array{mnp-6-11: string, mnp-12-23: string},
     *     lns_sq: array{lns-6-11: string, lns-12-23: string},
     *     mam: array<string, array{date: string, action: string}>,
     *     sam: array<string, array{date: string, action: string}>
     * }
     */
    private function toReadContract(ChildNutrition $record): array
    {
        $mam = $this->emptySfpProgram();
        $sam = $this->emptySfpProgram();

        foreach ($record->sfpOutcomes as $row) {
            $program = (string) $row->program;
            $outcome = (string) $row->outcome;
            if (! in_array($program, ChildNutrition::SFP_PROGRAMS, true)) {
                continue;
            }
            if (! in_array($outcome, ChildNutrition::SFP_OUTCOMES, true)) {
                continue;
            }

            $slot = [
                'date' => $this->formatDate($row->outcome_date),
                'action' => $row->action_yes_no !== null ? (string) $row->action_yes_no : '',
            ];

            if ($program === 'mam') {
                $mam[$outcome] = $slot;
            } else {
                $sam[$outcome] = $slot;
            }
        }

        return [
            'persisted' => true,
            'newborn' => [
                'length' => $this->formatDecimal($record->newborn_length_cm),
                'weight' => $this->formatDecimal($record->newborn_weight_kg),
                'breastfeeding_date' => $this->formatDate($record->newborn_breastfeeding_date),
            ],
            'iron' => [
                '1st' => $this->formatDate($record->iron_1st_date),
                '2nd' => $this->formatDate($record->iron_2nd_date),
                '3rd' => $this->formatDate($record->iron_3rd_date),
            ],
            'vitamin_a' => [
                'va-6-11' => $this->formatDate($record->vitamin_a_va_6_11_date),
                'va-12-59-1' => $this->formatDate($record->vitamin_a_va_12_59_1_date),
                'va-12-59-2' => $this->formatDate($record->vitamin_a_va_12_59_2_date),
            ],
            'mnp' => [
                'mnp-6-11' => $this->formatDate($record->mnp_6_11_date),
                'mnp-12-23' => $this->formatDate($record->mnp_12_23_date),
            ],
            'lns_sq' => [
                'lns-6-11' => $this->formatDate($record->lns_sq_6_11_date),
                'lns-12-23' => $this->formatDate($record->lns_sq_12_23_date),
            ],
            'mam' => $mam,
            'sam' => $sam,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeWorksheetAttributes(array $payload): array
    {
        $attributes = [];

        $newborn = is_array($payload['newborn'] ?? null) ? $payload['newborn'] : [];
        foreach (self::NEWBORN_FIELDS as $key => $meta) {
            $raw = $newborn[$key] ?? null;
            $attributes[$meta['column']] = $meta['type'] === 'decimal'
                ? $this->nullableDecimal($raw, "newborn.{$key}")
                : $this->nullableDate($raw, "newborn.{$key}");
        }

        $attributes = array_merge(
            $attributes,
            $this->normalizeDateGroup($payload['iron'] ?? null, self::IRON_FIELDS, 'iron'),
            $this->normalizeDateGroup($payload['vitamin_a'] ?? null, self::VITAMIN_A_FIELDS, 'vitamin_a'),
            $this->normalizeDateGroup($payload['mnp'] ?? null, self::MNP_FIELDS, 'mnp'),
            $this->normalizeDateGroup($payload['lns_sq'] ?? null, self::LNS_FIELDS, 'lns_sq'),
        );

        return $attributes;
    }

    /**
     * @param  array<string, string>  $fieldMap
     * @return array<string, ?string>
     */
    private function normalizeDateGroup(mixed $group, array $fieldMap, string $prefix): array
    {
        $input = is_array($group) ? $group : [];
        $out = [];

        foreach ($fieldMap as $key => $column) {
            $out[$column] = $this->nullableDate($input[$key] ?? null, "{$prefix}.{$key}");
        }

        return $out;
    }

    /**
     * Returns all 12 logical SFP slots from the request shape.
     * Persistence filters to meaningful date/action values only.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{program: string, outcome: string, outcome_date: ?string, action_yes_no: ?string}>
     */
    private function normalizeSfpSlots(array $payload): array
    {
        $slots = [];

        foreach (ChildNutrition::SFP_PROGRAMS as $program) {
            $programGroup = is_array($payload[$program] ?? null) ? $payload[$program] : [];

            foreach (ChildNutrition::SFP_OUTCOMES as $outcome) {
                $row = is_array($programGroup[$outcome] ?? null) ? $programGroup[$outcome] : [];
                $radioKey = $program.'_'.$outcome;
                $actionRaw = $payload[$radioKey] ?? null;

                $slots[] = [
                    'program' => $program,
                    'outcome' => $outcome,
                    'outcome_date' => $this->nullableDate(
                        $row['date'] ?? null,
                        "{$program}.{$outcome}.date"
                    ),
                    'action_yes_no' => $this->nullableAction($actionRaw, $radioKey),
                ];
            }
        }

        return $slots;
    }

    /**
     * @param  array{program: string, outcome: string, outcome_date: ?string, action_yes_no: ?string}  $slot
     */
    private function sfpSlotIsMeaningful(array $slot): bool
    {
        return ($slot['outcome_date'] !== null && $slot['outcome_date'] !== '')
            || ($slot['action_yes_no'] !== null && $slot['action_yes_no'] !== '');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function meaningfulAttributes(array $attributes): array
    {
        $out = [];

        foreach ($attributes as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{type: string, age: string, dose: int, date_given: string}>
     */
    private function meaningfulSupplementationSlots(array $payload): array
    {
        $slots = [];

        foreach ($this->supplementationSlots() as $slot) {
            $group = is_array($payload[$slot['group']] ?? null) ? $payload[$slot['group']] : [];
            $date = $this->nullableDate($group[$slot['key']] ?? null, $slot['group'].'.'.$slot['key']);
            if ($date === null || $date === '') {
                continue;
            }

            $slots[] = [
                'type' => $slot['type'],
                'age' => $slot['age'],
                'dose' => $slot['dose'],
                'date_given' => $date,
            ];
        }

        return $slots;
    }

    protected function upsertSfpSlot(
        ChildNutrition $record,
        string $program,
        string $outcome,
        ?string $outcomeDate,
        ?string $actionYesNo,
    ): ?ChildNutritionSfpOutcome {
        $values = [];
        if ($outcomeDate !== null && $outcomeDate !== '') {
            $values['outcome_date'] = $outcomeDate;
        }
        if ($actionYesNo !== null && $actionYesNo !== '') {
            $values['action_yes_no'] = $actionYesNo;
        }

        if ($values === []) {
            return null;
        }

        $existing = ChildNutritionSfpOutcome::query()
            ->where('child_nutrition_id', $record->id)
            ->where('program', $program)
            ->where('outcome', $outcome)
            ->first();

        if ($existing !== null) {
            $existing->fill($values);
            $existing->save();

            return $existing;
        }

        return ChildNutritionSfpOutcome::query()->create(array_merge([
            'child_nutrition_id' => $record->id,
            'program' => $program,
            'outcome' => $outcome,
        ], $values));
    }

    /**
     * @return array<string, array{date: string, action: string}>
     */
    private function emptySfpProgram(): array
    {
        $rows = [];
        foreach (ChildNutrition::SFP_OUTCOMES as $outcome) {
            $rows[$outcome] = ['date' => '', 'action' => ''];
        }

        return $rows;
    }

    private function nullableDate(mixed $value, string $errorKey): ?string
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
                $errorKey => 'The date must be a valid date.',
            ]);
        }
    }

    private function nullableDecimal(mixed $value, string $errorKey): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (! is_numeric($trimmed)) {
            throw ValidationException::withMessages([
                $errorKey => 'The value must be a number.',
            ]);
        }

        $number = (float) $trimmed;
        if ($number < 0) {
            throw ValidationException::withMessages([
                $errorKey => 'The value must be zero or greater.',
            ]);
        }

        return number_format($number, 2, '.', '');
    }

    private function nullableAction(mixed $value, string $errorKey): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim((string) $value));
        if ($trimmed === '') {
            return null;
        }

        if (! in_array($trimmed, ChildNutrition::SFP_ACTIONS, true)) {
            throw ValidationException::withMessages([
                $errorKey => 'Action must be yes or no.',
            ]);
        }

        return $trimmed;
    }

    private function formatDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Latest non-empty Y-m-d from a map of form/service date strings.
     *
     * @param  array<string, mixed>  $datesByKey
     */
    public static function latestMeaningfulDate(array $datesByKey): ?string
    {
        $dates = [];

        foreach ($datesByKey as $value) {
            $raw = trim((string) $value);
            if ($raw === '') {
                continue;
            }

            try {
                $dates[] = Carbon::parse($raw)->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
        }

        if ($dates === []) {
            return null;
        }

        rsort($dates);

        return $dates[0];
    }

    /**
     * Display formatting aligned with Birth History date presentation on this page.
     */
    public static function formatPanelDate(?string $isoDate): string
    {
        if ($isoDate === null || trim($isoDate) === '') {
            return '';
        }

        try {
            return Carbon::parse($isoDate)->format('m/d/Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function isDuplicateSfpViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return (str_contains($message, 'UNIQUE constraint failed')
                && str_contains($message, 'child_nutrition_sfp_outcomes'))
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'child_nut_sfp_record_program_outcome_unique');
    }
}
