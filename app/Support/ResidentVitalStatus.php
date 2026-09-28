<?php

namespace App\Support;

use App\Models\DeathRequest;
use App\Models\Resident;
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
        if (DeathRecordsErdMode::isActive()) {
            $resolved = app(HouseholdMemberResolver::class)->resolveMember($householdNo, $memberId);
            $resident = $resolved['resident'] ?? null;
            if (! $resident instanceof Resident) {
                return false;
            }

            return DeathRequest::query()
                ->where('resident_id', $resident->id)
                ->approved()
                ->exists();
        }

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
        if (DeathRecordsErdMode::isActive()) {
            return new ResidentStatus([
                'household_no' => $request->household_no,
                'member_id' => $request->member_id,
                'resident_id' => $request->resident_id,
                'status' => ResidentStatus::STATUS_DECEASED,
            ]);
        }

        $householdNo = DemoCatalog::normalizeHouseholdNo($request->household_no);
        $memberId = DemoCatalog::normalizeMemberId($request->member_id);

        return ResidentStatus::query()->updateOrCreate(
            [
                'household_no' => $householdNo,
                'member_id' => $memberId,
            ],
            [
                'resident_id' => $request->resident_id,
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
        if (DeathRecordsErdMode::isActive()) {
            return DeathRequest::query()
                ->approved()
                ->with(['resident.household'])
                ->get()
                ->map(static function (DeathRequest $row): ?string {
                    $householdNo = DemoCatalog::normalizeHouseholdNo($row->household_no);
                    $memberId = DemoCatalog::normalizeMemberId($row->member_id);
                    if ($householdNo === '' || $memberId === '') {
                        return null;
                    }

                    return $householdNo.'|'.$memberId;
                })
                ->filter(static fn (?string $key): bool => $key !== null)
                ->values()
                ->all();
        }

        return ResidentStatus::query()
            ->where('status', ResidentStatus::STATUS_DECEASED)
            ->get(['household_no', 'member_id'])
            ->map(static fn (ResidentStatus $row): string => $row->household_no.'|'.$row->member_id)
            ->values()
            ->all();
    }
}
