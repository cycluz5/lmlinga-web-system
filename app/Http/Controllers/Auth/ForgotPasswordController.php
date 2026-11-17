<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetStaffPasswordRequest;
use App\Http\Requests\SendStaffPasswordResetLinkRequest;
use App\Models\User;
use App\Services\StaffEmailVerificationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Staff/admin password recovery against App\Models\User (users or user_management).
 * Does not use ResidentAccount or chatbot reset routes.
 */
class ForgotPasswordController extends Controller
{
    public const SENT_MESSAGE = 'Password reset instructions have been sent to your email.';

    public const NOT_REGISTERED_MESSAGE = 'This email is not registered as a health worker account. Check the address, or ask your barangay administrator to create your account.';

    public const NOT_ELIGIBLE_MESSAGE = 'A password reset link cannot be sent for this account right now. Contact your barangay administrator.';

    public const RESET_SUCCESS_MESSAGE = 'Your password has been reset successfully. You may now log in.';

    public const INVALID_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('pages.auth.forgot-password');
    }

    public function store(SendStaffPasswordResetLinkRequest $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $email = (string) $request->validated('email');
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        // Refinement item 2: an email that is not a registered staff account is told so, and no reset link is made.
        // This reveals whether an address belongs to a health worker; the route's per-IP throttle (6 per minute) limits probing.
        if (! $user instanceof User) {
            return back()->withInput($request->only('email'))->withErrors(['email' => self::NOT_REGISTERED_MESSAGE]);
        }

        // Inactive, or the email address is not verified yet (a new account activates through the emailed code
        // at first sign-in, not through a reset link).
        if (! $user->isActive() || app(StaffEmailVerificationService::class)->isPending($user)) {
            return back()->withInput($request->only('email'))->withErrors(['email' => self::NOT_ELIGIBLE_MESSAGE]);
        }

        Password::sendResetLink([
            'email' => $user->getEmailForPasswordReset(),
        ]);

        return back()->with('status', self::SENT_MESSAGE);
    }

    public function edit(Request $request, string $token): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('pages.auth.reset-password', [
            'token' => $token,
            'email' => strtolower(trim((string) $request->query('email', ''))),
        ]);
    }

    public function update(ResetStaffPasswordRequest $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $payload = [
                    'password' => $password,
                    'must_change_password' => false,
                ];

                if (Schema::hasColumn($user->getTable(), 'remember_token')) {
                    $payload['remember_token'] = Str::random(60);
                }

                $user->forceFill($payload)->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()
                ->route('login')
                ->with('status', self::RESET_SUCCESS_MESSAGE);
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => self::INVALID_LINK_MESSAGE]);
    }
}
