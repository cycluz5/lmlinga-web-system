<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Mail\ResidentPasswordResetMail;
use App\Models\ResidentAccount;
use App\Models\ResidentPasswordResetToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ResidentForgotPasswordController extends Controller
{
    public const STATUS_MESSAGE = 'If an account exists for that email, a password reset link has been sent.';

    public const EXPIRE_MINUTES = 60;

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $account = $this->findResidentAccountByEmail($validated['email']);

        if ($account !== null) {
            $this->issueResetToken($account);
        }

        return back()->with('status', self::STATUS_MESSAGE);
    }

    private function findResidentAccountByEmail(string $email): ?ResidentAccount
    {
        $normalized = Str::lower(trim($email));

        return ResidentAccount::query()
            ->whereRaw('lower(email) = ?', [$normalized])
            ->first();
    }

    private function issueResetToken(ResidentAccount $account): void
    {
        $now = now();

        ResidentPasswordResetToken::query()
            ->where('account_id', $account->account_id)
            ->where('is_used', false)
            ->update([
                'is_used' => true,
                'used_at' => $now,
            ]);

        $plainToken = bin2hex(random_bytes(32));

        ResidentPasswordResetToken::query()->create([
            'account_id' => $account->account_id,
            'reset_token' => Hash::make($plainToken),
            'requested_at' => $now,
            'expires_at' => $now->copy()->addMinutes(self::EXPIRE_MINUTES),
            'is_used' => false,
            'used_at' => null,
            'created_at' => $now,
        ]);

        $resetUrl = route('chatbot.password.reset', [
            'token' => $plainToken,
            'email' => $account->email,
        ]);

        Mail::to($account->email)->send(new ResidentPasswordResetMail($resetUrl));
    }
}
