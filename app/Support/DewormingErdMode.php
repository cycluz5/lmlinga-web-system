<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Detects authoritative ERD deworming_records persistence vs Laravel legacy shape.
 */
final class DewormingErdMode
{
    private static ?bool $active = null;

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = Schema::hasTable('deworming_records')
                && Schema::hasColumn('deworming_records', 'deworming_round');
        }

        return self::$active;
    }

    public static function primaryKey(): string
    {
        return self::isActive() ? 'deworming_id' : 'id';
    }

    public static function roundColumn(): string
    {
        return self::isActive() ? 'deworming_round' : 'round';
    }

    public static function hasSeStatusColumn(): bool
    {
        return Schema::hasTable('deworming_records')
            && Schema::hasColumn('deworming_records', 'se_status');
    }

    public static function resetCachedState(): void
    {
        self::$active = null;
    }
}
