<?php

namespace App\Services;

use App\Mail\HouseholdRecordVerificationOtpMail;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\ResidentAccount;
use App\Support\HouseholdRecordVerifiedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Issues a local hashed OTP and delivers plaintext once via Laravel Mail.
 * Does not verify OTP, approve requests, send SMS, or grant household access.
 */
final class HouseholdRecordRequestEmailOtpDelivery
{
    /** Anti-spam: ignore rapid repeat sends while an OTP was just emailed. */
    public const RESEND_COOLDOWN_SECONDS = 30;

    public function __construct(
        private readonly HouseholdRecordRequestOtpIssuer $issuer,
    ) {}

    public function deliverForAwaitingRequest(ResidentAccount $account, RecordRequest $record): HouseholdOtpEmailDeliveryResult
    {
        if ((int) $record->account_id !== (int) $account->account_id) {
            return HouseholdOtpEmailDeliveryResult::failed('unauthorized');
        }

        if (! HouseholdRecordVerifiedAccess::allowsOtpInterface($record)) {
            return HouseholdOtpEmailDeliveryResult::failed('invalid_status');
        }

        $email = trim((string) $account->email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return HouseholdOtpEmailDeliveryResult::failed('invalid_email');
        }

        $issued = $this->issuePlaintextForEmailSend($account, $record, $email);

        if ($issued instanceof HouseholdOtpEmailDeliveryResult) {
            return $issued;
        }

        if (! $issued instanceof IssuedHouseholdRecordOtp || $issued->plaintext === null) {
            return HouseholdOtpEmailDeliveryResult::failed('issue_failed');
        }

        try {
            Mail::to($email)->send(new HouseholdRecordVerificationOtpMail($issued->plaintext));
        } catch (Throwable) {
            $this->invalidateOtp($issued->otp);

            Log::warning('household.otp.email.failed', [
                'provider' => 'mail',
                'request_id' => (int) $record->request_id,
                'failure_category' => 'mail_exception',
            ]);

            return HouseholdOtpEmailDeliveryResult::failed('mail_exception');
        }

        $this->markSent($issued->otp);

        Log::info('household.otp.email.sent', [
            'provider' => 'mail',
            'request_id' => (int) $record->request_id,
        ]);

        return HouseholdOtpEmailDeliveryResult::sent();
    }

    /**
     * @return IssuedHouseholdRecordOtp|HouseholdOtpEmailDeliveryResult|null
     */
    private function issuePlaintextForEmailSend(
        ResidentAccount $account,
        RecordRequest $record,
        string $email,
    ): IssuedHouseholdRecordOtp|HouseholdOtpEmailDeliveryResult|null {
        $emailFingerprint = $this->issuer->fingerprintForDestination(
            HouseholdRecordRequestOtpIssuer::DEST_EMAIL,
            $email,
        );

        return DB::transaction(function () use ($account, $record, $email, $emailFingerprint) {
            $issued = $this->issuer->issueForOwnedAwaitingRequest(
                $account,
                $record->fresh(),
                HouseholdRecordRequestOtpIssuer::DEST_EMAIL,
                $email,
            );

            if ($issued === null) {
                return null;
            }

            if ($issued->reused) {
                $fingerprintMatches = hash_equals(
                    (string) $issued->otp->destination_fingerprint,
                    $emailFingerprint,
                );

                $lastSent = $issued->otp->last_sent_at;
                $withinCooldown = $fingerprintMatches
                    && $lastSent !== null
                    && $lastSent->greaterThan(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS));

                if ($withinCooldown) {
                    return HouseholdOtpEmailDeliveryResult::alreadySent();
                }

                $this->invalidateOtp($issued->otp);
                $issued = $this->issuer->issueForOwnedAwaitingRequest(
                    $account,
                    $record->fresh(),
                    HouseholdRecordRequestOtpIssuer::DEST_EMAIL,
                    $email,
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
