<?php

namespace App\Support;

/**
 * Session-only return context for Environmental Health wizard completion.
 *
 * Set only after a successful normal Household Profiling create so Step 4 can
 * return to Household Information instead of Spot Mapping. Plot New / plot
 * handoff flows never set this key.
 */
final class EnvironmentalHealthReturnContext
{
    public const SESSION_KEY = 'lml.eh.return_to_household.v1';

    /**
     * Remember the household that should receive focus after EH Step 4.
     */
    public static function rememberForHouseholdProfilingCreate(string $householdNo): void
    {
        $normalized = DemoCatalog::normalizeHouseholdNo($householdNo);
        if ($normalized === '') {
            return;
        }

        session([self::SESSION_KEY => $normalized]);
    }

    /**
     * Consume the return context when it matches the completing household.
     * Mismatched or missing context is left untouched (caller keeps Spot Mapping redirect).
     */
    public static function consumeIfMatches(string $householdNo): bool
    {
        $stored = session(self::SESSION_KEY);
        if (! is_string($stored) || $stored === '') {
            return false;
        }

        $normalized = DemoCatalog::normalizeHouseholdNo($householdNo);
        if ($normalized === '' || $stored !== $normalized) {
            return false;
        }

        session()->forget(self::SESSION_KEY);

        return true;
    }

    public static function peek(): ?string
    {
        $stored = session(self::SESSION_KEY);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }
}
