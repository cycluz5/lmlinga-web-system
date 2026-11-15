<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\StaffEmailVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Batch 1 — health-worker email verification step.
 *
 * Reached only after the worker entered the correct email + password for an account whose email is still
 * pending. No authenticated session exists here; the pending account is bound to this browser session for a
 * limited time. A correct 6-digit code activates the account; the worker then signs in normally.
 */
class StaffEmailVerificationController extends Controller
{
    public const VERIFIED_MESSAGE = 'Email verified. Your account is now active. Please log in.';

    public const RESTART_MESSAGE = 'Your verification session has ended. Please log in again to receive a new code.';

    public function __construct(
        private readonly StaffEmailVerificationService $verification,
    ) {}

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $user = $this->verification->sessionUser();
        if ($user === null) {
            return redirect()->route('login')->withErrors(['email' => self::RESTART_MESSAGE]);
        }

        return view('pages.auth.staff-verify-email', [
            'maskedEmail' => StaffEmailVerificationService::maskEmail((string) $user->email),
            'secondsRemaining' => $this->verification->secondsRemaining($user),
            'cooldownRemaining' => $this->verification->cooldownRemaining($user),
            'expiryMinutes' => StaffEmailVerificationService::EXPIRY_MINUTES,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->verification->sessionUser();
        if ($user === null) {
            return redirect()->route('login')->withErrors(['email' => self::RESTART_MESSAGE]);
        }

        $outcome = $this->verification->verify($user, (string) $request->input('otp', ''));

        if ($outcome === StaffEmailVerificationService::VERIFIED) {
            $this->verification->forgetSession();
            $request->session()->regenerate();

            return redirect()->route('login')->with('status', self::VERIFIED_MESSAGE);
        }

        if ($outcome === StaffEmailVerificationService::NOT_PENDING) {
            $this->verification->forgetSession();

            return redirect()->route('login')->withErrors(['email' => self::RESTART_MESSAGE]);
        }

        return redirect()
            ->route('staff.email-verification.show')
            ->withErrors(['otp' => $outcome === StaffEmailVerificationService::EXHAUSTED
                ? 'Too many incorrect attempts. Please request a new code.'
                : 'The code is invalid or has expired.']);
    }

    public function resend(): RedirectResponse
    {
        $user = $this->verification->sessionUser();
        if ($user === null) {
            return redirect()->route('login')->withErrors(['email' => self::RESTART_MESSAGE]);
        }

        $outcome = $this->verification->sendCode($user);

        return match ($outcome) {
            StaffEmailVerificationService::SENT => redirect()
                ->route('staff.email-verification.show')
                ->with('status', 'A new verification code has been sent.'),
            StaffEmailVerificationService::COOLDOWN => redirect()
                ->route('staff.email-verification.show')
                ->withErrors(['otp' => 'Please wait before requesting another code.']),
            StaffEmailVerificationService::SEND_LIMIT => redirect()
                ->route('staff.email-verification.show')
                ->withErrors(['otp' => 'Too many codes have been requested. Please try again later or contact your administrator.']),
            StaffEmailVerificationService::DELIVERY_FAILED => redirect()
                ->route('staff.email-verification.show')
                ->withErrors(['otp' => 'We could not send the verification email. Please try again or contact your administrator.']),
            default => redirect()->route('login')->withErrors(['email' => self::RESTART_MESSAGE]),
        };
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->verification->forgetSession();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
