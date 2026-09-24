<?php

namespace App\Support;

/**
 * Dashboard home presentation shape.
 *
 * Numeric / unavailable authority comes from DashboardStatistics (DB21).
 * This class keeps frozen labels, keys, icons, and tones only.
 */
final class DashboardUiData
{
    /**
     * Normalized top-summary values used by the Dashboard home view.
     *
     * @return array{
     *     totalHouseholds: int,
     *     totalResidents: int,
     *     nhts: int,
     *     nonNhts: int
     * }
     */
    public static function summaryCounts(): array
    {
        $stats = DashboardStatistics::summary();

        return [
            'totalHouseholds' => $stats['totalHouseholds'],
            'totalResidents' => $stats['totalResidents'],
            'nhts' => $stats['nhts'],
            'nonNhts' => $stats['nonNhts'],
        ];
    }

    /**
     * Primary (top) summary cards.
     *
     * @return list<array{key: string, label: string, value: int, icon: string}>
     */
    public static function primaryCards(): array
    {
        $counts = self::summaryCounts();

        return [
            [
                'key' => 'households',
                'label' => 'Total Household',
                'value' => $counts['totalHouseholds'],
                'icon' => 'bi-house-door-fill',
            ],
            [
                'key' => 'residents',
                'label' => 'Total Residents',
                'value' => $counts['totalResidents'],
                'icon' => 'bi-people-fill',
            ],
            [
                'key' => 'nhts',
                'label' => 'NHTS',
                'value' => $counts['nhts'],
                'icon' => 'bi-person-badge-fill',
            ],
            [
                'key' => 'non-nhts',
                'label' => 'Non NHTS',
                'value' => $counts['nonNhts'],
                'icon' => 'bi-person-lines-fill',
            ],
        ];
    }

    /**
     * Household snapshot table rows (DB-backed; empty when none).
     *
     * @return list<array{hhNo: string, hhHead: string, zone: string, street: string, members: int}>
     */
    public static function householdSnapshot(): array
    {
        return DashboardStatistics::householdSnapshot();
    }

    /**
     * Zone-based household and population cards for the Dashboard home view.
     *
     * @return list<array{
     *     key: string,
     *     zone: string,
     *     zoneNumber: int,
     *     households: int,
     *     population: int,
     *     householdIcon: string,
     *     populationIcon: string
     * }>
     */
    public static function zoneSummaryCards(): array
    {
        $cards = [];

        foreach (DashboardStatistics::zoneSummary() as $row) {
            $zoneNumber = (int) $row['zoneNumber'];
            $cards[] = [
                'key' => 'zone-'.$zoneNumber,
                'zone' => (string) $row['zone'],
                'zoneNumber' => $zoneNumber,
                'households' => (int) $row['households'],
                'population' => (int) $row['population'],
                'householdIcon' => 'bi-house-door-fill',
                'populationIcon' => 'bi-people-fill',
            ];
        }

        return $cards;
    }

    /**
     * Health indicator tiles — every value is computed from persisted
     * resident/household records (see DashboardStatistics); none are
     * placeholder/unavailable markers.
     *
     * @return list<array{key: string, label: string, value: int, icon: string, tone: string}>
     */
    public static function healthIndicators(): array
    {
        $stats = DashboardStatistics::summary();

        return [
            ['key' => 'teenage-pregnant', 'label' => 'Teenage Pregnant', 'value' => $stats['teenagePregnant'], 'icon' => 'lml-pregnant', 'tone' => 'maternal'],
            ['key' => 'pregnant', 'label' => 'Pregnant', 'value' => $stats['pregnant'], 'icon' => 'lml-pregnant', 'tone' => 'maternal'],
            ['key' => 'fp-current-user', 'label' => 'FP Current User', 'value' => $stats['fpCurrentUser'], 'icon' => 'lml-family', 'tone' => 'fp'],
            ['key' => 'normal-weight', 'label' => 'Normal Weight Children', 'value' => $stats['normalWeight'], 'icon' => 'lml-child-normal', 'tone' => 'nutrition'],
            ['key' => 'underweight', 'label' => 'Underweight Children', 'value' => $stats['underweight'], 'icon' => 'lml-child-under', 'tone' => 'attention'],
            ['key' => 'overweight', 'label' => 'Overweight Children', 'value' => $stats['overweight'], 'icon' => 'lml-child-over', 'tone' => 'attention'],
            ['key' => 'infants-0-11', 'label' => 'Infants 0–11 Months', 'value' => $stats['infants011'], 'icon' => 'lml-infant', 'tone' => 'infant'],
            ['key' => 'hh-large-family', 'label' => 'HH With Large Family Size', 'value' => $stats['hhLargeFamily'], 'icon' => 'lml-family', 'tone' => 'household'],
            ['key' => 'hh-potable-water', 'label' => 'HH With Potable Water Source', 'value' => $stats['hhPotableWater'], 'icon' => 'lml-droplet', 'tone' => 'household'],
            ['key' => 'hh-sanitary-toilet', 'label' => 'HH With Sanitary Toilet', 'value' => $stats['hhSanitaryToilet'], 'icon' => 'lml-toilet', 'tone' => 'household'],
        ];
    }

    /**
     * Format a card/indicator value for Blade. Every metric is a real
     * count now — missing data renders as "0", never a dash.
     */
    public static function formatMetricValue(int|string $value): string
    {
        return number_format((int) $value);
    }
}
