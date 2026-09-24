<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers outbound OTP SMS through Semaphore POST /otp.
 * Does not generate, store, or verify OTPs — Laravel supplies the code.
 */
final class SemaphoreSmsService
{
    /** @var list<string> */
    private const ACCEPTED_STATUSES = ['queued', 'pending', 'sent'];

    public function sendSms(
        string $phoneNumber,
        string $message,
        string $code,
        ?int $requestId = null,
    ): SemaphoreSmsSendResult {
        $apiKey = trim((string) config('services.semaphore.api_key', ''));
        $baseUrl = rtrim((string) config('services.semaphore.base_url', 'https://api.semaphore.co/api/v4'), '/');
        $senderName = trim((string) config('services.semaphore.sender_name', 'LMLINGA'));
        $timeout = max(1, (int) config('services.semaphore.timeout', 10));

        if ($apiKey === '' || $senderName === '') {
            $this->logFailure($requestId, SemaphoreSmsSendResult::CATEGORY_CONFIGURATION, null);

            return SemaphoreSmsSendResult::failed(SemaphoreSmsSendResult::CATEGORY_CONFIGURATION);
        }

        $code = trim($code);
        if ($code === '' || $phoneNumber === '' || $message === '') {
            $this->logFailure($requestId, SemaphoreSmsSendResult::CATEGORY_CONFIGURATION, null);

            return SemaphoreSmsSendResult::failed(SemaphoreSmsSendResult::CATEGORY_CONFIGURATION);
        }

        $url = $baseUrl.'/otp';

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asForm()
                ->post($url, [
                    'apikey' => $apiKey,
                    'number' => $phoneNumber,
                    'message' => $message,
                    'sendername' => $senderName,
                    'code' => $code,
                ]);
        } catch (ConnectionException $e) {
            $category = str_contains(strtolower($e->getMessage()), 'timed out')
                || str_contains(strtolower($e->getMessage()), 'timeout')
                ? SemaphoreSmsSendResult::CATEGORY_TIMEOUT
                : SemaphoreSmsSendResult::CATEGORY_NETWORK;

            $this->logFailure($requestId, $category, null);

            return SemaphoreSmsSendResult::failed($category);
        } catch (Throwable) {
            $this->logFailure($requestId, SemaphoreSmsSendResult::CATEGORY_NETWORK, null);

            return SemaphoreSmsSendResult::failed(SemaphoreSmsSendResult::CATEGORY_NETWORK);
        }

        $httpStatus = $response->status();

        if (! $response->successful()) {
            $this->logFailure($requestId, SemaphoreSmsSendResult::CATEGORY_HTTP, $httpStatus);

            return SemaphoreSmsSendResult::failed(SemaphoreSmsSendResult::CATEGORY_HTTP, $httpStatus);
        }

        $payload = $response->json();

        if (! is_array($payload) || $payload === [] || ! array_is_list($payload)) {
            $this->logFailure($requestId, SemaphoreSmsSendResult::CATEGORY_MALFORMED, $httpStatus);

            return SemaphoreSmsSendResult::failed(SemaphoreSmsSendResult::CATEGORY_MALFORMED, $httpStatus);
        }

        $first = $payload[0] ?? null;
        if (! is_array($first)) {
            $this->logFailure($requestId, SemaphoreSmsSendResult::CATEGORY_MALFORMED, $httpStatus);

            return SemaphoreSmsSendResult::failed(SemaphoreSmsSendResult::CATEGORY_MALFORMED, $httpStatus);
        }

        $messageId = trim((string) ($first['message_id'] ?? ''));
        $status = strtolower(trim((string) ($first['status'] ?? '')));

        if ($messageId === '' || ! in_array($status, self::ACCEPTED_STATUSES, true)) {
            $this->logFailure($requestId, SemaphoreSmsSendResult::CATEGORY_PROVIDER, $httpStatus);

            return SemaphoreSmsSendResult::failed(SemaphoreSmsSendResult::CATEGORY_PROVIDER, $httpStatus);
        }

        Log::info('semaphore.sms.queued', array_filter([
            'provider' => 'semaphore',
            'request_id' => $requestId,
            'http_status' => $httpStatus,
            'message_id' => $messageId,
        ], static fn ($value) => $value !== null));

        return SemaphoreSmsSendResult::queued($messageId, $httpStatus);
    }

    private function logFailure(?int $requestId, string $category, ?int $httpStatus): void
    {
        Log::warning('semaphore.sms.failed', array_filter([
            'provider' => 'semaphore',
            'request_id' => $requestId,
            'failure_category' => $category,
            'http_status' => $httpStatus,
        ], static fn ($value) => $value !== null));
    }
}
