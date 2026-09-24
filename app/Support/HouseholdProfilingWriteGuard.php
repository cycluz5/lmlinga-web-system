<?php

namespace App\Support;

use App\Services\HouseholdEnvironmentalProfileService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Schema-aware write guards for Household Profiling.
 *
 * Household shell writes are allowed when HouseholdShellWriteAdapter can map
 * UI location onto households.zone (legacy) or households.purok (ERD).
 * Resident writes are allowed when ResidentShellWriteAdapter can persist a
 * household-head row (relation or relation_to_household_head).
 * Genuinely unsupported schemas are still rejected.
 */
final class HouseholdProfilingWriteGuard
{
    public const MESSAGE = 'This record type is not yet available for saving with the current database configuration.';

    public static function rejectHouseholdShellWrite(): void
    {
        if (self::isHouseholdShellWriteUnsupported()) {
            throw ValidationException::withMessages([
                'household' => self::MESSAGE,
            ]);
        }
    }

    public static function isHouseholdShellWriteUnsupported(): bool
    {
        return ! HouseholdShellWriteAdapter::isSupported();
    }

    public static function rejectResidentWrite(): void
    {
        if (self::isResidentWriteUnsupported()) {
            throw ValidationException::withMessages([
                'member' => self::MESSAGE,
            ]);
        }
    }

    public static function isResidentWriteUnsupported(): bool
    {
        return ! ResidentShellWriteAdapter::isSupported();
    }

    public static function rejectAmenitiesWrite(): void
    {
        if (self::isAmenitiesWriteUnsupported()) {
            throw ValidationException::withMessages([
                'amenities' => self::MESSAGE,
            ]);
        }
    }

    public static function isAmenitiesWriteUnsupported(): bool
    {
        if (HouseholdEnvironmentalProfileService::persistenceAvailable()) {
            return false;
        }

        return ! (EnvironmentalSanitationErdMode::isActive()
            && EnvironmentalSanitationErdMode::wasteManagementTableActive());
    }

    public static function rejectBirthHistoryWrite(): void
    {
        if (! ChildBirthHistoryService::persistenceAvailable()) {
            throw ValidationException::withMessages([
                'birth_history' => self::MESSAGE,
            ]);
        }
    }

    public static function rejectChildImmunizationWrite(): void
    {
        if (ChildImmunizationErdMode::isActive()) {
            return;
        }

        if (! Schema::hasTable('child_immunizations')) {
            throw ValidationException::withMessages([
                'immunization' => self::MESSAGE,
            ]);
        }
    }

    public static function rejectSchoolImmunizationWrite(): void
    {
        if (self::isSchoolImmunizationWriteUnsupported()) {
            throw ValidationException::withMessages([
                'immunization' => self::MESSAGE,
            ]);
        }
    }

    public static function isSchoolImmunizationWriteUnsupported(): bool
    {
        return ! SchoolImmunizationErdMode::isSupported();
    }

    public static function rejectTimbangWrite(): void
    {
        if (! TimbangRecordService::persistenceAvailable()) {
            throw ValidationException::withMessages([
                'measurement' => self::MESSAGE,
            ]);
        }
    }

    public static function rejectChildNutritionWrite(): void
    {
        if (self::isChildNutritionWriteUnsupported()) {
            throw ValidationException::withMessages([
                'nutrition' => self::MESSAGE,
            ]);
        }
    }

    public static function isChildNutritionWriteUnsupported(): bool
    {
        return ! ChildNutritionErdMode::isWriteSupported();
    }

    public static function rejectRiskAssessmentWrite(): void
    {
        if (self::isRiskAssessmentWriteUnsupported()) {
            throw ValidationException::withMessages([
                'assessment' => self::MESSAGE,
            ]);
        }
    }

    public static function isRiskAssessmentWriteUnsupported(): bool
    {
        if (Schema::hasTable('risk_assessments')) {
            return false;
        }

        if (RiskAssessmentErdMode::isActive()) {
            return ! RiskAssessmentErdMode::schemaCompatibleForWrites();
        }

        return ! Schema::hasTable('risk_assessment');
    }

    public static function rejectFamilyPlanningWrite(): void
    {
        if (self::isFamilyPlanningWriteUnsupported()) {
            throw ValidationException::withMessages([
                'visit' => self::MESSAGE,
            ]);
        }
    }

    public static function isFamilyPlanningWriteUnsupported(): bool
    {
        if (Schema::hasTable('family_planning_visits')) {
            return false;
        }

        if (FamilyPlanningErdMode::isActive()) {
            return false;
        }

        return ! Schema::hasTable('family_planning');
    }

    public static function rejectMaternalCareWrite(): void
    {
        if (MaternalCareErdMode::isPersistenceActive()) {
            return;
        }

        if (! Schema::hasTable('maternal_pregnancies') && Schema::hasTable('maternal_care')) {
            throw ValidationException::withMessages([
                'maternal_care' => self::MESSAGE,
            ]);
        }
    }
}
