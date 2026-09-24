<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chatbot\StoreHouseholdRecordRequest;
use App\Http\Requests\Chatbot\VerifyHouseholdEmailOtpRequest;
use App\Http\Requests\Chatbot\VerifyHouseholdSmsOtpRequest;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\ResidentAccount;
use App\Services\HouseholdEmailOtpAttemptGuard;
use App\Services\HouseholdOtpAttemptGuard;
use App\Services\HouseholdOtpEmailDeliveryResult;
use App\Services\HouseholdOtpSmsDeliveryResult;
use App\Services\HouseholdOtpVerifyResult;
use App\Services\HouseholdRecordRequestEmailOtpDelivery;
use App\Services\HouseholdRecordRequestEmailOtpVerifier;
use App\Services\HouseholdRecordRequestOtpDelivery;
use App\Services\HouseholdRecordRequestOtpIssuer;
use App\Services\HouseholdRecordRequestSmsOtpVerifier;
use App\Support\HouseholdRecordRequestMatcher;
use App\Support\HouseholdRecordVerifiedAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HouseholdRecordRequestController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        if ($this->hasVerificationPendingRequest($account)) {
            return redirect()->route('chatbot.household.verification.sms');
        }

        if ($this->hasPendingRequest($account)) {
            return redirect()->route('chatbot.main');
        }

        return view('pages.chatbot.household-request');
    }

    public function store(StoreHouseholdRecordRequest $request): RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $record = DB::transaction(function () use ($request, $account) {
            ResidentAccount::query()
                ->whereKey($account->account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingPending = RecordRequest::query()
                ->where('account_id', $account->account_id)
                ->where('status', RecordRequest::STATUS_PENDING)
                ->orderBy('request_id')
                ->lockForUpdate()
                ->first();

            if ($existingPending !== null) {
                return null;
            }

            $validated = $request->validated();
            $zoneSubmitted = $this->zoneSubmitted($account);

            $record = new RecordRequest;
            $record->account_id = $account->account_id;
            $record->household_no_submitted = $validated['householdNo'];
            $record->zone_submitted = $zoneSubmitted;
            $record->relationship_submitted = $validated['relationship'];
            $record->first_name_submitted = $validated['firstName'];
            $record->middle_name_submitted = $validated['middleName'];
            $record->last_name_submitted = $validated['lastName'];
            $record->mobile_number_submitted = $validated['mobileNumber'];
            $record->email_submitted = $validated['emailAddress'];
            $record->submitter_ip = $request->ip();
            $record->matched_resident_id = null;
            $record->status = RecordRequest::STATUS_PENDING;
            $record->decision_reason = null;
            $record->evaluated_at = null;
            $record->approved_at = null;
            $record->save();

            app(HouseholdRecordRequestMatcher::class)->evaluate($account, $record);

            return $record->fresh();
        });

        if ($record === null) {
            return redirect()->route('chatbot.main');
        }

        if ($record->status === RecordRequest::STATUS_AWAITING_OTP) {
            $delivery = app(HouseholdRecordRequestOtpDelivery::class)
                ->deliverForAwaitingRequest($account, $record);

            return $this->redirectToSmsVerificationAfterDelivery($record, $delivery);
        }

        return redirect()->route('chatbot.main');
    }

    public function otpMethod(Request $request): View|RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $record = RecordRequest::latestForAccount($account->account_id);

        if (
            $record === null
            || (int) $record->account_id !== (int) $account->account_id
            || ! HouseholdRecordVerifiedAccess::allowsOtpInterface($record)
        ) {
            return redirect()->route('chatbot.main');
        }

        // Method choice removed from normal flow — continue on SMS UI without auto-send.
        return redirect()->route('chatbot.household.verification.sms');
    }

    public function status(Request $request): View|RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $record = $this->currentRequestFor($account);

        if ($record === null) {
            return redirect()->route('chatbot.household.verification');
        }

        abort_unless((int) $record->account_id === (int) $account->account_id, 404);

        return view('pages.chatbot.household-verification-status', [
            'recordRequest' => $record,
            'uiState' => $this->uiStateFor($record),
            'grantHouseholdAccess' => false,
        ]);
    }

    public function sms(Request $request): View|RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $record = RecordRequest::latestForAccount($account->account_id);

        if (
            $record === null
            || (int) $record->account_id !== (int) $account->account_id
            || ! $this->canOpenOtpInterface($record)
        ) {
            return redirect()->route('chatbot.main');
        }

        $guard = app(HouseholdOtpAttemptGuard::class);
        $lockSeconds = $guard->remainingLockSeconds(
            (int) $account->account_id,
            (int) $record->request_id,
            HouseholdOtpAttemptGuard::CHANNEL_SMS,
        );
        $verifyError = trim((string) session('household_otp_verify_error', ''));

        if ($lockSeconds > 0 && $verifyError === '') {
            $verifyError = HouseholdOtpVerifyResult::locked($lockSeconds)->message;
        }

        $otpSmsSent = (bool) session('household_otp_sms_sent');
        $otpSeconds = $this->activeSmsOtpSecondsRemaining($account, $record);
        if (! $otpSmsSent && $otpSeconds <= 0) {
            // First visit without a sent SMS OTP — enable Resend to trigger POST send.
            $otpSeconds = 0;
        }

        return view('pages.chatbot.household-sms-verification', [
            'maskedMobile' => $this->maskedMobile((string) $record->mobile_number_submitted),
            'otpSmsSent' => $otpSmsSent,
            'otpSmsError' => trim((string) session('household_otp_sms_error', '')),
            'otpVerifyError' => $verifyError,
            'otpSeconds' => $otpSeconds > 0 ? $otpSeconds : ($otpSmsSent ? 0 : 0),
            'otpLockSeconds' => $lockSeconds,
            'smsPaused' => false,
        ]);
    }

    public function sendSmsOtp(Request $request): RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $record = RecordRequest::latestForAccount($account->account_id);

        if (
            $record === null
            || (int) $record->account_id !== (int) $account->account_id
            || ! HouseholdRecordVerifiedAccess::allowsOtpInterface($record)
        ) {
            return redirect()->route('chatbot.main');
        }

        $delivery = app(HouseholdRecordRequestOtpDelivery::class)
            ->deliverForAwaitingRequest($account, $record);

        return $this->redirectToSmsVerificationAfterDelivery($record, $delivery);
    }

    private function redirectToSmsVerificationAfterDelivery(
        RecordRequest $record,
        HouseholdOtpSmsDeliveryResult $delivery,
    ): RedirectResponse {
        $error = '';
        if (! $delivery->sent) {
            $error = HouseholdOtpSmsDeliveryResult::SAFE_FAILURE_MESSAGE;
        } elseif ($delivery->alreadySent) {
            $error = '';
        }

        

        return redirect()
            ->route('chatbot.household.verification.sms')
            ->with('household_otp_sms_sent', $delivery->sent)
            ->with('household_otp_sms_error', $error);
           
    }

    public function verifySmsOtp(VerifyHouseholdSmsOtpRequest $request): RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $result = app(HouseholdRecordRequestSmsOtpVerifier::class)
            ->verifyForAuthenticatedAccount($account, (string) $request->validated('otp'));

        if (! $result->ok) {
            if (in_array($result->reason, ['invalid_status', 'account_missing'], true)) {
                return redirect()->route('chatbot.main');
            }

            return redirect()
                ->route('chatbot.household.verification.sms')
                ->with(
                    'household_otp_verify_error',
                    $result->message !== ''
                        ? $result->message
                        : HouseholdOtpVerifyResult::SAFE_INVALID_MESSAGE,
                );
        }

        return redirect()->route('chatbot.household.information');
    }

    public function email(Request $request): View|RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $record = RecordRequest::latestForAccount($account->account_id);

        if (
            $record === null
            || (int) $record->account_id !== (int) $account->account_id
            || ! $this->canOpenOtpInterface($record)
        ) {
            return redirect()->route('chatbot.main');
        }

        $guard = app(HouseholdEmailOtpAttemptGuard::class);
        $lockSeconds = $guard->remainingLockSeconds((int) $account->account_id, (int) $record->request_id);
        $verifyError = trim((string) session('household_otp_verify_error', ''));

        if ($lockSeconds > 0 && $verifyError === '') {
            $verifyError = HouseholdOtpVerifyResult::locked($lockSeconds)->message;
        }

        return view('pages.chatbot.household-email-verification', [
            'maskedEmail' => $this->maskedEmail((string) $account->email),
            'otpEmailSent' => (bool) session('household_otp_email_sent'),
            'otpEmailError' => trim((string) session('household_otp_email_error', '')),
            'otpVerifyError' => $verifyError,
            'otpSeconds' => $this->activeEmailOtpSecondsRemaining($account, $record),
            'otpLockSeconds' => $lockSeconds,
        ]);
    }

    public function sendEmailOtp(Request $request): RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $record = RecordRequest::latestForAccount($account->account_id);

        if (
            $record === null
            || (int) $record->account_id !== (int) $account->account_id
            || ! HouseholdRecordVerifiedAccess::allowsOtpInterface($record)
        ) {
            return redirect()->route('chatbot.main');
        }

        $delivery = app(HouseholdRecordRequestEmailOtpDelivery::class)
            ->deliverForAwaitingRequest($account, $record);

        return redirect()
            ->route('chatbot.household.verification.email')
            ->with('household_otp_email_sent', $delivery->sent)
            ->with(
                'household_otp_email_error',
                $delivery->sent ? '' : HouseholdOtpEmailDeliveryResult::SAFE_FAILURE_MESSAGE,
            );
    }

    public function verifyEmailOtp(VerifyHouseholdEmailOtpRequest $request): RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $result = app(HouseholdRecordRequestEmailOtpVerifier::class)
            ->verifyForAuthenticatedAccount($account, (string) $request->validated('otp'));

        if (! $result->ok) {
            if (in_array($result->reason, ['invalid_status', 'account_missing'], true)) {
                return redirect()->route('chatbot.main');
            }

            return redirect()
                ->route('chatbot.household.verification.email')
                ->with(
                    'household_otp_verify_error',
                    $result->message !== ''
                        ? $result->message
                        : HouseholdOtpVerifyResult::SAFE_INVALID_MESSAGE,
                );
        }

        return redirect()->route('chatbot.household.information');
    }

    private function currentRequestFor(ResidentAccount $account): ?RecordRequest
    {
        $pending = RecordRequest::query()
            ->where('account_id', $account->account_id)
            ->where('status', RecordRequest::STATUS_PENDING)
            ->orderByDesc('request_id')
            ->first();

        if ($pending !== null) {
            return $pending;
        }

        return RecordRequest::query()
            ->where('account_id', $account->account_id)
            ->orderByDesc('request_id')
            ->first();
    }

    private function uiStateFor(RecordRequest $record): string
    {
        return match ($record->status) {
            RecordRequest::STATUS_PENDING => 'verifying',
            RecordRequest::STATUS_AWAITING_OTP => 'verifying',
            RecordRequest::STATUS_NO_MATCH, RecordRequest::STATUS_DENIED => 'rejected',
            RecordRequest::STATUS_APPROVED => 'approved',
            default => 'verifying',
        };
    }

    private function zoneSubmitted(ResidentAccount $account): string
    {
        $zone = trim((string) ($account->zone_purok ?? ''));

        abort_if(
            $zone === '',
            422,
            'Resident account is missing zone_purok; zone_submitted cannot be derived.'
        );

        return $zone;
    }

    private function hasPendingRequest(ResidentAccount $account): bool
    {
        return RecordRequest::query()
            ->where('account_id', $account->account_id)
            ->where('status', RecordRequest::STATUS_PENDING)
            ->exists();
    }

    private function hasVerificationPendingRequest(ResidentAccount $account): bool
    {
        $record = RecordRequest::latestForAccount($account->account_id);

        return $record instanceof RecordRequest
            && (int) $record->account_id === (int) $account->account_id
            && HouseholdRecordVerifiedAccess::requiresOtpVerification($record);
    }

    private function canOpenOtpInterface(RecordRequest $record): bool
    {
        return HouseholdRecordVerifiedAccess::allowsOtpInterface($record);
    }

    private function maskedMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if (strlen($digits) < 5) {
            return '09******000';
        }

        return substr($digits, 0, 2).str_repeat('*', max(strlen($digits) - 5, 4)).substr($digits, -3);
    }

    private function maskedEmail(string $email): string
    {
        $email = trim($email);
        $at = strpos($email, '@');

        if ($at === false || $at < 1) {
            return '***@***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $visibleLen = min(2, strlen($local));
        $visible = substr($local, 0, $visibleLen);

        return $visible.str_repeat('*', 6).'@'.$domain;
    }

    private function activeSmsOtpSecondsRemaining(ResidentAccount $account, RecordRequest $record): int
    {
        $mobile = trim((string) $record->mobile_number_submitted);
        if ($mobile === '') {
            return 0;
        }

        $fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_MOBILE, $mobile);

        $otp = RecordRequestOtp::query()
            ->where('request_id', $record->request_id)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->where('destination_fingerprint', $fingerprint)
            ->where('expires_at', '>', now())
            ->orderByDesc('otp_id')
            ->first();

        return $this->clampedOtpSecondsRemaining($otp);
    }

    private function activeEmailOtpSecondsRemaining(ResidentAccount $account, RecordRequest $record): int
    {
        $email = trim((string) $account->email);
        if ($email === '') {
            return 0;
        }

        $fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_EMAIL, $email);

        $otp = RecordRequestOtp::query()
            ->where('request_id', $record->request_id)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->where('destination_fingerprint', $fingerprint)
            ->where('expires_at', '>', now())
            ->orderByDesc('otp_id')
            ->first();

        return $this->clampedOtpSecondsRemaining($otp);
    }

    /**
     * Remaining OTP lifetime in whole seconds, clamped to [0, EXPIRY_MINUTES * 60].
     * Uses Unix timestamps so timezone-sensitive Carbon diffs cannot inflate the UI.
     */
    private function clampedOtpSecondsRemaining(?RecordRequestOtp $otp): int
    {
        if (! $otp instanceof RecordRequestOtp || $otp->expires_at === null) {
            return 0;
        }

        $maxSeconds = HouseholdRecordRequestOtpIssuer::EXPIRY_MINUTES * 60;
        $remaining = $otp->expires_at->getTimestamp() - now()->getTimestamp();

        return max(0, min($maxSeconds, $remaining));
    }
}
