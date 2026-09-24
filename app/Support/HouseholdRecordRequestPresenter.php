<?php

namespace App\Support;

use App\Models\RecordRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only Household Requests list/detail from authoritative record_requests.
 */
final class HouseholdRecordRequestPresenter
{
    public const EMPTY = '—';

    public const PUBLIC_ID_PREFIX = 'req-';

    /**
     * @return list<array<string, mixed>>
     */
    public static function listingRows(): array
    {
        if (! Schema::hasTable('record_requests')) {
            return [];
        }

        return RecordRequest::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (RecordRequest $request): array => self::present($request))
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function zoneFilterOptions(): array
    {
        $zones = collect(HouseholdZoneResolver::DISPLAY_ZONES);

        if (Schema::hasTable('record_requests')) {
            $stored = RecordRequest::query()
                ->whereNotNull('zone_submitted')
                ->where('zone_submitted', '!=', '')
                ->distinct()
                ->pluck('zone_submitted');

            foreach ($stored as $raw) {
                $label = self::zoneLabel((string) $raw);
                if ($label !== '') {
                    $zones->push($label);
                }
            }
        }

        return $zones
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByPublicId(string $publicId): ?array
    {
        if (! Schema::hasTable('record_requests')) {
            return null;
        }

        $requestId = self::requestIdFromPublicId($publicId);
        if ($requestId === null) {
            return null;
        }

        $request = RecordRequest::query()->find($requestId);

        return $request !== null ? self::present($request) : null;
    }

    public static function publicId(int $requestId): string
    {
        return self::PUBLIC_ID_PREFIX.$requestId;
    }

    public static function requestIdFromPublicId(string $publicId): ?int
    {
        $publicId = trim($publicId);
        if ($publicId === '') {
            return null;
        }

        if (str_starts_with($publicId, self::PUBLIC_ID_PREFIX)) {
            $id = substr($publicId, strlen(self::PUBLIC_ID_PREFIX));

            return ctype_digit($id) ? (int) $id : null;
        }

        return ctype_digit($publicId) ? (int) $publicId : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(RecordRequest $request): array
    {
        $first = trim((string) ($request->first_name_submitted ?? ''));
        $middle = trim((string) ($request->middle_name_submitted ?? ''));
        $last = trim((string) ($request->last_name_submitted ?? ''));
        $name = trim(implode(' ', array_filter([$first, $middle, $last])));
        $status = self::displayStatus($request->status);
        $isApproved = strtolower($status) === 'approved';

        return [
            'id' => self::publicId((int) $request->getKey()),
            'request_id' => (int) $request->getKey(),
            'name' => $name !== '' ? $name : self::EMPTY,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'zone' => self::zoneLabel((string) ($request->zone_submitted ?? '')),
            'status' => $status,
            'submitted_at' => $request->created_at?->format('Y-m-d H:i:s') ?? self::EMPTY,
            'evaluated_at' => $request->evaluated_at?->format('Y-m-d H:i:s') ?? self::EMPTY,
            'mobile' => trim((string) ($request->mobile_number_submitted ?? '')) ?: self::EMPTY,
            'email' => trim((string) ($request->email_submitted ?? '')) ?: self::EMPTY,
            'house_no' => trim((string) ($request->household_no_submitted ?? '')) ?: self::EMPTY,
            'street' => self::EMPTY,
            'barangay' => self::EMPTY,
            'relationship' => trim((string) ($request->relationship_submitted ?? '')) ?: self::EMPTY,
            'household_members' => [],
            'decision_reason' => trim((string) ($request->decision_reason ?? '')) ?: (
                $isApproved
                    ? 'The submitted household information passed the required completeness and validation checks.'
                    : self::EMPTY
            ),
            'validation_result' => $isApproved
                ? 'Passed automated validation'
                : 'Failed automated validation',
            'rejection_reasons' => $isApproved
                ? []
                : array_values(array_filter([
                    trim((string) ($request->decision_reason ?? '')),
                ])),
            'matched_resident_id' => $request->matched_resident_id,
        ];
    }

    public static function displayStatus(?string $status): string
    {
        $raw = trim((string) $status);
        if ($raw === '') {
            return self::EMPTY;
        }

        return match (strtolower($raw)) {
            'approved', 'verified', 'matched', 'match found' => HouseholdRequestValidator::STATUS_APPROVED,
            'rejected', 'failed', 'no match', 'no match found' => HouseholdRequestValidator::STATUS_REJECTED,
            default => $raw,
        };
    }

    public static function zoneLabel(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::EMPTY;
        }

        $label = HouseholdZoneResolver::displayLabelFromStoredValue($raw);

        return $label !== '' ? $label : $raw;
    }
}
