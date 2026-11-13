<?php

namespace App\Support;

use App\Exceptions\ChatbotAiUnavailableException;

/**
 * SR-08 chat endpoint selection for Ollama.
 *
 * - auto (default): the existing rule — ollama.com only when the model ends in "-cloud" AND an API key is
 *   configured; otherwise the local server.
 * - local: always the local server; the API key is never sent; a "-cloud" model is a configuration error.
 * - cloud: always ollama.com; requires the API key.
 * There is no automatic fallback from one provider to another.
 */
final class OllamaChatEndpoint
{
    public const AUTO = 'auto';

    public const LOCAL = 'local';

    public const CLOUD = 'cloud';

    public const PROVIDERS = [self::AUTO, self::LOCAL, self::CLOUD];

    /**
     * @return array{provider: string, url: string, token: ?string}
     */
    public static function resolve(string $provider, string $model, ?string $apiKey, string $localBaseUrl, string $cloudUrl): array
    {
        $provider = strtolower(trim($provider));
        $hasKey = $apiKey !== null && $apiKey !== '';
        $isCloudModel = str_ends_with($model, '-cloud');

        $useCloud = match ($provider) {
            self::AUTO => $isCloudModel && $hasKey,
            self::CLOUD => true,
            self::LOCAL => false,
            default => throw new ChatbotAiUnavailableException("Unknown chat provider \"{$provider}\" (use auto, local or cloud)."),
        };

        if ($provider === self::LOCAL && $isCloudModel) {
            throw new ChatbotAiUnavailableException("Chat provider \"local\" cannot use the cloud model \"{$model}\".");
        }
        if ($useCloud && ! $hasKey) {
            throw new ChatbotAiUnavailableException('Chat provider "cloud" requires OLLAMA_API_KEY.');
        }

        return $useCloud
            ? ['provider' => self::CLOUD, 'url' => rtrim($cloudUrl, '/').'/api/chat', 'token' => $apiKey]
            : ['provider' => self::LOCAL, 'url' => rtrim($localBaseUrl, '/').'/api/chat', 'token' => null];
    }

    /** Timeout bounded to 5–600 seconds so a bad setting cannot disable or hang requests. */
    public static function boundedTimeout(int $seconds, int $default): int
    {
        return $seconds > 0 ? max(5, min(600, $seconds)) : $default;
    }
}
