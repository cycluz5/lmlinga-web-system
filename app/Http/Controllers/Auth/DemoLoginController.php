<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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

    public function destroy(Request $request): RedirectResponse
    {
        StaffAuthenticator::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
