<?php

namespace App\Support\Geo;

use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Official Barangay La Medalla boundary (panel refinement SR-11), loaded from a GeoJSON file.
 *
 * The file must be a GeoJSON Feature (or a FeatureCollection with exactly one Feature) whose geometry is a
 * Polygon or MultiPolygon, with properties.source naming the authoritative source. GeoJSON positions are
 * [longitude, latitude]. Fails closed: when the file is missing, invalid or incomplete, isValid() is false,
 * nothing is drawn and contains() returns false for every point. No coordinates are ever invented here.
 */
final class BarangayBoundary
{
    public const STATE_VALID = 'valid';

    public const STATE_MISSING = 'missing';

    public const STATE_INVALID = 'invalid';

    public const MAX_BYTES = 2_000_000;

    public const MAX_POSITIONS = 50_000;

    /** About 0.1 mm: points exactly on an edge count as inside. */
    private const EDGE_EPSILON = 1e-9;

    /** @var array<string, self> */
    private static array $loaded = [];

    /**
     * @param  list<list<list<array{0: float, 1: float}>>>  $polygons  polygons → rings (first = outer, rest = holes) → [lng, lat]
     * @param  array<string, string>  $meta
     */
    private function __construct(
        private readonly string $state,
        private readonly string $reason,
        private readonly array $polygons = [],
        private readonly array $meta = [],
    ) {}

    public static function configuredPath(): string
    {
        return (string) config('lmlinga.geofence.boundary_path', base_path('resources/geo/la-medalla-boundary.geojson'));
    }

    public static function load(?string $path = null): self
    {
        $path ??= self::configuredPath();

        if (! is_file($path)) {
            return new self(self::STATE_MISSING, 'The official Barangay La Medalla boundary has not been added yet.');
        }

        $size = (int) @filesize($path);
        $cacheKey = $path.'|'.$size.'|'.(int) @filemtime($path);
        if (isset(self::$loaded[$cacheKey])) {
            return self::$loaded[$cacheKey];
        }

        if ($size <= 0 || $size > self::MAX_BYTES) {
            $boundary = self::invalid('The boundary file is empty or larger than 2 MB.');
        } else {
            $boundary = self::fromJson((string) @file_get_contents($path));
        }

        if (! $boundary->isValid()) {
            Log::warning('geo.boundary.invalid', ['path' => basename($path), 'reason' => $boundary->reason()]);
        }

        return self::$loaded[$cacheKey] = $boundary;
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::invalid('The boundary file is not valid JSON.');
        }

        if (! is_array($data)) {
            return self::invalid('The boundary must be a GeoJSON Feature.');
        }

        if (($data['type'] ?? null) === 'FeatureCollection') {
            $features = $data['features'] ?? null;
            if (! is_array($features) || count($features) !== 1) {
                return self::invalid('A boundary FeatureCollection must contain exactly one Feature.');
            }
            $data = $features[0];
        }

        if (! is_array($data) || ($data['type'] ?? null) !== 'Feature') {
            return self::invalid('The boundary must be a GeoJSON Feature with a Polygon or MultiPolygon geometry.');
        }

        $properties = is_array($data['properties'] ?? null) ? $data['properties'] : [];
        $source = trim((string) ($properties['source'] ?? ''));
        if ($source === '') {
            return self::invalid('The boundary is incomplete: properties.source (the authoritative source) is missing.');
        }

        $geometry = $data['geometry'] ?? null;
        $type = is_array($geometry) ? ($geometry['type'] ?? null) : null;
        $coordinates = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;

        if ($type === 'Polygon') {
            $rawPolygons = [$coordinates];
        } elseif ($type === 'MultiPolygon') {
            $rawPolygons = $coordinates;
        } else {
            return self::invalid('The boundary geometry must be a Polygon or MultiPolygon.');
        }

        if (! is_array($rawPolygons) || $rawPolygons === []) {
            return self::invalid('The boundary geometry has no coordinates.');
        }

        $polygons = [];
        $positions = 0;
        foreach (array_values($rawPolygons) as $p => $rawRings) {
            if (! is_array($rawRings) || $rawRings === []) {
                return self::invalid(sprintf('Polygon %d has no rings.', $p + 1));
            }
            $rings = [];
            foreach (array_values($rawRings) as $r => $rawRing) {
                $label = sprintf('Polygon %d, ring %d', $p + 1, $r + 1);
                $ring = self::parseRing($rawRing, $label, $error);
                if ($ring === null) {
                    return self::invalid($error);
                }
                $positions += count($ring);
                if ($positions > self::MAX_POSITIONS) {
                    return self::invalid('The boundary has too many positions (limit 50,000).');
                }
                $rings[] = $ring;
            }
            $polygons[] = $rings;
        }

