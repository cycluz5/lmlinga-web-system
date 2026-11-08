<?php

namespace App\Support;

use App\Models\ChildNutrition;
use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only barangay-wide Vitamin A monitoring aggregates from persisted records.
 */
final class VitaminAMonitoringService
{
    /** @var array<int, list<object>>|null */
    private ?array $erdSupplementsByResidentId = null;

    /**
     * @return list<array<string, mixed>>
     */
    public function monitoringRows(?string $zone = null, ?string $year = null, ?string $month = null): array
    {
        if (! $this->tablesReady()) {
            return $this->emptyMonitoringRows();
        }

        $this->erdSupplementsByResidentId = null;
        $eligible = $this->eligibleResidents($zone);
        $rows = [];

        foreach (HealthRecordsVitaminA::ageGroups() as $group) {
            if (($group['key'] ?? '') === 'total') {
                continue;
            }

            $rows[] = $this->metricRowForGroup($group, $eligible, $year, $month);
        }

        $rows[] = $this->totalRow($rows);

        return $rows;
    }

    /**
     * Distinct calendar years with a persisted Vitamin A dose date, newest
     * first — the Vitamin A page's year selector.
     *
     * @return list<string>
     */
    public function years(): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        $years = [];

        if (ChildNutritionErdMode::isActive()) {
            $suppTable = ChildNutritionErdMode::supplementationTable();
            foreach (DB::table($suppTable)->whereNotNull('date_given')->pluck('date_given') as $date) {
                $year = $this->yearFromDate($date);
                if ($year !== null) {
                    $years[$year] = true;
                }
            }
        } else {
            foreach (ChildNutrition::query()->get() as $nutrition) {
                foreach (['vitamin_a_va_6_11_date', 'vitamin_a_va_12_59_1_date', 'vitamin_a_va_12_59_2_date'] as $column) {
                    $year = $this->yearFromDate($nutrition->{$column} ?? null);
                    if ($year !== null) {
                        $years[$year] = true;
                    }
                }
            }
        }

        $list = array_map('strval', array_keys($years));
        rsort($list, SORT_NUMERIC);

