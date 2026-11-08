<?php

namespace Tests\Unit;

use App\Support\AtRestEncrypter;
use App\Support\AtRestEncryptionException;
use Tests\TestCase;

class AtRestEncrypterTest extends TestCase
{
    private const FP_AAD = 'lmlinga|family_planning|remarks';

    private const DEATH_AAD = 'lmlinga|death_records|rejection_reason';

    public function test_encrypt_decrypt_round_trip_utf8_including_filipino(): void
    {
        $encrypter = $this->encrypter();
        $plaintext = 'Pagsusuri ng kalusugan — ñ, Ngayon, 中文, emoji 🙂';

        $sealed = $encrypter->encrypt($plaintext, self::FP_AAD);

        $this->assertSame($plaintext, $encrypter->decrypt($sealed, self::FP_AAD));
    }

    public function test_same_plaintext_produces_different_ciphertext_because_of_nonce(): void
    {
        $encrypter = $this->encrypter();
        $first = $encrypter->encrypt('same remarks', self::FP_AAD);
        $second = $encrypter->encrypt('same remarks', self::FP_AAD);

        $this->assertNotSame($first, $second);
        $this->assertSame('same remarks', $encrypter->decrypt($first, self::FP_AAD));
        $this->assertSame('same remarks', $encrypter->decrypt($second, self::FP_AAD));
    }

    public function test_payload_matches_v2_envelope_and_raw_length(): void
    {
        $encrypter = $this->encrypter('k1');
        $sealed = $encrypter->encrypt('hello', self::FP_AAD);

        $this->assertMatchesRegularExpression(AtRestEncrypter::SEALED_PATTERN, $sealed);
        $this->assertTrue(AtRestEncrypter::isSealed($sealed));
        $this->assertStringStartsWith('lmlinga:v2:k1:', $sealed);

        $payload = explode(':', $sealed, 4)[3];
        $raw = base64_decode($payload, true);
        $this->assertIsString($raw);
        $this->assertGreaterThanOrEqual(AtRestEncrypter::MIN_PAYLOAD_BYTES, strlen($raw));
        $this->assertSame(12 + strlen('hello') + 16, strlen($raw));
    }

    public function test_tag_nonce_and_body_tampering_fail_closed(): void
    {
        $encrypter = $this->encrypter();
        $sealed = $encrypter->encrypt('do not leak', self::FP_AAD);
        $parts = explode(':', $sealed, 4);
        $raw = base64_decode($parts[3], true);
        $this->assertIsString($raw);

        $cases = [
            'tag' => strlen($raw) - 1,
            'nonce' => 0,
            'body' => 12,
        ];

        foreach ($cases as $label => $index) {
            $mutated = $raw;
            $mutated[$index] = $mutated[$index] === "\x00" ? "\x01" : "\x00";
            $tampered = $parts[0].':'.$parts[1].':'.$parts[2].':'.base64_encode($mutated);

            try {
                $encrypter->decrypt($tampered, self::FP_AAD);
                $this->fail("Expected {$label} tampering to throw.");
            } catch (AtRestEncryptionException $e) {
                $this->assertStringNotContainsString('do not leak', $e->getMessage());
                $this->assertStringNotContainsString($tampered, $e->getMessage());
            }
        }
    }

    public function test_truncated_payload_fails_closed(): void
    {
        $encrypter = $this->encrypter();
        $sealed = $encrypter->encrypt('truncate me', self::FP_AAD);
        $parts = explode(':', $sealed, 4);
        $raw = base64_decode($parts[3], true);
        $this->assertIsString($raw);

        $truncated = $parts[0].':'.$parts[1].':'.$parts[2].':'.base64_encode(substr($raw, 0, 20));

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->decrypt($truncated, self::FP_AAD);
    }

    public function test_wrong_current_key_fails_closed(): void
    {
        $writer = $this->encrypter('k1', $this->key("\x11"));
        $sealed = $writer->encrypt('secret', self::FP_AAD);

        $reader = $this->encrypter('k1', $this->key("\x22"));

        $this->expectException(AtRestEncryptionException::class);
        $reader->decrypt($sealed, self::FP_AAD);
    }

