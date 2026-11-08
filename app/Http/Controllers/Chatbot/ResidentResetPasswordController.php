<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\ResidentAccount;
use App\Models\ResidentPasswordResetToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ResidentResetPasswordController extends Controller
{
    public const INVALID_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

    public const EXPIRE_MINUTES = 60;

    public function create(Request $request, string $token): View
    {
        return view('pages.chatbot.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
        ]);

        $account = $this->findResidentAccountByEmail($validated['email']);
        $resetRow = $account === null ? null : $this->findValidReset($account, $validated['token']);

        if ($account === null || $resetRow === null) {
            return back()
                ->withErrors(['email' => self::INVALID_LINK_MESSAGE])
                ->withInput($request->only('email'));
        }

        $account->password = Hash::make($validated['password']);
        $account->save();

        $resetRow->forceFill([
            'is_used' => true,
            'used_at' => now(),
        ])->save();

        return redirect()
            ->route('chatbot.login')
            ->with('success', 'Your password has been reset. You can now log in.');
    }

    private function findResidentAccountByEmail(string $email): ?ResidentAccount
    {
        $normalized = Str::lower(trim($email));

        return ResidentAccount::query()
            ->whereRaw('lower(email) = ?', [$normalized])
            ->first();
    }

    private function findValidReset(ResidentAccount $account, string $plainToken): ?ResidentPasswordResetToken
    {
        $candidates = ResidentPasswordResetToken::query()
            ->where('account_id', $account->account_id)
            ->where('is_used', false)
            ->where('expires_at', '>=', now())
            ->orderByDesc('requested_at')
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($plainToken, $candidate->reset_token)) {
                return $candidate;
            }
        }

        return null;
    }
}
