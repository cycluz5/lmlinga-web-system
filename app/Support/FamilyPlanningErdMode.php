<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Detects authoritative ERD family_planning persistence vs Laravel legacy visits table.
 */
final class FamilyPlanningErdMode
{
    public const COMMODITIES_UI_MESSAGE = 'Commodity distribution is not recorded in the current Family Planning database configuration.';

    private static ?bool $active = null;

    private static ?bool $commoditiesReady = null;

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = ! Schema::hasTable('family_planning_visits')
                && Schema::hasTable('family_planning')
                && Schema::hasColumn('family_planning', 'fp_id')
                && Schema::hasColumn('family_planning', 'resident_id')
                && Schema::hasColumn('family_planning', 'visitation_date');
        }

        return self::$active;
    }

    public static function commoditiesTableReady(): bool
    {
        if (self::$commoditiesReady === null) {
            self::$commoditiesReady = Schema::hasTable('fp_commodities_given')
                && Schema::hasColumn('fp_commodities_given', 'fp_id')
                && Schema::hasColumn('fp_commodities_given', 'commodity_name')
                && Schema::hasColumn('fp_commodities_given', 'quantity');
        }

        return self::$commoditiesReady;
    }

    public static function primaryKey(): string
    {
        return 'fp_id';
    }

    public static function resetCachedState(): void
    {
        self::$active = null;
        self::$commoditiesReady = null;
    }

    /**
     * Commodity UI is shown for demo members, legacy JSON visits, or ERD
     * fp_commodities_given when that table is present.
     */
    public static function commoditiesSupported(bool $canPersist): bool
    {
        if (! $canPersist) {
            return true;
        }

        if (! self::isActive()) {
            return true;
        }

        return self::commoditiesTableReady();
    }
}
