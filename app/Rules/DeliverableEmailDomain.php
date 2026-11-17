<?php

namespace App\Rules;

use App\Support\SmtpMailboxProbe;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refinement item 1 — a health-worker account must not be created for an email address that cannot receive mail.
 *
 * Rejects addresses whose domain has no mail server: no MX record and no A/AAAA fallback ("name@gmial.co",
 * "name@no-such-domain.test"). For Gmail it also asks Gmail's mail server whether the mailbox exists
 * (SmtpMailboxProbe, nothing is sent) and rejects "does not exist". When that question cannot be answered the
 * address is accepted and the emailed one-time code the worker must enter before first use
 * (StaffEmailVerificationService) remains the final proof.
 *
 * - $unchangedEmail: an address already stored for this account is not re-checked, so older accounts can still
 *   have their other details edited.
 * - config('lmlinga.staff_email_dns_check') = false is the rollback switch (also used by the test suite and
 *   for offline demos, where DNS is unreachable).
 * - If DNS itself is unreachable the lookup says "no record", so an offline server also rejects. Turn the
 *   switch off there instead of accepting unchecked addresses silently.
 */
final class DeliverableEmailDomain implements ValidationRule
{
    public const MESSAGE = 'This email address cannot receive mail (its domain has no mail server). Enter a real, working email address.';

    public const MAILBOX_MESSAGE = 'This Gmail address does not exist. Enter the worker\'s real Gmail address.';

    /** @var (callable(string, string): bool)|null */
    private static $resolver = null;

    public function __construct(private readonly ?string $unchangedEmail = null) {}

    /** Test seam: replace the DNS lookup. Pass null to restore the real one. */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! (bool) config('lmlinga.staff_email_dns_check', true)) {
            return;
        }

        if (! is_string($value) || ! str_contains($value, '@')) {
            return; // Left to the required/email rules.
        }

        $email = strtolower(trim($value));
        if ($this->unchangedEmail !== null && $email === strtolower(trim($this->unchangedEmail))) {
            return;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domain === '' || ! $this->domainCanReceiveMail($domain)) {
            $fail(self::MESSAGE);

            return;
        }

        // Real domain: for Gmail, also ask Gmail's own mail server whether this exact mailbox exists.
        // "unknown" (port 25 blocked, timeout, refusal) is accepted; the emailed code still gates first use.
        if ((bool) config('lmlinga.staff_email_mailbox_probe', true)
            && in_array($domain, (array) config('lmlinga.staff_email_probe_domains', ['gmail.com', 'googlemail.com']), true)
            && SmtpMailboxProbe::probe($email) === SmtpMailboxProbe::MISSING) {
            $fail(self::MAILBOX_MESSAGE);
        }
    }

    private function domainCanReceiveMail(string $domain): bool
    {
        $check = self::$resolver ?? static fn (string $host, string $type): bool => @checkdnsrr($host.'.', $type);

        return $check($domain, 'MX') || $check($domain, 'A') || $check($domain, 'AAAA');
    }
}
