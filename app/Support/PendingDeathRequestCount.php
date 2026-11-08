<?php

namespace App\Support;

use App\Models\DeathRequest;
use Throwable;

/**
 * Admin-nav pending death-request count.
 *
 * Server-rendered only. Never queried for non-admin shells.
 */
final class PendingDeathRequestCount
{
    /**
     * Actionable queue size for Admin navigation, or 0 when the caller is not Admin
     * or death persistence is unavailable.
     */
    public static function forAdminNav(): int
    {
        if (! UiRole::isAdmin()) {
            return 0;
        }

        return self::safePendingCount();
    }

    public static function safePendingCount(): int
    {
        try {
            return (int) DeathRequest::query()->pending()->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
