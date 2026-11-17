<?php

namespace App\Services;

use App\Mail\StaffEmailVerificationOtpMail;
use App\Models\StaffEmailVerification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Batch 1 — health-worker email ownership verification.
 *
 * An Admin-created staff account is "pending" (unusable) until the worker proves they control the
 * account email by entering a 6-digit code sent to it. Pending = a staff_email_verifications row with
 * verified_at NULL. Accounts with no row (every account that existed before this feature) are treated as
 * verified, so existing logins, statuses and admin controls are unchanged.
 *
 * Codes: random_int (CSPRNG), stored only as bcrypt hashes, valid for 5 minutes, single use, voided after
 * 5 wrong attempts, resend cooldown 60 s, at most 5 codes per hour per account. Codes are never logged.
 */
final class StaffEmailVerificationService
{
    /** Panel requirement: verification codes are valid for 5 minutes. */
    public const EXPIRY_MINUTES = 5;

    public const SESSION_KEY = 'lml.staff_email_verification';

    public const SENT = 'sent';

    public const COOLDOWN = 'cooldown';

    public const SEND_LIMIT = 'send_limit';

    public const DELIVERY_FAILED = 'delivery_failed';

    public const VERIFIED = 'verified';

    public const INVALID = 'invalid';

    public const EXHAUSTED = 'exhausted';

    public const NOT_PENDING = 'not_pending';

    public static function enabled(): bool
    {
        return (bool) config('lmlinga.staff_email_verification.enabled', true)
            && Schema::hasTable('staff_email_verifications');
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

    public function isPending(User $user): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return StaffEmailVerification::query()
            ->where('user_id', $user->getKey())
            ->whereNull('verified_at')
            ->exists();
    }