        $meta = ['source' => $source];
        foreach (['name', 'version', 'retrieved_at'] as $key) {
            if (isset($properties[$key]) && is_scalar($properties[$key]) && trim((string) $properties[$key]) !== '') {
                $meta[$key] = trim((string) $properties[$key]);
            }
        }

        return new self(self::STATE_VALID, '', $polygons, $meta);
    }

    /**
     * @return list<array{0: float, 1: float}>|null
     */
    private static function parseRing(mixed $rawRing, string $label, ?string &$error): ?array
    {
        if (! is_array($rawRing) || count($rawRing) < 4) {
            $error = $label.' needs at least 4 positions (a closed ring).';

            return null;
        }

        $ring = [];
        foreach (array_values($rawRing) as $i => $position) {
            if (! is_array($position) || count($position) < 2
                || ! is_int($position[0] ?? null) && ! is_float($position[0] ?? null)
                || ! is_int($position[1] ?? null) && ! is_float($position[1] ?? null)) {
                $error = sprintf('%s, position %d is not a [longitude, latitude] pair of numbers.', $label, $i + 1);

                return null;
            }
            [$lng, $lat] = [(float) $position[0], (float) $position[1]];
            if (! is_finite($lng) || ! is_finite($lat) || $lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
                $error = sprintf('%s, position %d is out of range (GeoJSON order is longitude, latitude).', $label, $i + 1);

                return null;
            }
            $ring[] = [$lng, $lat];
        }

        if ($ring[0] !== $ring[count($ring) - 1]) {
            $error = $label.' is not closed (the first and last positions must be identical).';

            return null;
        }

        if (abs(self::signedArea($ring)) < 1e-14) {
            $error = $label.' has no area.';

            return null;
        }

        return $ring;
    }

    private static function invalid(string $reason): self
    {
        return new self(self::STATE_INVALID, $reason);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isValid(): bool
    {
        return $this->state === self::STATE_VALID;
    }

    /** Human-readable reason when not valid (safe to show to staff). */
    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<string, string> source (+ optional name, version, retrieved_at) */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * Normalized GeoJSON Feature (MultiPolygon, [lng, lat]) for drawing, or null when not valid.
     *
     * @return array<string, mixed>|null
     */
    public function toGeoJson(): ?array
    {
        if (! $this->isValid()) {
            return null;
        }

        return [
            'type' => 'Feature',
            'properties' => $this->meta,
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => $this->polygons],
        ];
    }

    /**
     * Is the point inside the boundary (edges count as inside, holes excluded)? Fails closed: false when not valid.
     * Note the argument order (latitude, longitude) versus GeoJSON's [longitude, latitude].
     */
    public function contains(float $latitude, float $longitude): bool
    {
        if (! $this->isValid()) {
            return false;
        }

        foreach ($this->polygons as $rings) {
            if (! self::inRing($longitude, $latitude, $rings[0])) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($rings, 1) as $hole) {
                if (self::inRing($longitude, $latitude, $hole) && ! self::onEdge($longitude, $latitude, $hole)) {
                    $inHole = true;
                    break;
                }
            }
            if (! $inHole) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private static function inRing(float $x, float $y, array $ring): bool
    {
        if (self::onEdge($x, $y, $ring)) {
            return true;
        }

        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private static function onEdge(float $x, float $y, array $ring): bool
    {
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            [$x1, $y1] = $ring[$i];
            [$x2, $y2] = $ring[$i + 1];
            $cross = ($x - $x1) * ($y2 - $y1) - ($y - $y1) * ($x2 - $x1);
            if (abs($cross) > self::EDGE_EPSILON) {
                continue;
            }
            if ($x >= min($x1, $x2) - self::EDGE_EPSILON && $x <= max($x1, $x2) + self::EDGE_EPSILON
                && $y >= min($y1, $y2) - self::EDGE_EPSILON && $y <= max($y1, $y2) + self::EDGE_EPSILON) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private static function signedArea(array $ring): float
    {
        $sum = 0.0;
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            $sum += $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
        }

        return $sum / 2;
    }

    /** Test helper: forget cached files (e.g. after a test rewrites a fixture). */
    public static function flushCache(): void
    {
        self::$loaded = [];
    }
}
