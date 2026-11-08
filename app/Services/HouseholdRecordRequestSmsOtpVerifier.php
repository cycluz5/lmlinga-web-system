<?php

namespace App\Services;

use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\ResidentAccount;
use App\Support\HouseholdRecordVerifiedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies SMS OTP for an owned household-record request.
 * Same approval/linking outcome as Email OTP; separate destination fingerprint + lock channel.
 */
final class HouseholdRecordRequestSmsOtpVerifier
{
    public function __construct(
        private readonly HouseholdRecordRequestOtpIssuer $issuer,
        private readonly HouseholdOtpAttemptGuard $attemptGuard,
    ) {}

    public function verifyForAuthenticatedAccount(ResidentAccount $account, string $otpCode): HouseholdOtpVerifyResult
    {
        $digits = preg_replace('/\D+/', '', $otpCode) ?? '';

        if (! preg_match('/^\d{6}$/', $digits)) {
            return HouseholdOtpVerifyResult::invalid();
        }

        try {
            return DB::transaction(function () use ($account, $digits) {
                $accountLocked = ResidentAccount::query()
                    ->whereKey($account->account_id)
                    ->lockForUpdate()
                    ->first();

                if (! $accountLocked instanceof ResidentAccount) {
                    return HouseholdOtpVerifyResult::failed('account_missing');
                }

                $record = RecordRequest::query()
                    ->where('account_id', $accountLocked->account_id)
                    ->orderByDesc('request_id')
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $record instanceof RecordRequest
                    || (int) $record->account_id !== (int) $accountLocked->account_id
                    || ! HouseholdRecordVerifiedAccess::allowsOtpInterface($record)
                ) {
                    return HouseholdOtpVerifyResult::failed('invalid_status');
                }

                $accountId = (int) $accountLocked->account_id;
                $requestId = (int) $record->request_id;

                if ($this->attemptGuard->isLocked($accountId, $requestId, HouseholdOtpAttemptGuard::CHANNEL_SMS)) {
                    return HouseholdOtpVerifyResult::locked(
                        $this->attemptGuard->remainingLockSeconds(
                            $accountId,
                            $requestId,
                            HouseholdOtpAttemptGuard::CHANNEL_SMS,
                        )
                    );
                }

                if ($record->matched_resident_id === null || $record->matched_resident_id === '') {
                    return HouseholdOtpVerifyResult::failed('missing_match');
                }

                $mobile = trim((string) $record->mobile_number_submitted);
                $digitsOnly = preg_replace('/\D+/', '', $mobile) ?? '';
                if ($mobile === '' || ! preg_match('/^09\d{9}$/', $digitsOnly)) {
                    return HouseholdOtpVerifyResult::failed('invalid_mobile');
                }

                $mobileFingerprint = $this->issuer->fingerprintForDestination(
                    HouseholdRecordRequestOtpIssuer::DEST_MOBILE,
                    $digitsOnly,
                );

                $otp = RecordRequestOtp::query()
                    ->where('request_id', $record->request_id)
                    ->whereNull('verified_at')
                    ->whereNull('invalidated_at')
                    ->where('destination_fingerprint', $mobileFingerprint)
                    ->orderByDesc('otp_id')
                    ->lockForUpdate()
                    ->first();

                if (! $otp instanceof RecordRequestOtp) {
                    return $this->failureResult($accountId, $requestId, $otp);
                }

                if ($otp->expires_at === null || $otp->expires_at->isPast()) {
                    return $this->failureResult($accountId, $requestId, $otp);
                }

                if (! Hash::check($digits, (string) $otp->code_hash)) {
                    return $this->failureResult($accountId, $requestId, $otp);
                }

                if (! $this->matchedResidentExists($record->matched_resident_id)) {
                    return HouseholdOtpVerifyResult::failed('resident_missing');
                }

                $linked = $accountLocked->resident_id;
                if ($linked !== null && $linked !== '' && (string) $linked !== (string) $record->matched_resident_id) {
                    return HouseholdOtpVerifyResult::failed('link_conflict');
                }

                if ($this->residentLinkedToAnotherAccount($record->matched_resident_id, (int) $accountLocked->account_id)) {
                    return HouseholdOtpVerifyResult::failed('resident_taken');
                }

                $otp->verified_at = now();
                $otp->attempt_count = ((int) $otp->attempt_count) + 1;
                $otp->save();

                if ($record->status === RecordRequest::STATUS_AWAITING_OTP) {
                    $record->status = RecordRequest::STATUS_APPROVED;
                    $record->approved_at = now();
                    $record->save();
                } elseif ($record->status === RecordRequest::STATUS_APPROVED) {
                    if ($record->approved_at === null) {
                        $record->approved_at = now();
                        $record->save();
                    }
                } else {
                    return HouseholdOtpVerifyResult::failed('invalid_status');
                }

                if ($linked === null || $linked === '') {
                    $accountLocked->resident_id = $record->matched_resident_id;
                    $accountLocked->save();
                }

                if (! HouseholdRecordVerifiedAccess::isVerifiedApproval($record->fresh())) {
                    throw new \RuntimeException('household.otp.verify.inconsistent_state');
                }

                $this->attemptGuard->clear($accountId, $requestId, HouseholdOtpAttemptGuard::CHANNEL_SMS);

                return HouseholdOtpVerifyResult::success();
            });
        } catch (\Throwable) {
            return HouseholdOtpVerifyResult::failed('transaction');
        }
    }

    private function failureResult(int $accountId, int $requestId, ?RecordRequestOtp $otp): HouseholdOtpVerifyResult
    {
        if ($otp instanceof RecordRequestOtp) {
            $otp->attempt_count = ((int) $otp->attempt_count) + 1;
            $otp->save();
        }

        $outcome = $this->attemptGuard->recordFailure(
            $accountId,
            $requestId,
            HouseholdOtpAttemptGuard::CHANNEL_SMS,
        );

        if ($outcome['locked']) {
            return HouseholdOtpVerifyResult::locked($outcome['lock_seconds']);
        }

        return HouseholdOtpVerifyResult::invalidWithAttemptsRemaining($outcome['remaining_attempts']);
    }

    private function matchedResidentExists(mixed $residentId): bool
    {
        $residentKey = $this->tableIdentityColumn('residents', 'resident_id', 'id');

        if ($residentKey === null) {
            return false;
        }

        $query = DB::table('residents')->where($residentKey, $residentId);

        if (Schema::hasColumn('residents', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->exists();
    }

    private function residentLinkedToAnotherAccount(mixed $residentId, int $accountId): bool
    {
        return ResidentAccount::query()
            ->where('resident_id', $residentId)
            ->where('account_id', '!=', $accountId)
            ->exists();
    }

    private function tableIdentityColumn(string $table, string $livePrimaryKey, string $sqlitePrimaryKey): ?string
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
