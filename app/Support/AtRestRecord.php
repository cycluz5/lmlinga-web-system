<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Typed seal/open for the columns listed in AtRestColumns.
 *
 * Values are normalized to a canonical string before sealing and cast back to
 * their plaintext type after opening, so callers see the same shapes the
 * original MySQL column types produced (0/1 flags, ints, "165.50" decimals).
 * Legacy plaintext rows are still read as-is.
 */
final class AtRestRecord
{
    public static function seal(mixed $value, string $table, string $column): ?string
    {
        $type = AtRestColumns::type($table, $column);
        if ($type === null) {
            throw new AtRestEncryptionException("Column {$table}.{$column} is not registered for at-rest encryption.");
        }

        if (AtRestEncrypter::looksEncrypted($value)) {
            return AtRestNarrativeField::seal($value, $table, $column);
        }

        $normalized = match ($type) {
            AtRestColumns::BOOL => self::truthy($value) ? '1' : '0',
            AtRestColumns::INT => self::numericOrNull($value) === null ? null : (string) (int) self::numericOrNull($value),
            AtRestColumns::DECIMAL => self::numericOrNull($value) === null
                ? null
                : number_format((float) self::numericOrNull($value), 2, '.', ''),
            default => $value === null ? null : (string) $value,
        };

        return AtRestNarrativeField::seal($normalized, $table, $column);
    }

    /**
     * Open and cast. Undecryptable values are logged and returned as the
     * type's empty value instead of breaking the page.
     */
    public static function open(mixed $value, string $table, string $column): mixed
    {
        $type = AtRestColumns::type($table, $column);

        try {
            $plain = AtRestNarrativeField::open($value, $table, $column);
        } catch (AtRestEncryptionException $e) {
            Log::warning('At-rest decryption unavailable', [
                'exception' => $e::class,
                'table' => $table,
                'column' => $column,
            ]);

            return match ($type) {
                AtRestColumns::BOOL => 0,
                AtRestColumns::TEXT => (string) config('lmlinga.at_rest.display_unavailable', AtRestNarrativeField::DISPLAY_UNAVAILABLE),
                default => null,
            };
        }

        return match ($type) {
            AtRestColumns::BOOL => self::truthy($plain) ? 1 : 0,
            AtRestColumns::INT => self::numericOrNull($plain) === null ? null : (int) self::numericOrNull($plain),
            AtRestColumns::DECIMAL => self::numericOrNull($plain),
            default => $plain,
        };
    }

    /**
     * Seal every registered column present in a row payload.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function sealRow(string $table, array $attributes): array
    {
        foreach (AtRestColumns::columns($table) as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = self::seal($attributes[$column], $table, $column);
            }
        }

        return $attributes;
    }

    /**
     * Return a copy of a query-builder row with registered columns opened.
     *
     * @template T of object|null
     *
     * @param  T  $row
     * @return T
     */
    public static function openRow(string $table, ?object $row): ?object
    {
        if ($row === null) {
            return null;
        }

        $opened = clone $row;
        foreach (AtRestColumns::columns($table) as $column) {
            if (property_exists($opened, $column)) {
                $opened->{$column} = self::open($opened->{$column}, $table, $column);
            }
        }

        return $opened;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        $scalar = strtolower(trim((string) $value));

        return $scalar !== '' && $scalar !== '0' && $scalar !== 'false';
    }

    private static function numericOrNull(mixed $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        $scalar = trim((string) $value);

        return $scalar !== '' && is_numeric($scalar) ? $scalar : null;
    }
}
