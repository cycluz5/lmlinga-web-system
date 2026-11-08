<?php

namespace App\Support;

use App\Models\RecordRequest;
use App\Models\Resident;
use App\Models\ResidentAccount;
use Illuminate\Support\Facades\Schema;

/**
 * Resident-chatbot authorization for viewing a household member record.
 *
 * Authorized household is always derived from the verified account's linked
 * resident — never from the URL member identifier alone.
 */
final class ChatbotHouseholdMemberAccess
{
    /**
     * Resolve a household member the authenticated account is allowed to view.
     *
     * @return array{
     *     resident: Resident,
     *     authorized_household_id: int|string
     * }|null null when verified household access is not granted
     */
    public static function resolveAuthorizedMember(
        ResidentAccount $account,
        string $memberKey,
    ): ?array {
        $record = RecordRequest::latestForAccount($account->account_id);

        if (! HouseholdRecordVerifiedAccess::grantsHouseholdInformationAccess($account, $record)) {
            return null;
        }

        $authorizedHouseholdId = self::authorizedHouseholdIdForAccount($account);

        if ($authorizedHouseholdId === null) {
            return null;
        }

        $member = self::findResidentByMemberKey($memberKey);

        if ($member === null) {
            abort(404);
        }

        if (
            ! isset($member->household_id)
            || $member->household_id === null
            || (string) $member->household_id !== (string) $authorizedHouseholdId
        ) {
            abort(404);
        }

        return [
            'resident' => $member,
            'authorized_household_id' => $authorizedHouseholdId,
        ];
    }

    /**
     * Official household_id for the account's linked resident, or null.
     */
    public static function authorizedHouseholdIdForAccount(ResidentAccount $account): int|string|null
    {
        if ($account->resident_id === null || $account->resident_id === '') {
            return null;
        }

        if (! Schema::hasTable('residents') || ! Schema::hasColumn('residents', 'household_id')) {
            return null;
        }

        $query = Resident::query()->whereKey($account->resident_id);

        if (Schema::hasColumn('residents', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $linked = $query->first();

        if ($linked === null || $linked->household_id === null) {
            return null;
        }

        return $linked->household_id;
    }

    private static function findResidentByMemberKey(string $memberKey): ?Resident
    {
        $memberKey = trim($memberKey);

        if ($memberKey === '' || ! ctype_digit($memberKey)) {
            return null;
        }

        if (! Schema::hasTable('residents')) {
            return null;
        }

        $query = Resident::query()->whereKey($memberKey);

        if (Schema::hasColumn('residents', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->first();
    }
}
