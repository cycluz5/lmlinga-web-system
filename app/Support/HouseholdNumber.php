<?php

namespace App\Support;

use App\Models\Household;
use Illuminate\Validation\Rule;

/**
 * Physical household number (households.household_no), distinct from household_id.
 *
 * New households use exactly three digits. Existing HH-{n} rows stay as stored.
 */
final class HouseholdNumber
{
    public const DIGITS_PATTERN = '/^[0-9]{3}$/';

    /** Route {householdNo} segment: legacy HH-* and new 3-digit values. */
    public const ROUTE_CONSTRAINT = 'HH-[0-9]+|[0-9]{3}';

    public const DUPLICATE_MESSAGE = 'This household number is already registered.';

    public const FORMAT_MESSAGE = 'Household No. must be exactly 3 digits.';

    public const REQUIRED_MESSAGE = 'Household No. is required.';

    /**
     * Trim only. Never intval() — that would drop leading zeroes.
     */
    public static function normalizeSubmitted(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Visible Household Profiling HH No. label. Strips a leading HH- prefix
     * only. Stored household_no, routes, and sync keys stay unchanged.
     * Never intval() — "001" must remain "001".
     */
    public static function forDisplay(mixed $value): string
    {
        $raw = self::normalizeSubmitted($value);
        if ($raw === '') {
            return '';
        }

        return preg_replace('/^HH-/i', '', $raw) ?? $raw;
    }

    /**
     * @return list<mixed>
     */
    public static function createRules(): array
    {
        return [
            'required',
            'string',
            'regex:'.self::DIGITS_PATTERN,
            Rule::unique('households', 'household_no'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function validationMessages(): array
    {
        return [
            'household_no.required' => self::REQUIRED_MESSAGE,
            'household_no.regex' => self::FORMAT_MESSAGE,
            'household_no.unique' => self::DUPLICATE_MESSAGE,
        ];
    }

    /**
     * Exact match, or the same digits already stored as HH-{digits}.
     */
    public static function isOccupied(string $digits): bool
    {
        if (preg_match(self::DIGITS_PATTERN, $digits) !== 1) {
            return false;
        }

        return Household::query()
            ->where(function ($query) use ($digits): void {
                $query->where('household_no', $digits)
                    ->orWhereRaw('UPPER(household_no) = ?', ['HH-'.$digits]);
            })
            ->exists();
    }
}
