<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\ResidentAccount;
use App\Services\ResidentEmailOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One-time email verification for resident accounts created before SR-03 (backward-compatible).
 * The code goes only to the email already stored on the account. Sets email_verified_at only.
 */
class ResidentEmailVerificationController extends Controller
{
    /** Set by EnsureResidentEmailVerified so the resident returns to the request form afterwards. */
    public const SESSION_RETURN_TO_REQUEST = 'lml.email_verify_return_to_request';

    public function __construct(
        private readonly ResidentEmailOtpService $emailOtp,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $account = $this->account($request);
        if ($account->hasVerifiedEmail() || ! ResidentAccount::supportsEmailVerification()) {
            return redirect()->route('chatbot.main');
        }

        $state = $this->emailOtp->accountState($account);
        $otpSeconds = $this->emailOtp->secondsRemaining($state);

        return view('pages.chatbot.email-verify', [
            'maskedEmail' => ResidentEmailOtpService::maskEmail((string) $account->email),
            'codeSent' => $state !== null && $otpSeconds > 0,
            'otpSeconds' => $otpSeconds,
            'otpVerifyError' => (string) session('email_otp_error', ''),
            'otpStatus' => (string) session('email_otp_status', ''),
            'gateNotice' => (string) session('email_verification_required', ''),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $account = $this->account($request);
        $outcome = $this->emailOtp->sendForAccount($account);

        if ($outcome === ResidentEmailOtpService::ALREADY_VERIFIED) {
            return redirect()->route('chatbot.main');
        }

        if ($outcome === ResidentEmailOtpService::SENT) {
            return redirect()->route('chatbot.email.verify')->with('email_otp_status', 'A verification code has been sent to your email.');
        }

        return redirect()->route('chatbot.email.verify')->with('email_otp_error', match ($outcome) {
            ResidentEmailOtpService::COOLDOWN => 'Please wait '.$this->emailOtp->cooldownRemaining($this->emailOtp->accountState($account)).' seconds before requesting a new code.',
            ResidentEmailOtpService::RESEND_LIMIT, ResidentEmailOtpService::ADDRESS_LIMIT => 'You have reached the maximum number of code requests. Please try again later.',
            default => 'We could not send the verification email. Please try again.',
        });
    }

    public function verify(Request $request): RedirectResponse
    {
        $account = $this->account($request);
        $code = preg_replace('/\D+/', '', (string) $request->input('otp', '')) ?? '';
        $outcome = $this->emailOtp->verifyForAccount($account, $code);

        if ($outcome === ResidentEmailOtpService::VERIFIED || $outcome === ResidentEmailOtpService::ALREADY_VERIFIED) {
            $returnToRequest = (bool) $request->session()->pull(self::SESSION_RETURN_TO_REQUEST, false);

            return redirect()
                ->route($returnToRequest ? 'chatbot.household.verification' : 'chatbot.main')
                ->with('status', 'Your email address has been verified.');
        }

        return redirect()->route('chatbot.email.verify')->with('email_otp_error', match ($outcome) {
            ResidentEmailOtpService::EXHAUSTED => 'Too many incorrect attempts. Please request a new code.',
            ResidentEmailOtpService::MISSING => 'Please request a verification code first.',
            default => 'The code is invalid or has expired.',
        });
    }

    private function account(Request $request): ResidentAccount
    {
        // Resolved by the resident.chatbot middleware (session-owned account only).
        return $request->attributes->get('residentAccount');
    }
}
