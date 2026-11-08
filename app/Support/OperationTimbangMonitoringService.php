<?php

namespace App\Support;

use App\Models\Resident;
use App\Models\TimbangRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only barangay-wide Operation Timbang monitoring (Health Records → Child Care).
 *
 * Population: persisted residents under Child Care eligibility
 * ({@see HealthRecordsChildCare::MAX_AGE_MONTHS} / isChildCarePopulation).
 * Measurements: latest matching {@see TimbangRecord} in the selected year/month.
 *
 * Nutritional classification is NOT derived — no approved clinical algorithm exists.
 * Specialized DOH summary metrics without an in-repo definition remain unresolved
 * (returned as "0") rather than guessed.
 */
final class OperationTimbangMonitoringService
{
    public const OPT_MAX_AGE_MONTHS_FOR_SUMMARY = 23;

    public const STATUS_UNCLASSIFIED = 'unclassified';

    public function currentMonitoringYear(): int
    {
        return (int) now()->year;
    }

    public function currentMonitoringMonth(): int
    {
        return (int) now()->month;
    }

    /**
     * True when the calendar year/month is after the application "now" period.
     *
     * Current year+month is allowed (not future). Past years/months are not future.
     * Comparison uses server time via {@see now()} (Carbon-testable).
     */
    public function isFuturePeriod(int $year, int $month): bool
    {
        $currentYear = $this->currentMonitoringYear();
        $currentMonth = $this->currentMonitoringMonth();

        if ($year > $currentYear) {
            return true;
        }

        if ($year < $currentYear) {
            return false;
        }

        return $month > $currentMonth;
    }

    /**
     * Resolve year for the monitoring page (query param or default).
     */
    public function resolveYear(?int $requested): int
    {
        $years = $this->availableYears();
        if ($requested !== null && in_array($requested, $years, true)) {
            return $requested;
        }

        return $years[0] ?? $this->currentMonitoringYear();
    }

    /**
     * Resolve month (1–12) for the monitoring page.
     */
    public function resolveMonth(?int $requested): int
    {
        if ($requested !== null && $requested >= 1 && $requested <= 12) {
            return $requested;
        }

        return $this->currentMonitoringMonth();
    }

    /**
     * Years for the year selector.
     *
     * Authoritative source: distinct calendar years from persisted
     * {@see TimbangRecord::$measurement_date} values, union the current
     * server calendar year (always included even with zero measurements).
     *
     * Future-dated measurement years (after the current server year) are omitted
     * from the selector — those periods are not recordable. Newest year first.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $currentYear = $this->currentMonitoringYear();
        $years = [$currentYear];

        if ($this->measurementTableReady()) {
            foreach (
                TimbangRecord::query()
                    ->whereNotNull('measurement_date')
                    ->pluck('measurement_date') as $measuredAt
            ) {
                try {
                    $year = (int) Carbon::parse($measuredAt)->year;
                } catch (\Throwable) {
                    continue;
                }

                if ($year > 0 && $year <= $currentYear) {
                    $years[] = $year;
                }
            }
        }

        $years = array_values(array_unique($years));
        rsort($years, SORT_NUMERIC);

        return $years;
    }

    /**
     * Eligible Child Care residents with the latest measurement in the given year/month (if any).
     *
     * @return list<array<string, mixed>>
     */
    public function monitoringRowsForYearMonth(int $year, int $month): array
    {
        if ($this->isFuturePeriod($year, $month)) {
            return [];
        }

        if (! $this->tablesReady()) {
            return [];
        }

        $residents = Resident::query()
            ->with([
                'household',
                'timbangRecords' => static function ($query) use ($year, $month): void {
                    $query
                        ->whereYear('measurement_date', $year)
                        ->whereMonth('measurement_date', $month)
                        ->orderByDesc('measurement_date')
                        ->orderByDesc('timbang_id');
                },
            ])
            ->whereHas('household')
            ->get();

        $rows = [];

        foreach ($residents as $resident) {
            $member = HouseholdProfilingPresenter::memberFromModel($resident);
            if (! HealthRecordsChildCare::isChildCarePopulation($member)) {
                continue;
            }

            $measurement = $resident->timbangRecords->first();
            $rows[] = $this->rowFromResident($resident, $member, $measurement);
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcasecmp((string) $a['full_name'], (string) $b['full_name'])
        );

        return $rows;
    }

    /**
     * Summary cards for the selected session.
     *
     * Authoritative (derived from DB + Child Care age helpers):
     * - measured_0_23 — 0–23 month eligible residents with a measurement in the session
     * - total_male / total_female — 0–23 month eligible residents by sex
     *
     * Unresolved (no in-repo DOH formula; returned as "0"):
     * - ps_0_23, over_age, transferred, dead, not_available, new_cases
     *
     * @param  list<array<string, mixed>>  $rows  monitoring rows for the session
     * @return array{
     *     ps_0_23: string,
     *     measured_0_23: string,
     *     over_age: string,
     *     transferred: string,
     *     dead: string,
     *     not_available: string,
     *     new_cases: string,
     *     total_male: string,
     *     total_female: string
     * }
     */
    public function summaryCardsForYearMonth(int $year, int $month, array $rows = []): array
    {
        if ($this->isFuturePeriod($year, $month)) {
            return $this->emptySummaryCards();
        }

        if ($rows === [] && $this->tablesReady()) {
            $rows = $this->monitoringRowsForYearMonth($year, $month);
        }

        $male = 0;
        $female = 0;
        $measured023 = 0;

        foreach ($rows as $row) {
            $ageMonths = $row['age_months'] ?? null;
            if (! is_int($ageMonths) || $ageMonths > self::OPT_MAX_AGE_MONTHS_FOR_SUMMARY) {
                continue;
            }

            $sex = strtolower(trim((string) ($row['sex'] ?? '')));
            if ($sex === 'male') {
                $male++;
            } elseif ($sex === 'female') {
                $female++;
            }

            if (! empty($row['has_measurement'])) {
                $measured023++;
            }
        }

        return [
            'ps_0_23' => '0',
            'measured_0_23' => (string) $measured023,
            'over_age' => '0',
            'transferred' => '0',
            'dead' => '0',
            'not_available' => '0',
            'new_cases' => '0',
            'total_male' => (string) $male,
            'total_female' => (string) $female,
        ];
    }

