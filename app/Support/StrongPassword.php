<?php

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * Authoritative staff password contract shared by server validation and live UX strength.
 */
final class StrongPassword
{
    public const MIN_LENGTH = 8;

    public const MESSAGE = 'Use at least 8 characters with letters, numbers, and a symbol.';

    public const STRENGTH_WEAK = 'weak';

    public const STRENGTH_MEDIUM = 'medium';

    public const STRENGTH_STRONG = 'strong';

    public static function hasLetter(string $password): bool
    {
        return (bool) preg_match('/[A-Za-z]/', $password);
    }

    public static function hasNumber(string $password): bool
    {
        return (bool) preg_match('/\d/', $password);
    }

    public static function hasSymbol(string $password): bool
    {
        return (bool) preg_match('/[^A-Za-z0-9]/', $password);
    }

    public static function categoryCount(string $password): int
    {
        $count = 0;
        if (self::hasLetter($password)) {
            $count++;
        }
        if (self::hasNumber($password)) {
            $count++;
        }
        if (self::hasSymbol($password)) {
            $count++;
        }

        return $count;
    }

    public static function meetsRequirements(string $password): bool
    {
        return strlen($password) >= self::MIN_LENGTH
            && self::hasLetter($password)
            && self::hasNumber($password)
            && self::hasSymbol($password);
    }

    /**
     * @return self::STRENGTH_WEAK|self::STRENGTH_MEDIUM|self::STRENGTH_STRONG
     */
    public static function strengthLevel(string $password): string
    {
        if ($password === '') {
            return self::STRENGTH_WEAK;
        }

        if (self::meetsRequirements($password)) {
            return self::STRENGTH_STRONG;
        }

        $length = strlen($password);
        $categories = self::categoryCount($password);

        if ($length < self::MIN_LENGTH || $categories <= 1) {
            return self::STRENGTH_WEAK;
        }

        return self::STRENGTH_MEDIUM;
    }

    /**
     * @return list<string>
     */
    public static function laravelRules(bool $confirmed = true): array
    {
        $rules = [
            'required',
            'string',
            'min:'.self::MIN_LENGTH,
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! self::meetsRequirements($value)) {
                    $fail(self::MESSAGE);
                }
            },
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }

    public static function attachValidatorMessage(Validator $validator, string $attribute = 'password'): void
    {
        $validator->after(function (Validator $validator) use ($attribute): void {
            if ($validator->errors()->has($attribute)) {
                return;
            }

            $value = $validator->getData()[$attribute] ?? null;
            if (! is_string($value) || $value === '') {
                return;
            }

            if (! self::meetsRequirements($value)) {
                $validator->errors()->add($attribute, self::MESSAGE);
            }
        });
    }
}
