<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Barangay-wide Family Planning summary (Health Records → Family Planning).
 *
 * Listing rows and summary counts come only from persisted family planning records.
 */
final class HealthRecordsFamilyPlanning
{
    public const EMPTY = HealthRecordsClinicalListing::EMPTY;

    /**
     * @return list<string>
     */
    public static function zones(): array
    {
        return HouseholdZoneResolver::DISPLAY_ZONES;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        if (Schema::hasTable('family_planning_visits')) {
            return self::rowsFromLegacyTable();
        }

        if (Schema::hasTable('family_planning')) {
            return self::rowsFromErdTable();
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array{total: int, commodities: list<array{name: string, count: int}>}
     */
    public static function summaryCounts(?array $rows = null): array
    {
        $rows ??= self::rows();
        $byMethod = [];

        foreach ($rows as $row) {
            $method = trim((string) ($row['method'] ?? ''));
            if ($method === '' || $method === self::EMPTY) {
                continue;
            }

            $key = strtolower($method);
            if (! isset($byMethod[$key])) {
                $byMethod[$key] = [
                    'name' => $method,
                    'count' => 0,
                ];
            }

            $byMethod[$key]['count']++;
        }

        $commodities = array_values($byMethod);
        usort(
            $commodities,
            static function (array $a, array $b): int {
                $byCount = $b['count'] <=> $a['count'];

                return $byCount !== 0
                    ? $byCount
                    : strcasecmp((string) $a['name'], (string) $b['name']);
            }
        );

        return [
            'total' => count($rows),
            'commodities' => $commodities,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return list<string>
     */
    public static function years(?array $rows = null): array
    {
        $rows ??= self::rows();
        $years = [];

        foreach ($rows as $row) {
            $year = trim((string) ($row['year'] ?? ''));
            if ($year !== '') {
                $years[$year] = true;
            }
        }

        $list = array_map('strval', array_keys($years));
        rsort($list, SORT_NUMERIC);

        return $list;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function monthOptions(): array
    {
        return [
            ['value' => '01', 'label' => 'January'],
            ['value' => '02', 'label' => 'February'],
            ['value' => '03', 'label' => 'March'],
            ['value' => '04', 'label' => 'April'],
            ['value' => '05', 'label' => 'May'],
            ['value' => '06', 'label' => 'June'],
            ['value' => '07', 'label' => 'July'],
            ['value' => '08', 'label' => 'August'],
            ['value' => '09', 'label' => 'September'],
            ['value' => '10', 'label' => 'October'],
            ['value' => '11', 'label' => 'November'],
            ['value' => '12', 'label' => 'December'],
        ];
    }

    /**
     * "All Time" / "Year 2026" / "September 2026" — depends on which of
     * the year/month filters are active. Used for the Family Planning
     * report's summary period label.
     */
    public static function periodLabel(string $year, string $month): string
    {
        $year = trim($year);
        $month = trim($month);

        if ($year === '' || $year === 'all') {
            return 'All Time';
        }

        if ($month !== '' && $month !== 'all') {
            return self::monthLabel($month).' '.$year;
        }

        return 'Year '.$year;
    }

    private static function monthLabel(string $month): string
    {
        foreach (self::monthOptions() as $option) {
            if ($option['value'] === $month) {
                return $option['label'];
            }
        }

        return $month;
    }

    /**
     * One row per commodity given — the Family Planning Report Builder's
     * data source. Legacy mode decodes each visit's packed `commodities`
     * JSON column ([{name, quantity}, ...]); ERD mode joins the
     * `fp_commodities_given` child table (one `family_planning` visit can
     * have several commodities, each with its own quantity).
     *
     * @return list<array<string, mixed>>
     */
    public static function commodityExportRows(?string $zone = null, ?string $year = null, ?string $month = null): array
    {
        if (Schema::hasTable('family_planning_visits')) {
            return self::commodityRowsFromLegacyTable($zone, $year, $month);
        }

        if (Schema::hasTable('family_planning') && Schema::hasTable('fp_commodities_given')) {
            return self::commodityRowsFromErdTables($zone, $year, $month);
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function commodityRowsFromLegacyTable(?string $zone, ?string $year, ?string $month): array
    {
        if (! HealthRecordsClinicalListing::tablesReady('family_planning_visits', 'residents')) {
            return [];
        }

        $visits = HealthRecordsClinicalListing::joinResidentsHouseholds('family_planning_visits', 'fp');

        $rows = [];
        foreach ($visits as $visit) {
            if (self::belongsToNonResidentSentinel($visit)) {
                continue;
            }

            $visitDate = trim((string) ($visit->visited_at ?? ''));
            if ($visitDate === '') {
                continue;
            }

            $rowZone = HealthRecordsClinicalListing::zoneLabel($visit);
            $rowYear = HealthRecordsClinicalListing::yearFromDate($visitDate);
            $rowMonth = self::monthFromDate($visitDate);

            if (! self::matchesExportFilters($rowZone, $rowYear, $rowMonth, $zone, $year, $month)) {
                continue;
            }

            $fullName = HealthRecordsClinicalListing::fullName($visit);
            $formattedDate = HealthRecordsClinicalListing::formatFamilyPlanningDate($visitDate);

            foreach (self::decodeCommodityEntries($visit->commodities ?? null) as $entry) {
                $rows[] = [
                    'full_name' => $fullName,
                    'visit_date' => $formattedDate,
                    'commodity' => $entry['name'],
                    'quantity' => $entry['quantity'],
                    'zone' => $rowZone,
                    'year' => $rowYear,
                    'month' => $rowMonth,
                ];
            }
        }

        return self::sortCommodityRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function commodityRowsFromErdTables(?string $zone, ?string $year, ?string $month): array
    {
        if (! HealthRecordsClinicalListing::tablesReady('family_planning', 'residents', 'fp_commodities_given')) {
            return [];
        }

        $visits = HealthRecordsClinicalListing::joinResidentsHouseholds('family_planning', 'fp')
            ->keyBy(static fn (object $visit): int => (int) $visit->fp_id);

        if ($visits->isEmpty()) {
            return [];
        }

        $commodities = DB::table('fp_commodities_given')
            ->whereIn('fp_id', $visits->keys())
            ->orderBy('commodity_given_id')
            ->get();

        $rows = [];
        foreach ($commodities as $commodity) {
            $visit = $visits->get((int) $commodity->fp_id);
            if ($visit === null || self::belongsToNonResidentSentinel($visit)) {
                continue;
            }

            $visitDate = trim((string) ($visit->visitation_date ?? ''));
            if ($visitDate === '') {
                continue;
            }

            $rowZone = HealthRecordsClinicalListing::zoneLabel($visit);
            $rowYear = HealthRecordsClinicalListing::yearFromDate($visitDate);
            $rowMonth = self::monthFromDate($visitDate);

            if (! self::matchesExportFilters($rowZone, $rowYear, $rowMonth, $zone, $year, $month)) {
                continue;
            }

            $commodityName = trim((string) ($commodity->commodity_name ?? ''));
            if ($commodityName === '') {
                continue;
            }

            $rows[] = [
                'full_name' => HealthRecordsClinicalListing::fullName($visit),
                'visit_date' => HealthRecordsClinicalListing::formatFamilyPlanningDate($visitDate),
                'commodity' => $commodityName,
                'quantity' => (string) ($commodity->quantity ?? ''),
                'zone' => $rowZone,
                'year' => $rowYear,
                'month' => $rowMonth,
            ];
        }

        return self::sortCommodityRows($rows);
    }

    private static function matchesExportFilters(
        string $rowZone,
        string $rowYear,
        string $rowMonth,
        ?string $zone,
        ?string $year,
        ?string $month
    ): bool {
        if ($zone !== null && $rowZone !== $zone) {
            return false;
        }
        if ($year !== null && $rowYear !== $year) {
            return false;
        }
        if ($month !== null && $rowMonth !== $month) {
            return false;
        }

        return true;
    }

    /**
     * Decodes the legacy per-visit `commodities` JSON column
     * ([{name|method, quantity}, ...]) into flat {name, quantity} pairs.
     *
     * @return list<array{name: string, quantity: string}>
     */
    private static function decodeCommodityEntries(mixed $commodities): array
    {
        if (is_string($commodities) && trim($commodities) !== '') {
            try {
                $commodities = json_decode($commodities, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return [];
            }
        }

        if (! is_array($commodities)) {
            return [];
        }

        $entries = [];
        foreach ($commodities as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? $entry['method'] ?? ''));
            if ($name === '') {
                continue;
            }
            $entries[] = [
                'name' => $name,
                'quantity' => trim((string) ($entry['quantity'] ?? '')),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sortCommodityRows(array $rows): array
    {
        usort(
            $rows,
            static function (array $a, array $b): int {
                $byName = strcasecmp((string) $a['full_name'], (string) $b['full_name']);

                return $byName !== 0
                    ? $byName
                    : strcmp((string) $a['visit_date'], (string) $b['visit_date']);
            }
        );

        return $rows;
    }

    private static function monthFromDate(string $isoDate): string
    {
        $raw = trim($isoDate);
        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->format('m');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowsFromLegacyTable(): array
    {
        if (! HealthRecordsClinicalListing::tablesReady('family_planning_visits', 'residents')) {
            return [];
        }

        return self::aggregateResidentRows(
            HealthRecordsClinicalListing::joinResidentsHouseholds('family_planning_visits', 'fp'),
            'visited_at',
            static fn (object $record): string => HealthRecordsClinicalListing::methodFromCommodities($record->commodities ?? null)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowsFromErdTable(): array
    {
        if (! HealthRecordsClinicalListing::tablesReady('family_planning', 'residents')) {
            return [];
        }

        $methodsByFpId = self::firstCommodityByVisitId();

        return self::aggregateResidentRows(
            HealthRecordsClinicalListing::joinResidentsHouseholds('family_planning', 'fp'),
            'visitation_date',
            static fn (object $record): string => $methodsByFpId[(int) ($record->fp_id ?? 0)] ?? self::EMPTY
        );
    }

    /**
     * The Method column for ERD-mode rows: the first commodity recorded
     * against each `family_planning` visit (a visit may have several
     * commodities given; the listing shows one method per visit, same as
     * the legacy JSON path's methodFromCommodities() picking the first
     * entry). Pre-fetched once per listing render to avoid an N+1 query
     * per visit.
     *
     * @return array<int, string>
     */
    private static function firstCommodityByVisitId(): array
    {
        if (! Schema::hasTable('fp_commodities_given')) {
            return [];
        }

        $methods = [];
        DB::table('fp_commodities_given')
            ->orderBy('commodity_given_id')
            ->get(['fp_id', 'commodity_name'])
            ->each(function (object $row) use (&$methods): void {
                $fpId = (int) $row->fp_id;
                if (! isset($methods[$fpId])) {
                    $methods[$fpId] = trim((string) $row->commodity_name);
                }
            });

        return $methods;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function aggregateResidentRows(
        Collection $records,
        string $visitDateColumn,
        callable $methodResolver
    ): array {
        $grouped = [];

        foreach ($records as $record) {
            if (self::belongsToNonResidentSentinel($record)) {
                continue;
            }

            $residentId = (int) ($record->resident_id ?? 0);
            if ($residentId <= 0) {
                continue;
            }

            $visitDate = trim((string) ($record->{$visitDateColumn} ?? ''));
            if ($visitDate === '') {
                continue;
            }

            if (! isset($grouped[$residentId])) {
                $grouped[$residentId] = [
                    'record' => $record,
                    'start_date' => $visitDate,
                    'last_visit' => $visitDate,
                    'method' => $methodResolver($record),
                ];

                continue;
            }

            $entry = &$grouped[$residentId];
            if ($visitDate < $entry['start_date']) {
                $entry['start_date'] = $visitDate;
            }
            if ($visitDate >= $entry['last_visit']) {
                $entry['last_visit'] = $visitDate;
                $entry['record'] = $record;
                $method = $methodResolver($record);
                if ($method !== self::EMPTY) {
                    $entry['method'] = $method;
                }
            }
        }

        $rows = [];
        foreach ($grouped as $residentId => $entry) {
            $record = $entry['record'];
            $fullName = HealthRecordsClinicalListing::fullName($record);
            $householdNo = DemoCatalog::normalizeHouseholdNo((string) ($record->household_no ?? ''));
            $memberId = HealthRecordsClinicalListing::memberId($record);
            $method = (string) ($entry['method'] ?? self::EMPTY);

            $rows[] = [
                'key' => 'fp-'.$residentId,
                'resident_id' => (int) $residentId,
                'household_no' => $householdNo,
                'member_id' => $memberId,
                'full_name' => $fullName,
                'age' => HealthRecordsClinicalListing::ageInYearsFromBirthday(
                    self::isoBirthday($record->birthday ?? null)
                ) ?? 0,
                'method' => $method,
                'start_date' => HealthRecordsClinicalListing::formatFamilyPlanningDate($entry['start_date']),
                'last_visit' => HealthRecordsClinicalListing::formatFamilyPlanningDate($entry['last_visit']),
                'next_sched' => self::EMPTY,
                'zone' => HealthRecordsClinicalListing::zoneLabel($record),
                'year' => HealthRecordsClinicalListing::yearFromDate($entry['last_visit']),
                'follow_up_status' => 'ok',
                'view_url' => $householdNo !== '' && $memberId !== ''
                    ? route('household-profiling.members.family-planning.index', [
                        'householdNo' => $householdNo,
                        'memberId' => $memberId,
                    ])
                    : '',
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcasecmp((string) $a['full_name'], (string) $b['full_name'])
        );

        return $rows;
    }

    private static function belongsToNonResidentSentinel(object $record): bool
    {
        if (NonResidentFamilyPlanningService::isSentinelHouseholdNo($record->household_no ?? null)) {
            return true;
        }

        $relation = trim((string) ($record->relation_to_household_head ?? ''));

        return strcasecmp($relation, NonResidentFamilyPlanningService::RELATION) === 0;
    }

    private static function isoBirthday(mixed $birthday): string
    {
        if ($birthday instanceof \DateTimeInterface) {
            return $birthday->format('Y-m-d');
        }

        $raw = trim((string) $birthday);
        if ($raw === '') {
            return '';
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return $raw;
        }
    }
}
