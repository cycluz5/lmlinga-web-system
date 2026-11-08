<?php

namespace App\Support;

use App\Models\Resident;
use Carbon\Carbon;

/**
 * Household Profiling adult immunization: male and female residents 18+.
 */
final class AdultImmunizationEligibility
{
    public const MIN_AGE_YEARS = 18;

    public const INELIGIBLE_MESSAGE = 'Available for residents 18 years old and above.';

    public static function allows(mixed $sex, mixed $birthday, ?Carbon $on = null): bool
    {
        if (! self::allowsSex($sex)) {
            return false;
        }

        $age = MaternalCareEligibility::completedAgeYears($birthday, $on);

        return $age !== null && $age >= self::MIN_AGE_YEARS;
    }

    public static function allowsSex(mixed $sex): bool
    {
        if (! is_string($sex)) {
            return false;
        }

        $normalized = trim($sex);
        if ($normalized === '') {
            return false;
        }

        return HealthRecordsMaternal::isFemaleSex($normalized)
            || HealthRecordsMaternal::isMaleSex($normalized);
    }

    /**
     * @param  array{
     *     member: array<string, mixed>|null,
     *     resident: Resident|null
     * }  $ctx
     */
    public static function allowsMemberContext(array $ctx): bool
    {
        if ($ctx['member'] === null && ($ctx['resident'] ?? null) === null) {
            return true;
        }

        if (($ctx['resident'] ?? null) instanceof Resident) {
            return self::allows($ctx['resident']->sex, $ctx['resident']->birthday);
        }

        $member = is_array($ctx['member'] ?? null) ? $ctx['member'] : [];

        return self::allows($member['sex'] ?? null, $member['birthday'] ?? null);
    }
}
