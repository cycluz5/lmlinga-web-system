<?php

namespace App\Support;

/**
 * UI DEVELOPMENT FIXTURE — replace with backend/database aggregate counts during integration.
 *
 * Temporary in-memory dashboard totals for layout and readability review.
 * These values are not household catalog counts, not session records, and not
 * production aggregations. The Blade consumes this normalized structure so a
 * later DashboardService can swap the source without redesigning the page.
 */
final class DashboardUiData
{
    /**
     * Normalized top-summary counts used by the Dashboard home view.
     *
     * @return array{
     *     totalHouseholds: int,
     *     totalResidents: int,
     *     nhts: int,
     *     nonNhts: int,
     *     nonNhtsPoor: int
     * }
     */
    public static function summaryCounts(): array
    {
        return [
            'totalHouseholds' => 635,
            'totalResidents' => 2103,
            'nhts' => 418,
            'nonNhts' => 217,
            'nonNhtsPoor' => 94,
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
            [
                'key' => 'non-nhts-poor',
                'label' => 'Non NHTS Poor',
                'value' => $counts['nonNhtsPoor'],
                'icon' => 'bi-clipboard-heart-fill',
            ],
        ];
    }

    /**
     * Household snapshot table (UI fixture rows only).
     *
     * @return list<array{hhNo: string, hhHead: string, zone: string, street: string, members: int}>
     */
    public static function householdSnapshot(): array
    {
        return [
            [
                'hhNo' => 'HH-151',
                'hhHead' => 'Mark David Reyes',
                'zone' => '1',
                'street' => 'Rizal Street',
                'members' => 5,
            ],
            [
                'hhNo' => 'HH-204',
                'hhHead' => 'Ana Marie Santos',
                'zone' => '2',
                'street' => 'Mabini Street',
                'members' => 4,
            ],
            [
                'hhNo' => 'HH-318',
                'hhHead' => 'Jose Luis Cruz',
                'zone' => '3',
                'street' => 'Bonifacio Avenue',
                'members' => 6,
            ],
            [
                'hhNo' => 'HH-422',
                'hhHead' => 'Liza Gomez',
                'zone' => '4',
                'street' => 'Lopez Jaena Street',
                'members' => 3,
            ],
        ];
    }

    /**
     * Health indicator tiles (UI DEVELOPMENT FIXTURE — not database aggregation).
     *
     * Intended future sources (do not query here):
     * - Teenage Pregnant: Maternal Care records, active pregnancy, resident age < 19
     * - Pregnant: Maternal Care records with active pregnancy
     * - Lactating: maternal/postnatal records currently breastfeeding
     * - FP Current User: Family Planning records with active user status
     * - FP Unmet Needs: Family Planning records with missed appointments (LMLinga definition)
     * - Normal / Underweight / Overweight Children: Child Nutrition recorded status (0–5 for Normal)
     * - Exclusively Breastfed Infants: child records, age 0–6 months, exclusive breastfeeding
     * - Infants 0–11 Months: resident date of birth, age 0–11 months
     * - HH With Large Family Size: households with member count >= 6 (household unit)
     * - HH With Potable Water Source: household water source classified as safely managed
     * - HH With Sanitary Toilet: household toilet classified as sanitary
     *
     * Infants Given Complementary Food is not a maintained LMLinga record and is omitted.
     *
     * @return list<array{key: string, label: string, value: int, icon: string, tone: string}>
     */
    public static function healthIndicators(): array
    {
        return [
            ['key' => 'teenage-pregnant', 'label' => 'Teenage Pregnant', 'value' => 8, 'icon' => 'lml-pregnant', 'tone' => 'maternal'],
            ['key' => 'pregnant', 'label' => 'Pregnant', 'value' => 34, 'icon' => 'lml-pregnant', 'tone' => 'maternal'],
            ['key' => 'lactating', 'label' => 'Lactating', 'value' => 21, 'icon' => 'lml-breastfeeding', 'tone' => 'maternal'],
            ['key' => 'fp-current-user', 'label' => 'FP Current User', 'value' => 64, 'icon' => 'lml-family', 'tone' => 'fp'],
            ['key' => 'fp-unmet-needs', 'label' => 'FP Unmet Needs', 'value' => 19, 'icon' => 'lml-family-alert', 'tone' => 'fp'],
            ['key' => 'normal-weight', 'label' => 'Normal Weight Children', 'value' => 86, 'icon' => 'lml-child-normal', 'tone' => 'nutrition'],
            ['key' => 'underweight', 'label' => 'Underweight Children', 'value' => 17, 'icon' => 'lml-child-under', 'tone' => 'attention'],
            ['key' => 'overweight', 'label' => 'Overweight Children', 'value' => 9, 'icon' => 'lml-child-over', 'tone' => 'attention'],
            ['key' => 'exclusively-breastfed', 'label' => 'Exclusively Breastfed Infants', 'value' => 28, 'icon' => 'lml-breastfeeding', 'tone' => 'infant'],
            ['key' => 'infants-0-11', 'label' => 'Infants 0–11 Months', 'value' => 41, 'icon' => 'lml-infant', 'tone' => 'infant'],
            ['key' => 'hh-large-family', 'label' => 'HH With Large Family Size', 'value' => 53, 'icon' => 'lml-family', 'tone' => 'household'],
            ['key' => 'hh-potable-water', 'label' => 'HH With Potable Water Source', 'value' => 498, 'icon' => 'lml-droplet', 'tone' => 'household'],
            ['key' => 'hh-sanitary-toilet', 'label' => 'HH With Sanitary Toilet', 'value' => 471, 'icon' => 'lml-toilet', 'tone' => 'household'],
        ];
    }
}
