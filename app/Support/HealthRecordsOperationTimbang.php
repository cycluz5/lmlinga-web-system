<?php

namespace App\Support;

/**
 * Barangay-wide Operation Timbang monitoring summary (Health Records → Child Care).
 *
 * FR-30: monitoring rows, summaries, zones, and years are read-only views of
 * {@see TimbangRecord} via {@see OperationTimbangMonitoringService}.
 *
 * Status labels Below Normal / Normal / Above Normal remain filter option keys
 * for UI continuity only. Row status is unclassified until an approved clinical
 * algorithm exists — do NOT derive nutritional status from weight/height/MUAC.
 */
final class HealthRecordsOperationTimbang
{
    /**
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
    public static function summaryCards(?int $year = null, ?int $month = null): array
    {
        $service = self::service();
        $year = $service->resolveYear($year);
        $month = $service->resolveMonth($month);

        return $service->summaryCardsForYearMonth($year, $month);
    }

    /**
     * Years available in the year selector.
     *
     * Derived from persisted measurement years ∪ current server year
     * ({@see OperationTimbangMonitoringService::availableYears()}).
     *
     * @return list<int>
     */
    public static function years(): array
    {
        return self::service()->availableYears();
    }

    /**
     * Month session pills for a given year.
     *
     * Visible pill label is the month name only; the year selector is authoritative
     * for which calendar year each pill represents. Future months (after server "now")
     * are flagged so the UI can disable them; monitoring still enforces empty data
     * server-side via {@see OperationTimbangMonitoringService::isFuturePeriod()}.
     *
     * @return list<array{month: int, year: int, key: string, label: string, is_future: bool}>
     */
    public static function monthSessions(int $year): array
    {
        $service = self::service();
        $sessions = [];

        for ($month = 1; $month <= 12; $month++) {
            $sessions[] = [
                'month' => $month,
                'year' => $year,
                'key' => sprintf('%04d-%02d', $year, $month),
                'label' => date('F', mktime(0, 0, 0, $month, 1, $year)),
                'is_future' => $service->isFuturePeriod($year, $month),
            ];
        }

        return $sessions;
    }

    /**
     * Child Care–eligible residents for the selected weigh-in year/month.
     *
     * @return list<array<string, mixed>>
     */
    public static function monitoringRows(?int $year = null, ?int $month = null): array
    {
        $service = self::service();
        $year = $service->resolveYear($year);
        $month = $service->resolveMonth($month);

        return $service->monitoringRowsForYearMonth($year, $month);
    }

    /**
     * Zone/sex/status-filtered rows for the selected weigh-in year/month —
     * the Operation Timbang Report Builder's data source.
     *
     * @return list<array<string, mixed>>
     */
    public static function exportRows(
        ?int $year = null,
        ?int $month = null,
        ?string $zone = null,
        ?string $sex = null,
        ?string $status = null,
    ): array {
        return array_values(array_filter(
            self::monitoringRows($year, $month),
            static function (array $row) use ($zone, $sex, $status): bool {
                if ($zone !== null && trim((string) ($row['zone'] ?? '')) !== $zone) {
                    return false;
                }
                if ($sex !== null && (string) ($row['sex'] ?? '') !== $sex) {
                    return false;
                }
                if ($status !== null && (string) ($row['status'] ?? '') !== $status) {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * "July 2026" style label for the selected weigh-in session.
     */
    public static function sessionLabel(int $year, int $month): string
    {
        return date('F Y', mktime(0, 0, 0, $month, 1, $year));
    }

    /**
     * Status filter options for the Operation Timbang toolbar.
     *
     * Classification values are display/filter keys only — they are not calculated
     * from anthropometry until an approved algorithm exists.
     *
     * @return array<string, string>
     */
    public static function statusFilterOptions(): array
    {
        return [
            'all' => 'Status',
            'unclassified' => '—',
            'below-normal' => 'Below Normal',
            'normal' => 'Normal',
            'above-normal' => 'Above Normal',
        ];
    }

    /**
     * Human-readable status label for a status key.
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'below-normal' => 'Below Normal',
            'above-normal' => 'Above Normal',
            'unclassified' => '—',
            default => 'Normal',
        };
    }

    /**
     * Zones from authoritative household data for Child Care–eligible residents.
     *
     * @return list<string>
     */
    public static function zones(): array
    {
        return self::service()->zones();
    }

    private static function service(): OperationTimbangMonitoringService
    {
        return app(OperationTimbangMonitoringService::class);
    }
}
