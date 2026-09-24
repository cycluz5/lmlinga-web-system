<?php

namespace App\Support\Offline;

/**
 * Deterministic JSON + SHA-256 hashing for offline-sync idempotency.
 *
 * Associative-object keys are sorted recursively. Sequential (list) array
 * order is preserved. Scalar types are kept as-is for stable JSON.
 */
final class OfflinePayloadCanonicalizer
{
    /** @var list<string> */
    private const IGNORED_PAYLOAD_KEYS = [
        'attempt_count',
        'last_attempt_at',
        'client_status',
        'error',
        'error_message',
        'status',
        'actor_user_id',
        'actor_username',
        'server_result',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function semanticPayload(array $payload): array
    {
        foreach (self::IGNORED_PAYLOAD_KEYS as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    public static function operationHash(string $operationType, array $payload): string
    {
        return self::hash([
            'operation_type' => $operationType,
            'payload' => self::semanticPayload($payload),
        ]);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    public static function json(mixed $value): string
    {
        $encoded = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (! is_string($encoded)) {
            throw new \InvalidArgumentException('Unable to canonicalize payload as JSON.');
        }

        return $encoded;
    }

    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $canonical = [];
            foreach ($value as $item) {
                $canonical[] = self::canonicalize($item);
            }

            return $canonical;
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        $canonical = [];
        foreach ($keys as $key) {
            $canonical[$key] = self::canonicalize($value[$key]);
        }

        return $canonical;
    }
}
