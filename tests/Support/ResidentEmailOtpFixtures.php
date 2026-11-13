<?php

namespace Tests\Support;

use App\Mail\ResidentEmailVerificationOtpMail;
use Illuminate\Support\Facades\Mail;

/**
 * Reads email OTP codes from Mail::fake() (no real email is ever sent in tests).
 */
trait ResidentEmailOtpFixtures
{
    /** The 6-digit code in the most recent verification email sent to $email, or null. */
    protected function lastEmailedCode(string $email): ?string
    {
        $mails = Mail::sent(ResidentEmailVerificationOtpMail::class, fn ($mail): bool => $mail->hasTo($email));
        if ($mails->isEmpty()) {
            return null;
        }

        return preg_match('/<strong>(\d{6})<\/strong>/', $mails->last()->render(), $match) === 1 ? $match[1] : null;
    }

    protected function emailedCodeCount(string $email): int
    {
        return Mail::sent(ResidentEmailVerificationOtpMail::class, fn ($mail): bool => $mail->hasTo($email))->count();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function registrationPayload(string $email, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'middle_name' => 'B',
            'last_name' => 'Cruz',
            'zone' => '2',
            'email' => $email,
            'password' => 'SafePassw0rd!x',
        ], $overrides);
    }
}
