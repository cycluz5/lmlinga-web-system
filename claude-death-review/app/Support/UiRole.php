<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * UI shell role for the shared dashboard sidebar.
 * Single source of truth: authenticated user role when present, otherwise session.
 * Never defaults anonymous visitors to Admin.
 */
final class UiRole
{
    public const SESSION_KEY = 'lml.ui_role';

    /**
     * Least-privileged staff role used only when the dashboard shell must render
     * without an established role (never Admin).
     */
    public const LEAST_PRIVILEGED = 'bspo';

    /** @var list<string> */
    public const ALLOWED = ['admin', 'bhw', 'bns', 'bspo'];

    public static function normalize(?string $role): ?string
    {
        $normalized = strtolower(trim((string) $role));

        return in_array($normalized, self::ALLOWED, true) ? $normalized : null;
    }

    /**
     * Resolve the established shell role, or null when none is set.
     * Never uses destination page, route name, or query string.
     */
    public static function current(): ?string
    {
        $user = Auth::user();
        if ($user !== null) {
            $fromUser = self::normalize(
                data_get($user, 'role')
                    ?? data_get($user, 'user_role')
                    ?? data_get($user, 'type')
            );
            if ($fromUser !== null) {
                return $fromUser;
            }
        }

        return self::normalize(session(self::SESSION_KEY));
    }

    /**
     * Role used to filter the shared sidebar.
     * Falls back to the least-privileged staff role — never Admin — when unset.
     */
    public static function shellRole(): string
    {
        return self::current() ?? self::LEAST_PRIVILEGED;
    }

    public static function isAdmin(): bool
    {
        return self::current() === 'admin';
    }

    public static function set(string $role): void
    {
        $normalized = self::normalize($role);
        if ($normalized === null) {
            return;
        }

        session([self::SESSION_KEY => $normalized]);
    }

    public static function label(?string $role = null): string
    {
        $resolved = self::normalize($role) ?? self::current();

        if ($resolved === null) {
            return 'Guest';
        }

        return $resolved === 'admin' ? 'Admin' : strtoupper($resolved);
    }

    public static function displayName(?string $role = null): string
    {
        $user = Auth::user();
        if ($user !== null) {
            $name = trim((string) (data_get($user, 'name') ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        $resolved = self::normalize($role) ?? self::current();

        if ($resolved === null) {
            return 'Guest';
        }

        return $resolved === 'admin' ? 'Admin User' : 'Sarah';
    }

    /**
     * Map the current route name to a sidebar active key.
     *
     * @param  string|null  $fallback  Optional page-provided key (e.g. Health Records child keys).
     */
    public static function sidebarActiveKey(?string $fallback = null): string
    {
        $routeName = (string) (request()->route()?->getName() ?? '');

        $patterns = [
            'user-management' => ['user-management', 'user-management.*'],
            'household-requests' => ['household-requests', 'household-requests.*'],
            'death-requests' => ['death-requests', 'death-requests.*'],
            /*
             | Household Water Supply continues the Spot Mapping plot workflow.
             | Match these before environmental-health.* so the sidebar stays on
             | Spot Mapping even though the URL lives under /environmental-health.
             */
            'spot-mapping' => [
                'spot-mapping',
                'spot-mapping.*',
                'environmental-health.household-water-supply',
                'environmental-health.household-water-supply.*',
            ],
            /*
             | Child Immunization is reached from Household Profiling → View Member
             | → Child Care, so the primary sidebar item stays Household Profiling.
             */
            'household-profiling' => ['household-profiling', 'household-profiling.*'],
            'environmental-health' => ['environmental-health', 'environmental-health.*'],
            'dashboard' => ['dashboard'],
        ];

        foreach ($patterns as $key => $routePatterns) {
            foreach ($routePatterns as $pattern) {
                if (Str::is($pattern, $routeName)) {
                    return $key;
                }
            }
        }

        /*
         | Health Records sidebar children (exact order in the shared sidebar):
         | Child Care → Risk Assessment → Family Planning → Maternal → Death.
         |
         | Obsolete direct entries (immunizations, operation-timbang, vitamin-a,
         | deworming) are no longer sidebar destinations. Child Immunization and
         | Birth History remain mapped to household-profiling above.
         */
        $healthRecordChildren = [
            'child-care',
            'risk-assessment',
            'family-planning',
            'maternal',
            'death',
        ];

        foreach ($healthRecordChildren as $childKey) {
            if (
                $routeName === $childKey
                || Str::is('health-records.'.$childKey, $routeName)
                || Str::is('health-records.'.$childKey.'.*', $routeName)
            ) {
                return $childKey;
            }
        }

        if (Str::is('health-records.*', $routeName) || $routeName === 'health-records') {
            return 'health-records';
        }

        $fallbackKey = strtolower(trim((string) $fallback));

        return $fallbackKey !== '' ? $fallbackKey : 'dashboard';
    }
}
