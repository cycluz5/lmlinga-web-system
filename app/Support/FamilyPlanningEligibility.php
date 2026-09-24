<?php

namespace App\Support;

use App\Models\Resident;
use Carbon\Carbon;

/**
 * Household Profiling Family Planning: female residents 10 years and above.
 */
final class FamilyPlanningEligibility
{
    public const MIN_AGE_YEARS = 10;

    public const INELIGIBLE_MESSAGE = 'Available for female residents 10 years old and above.';

    public static function allows(mixed $sex, mixed $birthday, ?Carbon $on = null): bool
    {
        if (! is_string($sex) || ! HealthRecordsMaternal::isFemaleSex($sex)) {
            return false;
        }

        $age = MaternalCareEligibility::completedAgeYears($birthday, $on);

        return $age !== null && $age >= self::MIN_AGE_YEARS;
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
