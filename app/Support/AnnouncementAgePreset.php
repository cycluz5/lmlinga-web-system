<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Announcement age presets normalized to inclusive completed months.
 * Age is always derived from birthday vs an as-of date — never stored statically.
 */
final class AnnouncementAgePreset
{
    /**
     * Inclusive completed-month ranges keyed by create-form chip values.
     *
     * @var array<string, array{0: int, 1: int|null}>
     */
    public const PRESETS = [
        'infants_0_6' => [0, 6],
        'infants_7_11' => [7, 11],
        'young_children' => [12, 71], // 1–5 years (before 6th birthday)
        'school_age' => [72, 155], // 6–12 years (before 13th birthday)
        'teens' => [156, 215], // 13–17 years
        'adults' => [216, 719], // 18–59 years
        'seniors' => [720, null], // 60+ years
    ];

    public static function isValidPreset(string $key): bool
    {
        return array_key_exists($key, self::PRESETS);
    }

    /**
     * @return array{0: int, 1: int|null}
     */
    public static function rangeFor(string $key): array
    {
        if (! self::isValidPreset($key)) {
            throw new InvalidArgumentException("Unknown announcement age preset [{$key}].");
        }

        return self::PRESETS[$key];
    }

    /**
     * Convert a UI age value + unit into whole months (non-negative).
     */
    public static function toMonths(int|float|string $value, string $unit): int
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Age value must be numeric.');
        }

        $number = (int) $value;
        if ($number < 0) {
            throw new InvalidArgumentException('Age value must be zero or greater.');
        }

        $normalizedUnit = strtolower(trim($unit));

        return match ($normalizedUnit) {
            'month', 'months' => $number,
            'year', 'years' => $number * 12,
            default => throw new InvalidArgumentException("Unknown age unit [{$unit}]."),
        };
    }
}
