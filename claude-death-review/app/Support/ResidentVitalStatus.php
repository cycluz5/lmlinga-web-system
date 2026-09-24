<?php

namespace App\Support;

use App\Models\DeathRequest;
use App\Models\ResidentStatus;

/**
 * Authoritative current vital status.
 *
 * Written only when an Admin approves a death request (same DB transaction).
 * Historical health records are never deleted here.
 */
final class ResidentVitalStatus
{
    public const ALIVE = 'Active';

    public const DECEASED = 'Deceased';

    public static function isDeceased(string $householdNo, string $memberId): bool
    {
        $row = ResidentStatus::forMember(
            DemoCatalog::normalizeHouseholdNo($householdNo),
            DemoCatalog::normalizeMemberId($memberId)
        );

        return $row !== null && $row->isDeceased();
    }

    public static function label(string $householdNo, string $memberId, ?string $civilStatus = null): string
    {
        if (self::isDeceased($householdNo, $memberId)) {
            return self::DECEASED;
        }

        $civil = trim((string) $civilStatus);

        return $civil !== '' ? $civil : self::ALIVE;
    }

    /**
     * Persist Deceased in the same transaction as death-request approval.
     */
    public static function markDeceased(DeathRequest $request): ResidentStatus
    {
        $householdNo = DemoCatalog::normalizeHouseholdNo($request->household_no);
        $memberId = DemoCatalog::normalizeMemberId($request->member_id);

        return ResidentStatus::query()->updateOrCreate(
            [
                'household_no' => $householdNo,
                'member_id' => $memberId,
            ],
            [
                'status' => ResidentStatus::STATUS_DECEASED,
                'death_request_id' => $request->id,
                'recorded_at' => now(),
            ]
        );
    }

    /**
     * @return list<string>  "HOUSEHOLD_NO|MEMBER_ID"
     */
    public static function deceasedKeys(): array
    {
        return ResidentStatus::query()
            ->where('status', ResidentStatus::STATUS_DECEASED)
            ->get(['household_no', 'member_id'])
            ->map(static fn (ResidentStatus $row): string => $row->household_no.'|'.$row->member_id)
            ->values()
            ->all();
    }
}
