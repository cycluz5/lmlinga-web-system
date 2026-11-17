<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refinement item 1 (dummy mobile number): a health worker's mobile number must be a Philippine mobile number
 * (09XXXXXXXXX, +639XXXXXXXXX or 639XXXXXXXXX; spaces and dashes allowed) and must not look like a placeholder.
 *
 * Rejected as dummy: six or more identical digits in a row (09000000000, 09171111111), a run of six or more
 * consecutive digits going up or down (09123456789, 09987654321), and prefixes that are not assigned (900-904).
 * It cannot prove that the number belongs to the worker; only an SMS code could, which needs an SMS provider.
 *
 * config('lmlinga.staff_mobile_dummy_check') = false turns off only the placeholder test (the format test stays);
 * the test suite does that because its fixtures use placeholder numbers on purpose.
 *
 * $unchangedNumber: an already stored number re-submitted unchanged is not re-checked, so older accounts can
 * still have their other details edited.
 */
final class PhilippineMobileNumber implements ValidationRule
{
    public const FORMAT_MESSAGE = 'Enter a valid Philippine mobile number, for example 09171234567 or +639171234567.';

    public const DUMMY_MESSAGE = 'This mobile number looks like a placeholder. Enter the worker\'s real mobile number.';

    public function __construct(private readonly ?string $unchangedNumber = null) {}

    /** Returns the 10 digits after the leading 0 (for example "9171234567"), or null if the format is wrong. */
    public static function subscriber(string $value): ?string
    {
        $compact = preg_replace('/[\s-]+/', '', trim($value)) ?? '';

        return preg_match('/^(?:\+?63|0)(9\d{9})$/', $compact, $m) === 1 ? $m[1] : null;
    }

    public static function looksLikeDummy(string $subscriber): bool
    {
        if (preg_match('/(\d)\1{5,}/', $subscriber) === 1) {
            return true;
        }

        $run = 1;
        $dir = 0;
        for ($i = 1, $n = strlen($subscriber); $i < $n; $i++) {
            $step = (int) $subscriber[$i] - (int) $subscriber[$i - 1];
            if ($step === 1 || $step === -1) {
                $run = ($step === $dir) ? $run + 1 : 2;
                $dir = $step;
            } else {
                $run = 1;
                $dir = 0;
            }

            if ($run >= 6) {
                return true;
            }
        }

        return (int) substr($subscriber, 0, 3) < 905;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return; // Left to the required rule.
        }

        $subscriber = self::subscriber($value);
        if ($subscriber === null) {
            $fail(self::FORMAT_MESSAGE);

            return;
        }

        if ($this->unchangedNumber !== null && self::subscriber($this->unchangedNumber) === $subscriber) {
            return;
        }

        if ((bool) config('lmlinga.staff_mobile_dummy_check', true) && self::looksLikeDummy($subscriber)) {
            $fail(self::DUMMY_MESSAGE);
        }
    }
}
