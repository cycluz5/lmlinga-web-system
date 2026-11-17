<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\StaffEmailVerificationService;
use App\Support\Offline\OfflineSyncException;
use App\Support\StaffAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safety net for admin-issued temporary passwords.
 *
 * Password changes stay voluntary for every staff account EXCEPT one that still holds an admin-issued temporary
 * password (temp_password_issued_at set: new account or admin reset). Those accounts must replace it through the
 * first-login code + new-password screen, which signs them in only after the change. If such an account somehow
 * reaches an authenticated route (for example the email-verification step was switched off), this middleware:
 *
 * - verification enabled: ends the session, re-marks the account pending so the next login goes through the
 *   code + new-password step, and redirects to login (JSON/offline requests get a 403);
 * - verification disabled (LMLINGA_STAFF_EMAIL_VERIFICATION=false): there is no code step, so it redirects to the
 *   change-password page, with no OTP (JSON/offline requests get a 403).
 *
 * Legacy accounts (temp_password_issued_at NULL) are never forced, even when must_change_password is true.
 */
class EnsurePasswordIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $this->holdsTemporaryPassword($user)) {
            return $next($request);
        }

        $wantsJson = $request->is('offline') || $request->is('offline/*') || $request->expectsJson();

        if (StaffEmailVerificationService::enabled()) {
            app(StaffEmailVerificationService::class)->markPending($user);

            StaffAuthenticator::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($wantsJson) {
                throw OfflineSyncException::accountInactive();
            }

            return redirect()->route('login');
        }

        if ($wantsJson) {
            throw OfflineSyncException::accountInactive();
        }

        return redirect()->route('password.change.required');
    }

    private function holdsTemporaryPassword(User $user): bool
    {
        return Schema::hasColumn($user->getTable(), 'temp_password_issued_at')
            && $user->temp_password_issued_at !== null;
    }
}
