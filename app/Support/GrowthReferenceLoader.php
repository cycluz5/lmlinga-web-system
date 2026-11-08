<?php

namespace App\Support;

/**
 * Reusable loader for the digitized WHO/NNC growth references under
 * resources/growth-references/. Nothing here invents or approximates
 * missing reference rows — a lookup miss returns null and callers must treat
 * that as "unavailable", never guess.
 *
 * Per-request in-memory cache only (static array), cleared per PHP process.
 */
final class GrowthReferenceLoader
{
    private const STATUS_THRESHOLDS_FILE = 'who_status_thresholds.csv';

    private const NNC_GROWTH_STANDARDS_FILE = 'nnc_growth_standards.json';

    private const WEIGHT_FOR_LENGTH_HEIGHT_FILE = 'who_weight_for_length_height_zscore.csv';

    /** @var array<string, list<array<string, string>>> */
    private static array $csvCache = [];

    /** @var array<string, mixed>|null */
    private static ?array $nncCache = null;

    /** @var array<string, array<string, list<float>>> */
    private static array $weightForLengthHeightCache = [];

    /**
     * Map a Resident.sex value to the WHO reference file's Sex label.
     * Male -> Boy, Female -> Girl. Unknown/blank -> null (never guessed).
     */
    public static function sexLabel(mixed $sex): ?string
    {
        $raw = strtolower(trim((string) $sex));

        return match ($raw) {
            'male', 'm', 'boy' => 'Boy',
            'female', 'f', 'girl' => 'Girl',
            default => null,
        };
    }

    /**
     * Map a Resident.sex value to the NNC growth-standards.json sex key.
     * Unknown/blank -> null (never guessed).
     */
    public static function nncSexKey(mixed $sex): ?string
    {
        $raw = strtolower(trim((string) $sex));

        return match ($raw) {
            'male', 'm', 'boy' => 'male',
            'female', 'f', 'girl' => 'female',
            default => null,
        };
    }

    /**
     * who_status_thresholds.csv rows for MUAC, Absolute_cm method
     * (6-59 completed months). The Z-score MUAC rows in the same file are not
     * used — the project's approved rule for this age band is the absolute-cm
     * cutoffs.
     *
     * @return list<array<string, string>>
     */
    public static function muacAbsoluteThresholds(): array
    {
        return self::thresholdRows('MUAC', 'Absolute_cm');
    }

    /**
     * National Nutrition Council (DOH) Weight-for-Age row for one completed
     * age in months (0-71), by sex. Columns:
     * [severe, underweightFrom, underweightTo, normalFrom, normalTo].
     * Only severe/normalFrom/normalTo are used for classification — see
     * NutritionAssessmentService::classifyWeightForAge().
     *
     * @return list<float>|null
     */
    public static function nncWeightForAgeRow(string $sexKey, int $ageMonths): ?array
    {
        $row = self::loadNnc()['weightForAge'][$sexKey][(string) self::clampNncAge($ageMonths)] ?? null;

        return is_array($row) ? array_map(floatval(...), $row) : null;
    }

    /**
     * National Nutrition Council (DOH) Height-for-Age row for one completed
     * age in months (0-71), by sex. Columns:
     * [severeStunted, stuntedFrom, stuntedTo, normalFrom, normalTo, tall].
     * Only severeStunted/normalFrom/normalTo are used for classification —
     * see NutritionAssessmentService::classifyHeightForAge().
     *
     * @return list<float>|null
     */
    public static function nncHeightForAgeRow(string $sexKey, int $ageMonths): ?array
    {
        $row = self::loadNnc()['heightForAge'][$sexKey][(string) self::clampNncAge($ageMonths)] ?? null;

        return is_array($row) ? array_map(floatval(...), $row) : null;
    }

    private static function clampNncAge(int $ageMonths): int
    {
        return max(0, min(71, $ageMonths));
    }

