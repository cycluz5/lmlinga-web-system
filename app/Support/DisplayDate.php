<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * User-facing calendar dates: MM/DD/YYYY.
 * Storage and HTML date input values remain Y-m-d.
 */
final class DisplayDate
{
    public static function format(?string $iso): string
    {
        if ($iso === null) {
            return '';
        }

        $trimmed = trim($iso);
        if ($trimmed === '') {
            return '';
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $trimmed);

            if ($parsed instanceof Carbon && $parsed->format('Y-m-d') === $trimmed) {
                return $parsed->format('m/d/Y');
            }
        } catch (\Throwable) {
        }

        try {
            return Carbon::parse($trimmed)->format('m/d/Y');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * User-facing date-time stamps: MM/DD/YYYY with local time.
     */
    public static function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $parsed = $value instanceof DateTimeInterface
                ? Carbon::parse($value)
                : Carbon::parse((string) $value);

            return $parsed->timezone(config('app.timezone'))->format('m/d/Y, g:i A');
        } catch (\Throwable) {
            return '';
        }
    }
}
