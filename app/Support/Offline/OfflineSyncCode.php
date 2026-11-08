<?php

namespace App\Support\Offline;

/**
 * Structured JSON codes for /offline/status and /offline/sync.
 */
final class OfflineSyncCode
{
    public const SYNCED = 'SYNCED';

    public const ALREADY_APPLIED = 'ALREADY_APPLIED';

    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const CONFLICT = 'CONFLICT';

    public const TARGET_CHANGED = 'TARGET_CHANGED';

    public const TARGET_MISSING = 'TARGET_MISSING';

    public const SESSION_EXPIRED = 'SESSION_EXPIRED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const ACCOUNT_INACTIVE = 'ACCOUNT_INACTIVE';

    public const PASSWORD_CHANGE_REQUIRED = 'PASSWORD_CHANGE_REQUIRED';

    public const CSRF_MISMATCH = 'CSRF_MISMATCH';

    public const IDEMPOTENCY_PAYLOAD_MISMATCH = 'IDEMPOTENCY_PAYLOAD_MISMATCH';

    public const IDEMPOTENCY_ACTOR_MISMATCH = 'IDEMPOTENCY_ACTOR_MISMATCH';

    public const UNKNOWN_OPERATION = 'UNKNOWN_OPERATION';

    public const MALFORMED = 'MALFORMED';

    public const RETRYABLE_ERROR = 'RETRYABLE_ERROR';
}
