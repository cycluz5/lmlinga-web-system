<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\LoginAttemptLockout;
use App\Support\ResidentAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Resident chatbot authentication against resident_accounts.
 * Lockout is independent from staff login.
 */
class ResidentLoginController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $identity = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $namespace = LoginAttemptLockout::NAMESPACE_RESIDENT;

        if (LoginAttemptLockout::isLocked($namespace, $identity)) {
            return redirect()
                ->route('chatbot.login')
                ->withErrors([
                    'email' => LoginAttemptLockout::MESSAGE,
                ]);
        }

        $result = ResidentAuthenticator::attempt($identity, $password);

        if ($result['via'] === null) {
            LoginAttemptLockout::recordFailure($namespace, $identity);

            $message = LoginAttemptLockout::isLocked($namespace, $identity)
                ? LoginAttemptLockout::MESSAGE
                : LoginAttemptLockout::GENERIC_FAILURE;

            return redirect()
                ->route('chatbot.login')
                ->withErrors([
                    'email' => $message,
                ]);
        }

        LoginAttemptLockout::reset($namespace, $identity);
        $request->session()->regenerate();

        return redirect()->route('chatbot.main');
    }

    /**
     * Clear resident chatbot session keys only; leave the Laravel session
     * and any staff/admin auth state intact for shared-browser use.
     */
    public function destroy(Request $request): RedirectResponse
    {
        ResidentAuthenticator::clearSession();
        $request->session()->regenerateToken();

        return redirect()->route('chatbot.landing');
    }
}