    /**
     * Zeroed summary used for future periods (no recordable session yet).
     *
     * @return array{
     *     ps_0_23: string,
     *     measured_0_23: string,
     *     over_age: string,
     *     transferred: string,
     *     dead: string,
     *     not_available: string,
     *     new_cases: string,
     *     total_male: string,
     *     total_female: string
     * }
     */
    public function emptySummaryCards(): array
    {
        return [
            'ps_0_23' => '0',
            'measured_0_23' => '0',
            'over_age' => '0',
            'transferred' => '0',
            'dead' => '0',
            'not_available' => '0',
            'new_cases' => '0',
            'total_male' => '0',
            'total_female' => '0',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public function zonesForRows(array $rows): array
    {
        return HealthRecordsChildCare::zonesFromRows($rows);
    }

    /**
     * Zones from all households that have at least one Child Care–eligible resident.
     *
     * @return list<string>
     */
    public function zones(): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        $zones = [];

        $residents = Resident::query()
            ->with('household')
            ->whereHas('household')
            ->get();

        foreach ($residents as $resident) {
            $member = HouseholdProfilingPresenter::memberFromModel($resident);
            if (! HealthRecordsChildCare::isChildCarePopulation($member)) {
                continue;
            }

            $zone = self::zoneLabelForHousehold($resident->household);
            if ($zone !== '') {
                $zones[$zone] = true;
            }
        }

        $list = array_keys($zones);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    public function tablesReady(): bool
    {
        return Schema::hasTable('residents')
            && Schema::hasTable('households')
            && $this->measurementTableReady();
    }

    /**
     * Canonical measurements live on timbang_records (FR-12).
     * operation_timbang_measurements is not required at runtime.
     */
    public function measurementTableReady(): bool
    {
        return Schema::hasTable('timbang_records');
    }

    private function zoneLabelForHousehold(?\App\Models\Household $household): string
    {
        if ($household === null) {
            return '';
        }

        $column = HouseholdZoneResolver::locationColumn();
        if ($column === null) {
            return '';
        }

        return HouseholdZoneResolver::displayLabelFromStoredValue((string) ($household->{$column} ?? ''));
    }

    /**
     * @param  array<string, mixed>  $member
     * @return array<string, mixed>
     */
    private function rowFromResident(
        Resident $resident,
        array $member,
        ?TimbangRecord $measurement
    ): array {
        $household = $resident->household;
        $fullName = HealthRecordsChildCare::displayName($member);
        $asOf = $measurement?->measurement_date instanceof Carbon
            ? $measurement->measurement_date->copy()->startOfDay()
            : Carbon::now()->startOfDay();
        $ageMonths = $this->ageInMonthsAsOf($member, $asOf);

        return [
            'key' => strtolower((string) $resident->member_no),
            'resident_id' => (int) $resident->getKey(),
            'full_name' => $fullName,
            'age_months' => $ageMonths,
            'age_label' => $ageMonths === null
                ? 'Age not recorded'
                : HealthRecordsChildCare::formatAgeMonths($ageMonths),
            'weight' => $this->formatWeight($measurement),
            'height' => $this->formatHeight($measurement),
            'muac' => $this->formatMuac($measurement),
            'has_measurement' => $measurement !== null,
            'status' => self::STATUS_UNCLASSIFIED,
            'status_label' => '—',
            'zone' => $this->zoneLabelForHousehold($household),
            'sex' => strtolower(trim((string) ($member['sex'] ?? ''))),
        ];
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function ageInMonthsAsOf(array $member, Carbon $asOf): ?int
    {
        $birthday = $member['birthday'] ?? null;
        if (is_string($birthday) && $birthday !== '') {
            try {
                $born = Carbon::parse($birthday)->startOfDay();
                if ($born->greaterThan($asOf)) {
                    return 0;
                }

                return (int) $born->diffInMonths($asOf);
            } catch (\Throwable) {
                // fall through
            }
        }

        return HealthRecordsChildCare::ageInMonths($member);
    }

    private function formatWeight(?TimbangRecord $measurement): string
    {
        if ($measurement === null || $measurement->weight_kg === null) {
            return HealthRecordsChildCare::EMPTY_RECORD;
        }

        return $this->trimDecimal((string) $measurement->weight_kg).' kg';
    }

    private function formatHeight(?TimbangRecord $measurement): string
    {
        if ($measurement === null || $measurement->height_cm === null) {
            return HealthRecordsChildCare::EMPTY_RECORD;
        }

        return $this->trimDecimal((string) $measurement->height_cm).' cm';
    }

    private function formatMuac(?TimbangRecord $measurement): string
    {
        if ($measurement === null || $measurement->muac_cm === null) {
            return HealthRecordsChildCare::EMPTY_RECORD;
        }

        return $this->trimDecimal((string) $measurement->muac_cm);
    }

    private function trimDecimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? '0' : $value;
    }
}
