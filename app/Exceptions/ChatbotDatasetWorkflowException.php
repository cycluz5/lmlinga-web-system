<?php

namespace App\Exceptions;

/**
 * SR-07: a dataset action that is not allowed in the dataset's current state, or by this user, or an
 * upload that failed inspection. The message is safe to show to staff.
 */
class ChatbotDatasetWorkflowException extends ChatbotTermWorkflowException
{
}
