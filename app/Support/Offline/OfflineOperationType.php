<?php

namespace App\Support\Offline;

/**
 * Closed set of v1 offline sync operation types.
 */
final class OfflineOperationType
{
    public const HOUSEHOLD_CREATE = 'HOUSEHOLD_CREATE';

    public const PLOT_HOUSEHOLD_WITH_HEAD = 'PLOT_HOUSEHOLD_WITH_HEAD';

    public const RESIDENT_CREATE = 'RESIDENT_CREATE';

    public const HOUSEHOLD_UPDATE = 'HOUSEHOLD_UPDATE';

    public const RESIDENT_UPDATE = 'RESIDENT_UPDATE';

    public const HEALTH_WORKER_UPDATE = 'HEALTH_WORKER_UPDATE';

    public const HOUSEHOLD_AMENITIES_UPDATE = 'HOUSEHOLD_AMENITIES_UPDATE';

    public const ENVIRONMENTAL_WATER_SUPPLY_UPDATE = 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE';

    public const HEALTH_SERVICE_WRITE = 'HEALTH_SERVICE_WRITE';

    /** @var list<string> */
    public const ALL = [
        self::HOUSEHOLD_CREATE,
        self::PLOT_HOUSEHOLD_WITH_HEAD,
        self::RESIDENT_CREATE,
        self::HOUSEHOLD_UPDATE,
        self::RESIDENT_UPDATE,
        self::HEALTH_WORKER_UPDATE,
        self::HOUSEHOLD_AMENITIES_UPDATE,
        self::ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
        self::HEALTH_SERVICE_WRITE,
    ];

    public static function isKnown(?string $type): bool
    {
        return in_array((string) $type, self::ALL, true);
    }
}
