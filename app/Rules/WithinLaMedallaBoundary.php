<?php

namespace App\Rules;

use App\Support\Geo\LaMedallaBoundary;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

/**
 * Attach to the latitude field of a Spot Mapping plot. Reads the longitude from the same
 * request (default key "lng") and rejects points outside the Barangay La Medalla boundary.
 * Non-numeric coordinates are left to the existing numeric/between rules.
 */
final class WithinLaMedallaBoundary implements DataAwareRule, ValidationRule
{
    public const MESSAGE = 'You cannot plot outside the Barangay La Medalla boundary.';

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $lngKey = 'lng') {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $lng = $this->data[$this->lngKey] ?? null;
        if (! is_numeric($value) || ! is_numeric($lng)) {
            return;
        }

        $inside = LaMedallaBoundary::contains((float) $value, (float) $lng);
        if ($inside === null) {
            // Missing/unreadable boundary file must not block every plot.
            Log::warning('La Medalla boundary unavailable; plot boundary check skipped.', [
                'path' => LaMedallaBoundary::path(),
            ]);

            return;
        }

        if (! $inside) {
            $fail(self::MESSAGE);
        }
    }
}
