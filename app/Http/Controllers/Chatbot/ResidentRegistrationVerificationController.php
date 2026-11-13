<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Services\ResidentEmailOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Step 2 of resident registration: enter the 6-digit code emailed to the address (SR-03).
 * Messages are identical whether or not the address already has an account.
 */
class ResidentRegistrationVerificationController extends Controller
{
    private const RESTART_MESSAGE = 'Your registration session has expired. Please register again.';

    public function __construct(
        private readonly ResidentEmailOtpService $emailOtp,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $pending = $this->emailOtp->pending($this->token($request));
        if ($pending === null) {
            return redirect()->route('chatbot.register')->withErrors(['email' => self::RESTART_MESSAGE]);
        }

        return view('pages.chatbot.register-verify', [
            'maskedEmail' => ResidentEmailOtpService::maskEmail((string) $pending['email']),
            'otpSeconds' => $this->emailOtp->secondsRemaining($pending),
            'otpVerifyError' => (string) session('email_otp_error', ''),
            'otpStatus' => (string) session('email_otp_status', ''),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $code = preg_replace('/\D+/', '', (string) $request->input('otp', '')) ?? '';
        $outcome = $this->emailOtp->verifyRegistration($this->token($request), $code);

        if ($outcome === ResidentEmailOtpService::VERIFIED) {
            $request->session()->forget(ResidentEmailOtpService::SESSION_PENDING_TOKEN);

            return redirect()
                ->route('chatbot.login')
                ->with('success', 'Email verified. Account created successfully. You can now log in.');
        }

        if ($outcome === ResidentEmailOtpService::MISSING) {
            return redirect()->route('chatbot.register')->withErrors(['email' => self::RESTART_MESSAGE]);
        }

        return redirect()->route('chatbot.register.verify')->with('email_otp_error', $outcome === ResidentEmailOtpService::EXHAUSTED
            ? 'Too many incorrect attempts. Please request a new code.'
            : 'The code is invalid or has expired.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $token = $this->token($request);
        $outcome = $this->emailOtp->resendRegistration($token);

        if ($outcome === ResidentEmailOtpService::MISSING) {
            return redirect()->route('chatbot.register')->withErrors(['email' => self::RESTART_MESSAGE]);
        }

        if ($outcome === ResidentEmailOtpService::SENT) {
            return redirect()->route('chatbot.register.verify')->with('email_otp_status', 'A new code has been sent.');
        }

        return redirect()->route('chatbot.register.verify')->with('email_otp_error', match ($outcome) {
            ResidentEmailOtpService::COOLDOWN => 'Please wait '.$this->emailOtp->cooldownRemaining($this->emailOtp->pending($token)).' seconds before requesting a new code.',
            ResidentEmailOtpService::RESEND_LIMIT, ResidentEmailOtpService::ADDRESS_LIMIT => 'You have reached the maximum number of code requests. Please try again later.',
            default => 'We could not send the verification email. Please try again.',
        });
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->emailOtp->forgetPending($this->token($request));
        $request->session()->forget(ResidentEmailOtpService::SESSION_PENDING_TOKEN);

        return redirect()->route('chatbot.register');
    }

    private function token(Request $request): ?string
    {
        $token = $request->session()->get(ResidentEmailOtpService::SESSION_PENDING_TOKEN);

        return is_string($token) ? $token : null;
    }
}
