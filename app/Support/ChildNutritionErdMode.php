<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Dual-schema detector for Child Nutrition.
 *
 * Laravel: child_nutritions + child_nutrition_sfp_outcomes
 * Live ERD: child_nutrition + nutrition_supplementation + iron_supplementation
 *           + malnutrition_management
 */
final class ChildNutritionErdMode
{
    public const IRON_TABLE = 'iron_supplementation';

    public const MALNUTRITION_TABLE = 'malnutrition_management';

    /** @var array<string, string> */
    public const IRON_UI_TO_MONTH = [
        '1st' => '1',
        '2nd' => '2',
        '3rd' => '3',
    ];

    /** @var array<string, string> */
    public const MALNUTRITION_UI_TO_TYPE = [
        'mam' => 'MAM',
        'sam' => 'SAM',
    ];

    /** @var array<string, string> */
    public const SFP_UI_TO_STATUS = [
        'identified' => 'Identified',
        'enrolled' => 'Enrolled',
        'cured' => 'Cured',
        'non-cured' => 'Non-Cured',
        'default' => 'Default',
        'died' => 'Died',
    ];

    private static ?bool $laravel = null;

    private static ?bool $active = null;

    private static ?bool $erdWritable = null;

    public static function usesLaravelSchema(): bool
    {
        if (self::$laravel === null) {
            self::$laravel = Schema::hasTable('child_nutritions');
        }

        return self::$laravel;
    }

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = Schema::hasTable('child_nutrition')
                && ! self::usesLaravelSchema();
        }

        return self::$active;
    }

    /**
     * Writes are allowed on Laravel plural schema or a complete live ERD adapter.
     */
    public static function isWriteSupported(): bool
    {
        return self::usesLaravelSchema() || self::erdWriteSchemaCompatible();
    }

    public static function erdWriteSchemaCompatible(): bool
    {
        if (self::$erdWritable !== null) {
            return self::$erdWritable;
        }

        if (! self::isActive()) {
            self::$erdWritable = false;

            return false;
        }

        self::$erdWritable = self::headerWriteColumnsPresent()
            && self::supplementationWriteColumnsPresent()
            && self::ironWriteColumnsPresent()
            && self::malnutritionWriteColumnsPresent();

        return self::$erdWritable;
    }

    public static function nutritionTable(): string
    {
        return self::isActive() ? 'child_nutrition' : 'child_nutritions';
    }

    public static function nutritionPrimaryKey(): string
    {
        return self::isActive() ? 'child_nutrition_id' : 'id';
    }

    public static function residentForeignKey(): string
    {
        return 'resident_id';
    }

    public static function usesSupplementationTable(): bool
    {
        return self::isActive() && Schema::hasTable('nutrition_supplementation');
    }

    public static function supplementationTable(): string
    {
        return 'nutrition_supplementation';
    }

    public static function supplementationPrimaryKey(): string
    {
        return Schema::hasColumn('nutrition_supplementation', 'supplementation_id')
            ? 'supplementation_id'
            : 'id';
    }

    public static function nutritionForeignKeyOnSupplementation(): string
    {
        return 'child_nutrition_id';
    }

    public static function ironPrimaryKey(): string
    {
        return Schema::hasColumn(self::IRON_TABLE, 'iron_supp_id')
            ? 'iron_supp_id'
            : 'id';
    }

    public static function malnutritionPrimaryKey(): string
    {
        return Schema::hasColumn(self::MALNUTRITION_TABLE, 'malnutrition_mgmt_id')
            ? 'malnutrition_mgmt_id'
            : 'id';
    }

    public static function resetCachedState(): void
    {
        self::$laravel = null;
        self::$active = null;
        self::$erdWritable = null;
    }

    private static function headerWriteColumnsPresent(): bool
    {
        return Schema::hasColumns('child_nutrition', [
            'child_nutrition_id',
            'resident_id',
            'length_at_birth_cm',
            'weight_at_birth_kg',
            'initiated_breastfeeding_date',
        ]);
    }

    private static function supplementationWriteColumnsPresent(): bool
    {
        return Schema::hasTable('nutrition_supplementation')
            && Schema::hasColumns('nutrition_supplementation', [
                'child_nutrition_id',
                'supplement_type',
                'age_group',
                'dose_number',
                'date_given',
            ]);
    }

    private static function ironWriteColumnsPresent(): bool
    {
        return Schema::hasTable(self::IRON_TABLE)
            && Schema::hasColumns(self::IRON_TABLE, [
                'child_nutrition_id',
                'month_number',
                'date_given',
            ]);
    }

    private static function malnutritionWriteColumnsPresent(): bool
    {
        return Schema::hasTable(self::MALNUTRITION_TABLE)
            && Schema::hasColumns(self::MALNUTRITION_TABLE, [
                'child_nutrition_id',
                'malnutrition_type',
                'status_type',
                'status_date',
                'action',
            ]);
    }
}
