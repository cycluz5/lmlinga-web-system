<?php

namespace App\Services;

use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\ResidentAccount;
use App\Support\HouseholdRecordVerifiedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issues a local hashed OTP and delivers plaintext once via Semaphore.
 * Does not verify OTP, approve requests, or grant household access.
 */
final class HouseholdRecordRequestOtpDelivery
{
    /** Anti-spam: ignore rapid repeat sends while an OTP was just texted. */
    public const RESEND_COOLDOWN_SECONDS = 30;

    public function __construct(
        private readonly HouseholdRecordRequestOtpIssuer $issuer,
        private readonly SemaphoreSmsService $sms,
    ) {}

    public function deliverForAwaitingRequest(ResidentAccount $account, RecordRequest $record): HouseholdOtpSmsDeliveryResult
    {
        if ((int) $record->account_id !== (int) $account->account_id) {
            return HouseholdOtpSmsDeliveryResult::failed('unauthorized');
        }

        if (! HouseholdRecordVerifiedAccess::allowsOtpInterface($record)) {
            return HouseholdOtpSmsDeliveryResult::failed('invalid_status');
        }

        $phoneNumber = trim((string) $record->mobile_number_submitted);
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        if ($digits === '' || ! preg_match('/^09\d{9}$/', $digits)) {
            return HouseholdOtpSmsDeliveryResult::failed('invalid_mobile');
        }

        $issued = $this->issuePlaintextForSend($account, $record, $digits);

        if ($issued instanceof HouseholdOtpSmsDeliveryResult) {
            return $issued;
        }

        if (! $issued instanceof IssuedHouseholdRecordOtp || $issued->plaintext === null) {
            return HouseholdOtpSmsDeliveryResult::failed('issue_failed');
        }

        $message = sprintf(
            "LMLINGA verification code: {otp}.\nThis code expires in %d minutes.\nDo not share this code.",
            HouseholdRecordRequestOtpIssuer::EXPIRY_MINUTES,
        );

        $smsResult = $this->sms->sendSms(
            $digits,
            $message,
            $issued->plaintext,
            (int) $record->request_id,
        );

        if ($smsResult->queued) {
            $this->markSent($issued->otp);

            Log::info('household.otp.sms.sent', [
                'provider' => 'semaphore',
                'request_id' => (int) $record->request_id,
                'message_id' => $smsResult->messageId,
                'http_status' => $smsResult->httpStatus,
            ]);

            return HouseholdOtpSmsDeliveryResult::sent($smsResult->messageId);
        }

        $this->invalidateOtp($issued->otp);

        Log::warning('household.otp.sms.failed', [
            'provider' => 'semaphore',
            'request_id' => (int) $record->request_id,
            'failure_category' => $smsResult->failureCategory,
            'http_status' => $smsResult->httpStatus,
        ]);

        return HouseholdOtpSmsDeliveryResult::failed($smsResult->failureCategory);
    }

    /**
     * @return IssuedHouseholdRecordOtp|HouseholdOtpSmsDeliveryResult|null
     */
    private function issuePlaintextForSend(
        ResidentAccount $account,
        RecordRequest $record,
        string $mobileDigits,
    ): IssuedHouseholdRecordOtp|HouseholdOtpSmsDeliveryResult|null {
        $mobileFingerprint = $this->issuer->fingerprintForDestination(
            HouseholdRecordRequestOtpIssuer::DEST_MOBILE,
            $mobileDigits,
        );

        return DB::transaction(function () use ($account, $record, $mobileDigits, $mobileFingerprint) {
            $issued = $this->issuer->issueForOwnedAwaitingRequest(
                $account,
                $record->fresh(),
                HouseholdRecordRequestOtpIssuer::DEST_MOBILE,
                $mobileDigits,
            );

            if ($issued === null) {
                return null;
            }

            if ($issued->reused) {
                $fingerprintMatches = hash_equals(
                    (string) $issued->otp->destination_fingerprint,
                    $mobileFingerprint,
                );

                $lastSent = $issued->otp->last_sent_at;
                $withinCooldown = $fingerprintMatches
                    && $lastSent !== null
                    && $lastSent->greaterThan(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS));

                if ($withinCooldown) {
                    return HouseholdOtpSmsDeliveryResult::alreadySent();
                }

                $this->invalidateOtp($issued->otp);
                $issued = $this->issuer->issueForOwnedAwaitingRequest(
                    $account,
                    $record->fresh(),
                    HouseholdRecordRequestOtpIssuer::DEST_MOBILE,
                    $mobileDigits,
                );
            }

            if ($issued === null || $issued->reused || $issued->plaintext === null) {
                return null;
            }

            return $issued;
        });
    }

    private function markSent(RecordRequestOtp $otp): void
    {
        $row = RecordRequestOtp::query()->whereKey($otp->otp_id)->first();

        if (! $row instanceof RecordRequestOtp) {
            return;
        }

        $row->last_sent_at = now();
        $row->save();
    }

    private function invalidateOtp(RecordRequestOtp $otp): void
    {
        $row = RecordRequestOtp::query()->whereKey($otp->otp_id)->first();

        if (! $row instanceof RecordRequestOtp) {
            return;
        }

        $row->invalidated_at = now();
        $row->save();
    }
}
