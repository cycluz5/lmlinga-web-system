<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Environmental Health report builder — zone/period filters, columns.
 */
final class EnvironmentalHealthReport
{
    public const SCOPE_ALL_ZONES = 'all_zones';

    public const SCOPE_ZONE = 'zone';

    public const PERIOD_MODE_ALL = 'all';

    public const PERIOD_MODE_YEAR = 'year';

    public const PERIOD_MODE_MONTH = 'month';

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     scope: string,
     *     zone: string,
     *     period_mode: string,
     *     period: string,
     *     year: string,
     *     month: string,
     *     program_banner: string
     * }
     */
    public static function normalizeOptions(array $input): array
    {
        $scopeRaw = strtolower(trim((string) ($input['scope'] ?? self::SCOPE_ALL_ZONES)));
        $zone = trim((string) ($input['zone'] ?? 'all'));

        if ($scopeRaw === self::SCOPE_ZONE || $scopeRaw === 'specific') {
            if ($zone !== '' && $zone !== 'all') {
                $scope = self::SCOPE_ZONE;
            } else {
                $scope = self::SCOPE_ALL_ZONES;
                $zone = 'all';
            }
        } else {
            // all_zones, citywide, or unspecified
            $scope = self::SCOPE_ALL_ZONES;
            $zone = 'all';
        }

        $periodMode = strtolower(trim((string) ($input['period_mode'] ?? '')));
        $year = trim((string) ($input['year'] ?? ''));
        $month = trim((string) ($input['month'] ?? ''));

        if ($periodMode === '' || ! in_array($periodMode, [self::PERIOD_MODE_ALL, self::PERIOD_MODE_YEAR, self::PERIOD_MODE_MONTH], true)) {
            $legacyPeriod = EnvironmentalHealthReportHeader::normalizePeriod($input);
            if ($legacyPeriod === EnvironmentalHealthReportHeader::PERIOD_ALL) {
                $periodMode = self::PERIOD_MODE_ALL;
            } elseif (preg_match('/^\d{4}-\d{2}$/', $legacyPeriod) === 1) {
                $periodMode = self::PERIOD_MODE_MONTH;
                [$year, $month] = explode('-', $legacyPeriod);
            } elseif (preg_match('/^\d{4}$/', $legacyPeriod) === 1) {
                $periodMode = self::PERIOD_MODE_YEAR;
                $year = $legacyPeriod;
            } else {
                $periodMode = self::PERIOD_MODE_ALL;
            }
        }

        if ($periodMode === self::PERIOD_MODE_YEAR) {
            if (preg_match('/^\d{4}$/', $year) !== 1) {
                $periodMode = self::PERIOD_MODE_ALL;
                $year = '';
                $month = '';
                $period = EnvironmentalHealthReportHeader::PERIOD_ALL;
            } else {
                $month = '';
                $period = $year;
            }
        } elseif ($periodMode === self::PERIOD_MODE_MONTH) {
            $monthNum = (int) $month;
            if (preg_match('/^\d{4}$/', $year) !== 1 || $monthNum < 1 || $monthNum > 12) {
                $periodMode = self::PERIOD_MODE_ALL;
                $year = '';
                $month = '';
                $period = EnvironmentalHealthReportHeader::PERIOD_ALL;
            } else {
                $month = sprintf('%02d', $monthNum);
                $period = $year.'-'.$month;
            }
        } else {
            $periodMode = self::PERIOD_MODE_ALL;
            $year = '';
            $month = '';
            $period = EnvironmentalHealthReportHeader::PERIOD_ALL;
        }

        return [
            'scope' => $scope,
            'zone' => $zone,
            'period_mode' => $periodMode,
            'period' => $period,
            'year' => $year,
            'month' => $month,
            'program_banner' => EnvironmentalHealthReportHeader::normalizeProgramBanner($input),
        ];
    }

    /**
     * Years / months present in household survey dates (date_registered).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{years: list<string>, months_by_year: array<string, list<string>>}
     */
    public static function availablePeriods(array $rows): array
    {
        $years = [];
        $monthsByYear = [];

        foreach ($rows as $row) {
            $date = trim((string) ($row['date_surveyed'] ?? ''));
            if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $matches) !== 1) {
                continue;
            }
            $year = (string) $matches[1];
            $month = (string) $matches[2];
            $years[$year] = true;
            $monthsByYear[$year][$month] = true;
        }

        $yearList = array_map('strval', array_keys($years));
        rsort($yearList, SORT_STRING);

        $normalizedMonths = [];
        foreach ($monthsByYear as $year => $months) {
            $monthList = array_map('strval', array_keys($months));
            sort($monthList, SORT_STRING);
            $normalizedMonths[(string) $year] = $monthList;
        }

        return [
            'years' => $yearList,
            'months_by_year' => $normalizedMonths,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<array<string, mixed>>|null  $allRows
     * @return list<array<string, mixed>>
     */
    public static function filteredRows(array $options, ?array $allRows = null): array
    {
        $allRows ??= EnvironmentalHealthDashboard::rows();
        $dashboardFilters = EnvironmentalHealthDashboard::normalizeFilters([
            'zone' => ($options['scope'] ?? '') === self::SCOPE_ZONE ? ($options['zone'] ?? 'all') : 'all',
        ]);

        $rows = EnvironmentalHealthDashboard::filterRows($allRows, $dashboardFilters);

        $period = (string) ($options['period'] ?? EnvironmentalHealthReportHeader::PERIOD_ALL);
        if ($period !== EnvironmentalHealthReportHeader::PERIOD_ALL) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => self::rowMatchesPeriod($row, $period)
            ));
        }

        return $rows;
    }

    /**
     * @return list<array{key: string, label: string, group: string, value_key: string}>
     */
    public static function columnDefinitions(): array
    {
        return [
            ['key' => 'household_no', 'label' => 'HH No.', 'group' => 'household', 'value_key' => 'household_no'],
            ['key' => 'house_head', 'label' => 'HH Head', 'group' => 'household', 'value_key' => 'house_head'],
            ['key' => 'zone', 'label' => 'Zone', 'group' => 'household', 'value_key' => 'zone'],
            ['key' => 'date_surveyed', 'label' => 'Date Surveyed', 'group' => 'household', 'value_key' => 'date_surveyed_label'],

            ['key' => 'water_level', 'label' => 'Level', 'group' => 'water', 'value_key' => 'water_supply_label'],
            ['key' => 'water_source_location', 'label' => 'Water Source Location', 'group' => 'water', 'value_key' => 'water_source_location_label'],
            ['key' => 'water_availability', 'label' => 'Water Availability', 'group' => 'water', 'value_key' => 'water_availability_label'],

            ['key' => 'micro_date', 'label' => 'Microbiological Validation Date', 'group' => 'validation', 'value_key' => 'microbiological_test_date_label'],
            ['key' => 'micro_result', 'label' => 'Microbiological Result', 'group' => 'validation', 'value_key' => 'microbiological_result_label'],
            ['key' => 'physico_date', 'label' => 'Physico-Chemical Test Date', 'group' => 'validation', 'value_key' => 'physicochemical_test_date_label'],
            ['key' => 'physico_result', 'label' => 'Physico-Chemical Result', 'group' => 'validation', 'value_key' => 'physicochemical_result_label'],

            ['key' => 'toilet_type', 'label' => 'Type of Toilet', 'group' => 'sanitation', 'value_key' => 'toilet_type_label'],
            ['key' => 'toilet_status', 'label' => 'Sanitary Status', 'group' => 'sanitation', 'value_key' => 'toilet_status_label'],
            ['key' => 'open_defecation', 'label' => 'Open Defecation within 20m', 'group' => 'sanitation', 'value_key' => 'open_defecation_label'],
            ['key' => 'shared_toilet', 'label' => 'Shared Toilet', 'group' => 'sanitation', 'value_key' => 'shared_toilet_label'],
            ['key' => 'sewage', 'label' => 'Sewage Disposal Method', 'group' => 'sanitation', 'value_key' => 'sewage_disposal_label'],

            ['key' => 'waste_segregation', 'label' => 'Waste Segregation', 'group' => 'waste', 'value_key' => 'waste_segregation_label'],
            ['key' => 'backyard_composting', 'label' => 'Backyard Composting', 'group' => 'waste', 'value_key' => 'backyard_composting_label'],
            ['key' => 'recycling_reuse', 'label' => 'Recycling/Reuse', 'group' => 'waste', 'value_key' => 'recycling_reuse_label'],
            ['key' => 'municipal_collection', 'label' => 'Collected by Municipality', 'group' => 'waste', 'value_key' => 'municipal_collection_label'],

            ['key' => 'basic_safe_water', 'label' => 'Basic Safe Water Status', 'group' => 'summary', 'value_key' => 'basic_safe_water_label'],
            ['key' => 'validation_status', 'label' => 'Validation/Testing Status', 'group' => 'summary', 'value_key' => 'validation_label'],
            ['key' => 'basic_sanitation', 'label' => 'Basic Sanitation Facility Status', 'group' => 'summary', 'value_key' => 'sanitation_label'],
            ['key' => 'complete_sanitation', 'label' => 'Complete Sanitation Facility Status', 'group' => 'summary', 'value_key' => 'overall_label'],
        ];
    }

    /**
     * Report always includes every section.
     *
     * @param  array<string, mixed>  $options
     * @return list<array{key: string, label: string, group: string, value_key: string}>
     */
    public static function activeColumns(array $options = []): array
    {
        return self::columnDefinitions();
    }

    /**
     * Section tables — source of truth for PDF grouped columns and Excel section blocks.
     *
     * @return list<array{key: string, title: string, columns: list<array{key: string, label: string, value_key: string}>}>
     */
    public static function sectionTables(): array
    {
        return [
            [
                'key' => 'household',
                'title' => 'A. HOUSEHOLD INFORMATION TABLE',
                'columns' => [
                    ['key' => 'household_no', 'label' => 'HH No.', 'value_key' => 'household_no'],
                    ['key' => 'house_head', 'label' => 'HH Head', 'value_key' => 'house_head'],
                    ['key' => 'zone', 'label' => 'Zone', 'value_key' => 'zone'],
                    ['key' => 'date_surveyed', 'label' => 'Date Surveyed', 'value_key' => 'date_surveyed_label'],
                ],
            ],
            [
                'key' => 'water',
                'title' => 'B. WATER SUPPLY TABLE',
                'columns' => [
                    ['key' => 'household_no', 'label' => 'HH No.', 'value_key' => 'household_no'],
                    ['key' => 'water_level', 'label' => 'Level', 'value_key' => 'water_supply_label'],
                    ['key' => 'water_source_location', 'label' => 'Water Source Location', 'value_key' => 'water_source_location_label'],
                    ['key' => 'water_availability', 'label' => 'Water Availability', 'value_key' => 'water_availability_label'],
                ],
            ],
            [
                'key' => 'validation',
                'title' => 'C. VALIDATION TABLE',
                'columns' => [
                    ['key' => 'household_no', 'label' => 'HH No.', 'value_key' => 'household_no'],
                    ['key' => 'micro_date', 'label' => 'Microbiological Validation Date', 'value_key' => 'microbiological_test_date_label'],
                    ['key' => 'micro_result', 'label' => 'Microbiological Result', 'value_key' => 'microbiological_result_label'],
                    ['key' => 'physico_date', 'label' => 'Physico-Chemical Test Date', 'value_key' => 'physicochemical_test_date_label'],
                    ['key' => 'physico_result', 'label' => 'Physico-Chemical Result', 'value_key' => 'physicochemical_result_label'],
                ],
            ],
            [
                'key' => 'sanitation',
                'title' => 'D. SANITATION TABLE',
                'columns' => [
                    ['key' => 'household_no', 'label' => 'HH No.', 'value_key' => 'household_no'],
                    ['key' => 'toilet_type', 'label' => 'Toilet Type', 'value_key' => 'toilet_type_label'],
                    ['key' => 'toilet_status', 'label' => 'Sanitary Status', 'value_key' => 'toilet_status_label'],
                    ['key' => 'open_defecation', 'label' => 'Open Defecation', 'value_key' => 'open_defecation_label'],
                    ['key' => 'shared_toilet', 'label' => 'Shared Toilet', 'value_key' => 'shared_toilet_label'],
                    ['key' => 'sewage', 'label' => 'Sewage Disposal Method', 'value_key' => 'sewage_disposal_label'],
                ],
            ],
            [
                'key' => 'waste',
                'title' => 'E. SOLID WASTE TABLE',
                'columns' => [
                    ['key' => 'household_no', 'label' => 'HH No.', 'value_key' => 'household_no'],
                    ['key' => 'waste_segregation', 'label' => 'Waste Segregation', 'value_key' => 'waste_segregation_label'],
                    ['key' => 'backyard_composting', 'label' => 'Backyard Composting', 'value_key' => 'backyard_composting_label'],
                    ['key' => 'recycling_reuse', 'label' => 'Recycling/Reuse', 'value_key' => 'recycling_reuse_label'],
                    ['key' => 'municipal_collection', 'label' => 'Collected by Municipality', 'value_key' => 'municipal_collection_label'],
                ],
            ],
            [
                'key' => 'summary',
                'title' => 'F. SUMMARY TABLE',
                'columns' => [
                    ['key' => 'household_no', 'label' => 'HH No.', 'value_key' => 'household_no'],
                    ['key' => 'basic_safe_water', 'label' => 'Basic Safe Water Status', 'value_key' => 'basic_safe_water_label'],
                    ['key' => 'validation_status', 'label' => 'Validation/Testing Status', 'value_key' => 'validation_label'],
                    ['key' => 'basic_sanitation', 'label' => 'Basic Sanitation Facility Status', 'value_key' => 'sanitation_label'],
                    ['key' => 'complete_sanitation', 'label' => 'Complete Sanitation Facility Status', 'value_key' => 'overall_label'],
                ],
            ],
        ];
    }

    /**
     * Per-household section cards — same title/field-list rules as the PDF's
     * reportSections() (household_no dropped from every section but
     * "household" itself, since it's already the card's title). Shared by
     * the PDF renderer and the Report Builder's live preview so both stay
     * in lockstep with sectionTables().
     *
     * @return list<array{key: string, title: string, fields: list<array{key: string, label: string, value_key: string}>}>
     */
    public static function cardSections(): array
    {
        $labels = self::groupLabels();
        $sections = [];

        foreach (self::sectionTables() as $section) {
            $key = (string) ($section['key'] ?? '');
            $title = match ($key) {
                'household' => 'HOUSEHOLD INFORMATION',
                'water' => 'WATER SUPPLY',
                'validation' => 'VALIDATION',
                'sanitation' => 'SANITATION',
                'waste' => 'SOLID WASTE',
                'summary' => 'SUMMARY',
                default => strtoupper((string) ($labels[$key] ?? $key)),
            };

            $fields = [];
            foreach ($section['columns'] as $column) {
                $columnKey = (string) ($column['key'] ?? '');
                if ($key !== 'household' && $columnKey === 'household_no') {
                    continue;
                }
                // Zone is already the page/card header (e.g. "ZONE 1 REPORT") — redundant as a field.
                if ($columnKey === 'zone') {
                    continue;
                }
                $fields[] = [
                    'key' => (string) ($column['key'] ?? ''),
                    'label' => (string) ($column['label'] ?? ''),
                    'value_key' => (string) ($column['value_key'] ?? ''),
                ];
            }

            $sections[] = [
                'key' => $key,
                'title' => $title,
                'fields' => $fields,
            ];
        }

        return $sections;
    }

    /**
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            'household' => 'Household',
            'water' => 'Water Supply',
            'validation' => 'Validation',
            'sanitation' => 'Sanitation',
            'waste' => 'Solid Waste',
            'summary' => 'Summary',
        ];
    }

    /**
     * Group filtered rows under zone headings (natural sort).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    public static function rowsGroupedByZone(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $zone = trim((string) ($row['zone'] ?? ''));
            if ($zone === '') {
                $zone = 'Unassigned Zone';
            }
            $groups[$zone][] = $row;
        }

        uksort($groups, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        foreach ($groups as &$zoneRows) {
            usort(
                $zoneRows,
                static fn (array $a, array $b): int => strnatcasecmp(
                    trim((string) ($a['house_head'] ?? '')),
                    trim((string) ($b['house_head'] ?? ''))
                )
            );
        }
        unset($zoneRows);

        return $groups;
    }

    /**
     * @param  list<array{key: string, label: string, group: string, value_key: string}>  $columns
     * @return list<string>
     */
    public static function csvHeaders(array $columns): array
    {
        return array_map(static fn (array $column): string => $column['label'], $columns);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{key: string, label: string, group: string, value_key: string}>  $columns
     * @return list<string>
     */
    public static function csvRowValues(array $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $column) {
            $text = trim((string) ($row[$column['value_key']] ?? ''));
            $values[] = $text === '' ? '—' : $text;
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function previewPayload(array $rows): array
    {
        $payload = [];
        foreach ($rows as $row) {
            $item = [
                '_record_status' => (string) ($row['record_status'] ?? ''),
                '_zone' => (string) ($row['zone'] ?? ''),
                '_date_surveyed' => (string) ($row['date_surveyed'] ?? ''),
            ];
            foreach (self::columnDefinitions() as $column) {
                $text = trim((string) ($row[$column['value_key']] ?? ''));
                $item[$column['key']] = $text === '' ? '—' : $text;
            }
            $payload[] = $item;
        }

        return $payload;
    }

    public static function scopeLabel(array $options): string
    {
        if (($options['scope'] ?? self::SCOPE_ALL_ZONES) === self::SCOPE_ZONE) {
            $zone = trim((string) ($options['zone'] ?? ''));

            return $zone !== '' && $zone !== 'all' ? $zone : 'Zone';
        }

        return 'All Zones';
    }

    public static function periodLabel(array $options): string
    {
        $mode = (string) ($options['period_mode'] ?? self::PERIOD_MODE_ALL);
        if ($mode === self::PERIOD_MODE_YEAR) {
            return 'Year '.((string) ($options['year'] ?? ''));
        }
        if ($mode === self::PERIOD_MODE_MONTH) {
            $year = (string) ($options['year'] ?? '');
            $month = (int) ($options['month'] ?? 0);
            if ($month >= 1 && $month <= 12) {
                return Carbon::createFromDate((int) $year, $month, 1)->format('F Y');
            }
        }

        return 'All Time';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function rowMatchesPeriod(array $row, string $period): bool
    {
        $date = trim((string) ($row['date_surveyed'] ?? ''));
        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        if (preg_match('/^\d{4}$/', $period) === 1) {
            return str_starts_with($date, $period.'-');
        }

        if (preg_match('/^\d{4}-\d{2}$/', $period) === 1) {
            return str_starts_with($date, $period.'-');
        }

        return true;
    }
}
