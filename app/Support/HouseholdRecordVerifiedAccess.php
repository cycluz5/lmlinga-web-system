<?php

namespace App\Support;

use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\ResidentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central rule: household access requires Approved + verified OTP evidence.
 * Legacy Approved without OTP must re-enter verification.
 */
final class HouseholdRecordVerifiedAccess
{
    public static function hasVerifiedOtpEvidence(RecordRequest $record): bool
    {
        return RecordRequestOtp::query()
            ->where('request_id', $record->request_id)
            ->whereNotNull('verified_at')
            ->exists();
    }

    public static function isVerifiedApproval(RecordRequest $record): bool
    {
        return $record->status === RecordRequest::STATUS_APPROVED
            && self::hasVerifiedOtpEvidence($record);
    }

    /**
     * Awaiting OTP, or legacy Approved without OTP verification evidence.
     */
    public static function requiresOtpVerification(RecordRequest $record): bool
    {
        if ($record->status === RecordRequest::STATUS_AWAITING_OTP) {
            return true;
        }

        return $record->status === RecordRequest::STATUS_APPROVED
            && ! self::hasVerifiedOtpEvidence($record);
    }

    public static function allowsOtpInterface(RecordRequest $record): bool
    {
        return self::requiresOtpVerification($record);
    }

    /**
     * Full verified household-access gate used by Main CTA and /chatbot/household.
     * Persistent DB/session state only — never query-string or flash.
     */
    public static function grantsHouseholdInformationAccess(
        ResidentAccount $account,
        ?RecordRequest $record = null,
    ): bool {
        $record ??= RecordRequest::latestForAccount($account->account_id);

        if (
            ! $record instanceof RecordRequest
            || (int) $record->account_id !== (int) $account->account_id
            || ! self::isVerifiedApproval($record)
        ) {
            return false;
        }

        if ($account->resident_id === null || $account->resident_id === '') {
            return false;
        }

        if (
            $record->matched_resident_id === null
            || $record->matched_resident_id === ''
            || (string) $record->matched_resident_id !== (string) $account->resident_id
        ) {
            return false;
        }

        return self::officialHouseholdNoForLinkedAccount($account) !== null;
    }

    /**
     * Official households.household_no for the account's linked resident, or null.
     */
    public static function officialHouseholdNoForLinkedAccount(ResidentAccount $account): ?string
    {
        if ($account->resident_id === null || $account->resident_id === '') {
            return null;
        }

        $residentKey = self::tableIdentityColumn('residents', 'resident_id', 'id');
        $householdKey = self::tableIdentityColumn('households', 'household_id', 'id');

        if (
            $residentKey === null
            || $householdKey === null
            || ! Schema::hasColumn('residents', 'household_id')
            || ! Schema::hasColumn('households', 'household_no')
        ) {
            return null;
        }

        $query = DB::table('residents')
            ->join('households', 'households.'.$householdKey, '=', 'residents.household_id')
            ->where('residents.'.$residentKey, $account->resident_id);

        if (Schema::hasColumn('residents', 'deleted_at')) {
            $query->whereNull('residents.deleted_at');
        }

        if (Schema::hasColumn('households', 'deleted_at')) {
            $query->whereNull('households.deleted_at');
        }

        $householdNo = trim((string) $query->value('households.household_no'));

        return $householdNo !== '' ? $householdNo : null;
    }

    private static function tableIdentityColumn(string $table, string $livePrimaryKey, string $sqlitePrimaryKey): ?string
    {
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }

            if (Schema::hasColumn($table, $livePrimaryKey)) {
                return $livePrimaryKey;
            }

            if (Schema::hasColumn($table, $sqlitePrimaryKey)) {
                return $sqlitePrimaryKey;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
