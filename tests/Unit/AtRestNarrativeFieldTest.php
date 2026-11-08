<?php

namespace Tests\Unit;

use App\Support\AtRestEncrypter;
use App\Support\AtRestEncryptionException;
use App\Support\AtRestNarrativeField;
use Tests\TestCase;

class AtRestNarrativeFieldTest extends TestCase
{
    public function test_plaintext_round_trip_via_seal_and_open(): void
    {
        $sealed = AtRestNarrativeField::seal('Counseling provided', 'family_planning', 'remarks');

        $this->assertIsString($sealed);
        $this->assertTrue(AtRestNarrativeField::isSealed($sealed));
        $this->assertSame(
            'Counseling provided',
            AtRestNarrativeField::open($sealed, 'family_planning', 'remarks')
        );
    }

    public function test_open_returns_legacy_plaintext_unchanged(): void
    {
        $this->assertSame(
            'already plaintext',
            AtRestNarrativeField::open('already plaintext', 'family_planning', 'remarks')
        );
    }

    public function test_seal_is_idempotent_after_verified_decrypt(): void
    {
        $first = AtRestNarrativeField::seal('once', 'family_planning', 'remarks');
        $this->assertIsString($first);

        $second = AtRestNarrativeField::seal($first, 'family_planning', 'remarks');
        $this->assertSame($first, $second);
        $this->assertSame(1, substr_count($first, 'lmlinga:v2:'));
    }

    public function test_malformed_prefix_is_not_reencrypted_as_plaintext(): void
    {
        $malformed = 'lmlinga:v2:k1:not-valid-payload';

        try {
            AtRestNarrativeField::seal($malformed, 'family_planning', 'remarks');
            $this->fail('Malformed sealed-looking input must fail closed.');
        } catch (AtRestEncryptionException $e) {
            $this->assertStringNotContainsString($malformed, $e->getMessage());
        }
    }

    public function test_copied_ciphertext_cannot_open_under_a_different_field_aad(): void
    {
        $fp = AtRestNarrativeField::seal('Counseling provided', 'family_planning', 'remarks');
        $death = AtRestNarrativeField::seal('Certificate mismatch', 'death_records', 'rejection_reason');
        $this->assertIsString($fp);
        $this->assertIsString($death);

        try {
            AtRestNarrativeField::open($fp, 'death_records', 'rejection_reason');
            $this->fail('FP ciphertext must not open as a death rejection reason.');
        } catch (AtRestEncryptionException $e) {
            $this->assertStringNotContainsString('Counseling provided', $e->getMessage());
        }

        try {
            AtRestNarrativeField::open($death, 'family_planning', 'remarks');
            $this->fail('Death ciphertext must not open as family planning remarks.');
        } catch (AtRestEncryptionException $e) {
            $this->assertStringNotContainsString('Certificate mismatch', $e->getMessage());
        }

        $this->assertSame(
            AtRestNarrativeField::DISPLAY_UNAVAILABLE,
            AtRestNarrativeField::openForDisplay($fp, 'death_requests', 'rejection_reason', 7)
        );
    }

    public function test_cross_field_ciphertext_is_not_reencrypted_as_plaintext(): void
    {
        $fp = AtRestNarrativeField::seal('Do not wrap again', 'family_planning', 'remarks');
        $this->assertIsString($fp);

        $this->expectException(AtRestEncryptionException::class);
        AtRestNarrativeField::seal($fp, 'death_records', 'rejection_reason');
    }

    public function test_open_for_display_returns_placeholder_never_ciphertext(): void
    {
        $sealed = AtRestNarrativeField::seal('secret display', 'family_planning', 'remarks');
        $this->assertIsString($sealed);
        $tampered = substr($sealed, 0, -2).'xx';

        $display = AtRestNarrativeField::openForDisplay($tampered, 'family_planning', 'remarks', 42);

        $this->assertSame(AtRestNarrativeField::DISPLAY_UNAVAILABLE, $display);
        $this->assertStringNotContainsString('secret display', (string) $display);
        $this->assertStringNotContainsString('lmlinga:v2:', (string) $display);
    }

    public function test_null_and_whitespace_seal_to_sql_null(): void
    {
        $this->assertNull(AtRestNarrativeField::seal(null, 'family_planning', 'remarks'));
        $this->assertNull(AtRestNarrativeField::seal('', 'family_planning', 'remarks'));
        $this->assertNull(AtRestNarrativeField::seal('   ', 'family_planning', 'remarks'));
        $this->assertNull(AtRestNarrativeField::open(null, 'family_planning', 'remarks'));
        $this->assertSame('', AtRestNarrativeField::open('', 'family_planning', 'remarks'));
    }

    public function test_kill_switch_stores_plaintext_but_still_decrypts_existing_ciphertext(): void
    {
        $sealed = AtRestNarrativeField::seal('already sealed', 'family_planning', 'remarks');
        $this->assertIsString($sealed);

        config(['lmlinga.at_rest.enabled' => false]);

        $plainWrite = AtRestNarrativeField::seal('emergency plaintext', 'family_planning', 'remarks');
        $this->assertSame('emergency plaintext', $plainWrite);
        $this->assertFalse(AtRestNarrativeField::isSealed($plainWrite));

        $this->assertSame(
            'already sealed',
            AtRestNarrativeField::open($sealed, 'family_planning', 'remarks')
        );
    }

    public function test_kill_switch_does_not_run_when_key_is_missing_and_encryption_is_enabled(): void
    {
        config([
            'lmlinga.at_rest.enabled' => true,
            'lmlinga.at_rest.key' => '',
        ]);
        $this->app->forgetInstance(AtRestEncrypter::class);

        $this->expectException(AtRestEncryptionException::class);
        AtRestNarrativeField::seal('must not become plaintext', 'family_planning', 'remarks');
    }
}
