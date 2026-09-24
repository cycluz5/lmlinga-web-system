<?php

namespace App\Support\Offline;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Structured offline-sync failure. Rendered as JSON without stack traces.
 */
final class OfflineSyncException extends HttpException
{
    /**
     * @param  array<string, list<string>|string>  $errors
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $syncCode,
        string $message,
        int $statusCode,
        public readonly array $errors = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($statusCode, $message);
    }

    public static function validationFailed(array $errors, ?string $message = null): self
    {
        $first = self::firstError($errors);

        return new self(
            OfflineSyncCode::VALIDATION_FAILED,
            $message ?: ($first ?: 'Validation failed.'),
            422,
            $errors,
        );
    }

    public static function malformed(string $message, array $errors = []): self
    {
        return new self(OfflineSyncCode::MALFORMED, $message, 422, $errors);
    }

    public static function unknownOperation(): self
    {
        return new self(
            OfflineSyncCode::UNKNOWN_OPERATION,
            'This operation type is not supported.',
            422,
            ['operation_type' => ['This operation type is not supported.']],
        );
    }

    public static function sessionExpired(): self
    {
        return new self(
            OfflineSyncCode::SESSION_EXPIRED,
            'Your session has expired. Please sign in again.',
            401,
        );
    }

    public static function forbidden(): self
    {
        return new self(
            OfflineSyncCode::FORBIDDEN,
            'You are not allowed to perform this operation.',
            403,
        );
    }

    public static function accountInactive(): self
    {
        return new self(
            OfflineSyncCode::ACCOUNT_INACTIVE,
            'This account is inactive.',
            403,
        );
    }

    public static function passwordChangeRequired(): self
    {
        return new self(
            OfflineSyncCode::PASSWORD_CHANGE_REQUIRED,
            'You must change your password before continuing.',
            403,
        );
    }

    public static function csrfMismatch(): self
    {
        return new self(
            OfflineSyncCode::CSRF_MISMATCH,
            'The CSRF token is missing or invalid.',
            419,
        );
    }

    public static function targetMissing(string $message = 'The target record was not found.'): self
    {
        return new self(OfflineSyncCode::TARGET_MISSING, $message, 409);
    }

    public static function targetChanged(): self
    {
        return new self(
            OfflineSyncCode::TARGET_CHANGED,
            'The record has changed since this operation was queued.',
            409,
        );
    }

    public static function conflict(string $message = 'The operation conflicts with the current server state.'): self
    {
        return new self(OfflineSyncCode::CONFLICT, $message, 409);
    }

    public static function payloadMismatch(): self
    {
        return new self(
            OfflineSyncCode::IDEMPOTENCY_PAYLOAD_MISMATCH,
            'This operation was already applied with a different payload.',
            409,
        );
    }

    public static function actorMismatch(): self
    {
        return new self(
            OfflineSyncCode::IDEMPOTENCY_ACTOR_MISMATCH,
            'This operation was already applied by a different account.',
            409,
        );
    }

    public static function retryable(): self
    {
        return new self(
            OfflineSyncCode::RETRYABLE_ERROR,
            'The server could not complete this operation. Please retry.',
            503,
        );
    }

    public function toResponse(): \Illuminate\Http\JsonResponse
    {
        $body = [
            'ok' => false,
            'code' => $this->syncCode,
            'message' => $this->getMessage(),
        ];

        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        foreach ($this->extra as $key => $value) {
            $body[$key] = $value;
        }

        return response()->json($body, $this->getStatusCode());
    }

    /**
     * @param  array<string, list<string>|string>  $errors
     */
    private static function firstError(array $errors): string
    {
        foreach ($errors as $messages) {
            if (is_array($messages)) {
                $first = $messages[0] ?? null;
                if (is_string($first) && $first !== '') {
                    return $first;
                }

                continue;
            }

            if (is_string($messages) && $messages !== '') {
                return $messages;
            }
        }

        return '';
    }
}
