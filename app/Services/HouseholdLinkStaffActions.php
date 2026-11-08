<?php

namespace App\Services;

use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Support\HouseholdLinkingPolicy;
use App\Support\HouseholdRecordVerifiedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Admin actions on resident household-link requests. Each action locks the rows it touches,
 * writes only existing columns, and leaves an audit line in the application log
 * (request id + staff user id; no personal data).
 */
final class HouseholdLinkStaffActions
{
    public const OK = 'ok';

    public const NOT_FOUND = 'not_found';

    public const NOT_ALLOWED = 'not_allowed';

    public const RESIDENT_TAKEN = 'resident_taken';

    /**
     * Staff verified the requester in person (or by the barangay's official procedure) and
     * confirmed the request's mobile number belongs to that resident. Moves the request to
     * Awaiting OTP; the requester can then receive an SMS OTP at that number only.
     */
    public function verify(int $requestId, User $staff): string
    {
        return DB::transaction(function () use ($requestId, $staff): string {
            $record = RecordRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $record instanceof RecordRequest) {
                return self::NOT_FOUND;
            }

            if (! HouseholdLinkingPolicy::canStaffVerify($record)) {
                return self::NOT_ALLOWED;
            }

            $takenByOther = ResidentAccount::query()
                ->where('resident_id', $record->matched_resident_id)
                ->where('account_id', '!=', $record->account_id)
                ->exists();
            if ($takenByOther) {
                return self::RESIDENT_TAKEN;
            }

            // Any OTP issued before staff verification (legacy rows) is void.
            $this->invalidateOpenOtps($requestId);

            $record->status = RecordRequest::STATUS_AWAITING_OTP;
            $record->decision_reason = HouseholdLinkingPolicy::REASON_STAFF_VERIFIED;
            $record->evaluated_at = now();
            $record->approved_at = null;
            $record->save();

            $this->audit('household.link.staff_verified', $requestId, $staff);

            return self::OK;
        });
    }

    public function decline(int $requestId, User $staff): string
    {
        return DB::transaction(function () use ($requestId, $staff): string {
            $record = RecordRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $record instanceof RecordRequest) {
                return self::NOT_FOUND;
            }

            if (! HouseholdLinkingPolicy::canStaffDecline($record)) {
                return self::NOT_ALLOWED;
            }

            $this->invalidateOpenOtps($requestId);

            $record->status = RecordRequest::STATUS_DENIED;
            $record->decision_reason = HouseholdLinkingPolicy::REASON_STAFF_DECLINED;
            $record->evaluated_at = now();
            $record->approved_at = null;
            $record->save();

            $this->audit('household.link.staff_declined', $requestId, $staff);

            return self::OK;
        });
    }

    /**
     * Remove an existing household link (e.g. a link created under the old automatic flow
     * that staff judge illegitimate). Clears resident_accounts.resident_id and marks the
     * request Denied; access ends immediately. Nothing is deleted.
     */
    public function revoke(int $requestId, User $staff): string
    {
        return DB::transaction(function () use ($requestId, $staff): string {
            $record = RecordRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $record instanceof RecordRequest) {
                return self::NOT_FOUND;
            }

            $account = ResidentAccount::query()->whereKey($record->account_id)->lockForUpdate()->first();
            if (
                ! $account instanceof ResidentAccount
                || ! HouseholdRecordVerifiedAccess::isVerifiedApproval($record)
                || $account->resident_id === null
                || (string) $account->resident_id !== (string) $record->matched_resident_id
            ) {
                return self::NOT_ALLOWED;
            }

            $account->resident_id = null;
            $account->save();

            $record->status = RecordRequest::STATUS_DENIED;
            $record->decision_reason = HouseholdLinkingPolicy::REASON_LINK_REVOKED;
            $record->evaluated_at = now();
            $record->save();

            $this->audit('household.link.revoked', $requestId, $staff);

            return self::OK;
        });
    }

    private function invalidateOpenOtps(int $requestId): void
    {
        if (! Schema::hasTable('record_request_otps')) {
            return;
        }

        DB::table('record_request_otps')
            ->where('request_id', $requestId)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now(), 'updated_at' => now()]);
    }

    private function audit(string $event, int $requestId, User $staff): void
    {
        Log::info($event, [
            'request_id' => $requestId,
            'staff_user_id' => $staff->getKey(),
        ]);
    }
}
