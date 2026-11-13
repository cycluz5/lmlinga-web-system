<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * SR-08: the chatbot's AI provider cannot be used (configuration error, unreachable server, failed
 * request). The message is for logs only and never contains the API key; residents see a friendly reply.
 */
class ChatbotAiUnavailableException extends RuntimeException
{
}
