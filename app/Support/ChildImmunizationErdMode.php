<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Detects authoritative ERD child_immunization persistence vs Laravel child_immunizations.
 *
 * Header mode (isActive) is independent of immunization_doses / fic_cic_status shape.
 */
final class ChildImmunizationErdMode
{
    /** @var array<string, string> */
    public const UI_TO_ERD_VACCINE_TYPE = [
        'bcg' => 'BCG',
        'hepa-b' => 'Hepa B',
        'dpt-hib-hepb' => 'DPT-HIB-HepB',
        'opv' => 'OPV',
        'ipv' => 'IPV',
        'pcv' => 'PCV',
        'mmr' => 'MMR',
    ];

    private static ?bool $active = null;

    /** @var array{dose_number: bool, dose_index: bool, dose_id: bool, id: bool}|null */
    private static ?array $doseColumns = null;

    private static ?bool $usesFicCic = null;

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = Schema::hasTable('child_immunization')
                && ! Schema::hasTable('child_immunizations');
        }

        return self::$active;
    }

    public static function headerTable(): string
    {
        return self::isActive() ? 'child_immunization' : 'child_immunizations';
    }

    public static function headerPrimaryKey(): string
    {
        return self::isActive() ? 'child_immunization_id' : 'id';
    }

    public static function usesDoseNumberSchema(): bool
    {
        $columns = self::doseTableColumns();

        return $columns['dose_number'] && ! $columns['dose_index'];
    }

    public static function usesDoseIdPrimaryKey(): bool
    {
        return self::doseTableColumns()['dose_id'];
    }

    public static function usesErdVaccineTypeLabels(): bool
    {
        return self::doseTableColumns()['dose_number'];
    }

    public static function dosePrimaryKey(): string
    {
        return self::usesDoseIdPrimaryKey() ? 'dose_id' : 'id';
    }

    public static function doseSequenceColumn(): string
    {
        $columns = self::doseTableColumns();

        if ($columns['dose_number']) {
            return 'dose_number';
        }

        return 'dose_index';
    }

    public static function usesFicCicStatusTable(): bool
    {
        if (self::$usesFicCic === null) {
            self::$usesFicCic = Schema::hasTable('fic_cic_status')
                && Schema::hasColumn('fic_cic_status', 'child_immunization_id')
                && Schema::hasColumn('fic_cic_status', 'fic_completed')
                && Schema::hasColumn('fic_cic_status', 'cic_completed');
        }

        return self::$usesFicCic;
    }

    /**
     * Match attributes for immunization_doses upsert using the live dose schema.
     *
     * @return array<string, mixed>
     */
    public static function doseMatchAttributes(int|string $headerId, string $uiVaccineKey, int $doseIndex): array
    {
        $sequenceColumn = self::doseSequenceColumn();
        $sequenceValue = $sequenceColumn === 'dose_number'
            ? self::doseNumberFromIndex($doseIndex)
            : $doseIndex;

        return [
            'child_immunization_id' => $headerId,
            'vaccine_type' => self::usesErdVaccineTypeLabels()
                ? self::storedVaccineTypeFromUiKey($uiVaccineKey)
                : $uiVaccineKey,
            $sequenceColumn => $sequenceValue,
        ];
    }

    public static function storedVaccineTypeFromUiKey(string $uiKey): string
    {
        $key = strtolower(trim($uiKey));

        return self::UI_TO_ERD_VACCINE_TYPE[$key] ?? $uiKey;
    }

    public static function uiKeyFromStoredVaccineType(string $stored): string
    {
        $trimmed = trim($stored);
        $lookup = array_flip(self::UI_TO_ERD_VACCINE_TYPE);

        return $lookup[$trimmed] ?? strtolower($trimmed);
    }

    public static function doseNumberFromIndex(int $doseIndex): int
    {
        return $doseIndex + 1;
    }

    public static function indexFromDoseNumber(int $doseNumber): int
    {
        return max(0, $doseNumber - 1);
    }

    public static function resetCachedState(): void
    {
        self::$active = null;
        self::$doseColumns = null;
        self::$usesFicCic = null;
    }

    /**
     * @return array{dose_number: bool, dose_index: bool, dose_id: bool, id: bool}
     */
    private static function doseTableColumns(): array
    {
        if (self::$doseColumns !== null) {
            return self::$doseColumns;
        }

        if (! Schema::hasTable('immunization_doses')) {
            self::$doseColumns = [
                'dose_number' => false,
                'dose_index' => false,
                'dose_id' => false,
                'id' => false,
            ];

            return self::$doseColumns;
        }

        self::$doseColumns = [
            'dose_number' => Schema::hasColumn('immunization_doses', 'dose_number'),
            'dose_index' => Schema::hasColumn('immunization_doses', 'dose_index'),
            'dose_id' => Schema::hasColumn('immunization_doses', 'dose_id'),
            'id' => Schema::hasColumn('immunization_doses', 'id'),
        ];

        return self::$doseColumns;
    }
}
