<?php

namespace App\Services;

use App\Mail\ResidentRegistrationOtpMail;
use App\Models\ResidentAccount;
use App\Models\ResidentRegistrationVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Email ownership verification for resident self-registration (/chatbot/register).
 *
 * The ResidentAccount is saved only after the emailed 6-digit code is entered correctly. Until then the
 * form data (with the already-hashed password) waits in resident_registration_verifications.
 *
 * Codes: random_int (CSPRNG), stored only as bcrypt hashes, valid for 5 minutes, single use, voided after
 * 5 wrong attempts, resend cooldown 60 s, at most 5 codes per hour per email. Codes are never logged.
 */
final class ResidentRegistrationOtpService
{
    public const EXPIRY_MINUTES = 5;

    public const SESSION_KEY = 'lml.resident_registration_verification';

    public const SENT = 'sent';

    public const COOLDOWN = 'cooldown';

    public const SEND_LIMIT = 'send_limit';

    public const DELIVERY_FAILED = 'delivery_failed';

    public const VERIFIED = 'verified';

    public const INVALID = 'invalid';

    public const EXHAUSTED = 'exhausted';

    public const NOT_PENDING = 'not_pending';

    public const EMAIL_TAKEN = 'email_taken';

    public static function enabled(): bool
    {
        return (bool) config('lmlinga.resident_registration_otp.enabled', true)
            && Schema::hasTable('resident_registration_verifications');
    }

    public static function maskEmail(string $email): string
    {
        return StaffEmailVerificationService::maskEmail($email);
    }

    /**
     * Store (or overwrite) the pending registration for this email. No code is sent here.
     * An existing row keeps its send-window counters so re-submitting cannot bypass the limits.
     *
     * @param  array{first_name: string, middle_name: string, last_name: string, zone: string, email: string, password_hash: string}  $data
     */
    public function start(array $data): ResidentRegistrationVerification
    {
        $this->pruneStale();

        $email = strtolower(trim($data['email']));

        $row = ResidentRegistrationVerification::query()->where('email', $email)->first()
            ?? new ResidentRegistrationVerification(['email' => $email]);

        $row->payload = [
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'last_name' => $data['last_name'],
            'zone' => $data['zone'],
            'password' => $data['password_hash'],
        ];
        $row->code_hash = null;
        $row->expires_at = null;
        $row->attempts = 0;
        $row->save();

        return $row;
    }

    public function sendCode(string $email): string
    {
        $email = strtolower(trim($email));

        return DB::transaction(function () use ($email): string {
            $row = ResidentRegistrationVerification::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return self::NOT_PENDING;
            }

            $now = now();
            $cooldown = max(0, (int) config('lmlinga.resident_registration_otp.resend_cooldown_seconds', 60));
            if ($row->last_sent_at !== null && $row->last_sent_at->copy()->addSeconds($cooldown)->greaterThan($now)) {
                return self::COOLDOWN;
            }

            $maxPerHour = max(1, (int) config('lmlinga.resident_registration_otp.max_sends_per_hour', 5));
            if ($row->send_window_started_at === null || $row->send_window_started_at->copy()->addHour()->lessThanOrEqualTo($now)) {
                $row->send_window_started_at = $now;
                $row->sends_in_window = 0;
            }
            if ($row->sends_in_window >= $maxPerHour) {
                return self::SEND_LIMIT;
            }

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            try {
                Mail::to($email)->send(new ResidentRegistrationOtpMail($code, self::EXPIRY_MINUTES));
            } catch (Throwable $e) {
                Log::warning('resident.registration_otp.delivery_failed', ['error' => $e::class]);

                return self::DELIVERY_FAILED;
            }

            $row->code_hash = Hash::make($code);
            $row->expires_at = $now->copy()->addMinutes(self::EXPIRY_MINUTES);
            $row->attempts = 0;
            $row->last_sent_at = $now;
            $row->sends_in_window = $row->sends_in_window + 1;
            $row->save();

            return self::SENT;
        });
    }

    public function verify(string $email, string $code): string
    {
        $email = strtolower(trim($email));
        $code = preg_replace('/\D+/', '', $code) ?? '';

        return DB::transaction(function () use ($email, $code): string {
            $row = ResidentRegistrationVerification::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return self::NOT_PENDING;
            }

            $maxAttempts = max(1, (int) config('lmlinga.resident_registration_otp.max_attempts', 5));

            if ($row->code_hash === null) {
                return $row->attempts >= $maxAttempts ? self::EXHAUSTED : self::INVALID;
            }

            $row->attempts = $row->attempts + 1;

            $valid = strlen($code) === 6
                && $row->expires_at !== null
                && $row->expires_at->greaterThan(now())
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

            // Someone may have registered this email while the code was pending.
            if (ResidentAccount::query()->where('email', $email)->exists()) {
                $row->delete();

                return self::EMAIL_TAKEN;
            }

            $payload = $row->payload;
            $account = new ResidentAccount([
                'first_name' => $payload['first_name'],
                'middle_name' => $payload['middle_name'],
                'last_name' => $payload['last_name'],
                'zone_purok' => $payload['zone'],
                'email' => $email,
            ]);
            // Already hashed at registration time. The model's hashed cast leaves a value that is
            // already a bcrypt hash untouched, so this is not hashed twice.
            $account->password = $payload['password'];
            $account->save();

            $row->delete();

            Log::info('resident.registration_otp.verified');

            return self::VERIFIED;
        });
    }

    public function secondsRemaining(string $email): int
    {
        $row = $this->row($email);
        if ($row === null || $row->code_hash === null || $row->expires_at === null) {
            return 0;
        }

        return max(0, $row->expires_at->getTimestamp() - now()->getTimestamp());
    }

    public function cooldownRemaining(string $email): int
    {
        $row = $this->row($email);
        if ($row === null || $row->last_sent_at === null) {
            return 0;
        }

        $cooldown = max(0, (int) config('lmlinga.resident_registration_otp.resend_cooldown_seconds', 60));

        return max(0, $row->last_sent_at->getTimestamp() + $cooldown - now()->getTimestamp());
    }

    // ---------------------------------------------------------------- pending session binding

    public function bindSession(string $email): void
    {
        session()->put(self::SESSION_KEY, [
            'email' => strtolower(trim($email)),
            'bound_at' => now()->getTimestamp(),
        ]);
    }

    public function forgetSession(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Email of the pending registration bound to this session; null when absent, stale or gone.
     */
    public function sessionEmail(): ?string
    {
        $binding = session()->get(self::SESSION_KEY);
        if (! is_array($binding) || ! isset($binding['email'], $binding['bound_at'])) {
            return null;
        }

        $ttlMinutes = max(1, (int) config('lmlinga.resident_registration_otp.pending_session_minutes', 30));
        if ((int) $binding['bound_at'] + $ttlMinutes * 60 < now()->getTimestamp()) {
            $this->forgetSession();

            return null;
        }

        $email = (string) $binding['email'];

        return $this->row($email) === null ? null : $email;
    }

    public function discard(string $email): void
    {
        ResidentRegistrationVerification::query()->where('email', strtolower(trim($email)))->delete();
    }

    private function row(string $email): ?ResidentRegistrationVerification
    {
        if (! self::enabled()) {
            return null;
        }

        return ResidentRegistrationVerification::query()->where('email', strtolower(trim($email)))->first();
    }

    private function pruneStale(): void
    {
        ResidentRegistrationVerification::query()
            ->where('updated_at', '<', now()->subDay())
            ->delete();
    }
}
