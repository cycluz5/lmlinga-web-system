<?php

namespace App\Support;

/**
 * Barangay-wide Risk Assessment summary (Health Records → Risk Assessment).
 *
 * UI-PHASE ONLY: fixture rows and derived summary counts for Figma-aligned
 * preview. Not persisted. Not mapped from Household Profiling DemoRiskAssessment.
 */
final class HealthRecordsRiskAssessment
{
    /**
     * Fixed zone labels shown on summary cards and in the Zone filter.
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
     *     zone: string,
     *     year: string,
     *     bmi_status: string,
     *     bp_status: string,
     *     smoking_status: string,
     *     alcohol_status: string,
     *     physical_activity_risk: string,
     *     family_history_risk: string,
     *     chronic_disease: string
     * }>
     */
    public static function rows(): array
    {
        return [
            self::row(
                'kristine-b-reyes',
                'Kristine B. Reyes',
                'Zone 1',
                '2026',
                'Normal',
                'Normal',
                'Never',
                'None',
                'Active',
                'No',
                'None'
            ),
            self::row(
                'jacob-a-magistrado',
                'Jacob A. Magistrado',
                'Zone 2',
                '2026',
                'Overweight',
                'Pre-HTN',
                'Current',
                'Moderate',
                'Inactive',
                'Yes',
                'Diabetes'
            ),
            self::row(
                'haziel-h-santos',
                'Haziel H. Santos',
                'Zone 3',
                '2026',
                'Normal',
                'Normal',
                'Never',
                'None',
                'Active',
                'No',
                'None'
            ),
            self::row(
                'andrei-b-malaya',
                'Andrei B. Malaya',
                'Zone 1',
                '2025',
                'Underweight',
                'Normal',
                'Quit',
                'None',
                'Active',
                'Yes',
                'CVD'
            ),
            self::row(
                'crisley-f-fernando',
                'Crisley F. Fernando',
                'Zone 4',
                '2026',
                'Obese',
                'HTN Stage 1',
                'Current',
                'Excessive',
                'Inactive',
                'Yes',
                'Diabetes'
            ),
            self::row(
                'gabriel-allan-s-chua',
                'Gabriel Allan S. Chua',
                'Zone 2',
                '2025',
                'Normal',
                'Normal',
                'Never',
                'Moderate',
                'Active',
                'No',
                'None'
            ),
            self::row(
                'maria-l-domingo',
                'Maria L. Domingo',
                'Zone 5',
                '2026',
                'Overweight',
                'HTN Stage 2',
                'Never',
                'None',
                'Inactive',
                'Yes',
                'CVD'
            ),
            self::row(
                'paolo-r-santos',
                'Paolo R. Santos',
                'Zone 3',
                '2024',
                'Normal',
                'Pre-HTN',
                'Quit',
                'Moderate',
                'Active',
                'No',
                'None'
            ),
        ];
    }

    /**
     * Overall dataset summary — not affected by client-side table filters.
     *
     * @return array{total: int, zones: array<string, int>}
     */
    public static function summaryCounts(?array $rows = null): array
    {
        $rows ??= self::rows();
        $zoneCounts = [];

        foreach (self::zones() as $zone) {
            $zoneCounts[$zone] = 0;
        }

        foreach ($rows as $row) {
            $zone = (string) ($row['zone'] ?? '');
            if (array_key_exists($zone, $zoneCounts)) {
                $zoneCounts[$zone]++;
            }
        }

        return [
            'total' => count($rows),
            'zones' => $zoneCounts,
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
     *     zone: string,
     *     year: string,
     *     bmi_status: string,
     *     bp_status: string,
     *     smoking_status: string,
     *     alcohol_status: string,
     *     physical_activity_risk: string,
     *     family_history_risk: string,
     *     chronic_disease: string
     * }
     */
    private static function row(
        string $key,
        string $fullName,
        string $zone,
        string $year,
        string $bmiStatus,
        string $bpStatus,
        string $smokingStatus,
        string $alcoholStatus,
        string $physicalActivityRisk,
        string $familyHistoryRisk,
        string $chronicDisease
    ): array {
        return [
            'key' => $key,
            'full_name' => $fullName,
            'zone' => $zone,
            'year' => $year,
            'bmi_status' => $bmiStatus,
            'bp_status' => $bpStatus,
            'smoking_status' => $smokingStatus,
            'alcohol_status' => $alcoholStatus,
            'physical_activity_risk' => $physicalActivityRisk,
            'family_history_risk' => $familyHistoryRisk,
            'chronic_disease' => $chronicDisease,
        ];
    }
}