    /**
     * @return list<int>
     */
    public function pendingUserIds(): array
    {
        if (! self::enabled()) {
            return [];
        }

        return StaffEmailVerification::query()
            ->whereNull('verified_at')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Require (re-)verification of the account's current email. No code is sent here: the worker
     * requests a code by signing in, so a 5-minute code is not wasted before they are ready.
     */
    public function markPending(User $user): void
    {
        if (! self::enabled()) {
            return;
        }

        StaffEmailVerification::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'email' => strtolower(trim((string) $user->email)),
                'code_hash' => null,
                'expires_at' => null,
                'attempts' => 0,
                'sends_in_window' => 0,
                'send_window_started_at' => null,
                'last_sent_at' => null,
                'verified_at' => null,
            ],
        );
    }

    public function sendCode(User $user): string
    {
        if (! $this->isPending($user)) {
            return self::NOT_PENDING;
        }

        return DB::transaction(function () use ($user): string {
            /** @var StaffEmailVerification $row */
            $row = StaffEmailVerification::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $now = now();
            $cooldown = max(0, (int) config('lmlinga.staff_email_verification.resend_cooldown_seconds', 60));
            if ($row->last_sent_at !== null && $row->last_sent_at->copy()->addSeconds($cooldown)->greaterThan($now)) {
                return self::COOLDOWN;
            }

            $maxPerHour = max(1, (int) config('lmlinga.staff_email_verification.max_sends_per_hour', 5));
            if ($row->send_window_started_at === null || $row->send_window_started_at->copy()->addHour()->lessThanOrEqualTo($now)) {
                $row->send_window_started_at = $now;
                $row->sends_in_window = 0;
            }
            if ($row->sends_in_window >= $maxPerHour) {
                return self::SEND_LIMIT;
            }

            // Always send to the account's CURRENT email (an Admin may have corrected a typo).
            $email = strtolower(trim((string) $user->email));
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            try {
                Mail::to($email)->send(new StaffEmailVerificationOtpMail($code, self::EXPIRY_MINUTES));
            } catch (Throwable $e) {
                Log::warning('staff.email_otp.delivery_failed', [
                    'user_id' => $user->getKey(),
                    'error' => $e::class,
                ]);

                return self::DELIVERY_FAILED;
            }

            $row->email = $email;
            $row->code_hash = Hash::make($code);
            $row->expires_at = $now->copy()->addMinutes(self::EXPIRY_MINUTES);
            $row->attempts = 0;
            $row->last_sent_at = $now;
            $row->sends_in_window = $row->sends_in_window + 1;
            $row->save();

            return self::SENT;
        });
    }

    public function verify(User $user, string $code): string
    {
        if (! $this->isPending($user)) {
            return self::NOT_PENDING;
        }

        $code = preg_replace('/\D+/', '', $code) ?? '';

        return DB::transaction(function () use ($user, $code): string {
            /** @var StaffEmailVerification $row */
            $row = StaffEmailVerification::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $maxAttempts = max(1, (int) config('lmlinga.staff_email_verification.max_attempts', 5));

            if ($row->code_hash === null) {
                // No live code: never sent, already used, or voided after too many attempts.
                return $row->attempts >= $maxAttempts ? self::EXHAUSTED : self::INVALID;
            }

            $row->attempts = $row->attempts + 1;

            $valid = strlen($code) === 6
                && $row->expires_at !== null
                && $row->expires_at->greaterThan(now())
                && $row->email === strtolower(trim((string) $user->email))
                && Hash::check($code, $row->code_hash);

            if (! $valid) {
                if ($row->attempts >= $maxAttempts) {
                    $row->code_hash = null;
                    $row->expires_at = null;
                    $row->save();

                    return self::EXHAUSTED;
                }
                $row->save();

                return self::INVALID;
            }

            $now = now();
            $row->code_hash = null;
            $row->expires_at = null;
            $row->verified_at = $now;
            $row->save();

            if (Schema::hasColumn($user->getTable(), 'email_verified_at')) {
                DB::table($user->getTable())
                    ->where($user->getKeyName(), $user->getKey())
                    ->update(['email_verified_at' => $now]);
            }

            Log::info('staff.email_otp.verified', ['user_id' => $user->getKey()]);

            return self::VERIFIED;
        });
    }

    public function secondsRemaining(User $user): int
    {
        $row = $this->row($user);
        if ($row === null || $row->code_hash === null || $row->expires_at === null) {
            return 0;
        }

        return max(0, $row->expires_at->getTimestamp() - now()->getTimestamp());
    }

    public function cooldownRemaining(User $user): int
    {
        $row = $this->row($user);
        if ($row === null || $row->last_sent_at === null) {
            return 0;
        }

        $cooldown = max(0, (int) config('lmlinga.staff_email_verification.resend_cooldown_seconds', 60));

        return max(0, $row->last_sent_at->getTimestamp() + $cooldown - now()->getTimestamp());
    }

    // ---------------------------------------------------------------- pending-login session binding

    public function bindSession(User $user): void
    {
        session()->put(self::SESSION_KEY, [
            'user_id' => (int) $user->getKey(),
            'bound_at' => now()->getTimestamp(),
        ]);
    }

    public function forgetSession(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * The staff account whose password was just verified but whose email is still pending.
     * Null when absent, stale, or no longer pending.
     */
    public function sessionUser(): ?User
    {
        $binding = session()->get(self::SESSION_KEY);
        if (! is_array($binding) || ! isset($binding['user_id'], $binding['bound_at'])) {
            return null;
        }

        $ttlMinutes = max(1, (int) config('lmlinga.staff_email_verification.pending_session_minutes', 30));
        if ((int) $binding['bound_at'] + $ttlMinutes * 60 < now()->getTimestamp()) {
            $this->forgetSession();

            return null;
        }

        $user = User::query()->whereKey((int) $binding['user_id'])->first();
        if (! $user instanceof User || ! $user->isActive() || ! $this->isPending($user)) {
            return null;
        }

        return $user;
    }

    private function row(User $user): ?StaffEmailVerification
    {
        if (! self::enabled()) {
            return null;
        }

        return StaffEmailVerification::query()->where('user_id', $user->getKey())->first();
    }
}