        return $list;
    }

    private function yearFromDate(mixed $date): ?int
    {
        if (blank($date)) {
            return null;
        }

        try {
            return $date instanceof \DateTimeInterface
                ? (int) $date->format('Y')
                : (int) Carbon::parse((string) $date)->year;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when $date falls in the given year/month; null year/month means
     * that dimension is unrestricted. Blank/unparsable dates never match a
     * restricted period.
     */
    private function matchesPeriod(mixed $date, ?string $year, ?string $month): bool
    {
        if ($year === null && $month === null) {
            return true;
        }

        if (blank($date)) {
            return false;
        }

        try {
            $carbon = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse((string) $date);
        } catch (\Throwable) {
            return false;
        }

        if ($year !== null && (string) $carbon->year !== $year) {
            return false;
        }

        if ($month !== null && str_pad((string) $carbon->month, 2, '0', STR_PAD_LEFT) !== $month) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function zones(): array
    {
        if (! Schema::hasTable('households')) {
            return [];
        }

        $column = HouseholdZoneResolver::locationColumn();
        if ($column === null) {
            return [];
        }

        $zones = [];
        $households = \App\Models\Household::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->pluck($column);

        foreach ($households as $raw) {
            $label = HouseholdZoneResolver::displayLabelFromStoredValue((string) $raw);
            if ($label !== '') {
                $zones[$label] = true;
            }
        }

        $list = array_keys($zones);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    private function tablesReady(): bool
    {
        if (! Schema::hasTable('residents') || ! Schema::hasTable('households')) {
            return false;
        }

        if (ChildNutritionErdMode::isActive()) {
            return Schema::hasTable(ChildNutritionErdMode::nutritionTable())
                && ChildNutritionErdMode::usesSupplementationTable();
        }

        return Schema::hasTable('child_nutritions');
    }

    /**
     * @return list<array{resident: Resident, member: array<string, mixed>, age_months: int}>
     */
    private function eligibleResidents(?string $zone): array
    {
        $with = ['household'];
        if (! ChildNutritionErdMode::isActive()) {
            $with[] = 'childNutrition';
        }

        $residents = Resident::query()
            ->with($with)
            ->whereHas('household')
            ->get();

        $eligible = [];

        foreach ($residents as $resident) {
            $member = HouseholdProfilingPresenter::memberFromModel($resident);
            $ageMonths = HealthRecordsChildCare::ageInMonths($member);
            if ($ageMonths === null || $ageMonths < 6 || $ageMonths > 71) {
                continue;
            }

            if ($zone !== null && $zone !== '' && $zone !== 'all') {
                $rowZone = $this->zoneLabelForResident($resident);
                if ($rowZone !== $zone) {
                    continue;
                }
            }

            $eligible[] = [
                'resident' => $resident,
                'member' => $member,
                'age_months' => $ageMonths,
            ];
        }

        return $eligible;
    }

    /**
     * @param  array{key: string, label: string, min_months: int, max_months: int}  $group
     * @param  list<array{resident: Resident, member: array<string, mixed>, age_months: int}>  $eligible
     * @return array<string, mixed>
     */
    private function metricRowForGroup(array $group, array $eligible, ?string $year, ?string $month): array
    {
        $min = (int) ($group['min_months'] ?? 0);
        $max = (int) ($group['max_months'] ?? 0);
        $key = (string) ($group['key'] ?? '');
        $label = (string) ($group['label'] ?? '');

        $target = 0;
        $male100 = 0;
        $female100 = 0;
        $male200 = 0;
        $female200 = 0;

        foreach ($eligible as $entry) {
            $ageMonths = (int) $entry['age_months'];
            if ($ageMonths < $min || $ageMonths > $max) {
                continue;
            }

            $target++;
            $sex = strtolower(trim((string) ($entry['member']['sex'] ?? '')));
            $isMale = in_array($sex, ['male', 'm', 'boy', 'man'], true);
            $isFemale = in_array($sex, ['female', 'f', 'girl', 'woman'], true);
            $residentId = (int) $entry['resident']->getKey();

            if ($key === '6-11') {
                $dosed = ChildNutritionErdMode::isActive()
                    ? $this->erdHas100kDose($residentId, $year, $month)
                    : $this->hasDoseDate($entry['resident']->childNutrition, 'vitamin_a_va_6_11_date', $year, $month);

                if ($dosed) {
                    if ($isMale) {
                        $male100++;
                    } elseif ($isFemale) {
                        $female100++;
                    }
                }
            } else {
                $dosed = ChildNutritionErdMode::isActive()
                    ? $this->erdHas200kDose($residentId, $year, $month)
                    : $this->has200kDose($entry['resident']->childNutrition, $year, $month);

                if ($dosed) {
                    if ($isMale) {
                        $male200++;
                    } elseif ($isFemale) {
                        $female200++;
                    }
                }
            }
        }

        $total100 = $male100 + $female100;
        $total200 = $male200 + $female200;
        $dosedTotal = $key === '6-11' ? $total100 : $total200;

        return [
            'key' => $key,
            'label' => $label,
            'is_total' => false,
            'target' => (string) $target,
            'va_100k_male' => $key === '6-11' ? (string) $male100 : self::emptyCell(),
            'va_100k_female' => $key === '6-11' ? (string) $female100 : self::emptyCell(),
            'va_100k_total' => $key === '6-11' ? (string) $total100 : self::emptyCell(),
            'va_200k_male' => $key !== '6-11' ? (string) $male200 : self::emptyCell(),
            'va_200k_female' => $key !== '6-11' ? (string) $female200 : self::emptyCell(),
            'va_200k_total' => $key !== '6-11' ? (string) $total200 : self::emptyCell(),
            'percentage' => $target > 0 && $dosedTotal > 0
                ? (string) round(($dosedTotal / $target) * 100).'%'
                : self::emptyCell(),
        ];
    }

    private static function emptyCell(): string
    {
        return HealthRecordsVitaminA::EMPTY_CELL;
    }

    /**
     * @param  list<array<string, mixed>>  $metricRows
     * @return array<string, mixed>
     */
    private function totalRow(array $metricRows): array
    {
        $keys = [
            'target',
            'va_100k_male',
            'va_100k_female',
            'va_100k_total',
            'va_200k_male',
            'va_200k_female',
            'va_200k_total',
        ];
        $sums = array_fill_keys($keys, 0);
        $hasNumeric = array_fill_keys($keys, false);

        foreach ($metricRows as $row) {
            if (! empty($row['is_total'])) {
                continue;
            }

            foreach ($keys as $key) {
                $n = $this->numericCell($row[$key] ?? null);
                if ($n === null) {
                    continue;
                }

                $sums[$key] += $n;
                $hasNumeric[$key] = true;
            }
        }

        $target = $sums['target'];
        $dosed = $sums['va_100k_total'] + $sums['va_200k_total'];
        $percentage = ($hasNumeric['target'] && $target > 0 && $dosed > 0)
            ? (string) round(($dosed / $target) * 100).'%'
            : self::emptyCell();

        $cell = static fn (string $key): string => $hasNumeric[$key]
            ? (string) $sums[$key]
            : self::emptyCell();

        return [
            'key' => 'total',
            'label' => 'Total',
            'is_total' => true,
            'target' => $cell('target'),
            'va_100k_male' => $cell('va_100k_male'),
            'va_100k_female' => $cell('va_100k_female'),
            'va_100k_total' => $cell('va_100k_total'),
            'va_200k_male' => $cell('va_200k_male'),
            'va_200k_female' => $cell('va_200k_female'),
            'va_200k_total' => $cell('va_200k_total'),
            'percentage' => $percentage,
        ];
    }

    private function numericCell(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === HealthRecordsVitaminA::EMPTY_CELL) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function emptyMonitoringRows(): array
    {
        $rows = [];

        foreach (HealthRecordsVitaminA::ageGroups() as $group) {
            if (($group['key'] ?? '') === 'total') {
                continue;
            }

            $rows[] = [
                'key' => (string) $group['key'],
                'label' => (string) $group['label'],
                'is_total' => false,
                'target' => HealthRecordsVitaminA::EMPTY_CELL,
                'va_100k_male' => HealthRecordsVitaminA::EMPTY_CELL,
                'va_100k_female' => HealthRecordsVitaminA::EMPTY_CELL,
                'va_100k_total' => HealthRecordsVitaminA::EMPTY_CELL,
                'va_200k_male' => HealthRecordsVitaminA::EMPTY_CELL,
                'va_200k_female' => HealthRecordsVitaminA::EMPTY_CELL,
                'va_200k_total' => HealthRecordsVitaminA::EMPTY_CELL,
                'percentage' => HealthRecordsVitaminA::EMPTY_CELL,
            ];
        }

        $rows[] = [
            'key' => 'total',
            'label' => 'Total',
            'is_total' => true,
            'target' => HealthRecordsVitaminA::EMPTY_CELL,
            'va_100k_male' => HealthRecordsVitaminA::EMPTY_CELL,
            'va_100k_female' => HealthRecordsVitaminA::EMPTY_CELL,
            'va_100k_total' => HealthRecordsVitaminA::EMPTY_CELL,
            'va_200k_male' => HealthRecordsVitaminA::EMPTY_CELL,
            'va_200k_female' => HealthRecordsVitaminA::EMPTY_CELL,
            'va_200k_total' => HealthRecordsVitaminA::EMPTY_CELL,
            'percentage' => HealthRecordsVitaminA::EMPTY_CELL,
        ];

        return $rows;
    }

    private function zoneLabelForResident(Resident $resident): string
    {
        $household = $resident->household;
        if ($household === null) {
            return '';
        }

        $column = HouseholdZoneResolver::locationColumn();
        if ($column === null) {
            return '';
        }

        return HouseholdZoneResolver::displayLabelFromStoredValue((string) ($household->{$column} ?? ''));
    }

    private function hasDoseDate(?ChildNutrition $nutrition, string $column, ?string $year, ?string $month): bool
    {
        if ($nutrition === null) {
            return false;
        }

        $value = $nutrition->{$column} ?? null;
        if (! filled($value)) {
            return false;
        }

        return $this->matchesPeriod($value, $year, $month);
    }

    private function has200kDose(?ChildNutrition $nutrition, ?string $year, ?string $month): bool
    {
        return $this->hasDoseDate($nutrition, 'vitamin_a_va_12_59_1_date', $year, $month)
            || $this->hasDoseDate($nutrition, 'vitamin_a_va_12_59_2_date', $year, $month);
    }

    private function erdHas100kDose(int $residentId, ?string $year, ?string $month): bool
    {
        foreach ($this->supplementsForResident($residentId) as $row) {
            if (
                $this->isVitaminASupplement($row)
                && $this->is100kSupplement($row)
                && $this->matchesPeriod($row->date_given ?? null, $year, $month)
            ) {
                return true;
            }
        }

        return false;
    }

    private function erdHas200kDose(int $residentId, ?string $year, ?string $month): bool
    {
        foreach ($this->supplementsForResident($residentId) as $row) {
            if (
                $this->isVitaminASupplement($row)
                && $this->is200kSupplement($row)
                && $this->matchesPeriod($row->date_given ?? null, $year, $month)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<object>
     */
    private function supplementsForResident(int $residentId): array
    {
        $map = $this->erdSupplementsByResidentId();

        return $map[$residentId] ?? [];
    }

    /**
     * @return array<int, list<object>>
     */
    private function erdSupplementsByResidentId(): array
    {
        if ($this->erdSupplementsByResidentId !== null) {
            return $this->erdSupplementsByResidentId;
        }

        $nutritionTable = ChildNutritionErdMode::nutritionTable();
        $nutritionPk = ChildNutritionErdMode::nutritionPrimaryKey();
        $suppTable = ChildNutritionErdMode::supplementationTable();
        $suppFk = ChildNutritionErdMode::nutritionForeignKeyOnSupplementation();

        /** @var Collection<int, object> $rows */
        $rows = DB::table($suppTable.' as s')
            ->join($nutritionTable.' as cn', 'cn.'.$nutritionPk, '=', 's.'.$suppFk)
            ->whereNotNull('s.date_given')
            ->select([
                'cn.resident_id',
                's.supplement_type',
                's.age_group',
                's.dose_number',
                's.date_given',
            ])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->resident_id][] = $row;
        }

        $this->erdSupplementsByResidentId = $map;

        return $map;
    }

    private function isVitaminASupplement(object $row): bool
    {
        $type = strtolower(trim((string) ($row->supplement_type ?? '')));
        if ($type === '') {
            return false;
        }

        if ($type === 'va' || str_contains($type, 'vit a') || str_contains($type, 'vitamin a')) {
            return true;
        }

        return str_contains($type, 'vitamin') && str_contains($type, 'a');
    }

    private function is100kSupplement(object $row): bool
    {
        $type = strtolower(trim((string) ($row->supplement_type ?? '')));
        $ageGroup = strtolower(trim((string) ($row->age_group ?? '')));

        if (str_contains($type, '100')) {
            return true;
        }

        return $ageGroup !== '' && preg_match('/6\s*[-–to]+\s*11/', $ageGroup) === 1;
    }

    private function is200kSupplement(object $row): bool
    {
        $type = strtolower(trim((string) ($row->supplement_type ?? '')));
        $ageGroup = strtolower(trim((string) ($row->age_group ?? '')));

        if (str_contains($type, '200')) {
            return true;
        }

        if ($ageGroup === '') {
            return false;
        }

        return preg_match('/12\s*[-–to]+\s*59|60\s*[-–to]+\s*71/', $ageGroup) === 1;
    }
}
