<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StaffEmailVerificationService;
use App\Support\LoginAttemptLockout;
use App\Support\StaffAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff login. Prefers database users; falls back to demo/config accounts.
 * Frozen login UI (email + password) is unchanged.
 */
class DemoLoginController extends Controller
{
    public function show(): View
    {
        return view('pages.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $identity = trim((string) $request->input('email', $request->input('full_name', '')));
        $password = (string) $request->input('password', '');
        $namespace = LoginAttemptLockout::NAMESPACE_WORKER;

        if (LoginAttemptLockout::isLocked($namespace, $identity)) {
            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => LoginAttemptLockout::MESSAGE,
                ]);
        }

        $result = StaffAuthenticator::attempt($identity, $password);

        // Batch 1: correct password, email not yet verified. No login session; start email verification.
        if ($result['pending_verification'] instanceof User) {
            return $this->startEmailVerification($request, $result['pending_verification'], $namespace, $identity);
        }

        if ($result['via'] === null) {
            LoginAttemptLockout::recordFailure($namespace, $identity);

            $message = LoginAttemptLockout::isLocked($namespace, $identity)
                ? LoginAttemptLockout::MESSAGE
                : LoginAttemptLockout::GENERIC_FAILURE;

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => $message,
                ]);
        }

        LoginAttemptLockout::reset($namespace, $identity);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function startEmailVerification(Request $request, User $user, string $namespace, string $identity): RedirectResponse
    {
        $verification = app(StaffEmailVerificationService::class);

        LoginAttemptLockout::reset($namespace, $identity);
        $request->session()->regenerate();
        $verification->bindSession($user);

        $outcome = $verification->sendCode($user);

        $redirect = redirect()->route('staff.email-verification.show');

        return match ($outcome) {
            StaffEmailVerificationService::SENT => $redirect->with(
                'status',
                'Your email address must be verified before your account can be used. We sent a 6-digit code to your email.'
            ),
            // A live code from a recent attempt is still valid; do not resend yet.
            StaffEmailVerificationService::COOLDOWN => $redirect->with(
                'status',
                'A verification code was sent recently. Enter it below or request a new code shortly.'
            ),
            StaffEmailVerificationService::SEND_LIMIT => $redirect->withErrors([
                'otp' => 'Too many codes have been requested. Please try again later or contact your administrator.',
            ]),
            default => $redirect->withErrors([
                'otp' => 'We could not send the verification email. Please try again or contact your administrator.',
            ]),
        };
    }

    public function destroy(Request $request): RedirectResponse
    {
        StaffAuthenticator::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
