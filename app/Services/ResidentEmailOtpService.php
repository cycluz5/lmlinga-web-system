<?php

namespace App\Services;

use App\Mail\ResidentEmailVerificationOtpMail;
use App\Mail\ResidentRegistrationAccountExistsMail;
use App\Models\ResidentAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email OTP for resident accounts (panel refinement SR-03).
 *
 * Registration: the account is created only after the emailed 6-digit code is entered. Until then the
 * form data waits in the cache (password already hashed). If the address already has an account, the
 * same flow runs but the owner receives a notice instead of a code, so responses never reveal whether
 * an email is registered. Existing accounts: a code to the stored account email sets email_verified_at.
 *
 * Codes are random_int (CSPRNG), stored only as hashes, valid for 5 minutes, voided after 5 wrong
 * attempts, never logged. Does not touch household links, requests or records.
 */
final class ResidentEmailOtpService
{
    /** Same contract as the household-record OTP (panel: 5 minutes, not 15). */
    public const EXPIRY_MINUTES = HouseholdRecordRequestOtpIssuer::EXPIRY_MINUTES;

    public const SESSION_PENDING_TOKEN = 'lml.pending_registration_token';

    public const SENT = 'sent';

    public const COOLDOWN = 'cooldown';

    public const RESEND_LIMIT = 'resend_limit';

    public const ADDRESS_LIMIT = 'address_limit';

    public const DELIVERY_FAILED = 'delivery_failed';

    public const VERIFIED = 'verified';

    public const INVALID = 'invalid';

    public const EXHAUSTED = 'exhausted';

    public const MISSING = 'missing';

    public const ALREADY_VERIFIED = 'already_verified';

