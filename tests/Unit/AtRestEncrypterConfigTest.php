<?php

namespace Tests\Unit;

use App\Support\AtRestEncrypter;
use App\Support\AtRestEncryptionException;
use Tests\TestCase;

class AtRestEncrypterConfigTest extends TestCase
{
    private const AAD = 'lmlinga|family_planning|remarks';

    public function test_phpunit_at_rest_key_is_thirty_two_bytes_and_not_app_key(): void
    {
        $configured = (string) config('lmlinga.at_rest.key');
        $this->assertStringStartsWith('base64:', $configured);
        $raw = base64_decode(substr($configured, 7), true);
        $this->assertIsString($raw);
        $this->assertSame(32, strlen($raw));
        $this->assertNotSame(config('app.key'), $configured);
        $this->assertSame('testk1', config('lmlinga.at_rest.key_id'));
    }

    public function test_missing_key_makes_encrypt_throw(): void
    {
        $encrypter = new AtRestEncrypter(null, 'k1', '', (string) config('app.key'));

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->encrypt('cannot store plaintext', self::AAD);
    }

    public function test_invalid_length_throws(): void
    {
        foreach ([16, 31, 33] as $bytes) {
            $encrypter = new AtRestEncrypter(
                'base64:'.base64_encode(str_repeat("\x01", $bytes)),
                'k1',
                '',
                (string) config('app.key')
            );

            try {
                $encrypter->encrypt('no', self::AAD);
                $this->fail("Expected {$bytes}-byte key to be rejected.");
            } catch (AtRestEncryptionException $e) {
                $this->assertStringNotContainsString('base64:', $e->getMessage());
            }
        }
    }

    public function test_malformed_enabled_env_does_not_disable_encryption(): void
    {
        $repository = \Illuminate\Support\Env::getRepository();
        $had = $repository->has('LMLINGA_AT_REST_ENABLED');
        $previous = $had ? $repository->get('LMLINGA_AT_REST_ENABLED') : null;

        try {
            foreach (['maybe', 'TRUEISH', 'enabled', ''] as $value) {
                $repository->set('LMLINGA_AT_REST_ENABLED', $value);
                $config = require dirname(__DIR__, 2).'/config/lmlinga.php';
                $this->assertTrue(
                    $config['at_rest']['enabled'],
                    "LMLINGA_AT_REST_ENABLED=".json_encode($value).' must stay enabled'
                );
            }

            $repository->set('LMLINGA_AT_REST_ENABLED', 'false');
            $disabled = require dirname(__DIR__, 2).'/config/lmlinga.php';
            $this->assertFalse($disabled['at_rest']['enabled']);
        } finally {
            if ($had && is_string($previous)) {
                $repository->set('LMLINGA_AT_REST_ENABLED', $previous);
            } elseif (method_exists($repository, 'clear')) {
                $repository->clear('LMLINGA_AT_REST_ENABLED');
            }
        }
    }

    public function test_missing_base64_prefix_throws(): void
    {
        $encrypter = new AtRestEncrypter(
            bin2hex(random_bytes(32)),
            'k1',
            '',
            (string) config('app.key')
        );

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->encrypt('no', self::AAD);
    }

    public function test_config_key_equal_to_app_key_throws(): void
    {
        $appKey = (string) config('app.key');
        if ($appKey === '' || ! str_starts_with($appKey, 'base64:')) {
            $appKey = 'base64:'.base64_encode(str_repeat("\x42", 32));
        }

        $encrypter = new AtRestEncrypter($appKey, 'k1', '', $appKey);

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->encrypt('must not use APP_KEY', self::AAD);
    }

    public function test_app_key_cannot_be_used_as_previous_key(): void
    {
        $appKey = 'base64:'.base64_encode(str_repeat("\x42", 32));
        $current = 'base64:'.base64_encode(str_repeat("\x11", 32));
        $encrypter = new AtRestEncrypter($current, 'k1', 'k0='.$appKey, $appKey);

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->encrypt('no', self::AAD);
    }

    public function test_previous_keys_parse_and_duplicate_ids_are_rejected(): void
    {
        $k0 = 'base64:'.base64_encode(str_repeat("\x01", 32));
        $kOld = 'base64:'.base64_encode(str_repeat("\x03", 32));
        $current = 'base64:'.base64_encode(str_repeat("\x02", 32));

        $ok = new AtRestEncrypter($current, 'k1', 'k0='.$k0.',k_old='.$kOld, (string) config('app.key'));
        $legacy = (new AtRestEncrypter($k0, 'k0', '', (string) config('app.key')))
            ->encrypt('from k0', self::AAD);
        $this->assertSame('from k0', $ok->decrypt($legacy, self::AAD));

        $dup = new AtRestEncrypter($current, 'k1', 'k0='.$k0.',k0='.$kOld, (string) config('app.key'));

        $this->expectException(AtRestEncryptionException::class);
        $dup->encrypt('no', self::AAD);
    }

    public function test_previous_key_id_equal_to_current_id_is_rejected(): void
    {
        $old = 'base64:'.base64_encode(str_repeat("\x01", 32));
        $current = 'base64:'.base64_encode(str_repeat("\x02", 32));
        $encrypter = new AtRestEncrypter($current, 'k1', 'k1='.$old, (string) config('app.key'));

        $this->expectException(AtRestEncryptionException::class);
        $encrypter->encrypt('no', self::AAD);
    }

    public function test_missing_key_does_not_downgrade_enabled_writes_through_the_container(): void
    {
        config(['lmlinga.at_rest.key' => '', 'lmlinga.at_rest.enabled' => true]);
        $this->app->forgetInstance(AtRestEncrypter::class);

        $this->assertTrue((bool) config('lmlinga.at_rest.enabled'));

        $this->expectException(AtRestEncryptionException::class);
        app(AtRestEncrypter::class)->encrypt('must fail closed', self::AAD);
    }
}
