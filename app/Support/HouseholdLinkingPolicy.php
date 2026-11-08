<?php

namespace App\Support;

use App\Models\RecordRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may complete a resident household link, and when.
 *
 * In staff_verified mode a matched request stays Pending until an Admin verifies the
 * resident's identity and the request's mobile number; only then may an OTP be issued,
 * and only by SMS to that staff-verified number (requests are never editable by the
 * requester). legacy keeps the original automatic flow for emergency rollback only.
 */
final class HouseholdLinkingPolicy
{
    public const MODE_STAFF_VERIFIED = 'staff_verified';

    public const MODE_LEGACY = 'legacy';

    /** Pending + matched: waiting for staff. Never shown to the requester (only Denied reasons are). */
    public const REASON_AWAITING_STAFF = 'Matched an existing resident record. Awaiting barangay staff identity verification.';

    /** Awaiting OTP after staff verification; the only state that may receive an OTP in staff_verified mode. */
    public const REASON_STAFF_VERIFIED = 'Identity and mobile number verified by barangay staff. Awaiting OTP confirmation.';

    /** Requester-visible (Denied). Generic: never says which detail failed. */
    public const REASON_STAFF_DECLINED = 'Barangay staff could not verify this request. Please visit your Barangay Health Station with a valid ID or contact your Barangay Health Worker.';

    /** Requester-visible (Denied). */
    public const REASON_LINK_REVOKED = 'Household access for this account was revoked by barangay staff. Please visit your Barangay Health Station with a valid ID to verify again.';

    public static function mode(): string
    {
        $mode = (string) config('lmlinga.household_linking.mode', self::MODE_STAFF_VERIFIED);

        // Anything unrecognised fails closed to staff verification.
        return $mode === self::MODE_LEGACY ? self::MODE_LEGACY : self::MODE_STAFF_VERIFIED;
    }

    public static function requiresStaffVerification(): bool
    {
        return self::mode() === self::MODE_STAFF_VERIFIED;
    }

    public static function emailOtpAllowed(): bool
    {
        return ! self::requiresStaffVerification();
    }

    public static function isAwaitingStaff(RecordRequest $record): bool
    {
        return $record->status === RecordRequest::STATUS_PENDING
            && $record->matched_resident_id !== null
            && $record->matched_resident_id !== ''
            && trim((string) $record->decision_reason) === self::REASON_AWAITING_STAFF;
    }

    public static function isStaffVerified(RecordRequest $record): bool
    {
        return trim((string) $record->decision_reason) === self::REASON_STAFF_VERIFIED;
    }

    /**
     * May this request receive / verify an OTP? Legacy mode: no extra condition.
     * Staff mode: only Awaiting OTP requests that staff verified. Legacy Awaiting OTP rows and
     * legacy Approved-without-OTP rows therefore need staff verification first.
     */
    public static function permitsOtp(RecordRequest $record): bool
    {
        if (! self::requiresStaffVerification()) {
            return true;
        }

        return $record->status === RecordRequest::STATUS_AWAITING_OTP && self::isStaffVerified($record);
    }

    /** Requests a staff member may verify: matched and not yet completed. */
    public static function canStaffVerify(RecordRequest $record): bool
    {
        if ($record->matched_resident_id === null || $record->matched_resident_id === '') {
            return false;
        }

        if (self::isAwaitingStaff($record)) {
            return true;
        }

        // Legacy rows created before staff verification existed.
        return $record->status === RecordRequest::STATUS_AWAITING_OTP && ! self::isStaffVerified($record);
    }

    /**
     * Legacy Approved request with no verified OTP evidence (approved before OTP existed, or
     * provisioned data). It never granted household access; in staff_verified mode the resident
     * re-verifies by submitting a new request for staff verification. Nothing here grants access.
     */
    public static function needsResubmission(RecordRequest $record): bool
    {
        return self::requiresStaffVerification()
            && $record->status === RecordRequest::STATUS_APPROVED
            && ! HouseholdRecordVerifiedAccess::hasVerifiedOtpEvidence($record);
    }

    public static function canStaffDecline(RecordRequest $record): bool
    {
        return in_array($record->status, [RecordRequest::STATUS_PENDING, RecordRequest::STATUS_AWAITING_OTP], true);
    }

    public static function accountLimitReached(int $accountId): bool
    {
        $max = (int) config('lmlinga.household_linking.max_requests_per_account_per_day', 3);
        if ($max <= 0 || ! Schema::hasTable('record_requests')) {
            return false;
        }

        return DB::table('record_requests')
            ->where('account_id', $accountId)
            ->where('created_at', '>=', now()->subDay())
            ->count() >= $max;
    }

    /**
     * Staff-queue spam guard: requests naming one household that are still waiting for staff
     * (Pending), across all accounts. Denied attempts are deliberately not counted, so bogus
     * submissions cannot lock a household's genuine residents out of requesting access.
     */
    public static function householdLimitReached(string $householdNo): bool
    {
        $max = (int) config('lmlinga.household_linking.max_requests_per_household_per_day', 5);
        $householdNo = trim($householdNo);
        if ($max <= 0 || $householdNo === '' || ! Schema::hasTable('record_requests')) {
            return false;
        }

        return DB::table('record_requests')
            ->where('household_no_submitted', $householdNo)
            ->where('created_at', '>=', now()->subDay())
            ->where('status', RecordRequest::STATUS_PENDING)
            ->count() >= $max;
    }

    /** Statuses that block a new submission from the same account (one open request at a time). */
    public static function openRequestStatuses(): array
    {
        return self::requiresStaffVerification()
            ? [RecordRequest::STATUS_PENDING, RecordRequest::STATUS_AWAITING_OTP]
            : [RecordRequest::STATUS_PENDING];
    }
}
