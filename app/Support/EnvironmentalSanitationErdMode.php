<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Detects authoritative environmental_sanitation reads vs legacy household_environmental_profiles.
 */
final class EnvironmentalSanitationErdMode
{
    private static ?bool $active = null;

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = ! Schema::hasTable('household_environmental_profiles')
                && Schema::hasTable('environmental_sanitation')
                && Schema::hasColumn('environmental_sanitation', 'household_id');
        }

        return self::$active;
    }

    public static function primaryKey(): string
    {
        if (Schema::hasColumn('environmental_sanitation', 'env_assessment_id')) {
            return 'env_assessment_id';
        }

        return 'environmental_sanitation_id';
    }

    public static function waterSupplyLabelToKey(string $label): string
    {
        return match (trim($label)) {
            'Level I' => DemoHouseholdWaterSupply::WATER_LEVEL_I,
            'Level II' => DemoHouseholdWaterSupply::WATER_LEVEL_II,
            'Level III' => DemoHouseholdWaterSupply::WATER_LEVEL_III,
            'Others' => DemoHouseholdWaterSupply::WATER_LEVEL_OTHERS,
            default => strtolower(str_replace(' ', '_', trim($label))),
        };
    }

    public static function sewageLabelToKey(?string $label): ?string
    {
        return match (trim((string) $label)) {
            'On-site Disposed' => DemoHouseholdWaterSupply::SEWAGE_ON_SITE,
            'Off-site Disposed' => DemoHouseholdWaterSupply::SEWAGE_OFF_SITE,
            default => null,
        };
    }

    public static function sewageKeyToErdLabel(?string $key): ?string
    {
        return match (strtolower(trim((string) $key))) {
            DemoHouseholdWaterSupply::SEWAGE_ON_SITE => 'On-site Disposed',
            DemoHouseholdWaterSupply::SEWAGE_OFF_SITE => 'Off-site Disposed',
            default => null,
        };
    }

    public static function booleanFlagToYesNo(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (int) $value === 1 ? 'yes' : 'no';
    }

    public static function yesNoToBooleanFlag(string $value): int
    {
        return strtolower(trim($value)) === 'yes' ? 1 : 0;
    }

    public static function testResultKeyToErdLabel(?string $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'passed' => 'Passed',
            'failed' => 'Failed',
            default => null,
        };
    }

    /**
     * Step 1 UI stores yes/no only. ERD free-text location values cannot be inferred.
     */
    public static function erdWaterSourceLocationToFormValue(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['yes', 'no'], true) ? $normalized : '';
    }

    /**
     * Resolve authoritative water level label from ERD column variants.
     */
    public static function waterSupplyLabelFromRow(object $row): string
    {
        if (Schema::hasColumn('environmental_sanitation', 'water_supply_status')) {
            $label = trim((string) ($row->water_supply_status ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        if (Schema::hasColumn('environmental_sanitation', 'water_source')) {
            return trim((string) ($row->water_source ?? ''));
        }

        return '';
    }

    public static function resetCachedState(): void
    {
        self::$active = null;
    }

    public static function wasteManagementTableActive(): bool
    {
        return Schema::hasTable('waste_management_practices')
            && Schema::hasColumn('waste_management_practices', 'env_assessment_id');
    }

    /**
     * @return list<string>
     */
    public static function solidWastePracticesFromWasteRow(?object $wasteRow): array
    {
        if ($wasteRow === null) {
            return [];
        }

        $practices = [];

        if ((int) ($wasteRow->waste_segregation ?? 0) === 1) {
            $practices[] = DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION;
        }
        if ((int) ($wasteRow->backyard_composting ?? 0) === 1) {
            $practices[] = DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING;
        }
        if ((int) ($wasteRow->recycling_reuse ?? 0) === 1) {
            $practices[] = DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE;
        }
        if ((int) ($wasteRow->collected_by_municipality ?? 0) === 1) {
            $practices[] = DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION;
        }

        return $practices;
    }

    public static function solidWasteStatusFromWasteRow(?object $wasteRow): string
    {
        return self::solidWastePracticesFromWasteRow($wasteRow) === []
            ? 'not_yet_determined'
            : 'good_practice';
    }

    /**
     * @param  list<string>  $practices
     * @return array{waste_segregation: int, backyard_composting: int, recycling_reuse: int, collected_by_municipality: int}
     */
    public static function solidWastePracticeKeysToErdFlags(array $practices): array
    {
        return [
            'waste_segregation' => in_array(DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION, $practices, true) ? 1 : 0,
            'backyard_composting' => in_array(DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING, $practices, true) ? 1 : 0,
            'recycling_reuse' => in_array(DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE, $practices, true) ? 1 : 0,
            'collected_by_municipality' => in_array(DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION, $practices, true) ? 1 : 0,
        ];
    }
}
