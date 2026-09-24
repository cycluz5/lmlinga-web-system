<?php

namespace App\Support;

/**
 * Barangay-wide Vitamin A monitoring summary (Health Records → Child Care → Vitamin A).
 *
 * Monitoring rows and zone filters come only from persisted child nutrition records.
 */
final class HealthRecordsVitaminA
{
    public const EMPTY_CELL = '';

    /**
     * @return list<array{key: string, label: string, min_months: int, max_months: int}>
     */
    public static function ageGroups(): array
    {
        return [
            [
                'key' => '6-11',
                'label' => '6 – 11 mos. old',
                'min_months' => 6,
                'max_months' => 11,
            ],
            [
                'key' => '12-59',
                'label' => '12 – 59 mos. old',
                'min_months' => 12,
                'max_months' => 59,
            ],
            [
                'key' => '60-71',
                'label' => '60 – 71 mos. old',
                'min_months' => 60,
                'max_months' => 71,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function monitoringRows(?string $zone = null, ?string $year = null, ?string $month = null): array
    {
        return app(VitaminAMonitoringService::class)->monitoringRows($zone, $year, $month);
    }

    /**
     * @return list<string>
     */
    public static function zones(): array
    {
        return app(VitaminAMonitoringService::class)->zones();
    }

    /**
     * Distinct calendar years with a persisted Vitamin A dose date — the
     * Vitamin A page's year selector.
     *
     * @return list<string>
     */
    public static function years(): array
    {
        return app(VitaminAMonitoringService::class)->years();
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
     * the year/month filters are active. Used for the Vitamin A export's
     * scope line.
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
}
