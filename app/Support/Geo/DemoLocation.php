<?php

namespace App\Support\Geo;

use App\Models\User;
use App\Support\StaffRole;
use Illuminate\Contracts\Session\Session;

/**
 * SR-10 Demo Location Mode (Phase A foundation).
 *
 * Availability fails closed — Demo Mode can only be enabled when ALL hold:
 * - the feature gate `lmlinga.demo_location.enabled` is on (default off);
 * - the official SR-11 boundary is valid (BarangayBoundary, rules unchanged);
 * - the reviewed demo-points file exists, is valid, and at least one point lies inside the boundary.
 * No coordinate is built in: points come only from that file (none exists yet).
 *
 * State lives in the enabling Admin's own session only (bound to that user id), expires after at most
 * 2 hours, and disappears on logout (session invalidated). While active, Spot Mapping writes are refused
 * so a simulated coordinate is never saved as a real household measurement.
 */
final class DemoLocation
{
    public const SESSION_KEY = 'spot_mapping.demo_location';

    public const MAX_TTL_MINUTES = 120;

    private const MAX_POINTS_BYTES = 64_000;

    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    public static function gateEnabled(): bool
    {
        return (bool) config('lmlinga.demo_location.enabled', false);
    }

    public static function ttlMinutes(): int
    {
        return min(self::MAX_TTL_MINUTES, max(1, (int) config('lmlinga.demo_location.ttl_minutes', self::MAX_TTL_MINUTES)));
    }

    /**
     * @return array{available: bool, reason: ?string, points: list<array{key: string, label: string, lat: float, lng: float, source: string}>, excluded: int}
     */
    public static function availability(): array
    {
        $unavailable = static fn (string $reason, int $excluded = 0): array => ['available' => false, 'reason' => $reason, 'points' => [], 'excluded' => $excluded];

        if (! self::gateEnabled()) {
            return $unavailable('Demo Location Mode is turned off for this deployment.');
        }

        $boundary = BarangayBoundary::load();
        if (! $boundary->isValid()) {
            return $unavailable('The official Barangay La Medalla boundary is not available ('.$boundary->state().'): '.$boundary->reason());
        }

        [$points, $error] = self::loadPoints();
        if ($error !== null) {
            return $unavailable($error);
        }

        $inside = array_values(array_filter($points, static fn (array $p): bool => $boundary->contains($p['lat'], $p['lng'])));
        $excluded = count($points) - count($inside);
        if ($inside === []) {
            return $unavailable('None of the configured demo points lies inside the official boundary.', $excluded);
        }

        return ['available' => true, 'reason' => null, 'points' => $inside, 'excluded' => $excluded];
    }

    /**
     * The active demo point for this user's session, or null. Invalid, expired, foreign or no-longer-available
     * state is removed (fail closed → Real GPS).
     *
     * @return array{key: string, label: string, lat: float, lng: float, source: string, verified: bool, expires_at: int}|null
     */
    public static function active(Session $session, ?User $user): ?array
    {
        $state = $session->get(self::SESSION_KEY);
        if ($state === null) {
            return null;
        }

        $valid = is_array($state)
            && $user instanceof User
            && (string) ($state['user_id'] ?? '') === (string) $user->getKey()
            && (int) ($state['expires_at'] ?? 0) > now()->getTimestamp()
            && self::isActiveAdmin($user);
        $point = null;
        if ($valid) {
            $availability = self::availability();
            foreach ($availability['points'] as $candidate) {
                if ($candidate['key'] === ($state['point'] ?? null)) {
                    $point = $candidate;
                }
            }
        }

        if ($point === null) {
            $session->forget(self::SESSION_KEY);

            return null;
        }

        return $point + ['expires_at' => (int) $state['expires_at']];
    }

    /** True while ANY demo state is present in the session (used to refuse writes, even if it just expired). */
    public static function hasState(Session $session): bool
    {
        return $session->has(self::SESSION_KEY);
    }

