<?php

namespace App\Support;

use App\Models\Resident;
use Illuminate\Support\Facades\Schema;

/**
 * DB-06 Phase 4 — read-only barangay-wide Deworming monitoring.
 *
 * Displayed rows are residents with at least one Child Care deworming_records
 * row for the selected year. Summary round counts come from those rows.
 * Received 1/2 dose percentages use all household residents as the eligible
 * coverage denominator (not the displayed recorded-row count).
 */
final class DewormingMonitoringService
{
    public function currentMonitoringYear(): int
    {
        return (int) now()->year;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function monitoringRowsForYear(int $year): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        $residents = Resident::query()
            ->with([
                'household',
                'dewormingRecords' => static fn ($query) => $query->where('year', $year),
            ])
            ->whereHas('household')
            ->whereHas('dewormingRecords', static fn ($query) => $query->where('year', $year))
            ->get();

        $rows = [];

        foreach ($residents as $resident) {
            if ($resident->dewormingRecords->isEmpty()) {
                continue;
            }

            $member = HouseholdProfilingPresenter::memberFromModel($resident);
            $rows[] = $this->rowFromResident($resident, $member);
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcasecmp((string) $a['full_name'], (string) $b['full_name'])
        );

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     first_round: string,
     *     second_round: string,
     *     received_1_dose_pct: string,
     *     received_2_dose_pct: string
     * }
     */
    public function summaryCardsForRows(array $rows): array
    {
        $eligible = $this->eligibleResidentCount();
        $empty = HealthRecordsChildCare::EMPTY_RECORD;

        $firstRound = 0;
        $secondRound = 0;
        $oneDose = 0;
        $twoDoses = 0;

        foreach ($rows as $row) {
            $july = (string) ($row['july_round'] ?? '');
            $january = (string) ($row['january_round'] ?? '');

            if ($this->hasRoundRecord($july, $empty)) {
                $firstRound++;
            }
            if ($this->hasRoundRecord($january, $empty)) {
                $secondRound++;
            }

            $status = (string) ($row['status'] ?? '');
            if ($status === '1-dose') {
                $oneDose++;
            } elseif ($status === '2-doses') {
                $twoDoses++;
            }
        }

        return [
            'first_round' => (string) $firstRound,
            'second_round' => (string) $secondRound,
            'received_1_dose_pct' => $this->formatPercentage($oneDose, $eligible),
            'received_2_dose_pct' => $this->formatPercentage($twoDoses, $eligible),
        ];
    }

    /**
     * Eligible coverage denominator: household residents (all ages).
     * Distinct from the displayed recorded-resident list.
     */
    public function eligibleResidentCount(): int
    {
        if (! $this->tablesReady()) {
            return 0;
        }

        return Resident::query()->whereHas('household')->count();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public function zonesForRows(array $rows): array
    {
        return HealthRecordsChildCare::zonesFromRows($rows);
    }

    public function memberDewormingUrlForChildKey(string $childKey): ?string
    {
        $resident = $this->residentForChildKey($childKey);
        if ($resident === null) {
            return null;
        }

        $household = $resident->household;
        if ($household === null) {
            return null;
        }

        return route('household-profiling.members.deworming', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    /**
     * Resolve a persisted resident by Health Records Deworming display-name slug.
     * Never consults DemoCatalog.
     */
    public function residentForChildKey(string $childKey): ?Resident
    {
        $childKey = strtolower(trim($childKey));
        if ($childKey === '' || preg_match('/^[a-z0-9\-]+$/', $childKey) !== 1) {
            return null;
        }

        if (! $this->tablesReady()) {
            return null;
        }

        $residents = Resident::query()
            ->with('household')
            ->whereHas('household')
            ->get();

        foreach ($residents as $resident) {
            $member = HouseholdProfilingPresenter::memberFromModel($resident);

            $slug = HealthRecordsDeworming::childKeyFromDisplayName(
                HealthRecordsChildCare::displayName($member)
            );
            if ($slug !== $childKey) {
                continue;
            }

            return $resident;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $member
     * @return array<string, mixed>
     */
    private function rowFromResident(Resident $resident, array $member): array
    {
        $household = $resident->household;
        $fullName = HealthRecordsChildCare::displayName($member);
        $ageMonths = HealthRecordsChildCare::ageInMonths($member);
        $key = HealthRecordsDeworming::childKeyFromDisplayName($fullName);
        $roundDates = $this->roundDatesFromRecords($resident);

        $householdNo = (string) ($household->household_no ?? '');
        $memberId = (string) $resident->member_no;

        return [
            'key' => $key,
            'full_name' => $fullName,
            'age_label' => $ageMonths === null
                ? 'Age not recorded'
                : HealthRecordsChildCare::formatAgeMonths($ageMonths),
            'july_round' => $roundDates[1],
            'january_round' => $roundDates[2],
            'zone' => $this->zoneLabelForHousehold($household),
            'sex' => strtolower(trim((string) ($member['sex'] ?? ''))),
            'status' => $this->statusFromRoundDates($roundDates),
            'view_url' => route('household-profiling.members.deworming', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
            ]),
            'create_url' => route('household-profiling.members.deworming.create', [
                'householdNo' => $householdNo,
                'memberId' => $memberId,
            ]),
        ];
    }

    /**
     * @return array{1: string, 2: string}
     */
    private function roundDatesFromRecords(Resident $resident): array
    {
        $empty = HealthRecordsChildCare::EMPTY_RECORD;
        $dates = [1 => $empty, 2 => $empty];

        foreach ($resident->dewormingRecords as $record) {
            $round = (int) $record->round;
            if ($round !== 1 && $round !== 2) {
                continue;
            }

            $dates[$round] = DewormingRecordService::toPresentation($record)['date_given_label'];
        }

        return $dates;
    }

    /**
     * @param  array{1: string, 2: string}  $roundDates
     */
    private function statusFromRoundDates(array $roundDates): string
    {
        $empty = HealthRecordsChildCare::EMPTY_RECORD;
        $hasRound1 = $this->hasRoundRecord($roundDates[1], $empty);
        $hasRound2 = $this->hasRoundRecord($roundDates[2], $empty);

        if ($hasRound1 && $hasRound2) {
            return '2-doses';
        }

        if ($hasRound1 || $hasRound2) {
            return '1-dose';
        }

        return 'none';
    }

    private function hasRoundRecord(string $value, string $empty): bool
    {
        $value = trim($value);

        return $value !== '' && strcasecmp($value, $empty) !== 0;
    }

    private function formatPercentage(int $numerator, int $denominator): string
    {
        if ($denominator === 0) {
            return '0%';
        }

        return ((int) round(($numerator / $denominator) * 100)).'%';
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

    private function tablesReady(): bool
    {
        try {
            return Schema::hasTable('residents')
                && Schema::hasTable('households')
                && Schema::hasTable('deworming_records');
        } catch (\Throwable) {
            return false;
        }
    }
}
