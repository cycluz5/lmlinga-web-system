<?php

namespace App\Services;

use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\ResidentAccount;
use App\Support\HouseholdRecordVerifiedAccess;
use Illuminate\Support\Facades\Hash;

/**
 * Issues a hashed 6-digit OTP for an owned request that still requires OTP verification.
 * Does not send SMS/email, verify codes, approve, or link resident_id.
 */
final class HouseholdRecordRequestOtpIssuer
{
    public const EXPIRY_MINUTES = 5;

    public const DEST_MOBILE = 'mobile';

    public const DEST_EMAIL = 'email';

    public function issueForOwnedAwaitingRequest(
        ResidentAccount $account,
        RecordRequest $record,
        string $destinationKind = self::DEST_MOBILE,
        ?string $destinationValue = null,
    ): ?IssuedHouseholdRecordOtp {
        if ((int) $record->account_id !== (int) $account->account_id) {
            return null;
        }

        if (! HouseholdRecordVerifiedAccess::allowsOtpInterface($record)) {
            return null;
        }

        RecordRequest::query()
            ->whereKey($record->request_id)
            ->lockForUpdate()
            ->first();

        $active = $this->activeOtpForRequest((int) $record->request_id);

        if ($active instanceof RecordRequestOtp) {
            return new IssuedHouseholdRecordOtp($active, null, true);
        }

        $plaintext = (string) random_int(100000, 999999);
        $destination = $destinationValue ?? (string) $record->mobile_number_submitted;

        $otp = new RecordRequestOtp;
        $otp->request_id = $record->request_id;
        $otp->code_hash = Hash::make($plaintext);
        $otp->destination_fingerprint = $this->fingerprintForDestination($destinationKind, $destination);
        $otp->expires_at = now()->copy()->addMinutes(self::EXPIRY_MINUTES);
        $otp->attempt_count = 0;
        $otp->resend_count = 0;
        $otp->last_sent_at = null;
        $otp->verified_at = null;
        $otp->invalidated_at = null;
        $otp->save();

        return new IssuedHouseholdRecordOtp($otp->fresh(), $plaintext, false);
    }

    /**
     * SHA-256 destination fingerprint. Never stores plaintext phone/email.
     */
    public function fingerprintForDestination(string $kind, string $value): string
    {
        if ($kind === self::DEST_EMAIL) {
            $normalized = mb_strtolower(trim($value), 'UTF-8');

            return hash('sha256', 'record-request-otp-dest|email|'.$normalized);
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return hash('sha256', 'record-request-otp-dest|'.$digits);
    }

    private function activeOtpForRequest(int $requestId): ?RecordRequestOtp
    {
        $row = RecordRequestOtp::query()
            ->where('request_id', $requestId)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->orderBy('otp_id')
            ->lockForUpdate()
            ->first();

        return $row instanceof RecordRequestOtp ? $row : null;
    }
}
