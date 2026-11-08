<?php

namespace App\Support;

use RuntimeException;

/**
 * Keyed, deterministic, tamper-proof codes for ids that appear in URLs.
 *
 * token = kind(1 letter) + base32( tag(6) || value XOR keystream )
 *
 * The tag is an HMAC over (kind, value) and doubles as the SIV nonce, so the same
 * value always yields the same token (cache/offline friendly), a token can only be
 * produced with the app key, and it cannot be moved between kinds.
 */
final class OpaqueId
{
    private const TAG_BYTES = 6;

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    /** Shape of any token (cheap pre-filter; validity is decided by the tag). */
    public const TOKEN_PATTERN = '/^[a-z][a-z2-7]{12,120}$/';

    public static function enabled(): bool
    {
        return (bool) config('lmlinga.opaque_urls', true);
    }

    /** Value to place in a URL / hand to browser code: the opaque code, or the raw value when disabled. */
    public static function forUrl(string $kind, string $value): string
    {
        return self::enabled() && $value !== '' ? self::encode($kind, $value) : $value;
    }

    public static function encode(string $kind, string $value): string
    {
        $tag = self::tag($kind, $value);
        $cipher = $value ^ self::keystream($kind, $tag, strlen($value));

        return $kind.self::base32($tag.$cipher);
    }

    /**
     * @return array{kind: string, value: string}|null
     */
    public static function decode(string $token): ?array
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $kind = $token[0];
        $raw = self::unbase32(substr($token, 1));
        if ($raw === null || strlen($raw) <= self::TAG_BYTES) {
            return null;
        }

        $tag = substr($raw, 0, self::TAG_BYTES);
        $cipher = substr($raw, self::TAG_BYTES);
        $value = $cipher ^ self::keystream($kind, $tag, strlen($cipher));

        if (! hash_equals(self::tag($kind, $value), $tag)) {
            return null;
        }

        return ['kind' => $kind, 'value' => $value];
    }

    public static function decodeKind(string $kind, string $token): ?string
    {
        $decoded = self::decode($token);

        return $decoded !== null && $decoded['kind'] === $kind ? $decoded['value'] : null;
    }

    private static function key(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $appKey = (string) config('app.key', '');
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is required for opaque URL ids.');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return $cached = hash_hkdf('sha256', $appKey, 32, 'lmlinga-opaque-url-v1');
    }

    private static function tag(string $kind, string $value): string
    {
        return substr(hash_hmac('sha256', $kind."\0".$value, self::key().'tag', true), 0, self::TAG_BYTES);
    }

    private static function keystream(string $kind, string $tag, int $length): string
    {
        $out = '';
        $counter = 0;
        while (strlen($out) < $length) {
            $out .= hash_hmac('sha256', $kind."\0".$tag."\0".$counter, self::key().'ks', true);
            $counter++;
        }

        return substr($out, 0, $length);
    }

    private static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private static function unbase32(string $text): ?string
    {
        $bits = '';
        foreach (str_split($text) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                return null;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                // Trailing padding bits must be zero (canonical form only).
                if (bindec($chunk) !== 0) {
                    return null;
                }
                break;
            }
            $bytes .= chr(bindec($chunk));
        }

        return $bytes;
    }
}
