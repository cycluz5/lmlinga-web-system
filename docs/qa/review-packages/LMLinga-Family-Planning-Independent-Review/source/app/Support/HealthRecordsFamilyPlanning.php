<?php

namespace App\Support;

/**
 * Barangay-wide Family Planning summary (Health Records → Family Planning).
 *
 * UI-PHASE ONLY: fixture rows and derived summary counts for Figma-aligned
 * preview. Not persisted. Not mapped from Household Profiling DemoFamilyPlanning.
 */
final class HealthRecordsFamilyPlanning
{
    /**
     * Fixed zone labels for the Zone filter.
     *
     * @return list<string>
     */
    public static function zones(): array
    {
        return [
            'Zone 1',
            'Zone 2',
            'Zone 3',
            'Zone 4',
            'Zone 5',
        ];
    }

    /**
     * UI-phase monitoring rows. Summary cards and Year options derive from these.
     *
     * @return list<array{
     *     key: string,
     *     full_name: string,
     *     age: int,
     *     method: string,
     *     start_date: string,
     *     last_visit: string,
     *     next_sched: string,
     *     zone: string,
     *     year: string,
     *     follow_up_status: string
     * }>
     */
    public static function rows(): array
    {
        return [
            self::row(
                'kristine-b-reyes',
                'Kristine B. Reyes',
                32,
                'Pills',
                '02/12/26',
                '03/12/26',
                '03/12/26',
                'Zone 1',
                '2026',
                'ok'
            ),
            self::row(
                'jacob-a-magistrado',
                'Jacob A. Magistrado',
                30,
                'Condom',
                '04/22/26',
                '04/21/26',
                '05/12/26',
                'Zone 2',
                '2026',
                'ok'
            ),
            self::row(
                'haziel-h-santos',
                'Haziel H. Santos',
                40,
                'Pills',
                '02/12/26',
                '05/12/26',
                '05/30/26',
                'Zone 3',
                '2026',
                'ok'
            ),
            self::row(
                'andrei-b-malaya',
                'Andrei B. Malaya',
                39,
                'BTL',
                '03/20/26',
                '04/12/26',
                '05/29/26',
                'Zone 1',
                '2026',
                'ok'
            ),
            self::row(
                'crisley-f-fernando',
                'Crisley F. Fernando',
                27,
                'Condom',
                '04/22/26',
                '05/29/26',
                '05/30/26',
                'Zone 4',
                '2026',
                'ok'
            ),
            self::row(
                'gabriel-allan-s-chua',
                'Gabriel Allan S. Chua',
                38,
                'Injectable',
                '05/22/26',
                '05/30/26',
                '06/30/26',
                'Zone 2',
                '2026',
                'ok'
            ),
        ];
    }

    /**
     * Overall dataset summary — not affected by client-side table filters.
     *
     * @return array{total: int, due: int, missed: int}
     */
    public static function summaryCounts(?array $rows = null): array
    {
        $rows ??= self::rows();
        $due = 0;
        $missed = 0;

        foreach ($rows as $row) {
            $status = (string) ($row['follow_up_status'] ?? 'ok');
            if ($status === 'due') {
                $due++;
            } elseif ($status === 'missed') {
                $missed++;
            }
        }

        return [
            'total' => count($rows),
            'due' => $due,
            'missed' => $missed,
        ];
    }

    /**
     * Distinct years from fixture rows (descending), for the Year filter.
     *
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
     * @return array{
     *     key: string,
     *     full_name: string,
     *     age: int,
     *     method: string,
     *     start_date: string,
     *     last_visit: string,
     *     next_sched: string,
     *     zone: string,
     *     year: string,
     *     follow_up_status: string
     * }
     */
    private static function row(
        string $key,
        string $fullName,
        int $age,
        string $method,
        string $startDate,
        string $lastVisit,
        string $nextSched,
        string $zone,
        string $year,
        string $followUpStatus
    ): array {
        return [
            'key' => $key,
            'full_name' => $fullName,
            'age' => $age,
            'method' => $method,
            'start_date' => $startDate,
            'last_visit' => $lastVisit,
            'next_sched' => $nextSched,
            'zone' => $zone,
            'year' => $year,
            'follow_up_status' => $followUpStatus,
        ];
    }
}
