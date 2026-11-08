<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Detects the adult_immunization table used for Health Summary immunization.
 * Never reuses child immunization_doses.
 */
final class AdultImmunizationErdMode
{
    public const TABLE_MISSING_MESSAGE = 'Adult immunization cannot be saved with the current database configuration.';

    public const VACCINE_PNEUMOCOCCAL = 'Pneumococcal Vaccine';

    public const VACCINE_FLU = 'Flu Vaccine';

    private static ?bool $active = null;

    /**
     * @return list<string>
     */
    public static function vaccineTypes(): array
    {
        return [
            self::VACCINE_PNEUMOCOCCAL,
            self::VACCINE_FLU,
        ];
    }

    public static function vaccineLabel(string $type): string
    {
        return match ($type) {
            self::VACCINE_PNEUMOCOCCAL => 'Pneumococcal Vaccine (1 dose)',
            self::VACCINE_FLU => 'Flu Vaccine (1 dose)',
            default => $type,
        };
    }

    public static function isActive(): bool
    {
        if (self::$active === null) {
            self::$active = Schema::hasTable('adult_immunization')
                && Schema::hasColumn('adult_immunization', 'resident_id')
                && Schema::hasColumn('adult_immunization', 'vaccine_type')
                && Schema::hasColumn('adult_immunization', 'date_given');
        }

        return self::$active;
    }

    public static function primaryKey(): string
    {
        if (Schema::hasTable('adult_immunization') && Schema::hasColumn('adult_immunization', 'adult_immunization_id')) {
            return 'adult_immunization_id';
        }

        return 'id';
    }

    public static function resetCachedState(): void
    {
        self::$active = null;
    }
}