    public static function enabled(): bool
    {
        return (bool) config('lmlinga.resident_email_verification.enabled', true);
    }

    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.mb_substr($email, $at);
    }

    // ---------------------------------------------------------------- registration

    /**
     * @param  array{first_name: string, middle_name: string, last_name: string, zone: string, email: string, password: string}  $validated
     * @return array{outcome: string, token: string|null}
     */
    public function startRegistration(array $validated): array
    {
        $email = trim((string) $validated['email']);

        $pending = [
            'first_name' => (string) $validated['first_name'],
            'middle_name' => (string) $validated['middle_name'],
            'last_name' => (string) $validated['last_name'],
            'zone' => (string) $validated['zone'],
            'email' => $email,
            'password_hash' => Hash::make((string) $validated['password']),
            'email_taken' => $this->emailBelongsToAccount($email),
            'code_hash' => null,
            'expires_at' => 0,
            'attempts' => 0,
            'resends' => 0,
            'last_sent_at' => 0,
        ];

        $outcome = $this->deliverRegistrationCode($pending);
        if ($outcome !== self::SENT) {
            return ['outcome' => $outcome, 'token' => null];
        }

        $token = Str::random(64);
        $this->putPending($token, $pending);

        return ['outcome' => self::SENT, 'token' => $token];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pending(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        $pending = Cache::get($this->pendingKey($token));

        return is_array($pending) ? $pending : null;
    }

    public function resendRegistration(?string $token): string
    {
        $pending = $this->pending($token);
        if ($pending === null) {
            return self::MISSING;
        }

        $guard = $this->resendGuard($pending);
        if ($guard !== null) {
            return $guard;
        }

        $pending['resends'] = (int) $pending['resends'] + 1;
        $outcome = $this->deliverRegistrationCode($pending);
        if ($outcome === self::SENT) {
            $this->putPending((string) $token, $pending);
        }

        return $outcome;
    }

    public function verifyRegistration(?string $token, string $code): string
    {
        if ($this->pending($token) === null) {
            return self::MISSING;
        }

        return Cache::lock($this->pendingKey((string) $token).':lock', 10)->block(5, function () use ($token, $code): string {
            $pending = $this->pending($token);
            if ($pending === null) {
                return self::MISSING;
            }

            $check = $this->checkCode($pending, $code);
            if ($check !== self::VERIFIED) {
                $this->putPending((string) $token, $pending);

                return $check;
            }

            $created = $this->createVerifiedAccount($pending);
            $this->forgetPending($token);

            if (! $created) {
                return self::INVALID;
            }

            Log::info('resident.email_otp.registration_verified', ['email' => $this->emailFingerprint($pending['email'])]);

            return self::VERIFIED;
        });
    }

    public function forgetPending(?string $token): void
    {
        if ($token !== null && $token !== '') {
            Cache::forget($this->pendingKey($token));
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function secondsRemaining(?array $state): int
    {
        // Identical for taken addresses (no code) and real codes, so the page reveals nothing.
        if ($state === null || ($state['voided'] ?? false)) {
            return 0;
        }

        return max(0, (int) ($state['expires_at'] ?? 0) - now()->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function cooldownRemaining(?array $state): int
    {
        if ($state === null) {
            return 0;
        }

        $cooldown = (int) config('lmlinga.resident_email_verification.resend_cooldown_seconds', 60);

        return max(0, (int) ($state['last_sent_at'] ?? 0) + $cooldown - now()->getTimestamp());
    }

    // ---------------------------------------------------------------- existing accounts

    public function sendForAccount(ResidentAccount $account): string
    {
        if ($account->hasVerifiedEmail()) {
            return self::ALREADY_VERIFIED;
        }

        $email = trim((string) $account->email);
        $state = $this->accountState($account);

        if ($state !== null) {
            $guard = $this->resendGuard($state);
            if ($guard !== null) {
                return $guard;
            }
            $state['resends'] = (int) $state['resends'] + 1;
        } else {
            $state = ['resends' => 0];
        }

        if ($this->addressLimitReached($email)) {
            return self::ADDRESS_LIMIT;
        }
        $this->hitAddress($email);

        $code = $this->newCode();
        try {
            Mail::to($email)->send(new ResidentEmailVerificationOtpMail($code, self::EXPIRY_MINUTES));
        } catch (Throwable) {
            Log::warning('resident.email_otp.delivery_failed', ['purpose' => 'existing_account', 'account_id' => (int) $account->getKey()]);

            return self::DELIVERY_FAILED;
        }

        $state = array_merge($state, [
            'code_hash' => Hash::make($code),
            'voided' => false,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES)->getTimestamp(),
            'attempts' => 0,
            'last_sent_at' => now()->getTimestamp(),
            'email' => $this->emailFingerprint($email),
        ]);
        Cache::put($this->accountKey($account), $state, now()->addMinutes($this->pendingMinutes()));

        Log::info('resident.email_otp.sent', ['purpose' => 'existing_account', 'account_id' => (int) $account->getKey()]);

        return self::SENT;
    }

    public function verifyForAccount(ResidentAccount $account, string $code): string
    {
        if ($account->hasVerifiedEmail()) {
            return self::ALREADY_VERIFIED;
        }

        return Cache::lock($this->accountKey($account).':lock', 10)->block(5, function () use ($account, $code): string {
            $state = $this->accountState($account);
            if ($state === null) {
                return self::MISSING;
            }

            // A code is valid only for the address it was sent to.
            if (($state['email'] ?? '') !== $this->emailFingerprint((string) $account->email)) {
                Cache::forget($this->accountKey($account));

                return self::MISSING;
            }

            $check = $this->checkCode($state, $code);
            if ($check !== self::VERIFIED) {
                Cache::put($this->accountKey($account), $state, now()->addMinutes($this->pendingMinutes()));

                return $check;
            }

            Cache::forget($this->accountKey($account));

            // Only this column; resident_id, requests and records are never touched.
            DB::table($account->getTable())
                ->where($account->getKeyName(), $account->getKey())
                ->whereNull('email_verified_at')
                ->update(['email_verified_at' => now()]);

            Log::info('resident.email_otp.account_verified', ['account_id' => (int) $account->getKey()]);

            return self::VERIFIED;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function accountState(ResidentAccount $account): ?array
    {
        $state = Cache::get($this->accountKey($account));

        return is_array($state) ? $state : null;
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param  array<string, mixed>  $pending
     */
    private function deliverRegistrationCode(array &$pending): string
    {
        $email = (string) $pending['email'];
        if ($this->addressLimitReached($email)) {
            return self::ADDRESS_LIMIT;
        }
        $this->hitAddress($email);

        $code = null;
        try {
            if ($pending['email_taken']) {
                Mail::to($email)->send(new ResidentRegistrationAccountExistsMail);
            } else {
                $code = $this->newCode();
                Mail::to($email)->send(new ResidentEmailVerificationOtpMail($code, self::EXPIRY_MINUTES));
            }
        } catch (Throwable) {
            Log::warning('resident.email_otp.delivery_failed', ['purpose' => 'registration', 'email' => $this->emailFingerprint($email)]);

            return self::DELIVERY_FAILED;
        }

        $pending['code_hash'] = $code === null ? null : Hash::make($code);
        $pending['voided'] = false;
        $pending['expires_at'] = now()->addMinutes(self::EXPIRY_MINUTES)->getTimestamp();
        $pending['attempts'] = 0;
        $pending['last_sent_at'] = now()->getTimestamp();

        Log::info('resident.email_otp.sent', ['purpose' => 'registration', 'email' => $this->emailFingerprint($email)]);

        return self::SENT;
    }

    /**
     * Shared attempt/expiry rules. A registration for a taken address has no code, so every entry fails
     * exactly like a wrong code.
     *
     * @param  array<string, mixed>  $state  updated in place (attempts, voided code)
     */
    private function checkCode(array &$state, string $code): string
    {
        $maxAttempts = (int) config('lmlinga.resident_email_verification.max_attempts', 5);

        if ((int) ($state['attempts'] ?? 0) >= $maxAttempts) {
            return self::EXHAUSTED;
        }

        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;

        $codeHash = $state['code_hash'] ?? null;
        $valid = is_string($codeHash)
            && (int) ($state['expires_at'] ?? 0) > now()->getTimestamp()
            && preg_match('/^\d{6}$/', $code) === 1
            && Hash::check($code, $codeHash);

        if ($valid) {
            return self::VERIFIED;
        }

        if ($state['attempts'] >= $maxAttempts) {
            $state['code_hash'] = null;
            $state['voided'] = true;

            return self::EXHAUSTED;
        }

        return self::INVALID;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function resendGuard(array $state): ?string
    {
        if ($this->cooldownRemaining($state) > 0) {
            return self::COOLDOWN;
        }

        if ((int) ($state['resends'] ?? 0) >= (int) config('lmlinga.resident_email_verification.max_resends', 3)) {
            return self::RESEND_LIMIT;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function createVerifiedAccount(array $pending): bool
    {
        try {
            return DB::transaction(function () use ($pending): bool {
                if ($this->emailBelongsToAccount((string) $pending['email'])) {
                    return false;
                }

                $account = new ResidentAccount([
                    'first_name' => $pending['first_name'],
                    'middle_name' => $pending['middle_name'],
                    'last_name' => $pending['last_name'],
                    'zone_purok' => $pending['zone'],
                    'email' => $pending['email'],
                    'password' => $pending['password_hash'],
                ]);

                if (ResidentAccount::supportsEmailVerification()) {
                    $account->email_verified_at = now();
                }

                return $account->save();
            });
        } catch (QueryException) {
            // Unique email index: another registration for this address completed first.
            return false;
        }
    }

    private function emailBelongsToAccount(string $email): bool
    {
        $table = (new ResidentAccount)->getTable();
        if (! Schema::hasTable($table)) {
            return false;
        }

        return ResidentAccount::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email), 'UTF-8')])
            ->exists();
    }

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function addressLimitReached(string $email): bool
    {
        return RateLimiter::tooManyAttempts(
            $this->addressKey($email),
            max(1, (int) config('lmlinga.resident_email_verification.emails_per_hour_per_address', 5))
        );
    }

    private function hitAddress(string $email): void
    {
        RateLimiter::hit($this->addressKey($email), 3600);
    }

    private function addressKey(string $email): string
    {
        return 'lmlinga-resident-email-otp-address:'.$this->emailFingerprint($email);
    }

    /** Never log or key by the raw address. */
    private function emailFingerprint(string $email): string
    {
        return hash('sha256', 'resident-email|'.mb_strtolower(trim($email), 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function putPending(string $token, array $pending): void
    {
        Cache::put($this->pendingKey($token), $pending, now()->addMinutes($this->pendingMinutes()));
    }

    private function pendingKey(string $token): string
    {
        return 'lmlinga:resident-registration:'.hash('sha256', $token);
    }

    private function accountKey(ResidentAccount $account): string
    {
        return 'lmlinga:resident-email-verify:'.(int) $account->getKey();
    }

    private function pendingMinutes(): int
    {
        return max(self::EXPIRY_MINUTES, (int) config('lmlinga.resident_email_verification.pending_registration_minutes', 30));
    }
}
