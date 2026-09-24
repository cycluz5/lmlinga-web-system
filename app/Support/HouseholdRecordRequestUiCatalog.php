<?php

namespace App\Support;

use App\Models\RecordRequest;
use Carbon\CarbonInterface;

/**
 * Maps record_requests rows into the Admin Household Requests UI shape.
 * Read-only. Does not join residents or households.
 */
final class HouseholdRecordRequestUiCatalog
{
    /** @var list<string> */
    public const ALLOWED_STATUSES = [
        RecordRequest::STATUS_PENDING,
        RecordRequest::STATUS_NO_MATCH,
        RecordRequest::STATUS_AWAITING_OTP,
        RecordRequest::STATUS_APPROVED,
        RecordRequest::STATUS_DENIED,
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = RecordRequest::query()
            ->orderByDesc('request_id')
            ->get();

        // Highest request_id per account_id (same rule as RecordRequest::latestForAccount).
        $currentRequestIdByAccount = [];
        foreach ($rows as $request) {
            $accountId = (int) $request->account_id;
            if (! array_key_exists($accountId, $currentRequestIdByAccount)) {
                $currentRequestIdByAccount[$accountId] = (int) $request->request_id;
            }
        }

        return $rows
            ->map(function (RecordRequest $request) use ($currentRequestIdByAccount): array {
                $isCurrent = ($currentRequestIdByAccount[(int) $request->account_id] ?? null)
                    === (int) $request->request_id;

                return self::toUiArray($request, $isCurrent);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $publicId): ?array
    {
        $request = self::findModel($publicId);

        return $request === null ? null : self::toUiArray($request, $request->isCurrentForAccount());
    }

    public static function findModel(string $publicId): ?RecordRequest
    {
        $requestId = self::parsePublicId($publicId);

        if ($requestId === null) {
            return null;
        }

        return RecordRequest::query()
            ->whereKey($requestId)
            ->first();
    }

    public static function parsePublicId(string $publicId): ?int
    {
        if (preg_match('/^rr-(\d+)$/', $publicId, $matches) !== 1) {
            return null;
        }

        $requestId = (int) $matches[1];

        return $requestId > 0 ? $requestId : null;
    }

    public static function publicId(int $requestId): string
    {
        return 'rr-'.$requestId;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toUiArray(RecordRequest $request, ?bool $isCurrent = null): array
    {
        $first = trim((string) $request->first_name_submitted);
        $middle = trim((string) $request->middle_name_submitted);
        $last = trim((string) $request->last_name_submitted);
        $name = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== '')));
        $status = (string) $request->status;
        $decisionReason = trim((string) ($request->decision_reason ?? ''));
        $isCurrent ??= $request->isCurrentForAccount();

        return [
            'id' => self::publicId((int) $request->request_id),
            'name' => $name,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'household_no' => (string) $request->household_no_submitted,
            'zone' => ResidentAccountUiCatalog::displayZone((string) $request->zone_submitted),
            'status' => $status,
            'is_current' => $isCurrent,
            'relationship' => (string) $request->relationship_submitted,
            'mobile' => (string) $request->mobile_number_submitted,
            'email' => (string) $request->email_submitted,
            'submitted_at' => self::formatDate($request->created_at),
            'evaluated_at' => self::formatDate($request->evaluated_at),
            'approved_at' => self::formatDate($request->approved_at),
            'decision_reason' => $decisionReason === '' ? null : $decisionReason,
            'rejection_reasons' => ($status === RecordRequest::STATUS_DENIED && $decisionReason !== '')
                ? [$decisionReason]
                : [],
            'household_members' => [],
        ];
    }

    private static function formatDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('F j, Y');
        }

        return null;
    }
}
