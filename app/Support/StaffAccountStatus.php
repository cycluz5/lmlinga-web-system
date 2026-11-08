<?php

namespace App\Support;

/**
 * Frozen User Management account status values (Active / Inactive).
 */
final class StaffAccountStatus
{
    public const ACTIVE = 'Active';

    public const INACTIVE = 'Inactive';

    /** @var list<string> */
    public const ALL = [
        self::ACTIVE,
        self::INACTIVE,
    ];

    public static function normalize(?string $status): ?string
    {
        $raw = trim((string) $status);

        if (strcasecmp($raw, 'Active') === 0) {
            return self::ACTIVE;
        }

        // Final SQL used Suspended; map onto frozen Inactive.
        if (
            strcasecmp($raw, 'Inactive') === 0
            || strcasecmp($raw, 'Suspended') === 0
            || strcasecmp($raw, 'Disabled') === 0
        ) {
            return self::INACTIVE;
        }

        return null;
    }

    public static function isValid(?string $status): bool
    {
        return self::normalize($status) !== null;
    }
}
