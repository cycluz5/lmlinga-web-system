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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Staff/admin password recovery against App\Models\User (users or user_management).
 * Does not use ResidentAccount or chatbot reset routes.
 *
 * Batch 2 (panel refinement): reset tokens are created and mailed ONLY for registered, eligible accounts
 * (active, not deleted, email ownership verified per Batch 1). Every request gets the same response, and the
 * mail is sent after the response (defer), so neither the message nor the response time reveals whether an
 * email is registered. An explicit "Email is not registered" message is intentionally NOT shown: it would let
 * anyone test which addresses belong to health workers (account enumeration). The generic message tells a
 * genuine worker what to do instead.
 */
class ForgotPasswordController extends Controller
{
    public const SENT_MESSAGE = 'If an account matches that email, password reset instructions have been sent. '
        .'If no email arrives within a few minutes, check your spam folder or contact your barangay administrator.';

    /** Per-address limit, applied to registered and unregistered addresses alike. */
    public const MAX_REQUESTS_PER_EMAIL = 3;

    public const EMAIL_WINDOW_SECONDS = 900;

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

        // Same per-address budget for every address, so the limit itself reveals nothing.
        $withinLimit = RateLimiter::attempt(
            'staff-password-reset|'.hash('sha256', $email),
            self::MAX_REQUESTS_PER_EMAIL,
            static fn (): bool => true,
            self::EMAIL_WINDOW_SECONDS,
        );

        if ($withinLimit) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($user instanceof User && self::isEligibleForReset($user)) {
                $resetEmail = $user->getEmailForPasswordReset();
                // After the response is sent: response time is the same for registered and unknown emails.
                defer(static function () use ($resetEmail): void {
                    try {
                        Password::sendResetLink(['email' => $resetEmail]);
                    } catch (Throwable $e) {
                        Log::warning('staff.password_reset.delivery_failed', ['error' => $e::class]);
                    }
                });
            }
        }

        return back()->with('status', self::SENT_MESSAGE);
    }

    /**
     * Registered staff account that may receive or use a reset link: active, not deleted, and (Batch 1)
     * its email ownership is not pending. Pending accounts activate via the email code at sign-in instead.
     */
    public static function isEligibleForReset(User $user): bool
    {
        return $user->isActive()
            && ! app(StaffEmailVerificationService::class)->isPending($user);
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

        // Batch 2: a link issued earlier stops working if the account was deactivated, deleted or made
        // pending (e.g. email changed) since. Same generic message as any other invalid link.
        $target = User::query()->whereRaw('LOWER(email) = ?', [(string) $request->input('email')])->first();
        if ($target instanceof User && ! self::isEligibleForReset($target)) {
            Password::broker()->deleteToken($target);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => self::INVALID_LINK_MESSAGE]);
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
