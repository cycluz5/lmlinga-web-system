<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetStaffPasswordRequest;
use App\Http\Requests\SendStaffPasswordResetLinkRequest;
use App\Models\User;
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
    public const SENT_MESSAGE = 'If an account matches that email, password reset instructions have been sent.';

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

        if ($user instanceof User && $user->isActive()) {
            Password::sendResetLink([
                'email' => $user->getEmailForPasswordReset(),
            ]);
        }

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