    /**
     * @return array{key: string, label: string, lat: float, lng: float, source: string, verified: bool, expires_at: int}
     */
    public static function enable(Session $session, User $user, string $pointKey): array
    {
        $availability = self::availability();
        foreach ($availability['points'] as $point) {
            if ($point['key'] === $pointKey) {
                $expires = now()->addMinutes(self::ttlMinutes())->getTimestamp();
                $session->put(self::SESSION_KEY, ['point' => $point['key'], 'user_id' => (string) $user->getKey(), 'expires_at' => $expires]);

                return $point + ['expires_at' => $expires];
            }
        }

        throw new \InvalidArgumentException($availability['reason'] ?? 'Unknown demo point.');
    }

    public static function disable(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    public static function isActiveAdmin(?User $user): bool
    {
        return $user instanceof User && $user->isActive() && StaffRole::normalize($user->role) === StaffRole::ADMIN;
    }

    /**
     * Data for the Spot Mapping page. Only an Admin on a gated deployment receives anything; coordinates are
     * sent only for the active point of this session.
     *
     * @return array{manage: bool, available: bool, reason: ?string, points: list<array{key: string, label: string, verified: bool}>, active: ?array{key: string, label: string, lat: float, lng: float, expires_at: int, verified: bool}}
     */
    public static function viewState(Session $session, ?User $user): array
    {
        $none = ['manage' => false, 'available' => false, 'reason' => null, 'points' => [], 'active' => null];
        if (! self::gateEnabled() || ! self::isActiveAdmin($user)) {
            // Gate turned off or the user is no longer an active Admin: drop any leftover state (→ Real GPS).
            self::disable($session);

            return $none;
        }

        $availability = self::availability();
        $active = self::active($session, $user);

        return [
            'manage' => true,
            'available' => $availability['available'],
            'reason' => $availability['reason'],
            'points' => array_map(static fn (array $p): array => ['key' => $p['key'], 'label' => $p['label'], 'verified' => $p['verified']], $availability['points']),
            'active' => $active === null ? null : [
                'key' => $active['key'], 'label' => $active['label'], 'lat' => $active['lat'], 'lng' => $active['lng'], 'expires_at' => $active['expires_at'],
                'verified' => $active['verified'],
            ],
        ];
    }

    /**
     * Reviewed points file: {"points":[{"key","label","lat","lng","source","verified"?}]}. Fails closed on any problem.
     * `verified` must be literally true for a point to be shown as verified; anything else is UNVERIFIED.
     *
     * @return array{0: list<array{key: string, label: string, lat: float, lng: float, source: string, verified: bool}>, 1: ?string}
     */
    private static function loadPoints(): array
    {
        $path = (string) config('lmlinga.demo_location.points_path', base_path('resources/geo/la-medalla-demo-points.json'));
        if (! is_file($path)) {
            return [[], 'No verified demo coordinates have been added yet.'];
        }
        $size = (int) @filesize($path);
        if ($size <= 0 || $size > self::MAX_POINTS_BYTES) {
            return [[], 'The demo points file is empty or too large.'];
        }

        try {
            $data = json_decode((string) @file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[], 'The demo points file is not valid JSON.'];
        }
        if (! is_array($data) || ! is_array($data['points'] ?? null) || $data['points'] === []) {
            return [[], 'The demo points file has no points.'];
        }

        $points = [];
        foreach ($data['points'] as $i => $p) {
            $key = is_array($p) ? ($p['key'] ?? null) : null;
            $label = is_array($p) ? trim((string) ($p['label'] ?? '')) : '';
            $source = is_array($p) ? trim((string) ($p['source'] ?? '')) : '';
            $lat = is_array($p) ? ($p['lat'] ?? null) : null;
            $lng = is_array($p) ? ($p['lng'] ?? null) : null;
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || $label === '' || mb_strlen($label) > 80
                || $source === '' || ! is_numeric($lat) || ! is_numeric($lng)
                || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180
                || isset($points[$key])) {
                return [[], 'Demo point '.($i + 1).' is invalid (key, label, source, latitude and longitude are required; keys must be unique).'];
            }
            // Phase B: a point counts as verified only when the file says "verified": true (default: unverified).
            $points[$key] = ['key' => $key, 'label' => $label, 'lat' => (float) $lat, 'lng' => (float) $lng, 'source' => $source, 'verified' => ($p['verified'] ?? false) === true];
        }

        return [array_values($points), null];
    }
}
