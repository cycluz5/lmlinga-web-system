<?php

namespace App\Support;

use RuntimeException;

/**
 * Fail-closed at-rest encryption error. Messages must never include keys, nonce, tag, or plaintext.
 */
final class AtRestEncryptionException extends RuntimeException
{
}
