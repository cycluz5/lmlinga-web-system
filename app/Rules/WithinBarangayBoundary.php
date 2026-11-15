<?php

namespace App\Rules;

use App\Support\Geo\BarangayBoundary;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Batch 5 (panel refinement SR-11, step SM-03) — server-side Spot Mapping geofence.
 *
 * Attach to the latitude field; the longitude is read from the same request ($lngKey). A coordinate pair is
 * accepted only when it lies inside the existing Barangay La Medalla boundary (BarangayBoundary::contains:
 * points exactly on an edge count as inside, holes are excluded). Fails closed: when the boundary file is
 * missing or invalid, no new or changed location is accepted.
 *
 * - Non-numeric or missing values are left to the existing required/numeric/between rules.
 * - $unchangedLat/$unchangedLng: an already stored pair that is re-submitted unchanged is allowed, so older
 *   households plotted before enforcement can still have their other details edited. Only new or changed
 *   locations are checked.
 * - config('lmlinga.geofence.enforce') = false is the rollback switch.
 */
final class WithinBarangayBoundary implements DataAwareRule, ValidationRule
{
    public const OUTSIDE_MESSAGE = 'This location is outside the Barangay La Medalla boundary. Households can only be plotted inside La Medalla.';

    public const UNAVAILABLE_MESSAGE = 'The Barangay La Medalla boundary is unavailable, so the location cannot be checked. Please contact the administrator.';

    /** Same-point tolerance (~1 cm) for "re-submitted unchanged". */
    private const SAME_POINT_EPSILON = 1e-7;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly string $lngKey = 'lng',
        private readonly ?float $unchangedLat = null,
        private readonly ?float $unchangedLng = null,
    ) {}

    public static function enforced(): bool
    {
        return (bool) config('lmlinga.geofence.enforce', true);
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::enforced()) {
            return;
        }

        $lng = data_get($this->data, $this->lngKey);
        if (! is_numeric($value) || ! is_numeric($lng)) {
            return;
        }

        $lat = (float) $value;
        $lng = (float) $lng;

        if (
            $this->unchangedLat !== null && $this->unchangedLng !== null
            && abs($lat - $this->unchangedLat) < self::SAME_POINT_EPSILON
            && abs($lng - $this->unchangedLng) < self::SAME_POINT_EPSILON
        ) {
            return;
        }

        $boundary = BarangayBoundary::load();
        if (! $boundary->isValid()) {
            $fail(self::UNAVAILABLE_MESSAGE);

            return;
        }

        if (! $boundary->contains($lat, $lng)) {
            $fail(self::OUTSIDE_MESSAGE);
        }
    }
}
