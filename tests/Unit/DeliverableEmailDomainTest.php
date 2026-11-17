<?php

namespace Tests\Unit;

use App\Rules\DeliverableEmailDomain;
use App\Support\SmtpMailboxProbe;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Refinement item 1: worker emails on domains without a mail server are rejected. */
class DeliverableEmailDomainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['lmlinga.staff_email_dns_check' => true]);
    }

    protected function tearDown(): void
    {
        DeliverableEmailDomain::resolveUsing(null);
        SmtpMailboxProbe::resolveUsing(null);
        parent::tearDown();
    }

    /** @param list<string> $domainsWithMail */
    private function fakeDns(array $domainsWithMail): void
    {
        DeliverableEmailDomain::resolveUsing(
            static fn (string $host, string $type): bool => $type === 'MX' && in_array($host, $domainsWithMail, true)
        );
    }

    private function passes(string $email, ?string $unchanged = null): bool
    {
        return Validator::make(['email' => $email], ['email' => [new DeliverableEmailDomain($unchanged)]])->passes();
    }

    public function test_domain_with_a_mail_server_is_accepted(): void
    {
        $this->fakeDns(['gmail.com']);

        $this->assertTrue($this->passes('someone@gmail.com'));
        $this->assertTrue($this->passes('Someone@GMAIL.com'));
    }

    public function test_domain_without_any_mail_server_is_rejected_with_the_message(): void
    {
        $this->fakeDns(['gmail.com']);

        $validator = Validator::make(['email' => 'x@gmial.co'], ['email' => [new DeliverableEmailDomain]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(DeliverableEmailDomain::MESSAGE, $validator->errors()->first('email'));
    }

    public function test_a_domain_with_only_an_address_record_still_counts(): void
    {
        DeliverableEmailDomain::resolveUsing(
            static fn (string $host, string $type): bool => $type === 'A' && $host === 'small-clinic.example'
        );

        $this->assertTrue($this->passes('nurse@small-clinic.example'));
    }

    public function test_an_unchanged_stored_address_is_not_rechecked(): void
    {
        $this->fakeDns([]);

        $this->assertTrue($this->passes('Old@Legacy.test', 'old@legacy.test'));
        $this->assertFalse($this->passes('new@legacy.test', 'old@legacy.test'));
    }

    public function test_the_rollback_switch_turns_the_check_off(): void
    {
        $this->fakeDns([]);
        config(['lmlinga.staff_email_dns_check' => false]);

        $this->assertTrue($this->passes('anyone@no-such-domain.test'));
    }

    // ------------------------------------------------------------------ mailbox check (Gmail)

    public function test_a_gmail_mailbox_that_does_not_exist_is_rejected(): void
    {
        config(['lmlinga.staff_email_mailbox_probe' => true]);
        $this->fakeDns(['gmail.com']);
        SmtpMailboxProbe::resolveUsing(static fn (string $email): string => SmtpMailboxProbe::MISSING);

        $validator = Validator::make(['email' => 'zzqx.nobody@gmail.com'], ['email' => [new DeliverableEmailDomain]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(DeliverableEmailDomain::MAILBOX_MESSAGE, $validator->errors()->first('email'));
    }

    public function test_a_gmail_mailbox_that_exists_or_cannot_be_checked_is_accepted(): void
    {
        config(['lmlinga.staff_email_mailbox_probe' => true]);
        $this->fakeDns(['gmail.com']);

        SmtpMailboxProbe::resolveUsing(static fn (string $email): string => SmtpMailboxProbe::EXISTS);
        $this->assertTrue($this->passes('real.person@gmail.com'));

        SmtpMailboxProbe::resolveUsing(static fn (string $email): string => SmtpMailboxProbe::UNKNOWN);
        $this->assertTrue($this->passes('blocked.network@gmail.com'));
    }

    public function test_only_configured_domains_are_probed(): void
    {
        config(['lmlinga.staff_email_mailbox_probe' => true]);
        $this->fakeDns(['small-clinic.example']);
        SmtpMailboxProbe::resolveUsing(static fn (string $email): string => SmtpMailboxProbe::MISSING);

        $this->assertTrue($this->passes('nurse@small-clinic.example'));
    }

    public function test_server_replies_are_classified(): void
    {
        $this->assertSame(SmtpMailboxProbe::EXISTS, SmtpMailboxProbe::classify(250, '250 2.1.5 OK'));
        $this->assertSame(SmtpMailboxProbe::MISSING, SmtpMailboxProbe::classify(550, "550-5.1.1 The email account that you tried to reach does not exist."));
        $this->assertSame(SmtpMailboxProbe::UNKNOWN, SmtpMailboxProbe::classify(450, '450 4.2.1 try again later'));
        $this->assertSame(SmtpMailboxProbe::UNKNOWN, SmtpMailboxProbe::classify(550, '550 5.7.1 blocked by policy'));
        $this->assertSame(SmtpMailboxProbe::UNKNOWN, SmtpMailboxProbe::classify(0, ''));
    }
}