    /**
     * WHO Weight-for-Length/Height SD-position row
     * (resources/growth-references/who_weight_for_length_height_zscore.csv),
     * by sex ('Boy'/'Girl' — see sexLabel()), indicator ('Weight-for-length'
     * or 'Weight-for-height'), and the resident's length/height in cm.
     *
     * The reference is tabulated every 0.5cm (not by age), so the lookup
     * rounds to the nearest 0.5cm grid point — the same precision WHO's own
     * printed job-aid tables are designed to be read at — rather than
     * inventing an interpolated value between rows. Null when sex,
     * indicator, or the rounded row is out of the tabulated range.
     *
     * @return list<float>|null [SD_neg3, SD_neg2, SD_neg1, Median, SD_pos1, SD_pos2, SD_pos3]
     */
    public static function whoWeightForLengthOrHeightRow(string $sexLabel, string $indicator, float $lengthOrHeightCm): ?array
    {
        $rows = self::weightForLengthHeightRowsByCm($indicator, $sexLabel);
        $key = number_format(round($lengthOrHeightCm * 2) / 2, 1, '.', '');

        return $rows[$key] ?? null;
    }

    /**
     * @return array<string, list<float>>
     */
    private static function weightForLengthHeightRowsByCm(string $indicator, string $sexLabel): array
    {
        $cacheKey = "{$indicator}:{$sexLabel}";
        if (isset(self::$weightForLengthHeightCache[$cacheKey])) {
            return self::$weightForLengthHeightCache[$cacheKey];
        }

        $byCm = [];
        foreach (self::loadCsv(self::WEIGHT_FOR_LENGTH_HEIGHT_FILE) as $row) {
            if (($row['Indicator'] ?? null) !== $indicator || ($row['Sex'] ?? null) !== $sexLabel) {
                continue;
            }

            if (! isset($row['Length_or_Height_cm']) || ! is_numeric($row['Length_or_Height_cm'])) {
                continue;
            }

            $key = number_format((float) $row['Length_or_Height_cm'], 1, '.', '');
            $byCm[$key] = [
                (float) ($row['SD_neg3'] ?? 0),
                (float) ($row['SD_neg2'] ?? 0),
                (float) ($row['SD_neg1'] ?? 0),
                (float) ($row['Median'] ?? 0),
                (float) ($row['SD_pos1'] ?? 0),
                (float) ($row['SD_pos2'] ?? 0),
                (float) ($row['SD_pos3'] ?? 0),
            ];
        }

        return self::$weightForLengthHeightCache[$cacheKey] = $byCm;
    }

    /**
     * @return list<array<string, string>>
     */
    private static function thresholdRows(string $indicator, string $method): array
    {
        return array_values(array_filter(
            self::loadCsv(self::STATUS_THRESHOLDS_FILE),
            static fn (array $row): bool => ($row['Indicator'] ?? null) === $indicator
                && ($row['Classification_Method'] ?? null) === $method
        ));
    }

    /**
     * @return list<array<string, string>>
     */
    private static function loadCsv(string $filename): array
    {
        $cacheKey = "raw:{$filename}";
        if (isset(self::$csvCache[$cacheKey])) {
            return self::$csvCache[$cacheKey];
        }

        $path = resource_path('growth-references/'.$filename);
        $rows = [];

        if (is_file($path) && ($handle = fopen($path, 'rb')) !== false) {
            $header = fgetcsv($handle);
            if ($header !== false) {
                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) !== count($header)) {
                        continue;
                    }

                    /** @var array<string, string> $mapped */
                    $mapped = array_combine($header, $data);
                    $rows[] = $mapped;
                }
            }
            fclose($handle);
        }

        self::$csvCache[$cacheKey] = $rows;

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadNnc(): array
    {
        if (self::$nncCache !== null) {
            return self::$nncCache;
        }

        $path = resource_path('growth-references/'.self::NNC_GROWTH_STANDARDS_FILE);
        if (! is_file($path)) {
            return self::$nncCache = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$nncCache = is_array($decoded) ? $decoded : [];
    }
}
