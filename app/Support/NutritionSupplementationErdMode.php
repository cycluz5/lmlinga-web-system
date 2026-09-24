<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative ERD nutrition_supplementation contract helpers.
 */
final class NutritionSupplementationErdMode
{
    private static ?array $supplementTypes = null;

    private static ?array $ageGroups = null;

    public static function vitaminASupplementType(): string
    {
        return 'Vitamin A';
    }

    public static function mnpSupplementType(): string
    {
        return 'MNP';
    }

    public static function lnsSqSupplementType(): string
    {
        return 'LNS-SQ';
    }

    public static function ageGroup6to11Months(): string
    {
        return '6-11 Months';
    }

    public static function ageGroup12to23Months(): string
    {
        return '12-23 Months';
    }

    public static function ageGroup12to59Months(): string
    {
        return '12-59 Months';
    }

    public static function usesStrictEnumDomain(): bool
    {
        return self::allowedSupplementTypes() !== []
            && self::allowedAgeGroups() !== [];
    }

    /**
     * @return list<string>
     */
    public static function allowedSupplementTypes(): array
    {
        if (self::$supplementTypes === null) {
            self::$supplementTypes = self::detectEnumValues('supplement_type');
        }

        return self::$supplementTypes;
    }

    /**
     * @return list<string>
     */
    public static function allowedAgeGroups(): array
    {
        if (self::$ageGroups === null) {
            self::$ageGroups = self::detectEnumValues('age_group');
        }

        return self::$ageGroups;
    }

    public static function isValidSupplementType(string $value): bool
    {
        $allowed = self::allowedSupplementTypes();

        return $allowed === [] || in_array($value, $allowed, true);
    }

    public static function isValidAgeGroup(string $value): bool
    {
        $allowed = self::allowedAgeGroups();

        return $allowed === [] || in_array($value, $allowed, true);
    }

    public static function isValidDoseNumber(int $value): bool
    {
        return $value >= 1 && $value <= 255;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function isWritablePayloadValid(array $payload): bool
    {
        if (! isset($payload['supplement_type'], $payload['age_group'], $payload['dose_number'])) {
            return false;
        }

        if (! self::isValidSupplementType((string) $payload['supplement_type'])) {
            return false;
        }

        if (! self::isValidAgeGroup((string) $payload['age_group'])) {
            return false;
        }

        return self::isValidDoseNumber((int) $payload['dose_number']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $payload): array
    {
        if (isset($payload['supplement_type'])) {
            $payload['supplement_type'] = self::normalizeSupplementType((string) $payload['supplement_type']);
        }

        if (isset($payload['age_group'])) {
            $payload['age_group'] = self::normalizeAgeGroup((string) $payload['age_group']);
        }

        return $payload;
    }

    public static function normalizeSupplementType(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        $allowed = self::allowedSupplementTypes();
        if ($allowed !== [] && in_array($trimmed, $allowed, true)) {
            return $trimmed;
        }

        $lower = strtolower($trimmed);
        if (str_contains($lower, 'vitamin a') || str_contains($lower, 'vit a') || $lower === 'va') {
            return self::vitaminASupplementType();
        }

        if (str_contains($lower, 'mnp')) {
            return 'MNP';
        }

        if (str_contains($lower, 'lns-sq') || str_contains($lower, 'lns sq')) {
            return 'LNS-SQ';
        }

        return $trimmed;
    }

    public static function normalizeAgeGroup(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        $allowed = self::allowedAgeGroups();
        if ($allowed !== [] && in_array($trimmed, $allowed, true)) {
            return $trimmed;
        }

        $lower = strtolower($trimmed);
        if (preg_match('/6\s*[-–to]+\s*11/', $lower) === 1) {
            return self::ageGroup6to11Months();
        }

        if (preg_match('/12\s*[-–to]+\s*23/', $lower) === 1) {
            return self::ageGroup12to23Months();
        }

        if (preg_match('/12\s*[-–to]+\s*59|60\s*[-–to]+\s*71/', $lower) === 1) {
            return self::ageGroup12to59Months();
        }

        return $trimmed;
    }

    public static function resetCachedState(): void
    {
        self::$supplementTypes = null;
        self::$ageGroups = null;
    }

    /**
     * @return list<string>
     */
    private static function detectEnumValues(string $column): array
    {
        if (! Schema::hasTable('nutrition_supplementation')
            || ! Schema::hasColumn('nutrition_supplementation', $column)) {
            return [];
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return [];
        }

        $row = DB::selectOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['nutrition_supplementation', $column]
        );

        return self::parseEnumValues((string) ($row->COLUMN_TYPE ?? ''));
    }

    /**
     * @return list<string>
     */
    private static function parseEnumValues(string $columnType): array
    {
        if (! preg_match("/^enum\((.*)\)$/i", $columnType, $matches)) {
            return [];
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $valueMatches);

        return $valueMatches[1] ?? [];
    }
}
