<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Household Profiling Maternal Care eligibility.
 *
 * Viewing Maternal Care remains female-only so historical episodes stay
 * reachable. Starting or writing an Active workflow also requires
 * 10 completed years from residents.birthday.
 *
 * Canonical stored sex values are Male / Female. Unknown, empty, and
 * non-female values fail closed and are never treated as female.
 * Missing or invalid birthday fails closed for the write/start gate.
 */
final class MaternalCareEligibility
{
    public const MIN_AGE_YEARS = 10;

    public const WORKFLOW_INELIGIBLE_MESSAGE = 'Available for female residents 10 years old and above.';

    public static function allows(mixed $sex): bool
    {
        if (! is_string($sex)) {
            return false;
        }

        $normalized = trim($sex);
        if ($normalized === '') {
            return false;
        }

        return HealthRecordsMaternal::isFemaleSex($normalized);
    }

    /**
     * @param  array{
     *     member: array<string, mixed>|null,
     *     resident: \App\Models\Resident|null
     * }  $ctx
     */
    public static function allowsMemberContext(array $ctx): bool
    {
        if ($ctx['member'] === null && ($ctx['resident'] ?? null) === null) {
            return true;
        }

        return self::allows(self::sexFromContext($ctx));
    }

    public static function completedAgeYears(mixed $birthday, ?Carbon $on = null): ?int
    {
        if ($birthday instanceof \DateTimeInterface) {
            $iso = $birthday->format('Y-m-d');
        } elseif (is_string($birthday)) {
            $iso = trim($birthday);
        } else {
            return null;
        }

        if ($iso === '') {
            return null;
        }

        return HealthRecordsClinicalListing::ageInYearsFromBirthday($iso, $on);
    }

    public static function meetsMinimumAge(mixed $birthday, ?Carbon $on = null): bool
    {
        $age = self::completedAgeYears($birthday, $on);

        return $age !== null && $age >= self::MIN_AGE_YEARS;
    }

    public static function allowsWorkflow(mixed $sex, mixed $birthday, ?Carbon $on = null): bool
    {
        return self::allows($sex) && self::meetsMinimumAge($birthday, $on);
    }

    /**
     * @param  array{
     *     member: array<string, mixed>|null,
     *     resident: \App\Models\Resident|null
     * }  $ctx
     */
    public static function allowsWorkflowMemberContext(array $ctx, ?Carbon $on = null): bool
    {
        if ($ctx['member'] === null && ($ctx['resident'] ?? null) === null) {
            return true;
        }

        return self::allowsWorkflow(self::sexFromContext($ctx), self::birthdayFromContext($ctx), $on);
    }

    /**
     * @param  array{
     *     member: array<string, mixed>|null,
     *     resident: \App\Models\Resident|null
     * }  $ctx
     */
    public static function sexFromContext(array $ctx): mixed
    {
        $resident = $ctx['resident'] ?? null;
        if ($resident !== null) {
            $fromResident = $resident->sex ?? null;
            if (is_string($fromResident) && trim($fromResident) !== '') {
                return $fromResident;
            }
        }

        $member = $ctx['member'] ?? null;
        if (is_array($member)) {
            return $member['sex'] ?? null;
        }

        return null;
    }

    /**
     * @param  array{
     *     member: array<string, mixed>|null,
     *     resident: \App\Models\Resident|null
     * }  $ctx
     */
    public static function birthdayFromContext(array $ctx): mixed
    {
        $resident = $ctx['resident'] ?? null;
        if ($resident !== null) {
            $fromResident = $resident->birthday ?? null;
            if ($fromResident instanceof \DateTimeInterface) {
                return $fromResident;
            }
            if (is_string($fromResident) && trim($fromResident) !== '') {
                return $fromResident;
            }
        }

        $member = $ctx['member'] ?? null;
        if (is_array($member)) {
            return $member['birthday'] ?? null;
        }

        return null;
    }
}
