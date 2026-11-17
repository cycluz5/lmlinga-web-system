<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Asks the recipient's own mail server whether a mailbox exists, without sending any email.
 *
 * It opens an SMTP conversation with the domain's mail server and stops right after "RCPT TO": the server
 * answers 250 for a mailbox it will accept and 550 5.1.1 for one that does not exist. No DATA is ever sent,
 * so nothing is delivered. Used for Gmail only (see config 'lmlinga.staff_email_probe_domains'), whose servers
 * answer this question honestly; other providers often accept every address (catch-all) or hide the answer.
 *
 * Result is one of:
 * - EXISTS:  the server accepted the address.
 * - MISSING: the server said the mailbox does not exist.
 * - UNKNOWN: anything else (port 25 blocked, timeout, greylisting, policy refusal). Callers must NOT treat
 *            this as "missing": the worker still has to confirm the emailed one-time code before first use.
 */
final class SmtpMailboxProbe
{
    public const EXISTS = 'exists';

    public const MISSING = 'missing';

    public const UNKNOWN = 'unknown';

    private const TIMEOUT_SECONDS = 6;

    /** @var (callable(string): string)|null */
    private static $resolver = null;

    /** Test seam: replace the network conversation. Pass null to restore the real one. */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function probe(string $email): string
    {
        $email = strtolower(trim($email));
        if (self::$resolver !== null) {
            return (self::$resolver)($email);
        }

        $key = 'smtp-mailbox-probe|'.hash('sha256', $email);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        $result = self::conversation($email);
        if ($result !== self::UNKNOWN) {
            Cache::put($key, $result, now()->addMinutes(10)); // definite answers only
        }

        return $result;
    }

    /** Maps the server's answer to RCPT TO onto a result. Public so it can be unit tested. */
    public static function classify(int $code, string $text): string
    {
        if ($code === 250 || $code === 251) {
            return self::EXISTS;
        }

        $text = strtolower($text);
        $permanentNoSuchUser = in_array($code, [550, 551, 553], true)
            && (str_contains($text, '5.1.1') || str_contains($text, 'does not exist') || str_contains($text, 'no such user'));

        return $permanentNoSuchUser ? self::MISSING : self::UNKNOWN;
    }

    private static function conversation(string $email): string
    {
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domain === '') {
            return self::UNKNOWN;
        }

        $hosts = [];
        $weights = [];
        if (! @getmxrr($domain, $hosts, $weights) || $hosts === []) {
            return self::UNKNOWN;
        }
        array_multisort($weights, SORT_ASC, $hosts);

        $socket = @stream_socket_client('tcp://'.$hosts[0].':25', $errno, $errstr, self::TIMEOUT_SECONDS);
        if ($socket === false) {
            return self::UNKNOWN; // e.g. outbound port 25 blocked by the network
        }

        try {
            stream_set_timeout($socket, self::TIMEOUT_SECONDS);

            if (self::readReply($socket)[0] !== 220) {
                return self::UNKNOWN;
            }

            $helo = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
            fwrite($socket, "EHLO {$helo}\r\n");
            if (self::readReply($socket)[0] !== 250) {
                return self::UNKNOWN;
            }

            $from = (string) config('mail.from.address');
            $from = str_contains($from, '@') ? $from : 'no-reply@'.$helo;
            fwrite($socket, "MAIL FROM:<{$from}>\r\n");
            if (self::readReply($socket)[0] !== 250) {
                return self::UNKNOWN;
            }

            fwrite($socket, "RCPT TO:<{$email}>\r\n");
            [$code, $text] = self::readReply($socket);

            fwrite($socket, "QUIT\r\n"); // never DATA: nothing is delivered

            return self::classify($code, $text);
        } finally {
            fclose($socket);
        }
    }

    /** @return array{0: int, 1: string} */
    private static function readReply($socket): array
    {
        $code = 0;
        $text = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $text .= $line;
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                $code = (int) $m[1];
                if ($m[2] === ' ') {
                    break; // last line of a multi-line reply
                }
            } else {
                break;
            }
        }

        return [$code, $text];
    }
}