    public function test_previous_key_decrypts_and_writes_use_current_key_id(): void
    {
        $oldKey = $this->key("\x01");
        $newKey = $this->key("\x02");
        $legacy = $this->encrypter('k0', $oldKey);
        $sealed = $legacy->encrypt('legacy row', self::FP_AAD);

        $rotated = $this->encrypter('k1', $newKey, 'k0='.$oldKey);

        $this->assertSame('legacy row', $rotated->decrypt($sealed, self::FP_AAD));

        $fresh = $rotated->encrypt('new row', self::FP_AAD);
        $this->assertStringStartsWith('lmlinga:v2:k1:', $fresh);
        $this->assertStringNotContainsString(':k0:', $fresh);
        $this->assertSame('new row', $rotated->decrypt($fresh, self::FP_AAD));
    }

    public function test_unknown_key_id_fails_closed(): void
    {
        $encrypter = $this->encrypter('k1');
        $sealed = $encrypter->encrypt('hidden', self::FP_AAD);
        $unknown = preg_replace('/^lmlinga:v2:k1:/', 'lmlinga:v2:unknown1:', $sealed);
        $this->assertIsString($unknown);

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->decrypt($unknown, self::FP_AAD);
    }

    public function test_v1_and_unknown_versions_fail_closed(): void
    {
        $encrypter = $this->encrypter();
        $payload = base64_encode(random_bytes(32));

        try {
            $encrypter->decrypt('lmlinga:v1:k1:'.$payload, self::FP_AAD);
            $this->fail('v1 must fail closed.');
        } catch (AtRestEncryptionException $e) {
            $this->assertStringNotContainsString($payload, $e->getMessage());
        }

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->decrypt('lmlinga:v9:k1:'.$payload, self::FP_AAD);
    }

    public function test_aad_mismatch_fails_closed(): void
    {
        $encrypter = $this->encrypter();
        $sealed = $encrypter->encrypt('fp remarks', self::FP_AAD);

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->decrypt($sealed, self::DEATH_AAD);
    }

    public function test_death_table_aads_are_not_interchangeable(): void
    {
        $encrypter = $this->encrypter();
        $recordsAad = 'lmlinga|death_records|rejection_reason';
        $requestsAad = 'lmlinga|death_requests|rejection_reason';
        $sealed = $encrypter->encrypt('admin rejection', $recordsAad);

        $this->assertSame('admin rejection', $encrypter->decrypt($sealed, $recordsAad));

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->decrypt($sealed, $requestsAad);
    }

    public function test_invalid_base64_empty_payload_and_malformed_v2_fail_closed(): void
    {
        $encrypter = $this->encrypter();

        foreach ([
            'lmlinga:v2:k1:',
            'lmlinga:v2:k1:!!!not-base64!!!',
            'lmlinga:v2:k1:abc',
            'lmlinga:v2::'.base64_encode(random_bytes(32)),
            'lmlinga:v2:k1',
        ] as $malformed) {
            try {
                $encrypter->decrypt($malformed, self::FP_AAD);
                $this->fail('Expected malformed ciphertext to throw: '.$malformed);
            } catch (AtRestEncryptionException $e) {
                $this->assertStringNotContainsString($malformed, $e->getMessage());
            }
        }
    }

    public function test_long_unicode_narrative_round_trips(): void
    {
        $encrypter = $this->encrypter();
        $plaintext = str_repeat('Pagsusuri ng kalusugan — ', 50);
        $this->assertGreaterThan(900, strlen($plaintext));

        $sealed = $encrypter->encrypt($plaintext, self::FP_AAD);

        $this->assertSame($plaintext, $encrypter->decrypt($sealed, self::FP_AAD));
        $this->assertStringNotContainsString($plaintext, $sealed);
    }

    public function test_empty_plaintext_is_not_required_at_crypto_layer(): void
    {
        $encrypter = $this->encrypter();
        $sealed = $encrypter->encrypt('', self::FP_AAD);

        $this->assertTrue(AtRestEncrypter::isSealed($sealed));
        $this->assertSame('', $encrypter->decrypt($sealed, self::FP_AAD));
        $payload = explode(':', $sealed, 4)[3];
        $raw = base64_decode($payload, true);
        $this->assertIsString($raw);
        $this->assertSame(AtRestEncrypter::MIN_PAYLOAD_BYTES, strlen($raw));
    }

    private function encrypter(
        string $keyId = 'k1',
        ?string $key = null,
        string $previous = ''
    ): AtRestEncrypter {
        return new AtRestEncrypter(
            $key ?? $this->key("\xA1"),
            $keyId,
            $previous,
            (string) config('app.key'),
        );
    }

    private function key(string $byte): string
    {
        return 'base64:'.base64_encode(str_repeat($byte, 32));
    }
}
