<?php

namespace App\Support\Geo;

/**
 * Barangay La Medalla (Iriga City, Camarines Sur) boundary check for Spot Mapping plots.
 *
 * Reads the same GeoJSON Feature the maps draw (resources/js/maps/la-medalla-boundary.json).
 * The polygon is INDICATIVE (NAMRIA/PSA COD-AB), not an official survey boundary.
 * GeoJSON positions are [longitude, latitude].
 */
final class LaMedallaBoundary
{
    /** Keep in sync with BOUNDARY_TOLERANCE_METERS in resources/js/maps/la-medalla-boundary.js. */
    public const TOLERANCE_METERS = 150;

    private const METERS_PER_DEGREE_LAT = 111320;

    /** @var array<string, list<list<array{0: float, 1: float}>>|null> */
    private static array $ringsByPath = [];

    public static function path(): string
    {
        return resource_path('js/maps/la-medalla-boundary.json');
    }

    /**
     * True when inside the boundary, false when outside, null when the boundary file
     * is missing or unreadable (callers decide whether to allow the point).
     */
    public static function contains(float $lat, float $lng, ?string $path = null): ?bool
    {
        $rings = self::outerRings($path ?? self::path());
        if ($rings === null) {
            return null;
        }

        foreach ($rings as $ring) {
            if (self::ringContains($ring, $lat, $lng)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Like contains(), but also accepts points within TOLERANCE_METERS of the polygon edge
     * (the polygon is indicative, so real border houses and GPS error fall just outside it).
     */
    public static function containsWithTolerance(float $lat, float $lng, ?float $meters = null, ?string $path = null): ?bool
    {
        $inside = self::contains($lat, $lng, $path);
        if ($inside !== false) {
            return $inside;
        }

        $distance = self::distanceToEdgeMeters($lat, $lng, $path);

        return $distance !== null && $distance <= ($meters ?? self::TOLERANCE_METERS);
    }

    /** Shortest distance in metres from the point to any boundary edge, or null when unreadable. */
    public static function distanceToEdgeMeters(float $lat, float $lng, ?string $path = null): ?float
    {
        $rings = self::outerRings($path ?? self::path());
        if ($rings === null) {
            return null;
        }

        $metersPerDegreeLng = self::METERS_PER_DEGREE_LAT * cos(deg2rad($lat));
        $min = INF;
        foreach ($rings as $ring) {
            for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
                [$ax, $ay] = $ring[$j];
                [$bx, $by] = $ring[$i];
                $px = ($lng - $ax) * $metersPerDegreeLng;
                $py = ($lat - $ay) * self::METERS_PER_DEGREE_LAT;
                $sx = ($bx - $ax) * $metersPerDegreeLng;
                $sy = ($by - $ay) * self::METERS_PER_DEGREE_LAT;
                $lengthSq = $sx * $sx + $sy * $sy;
                $t = $lengthSq == 0.0 ? 0.0 : max(0.0, min(1.0, ($px * $sx + $py * $sy) / $lengthSq));
                $min = min($min, hypot($px - $t * $sx, $py - $t * $sy));
            }
        }

        return $min;
    }

    public static function forgetCache(): void
    {
        self::$ringsByPath = [];
    }

    /**
     * @return list<list<array{0: float, 1: float}>>|null
     */
    private static function outerRings(string $path): ?array
    {
        if (array_key_exists($path, self::$ringsByPath)) {
            return self::$ringsByPath[$path];
        }

        return self::$ringsByPath[$path] = self::readOuterRings($path);
    }

    /**
     * @return list<list<array{0: float, 1: float}>>|null
     */
    private static function readOuterRings(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $feature = json_decode((string) file_get_contents($path), true);
        if (! is_array($feature) || ($feature['type'] ?? null) !== 'Feature') {
            return null;
        }

        $type = $feature['geometry']['type'] ?? null;
        $coordinates = $feature['geometry']['coordinates'] ?? null;
        if (! is_array($coordinates)) {
            return null;
        }

        $rings = match ($type) {
            'Polygon' => [$coordinates[0] ?? null],
            'MultiPolygon' => array_map(static fn ($polygon) => is_array($polygon) ? ($polygon[0] ?? null) : null, $coordinates),
            default => [],
        };

        $clean = [];
        foreach ($rings as $ring) {
            if (! is_array($ring) || count($ring) < 4) {
                return null;
            }

            $points = [];
            foreach ($ring as $position) {
                if (! is_array($position) || ! is_numeric($position[0] ?? null) || ! is_numeric($position[1] ?? null)) {
                    return null;
                }
                $points[] = [(float) $position[0], (float) $position[1]];
            }
            $clean[] = $points;
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Ray-casting test of one [longitude, latitude] ring (same algorithm as the map's isPointInBoundary).
     *
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private static function ringContains(array $ring, float $lat, float $lng): bool
    {
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
