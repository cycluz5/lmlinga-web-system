<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Dual-read string seal/open bound to table|column. Typed columns go through AtRestRecord.
 */
final class AtRestNarrativeField
{
    public const DISPLAY_UNAVAILABLE = '[Decryption unavailable]';

    public static function aad(string $table, string $column): string
    {
        return 'lmlinga|'.$table.'|'.$column;
    }

    public static function isSealed(mixed $value): bool
    {
        return AtRestEncrypter::isSealed($value);
    }

    public static function seal(mixed $value, string $table, string $column): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (AtRestEncrypter::looksEncrypted($trimmed)) {
            self::openOrFail($trimmed, $table, $column);

            return $trimmed;
        }

        if (! self::writesEnabled()) {
            return $trimmed;
        }

        return self::encrypter()->encrypt($trimmed, self::aad($table, $column));
    }

    public static function open(mixed $value, string $table, string $column): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return (string) $value;
        }

        if ($value === '') {
            return '';
        }

        if (! AtRestEncrypter::looksEncrypted($value)) {
            return $value;
        }

        return self::openOrFail($value, $table, $column);
    }

    public static function openOrFail(string $value, string $table, string $column): string
    {
        return self::encrypter()->decrypt($value, self::aad($table, $column));
    }

    public static function openForDisplay(mixed $value, string $table, string $column, int|string|null $rowId = null): ?string
    {
        try {
            return self::open($value, $table, $column);
        } catch (AtRestEncryptionException $e) {
            Log::warning('At-rest decryption unavailable', [
                'exception' => $e::class,
                'table' => $table,
                'column' => $column,
                'row_id' => $rowId,
            ]);

            return (string) config('lmlinga.at_rest.display_unavailable', self::DISPLAY_UNAVAILABLE);
        }
    }

    private static function writesEnabled(): bool
    {
        return (bool) config('lmlinga.at_rest.enabled', true);
    }

    private static function encrypter(): AtRestEncrypter
    {
        return app(AtRestEncrypter::class);
    }
}
