<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * SR-07: a dataset action that is not allowed in the dataset's current state, or by this user, or an
 * upload that failed inspection. The message is safe to show to staff.
 */
class ChatbotDatasetWorkflowException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 409, private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** Form field the message belongs to, if any (validation-style errors). */
    public function field(): ?string
    {
        return $this->field;
    }
}
