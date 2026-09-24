<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Dual-schema detector for School-Based Immunization.
 *
 * Laravel: school_immunizations + school_immunization_doses
 * Live ERD: school_immunization + hpv_immunization
 */
final class SchoolImmunizationErdMode
{
    public const GRADE_1 = 'Grade 1';

    public const GRADE_7 = 'Grade 7';

    public const HPV_1ST_DOSE = '1st Dose';

    public const HPV_2ND_DOSE = '2nd Dose';

    /** @var array<string, string> */
    public const UI_GROUP_TO_GRADE = [
        'grade-1' => self::GRADE_1,
        'grade-7' => self::GRADE_7,
    ];

    /** @var array<string, string> */
    public const UI_HPV_KEY_TO_DOSE = [
        '1' => self::HPV_1ST_DOSE,
        '2' => self::HPV_2ND_DOSE,
    ];

    /** @var array<string, string> */
    public const GRADE_DATE_COLUMNS = [
        'td' => 'td_date',
        'mr' => 'mr_date',
    ];

    private static ?bool $laravel = null;

    private static ?bool $erd = null;

    public static function usesLaravelSchema(): bool
    {
        if (self::$laravel === null) {
            self::$laravel = Schema::hasTable('school_immunizations')
                && Schema::hasTable('school_immunization_doses');
        }

        return self::$laravel;
    }

    public static function isActive(): bool
    {
        if (self::$erd === null) {
            self::$erd = Schema::hasTable('school_immunization')
                && Schema::hasTable('hpv_immunization')
                && Schema::hasColumn('school_immunization', 'grade_level')
                && Schema::hasColumn('school_immunization', 'td_date')
                && Schema::hasColumn('school_immunization', 'mr_date')
                && Schema::hasColumn('hpv_immunization', 'dose_number')
                && Schema::hasColumn('hpv_immunization', 'date_given');
        }

        return self::$erd && ! self::usesLaravelSchema();
    }

    public static function isSupported(): bool
    {
        return self::usesLaravelSchema() || self::isActive();
    }

    public static function resetCachedState(): void
    {
        self::$laravel = null;
        self::$erd = null;
    }
}
