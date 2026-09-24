<?php

namespace App\Support;

/**
 * AES-256-GCM field encryption. No Laravel Crypt, no APP_KEY, no HTTP/Eloquent.
 */
final class AtRestEncrypter
{
    public const SCHEME = 'lmlinga';

    public const VERSION = 'v2';

    public const CIPHER = 'aes-256-gcm';

    public const NONCE_BYTES = 12;

    public const TAG_BYTES = 16;

    public const MIN_PAYLOAD_BYTES = 28;

    public const KEY_BYTES = 32;

    public const KEY_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/';

    public const SEALED_PATTERN = '/^lmlinga:v2:[A-Za-z0-9][A-Za-z0-9_-]{0,31}:[A-Za-z0-9+\/]+=*$/';

    /** @var array<string, string>|null */
    private ?array $keyRing = null;

    private ?string $currentRawKey = null;

    private ?AtRestEncryptionException $currentKeyError = null;

    public function __construct(
        private readonly ?string $currentKey,
        private readonly string $currentKeyId,
        private readonly string $previousKeys,
        private readonly string $appKey,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, string $appKey): self
    {
        $key = $config['key'] ?? null;
        $keyId = $config['key_id'] ?? 'k1';
        $previous = $config['previous_keys'] ?? '';

        return new self(
            is_string($key) ? $key : null,
            is_string($keyId) && $keyId !== '' ? $keyId : 'k1',
            is_string($previous) ? $previous : '',
            $appKey,
        );
    }

    public static function looksEncrypted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::SCHEME.':');
    }

    public static function isSealed(mixed $value): bool
    {
        if (! is_string($value) || preg_match(self::SEALED_PATTERN, $value) !== 1) {
            return false;
        }

        $payload = self::payloadFromSealed($value);
        if ($payload === null) {
            return false;
        }

        $raw = base64_decode($payload, true);

        return is_string($raw) && strlen($raw) >= self::MIN_PAYLOAD_BYTES;
    }

    public function currentKeyId(): string
    {
        return $this->currentKeyId;
    }

    public function encrypt(string $plaintext, string $aad): string
    {
        $this->hydrateKeyRing();

        if ($this->currentRawKey === null) {
            throw $this->currentKeyError ?? new AtRestEncryptionException('At-rest encryption key is missing or invalid.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->currentRawKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TAG_BYTES
        );

        if (! is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
            throw new AtRestEncryptionException('Unable to encrypt at-rest field.');
        }

        return self::SCHEME.':'.self::VERSION.':'.$this->currentKeyId.':'.base64_encode($nonce.$ciphertext.$tag);
    }

    public function decrypt(string $ciphertext, string $aad): string
    {
        $this->hydrateKeyRing();

        if (! self::looksEncrypted($ciphertext)) {
            throw new AtRestEncryptionException('Value is not at-rest ciphertext.');
        }

        $parts = explode(':', $ciphertext, 4);
        if (count($parts) !== 4 || $parts[0] !== self::SCHEME) {
            throw new AtRestEncryptionException('Malformed at-rest ciphertext.');
        }

        if ($parts[1] !== self::VERSION) {
            throw new AtRestEncryptionException('Unsupported at-rest ciphertext version.');
        }

        $keyId = $parts[2];
        $payload = $parts[3];

        if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1) {
            throw new AtRestEncryptionException('Malformed at-rest ciphertext.');
        }

        if ($payload === '' || ! is_string($payload)) {
            throw new AtRestEncryptionException('Malformed at-rest ciphertext.');
        }

        $raw = base64_decode($payload, true);
        if (! is_string($raw) || strlen($raw) < self::MIN_PAYLOAD_BYTES) {
            throw new AtRestEncryptionException('Malformed at-rest ciphertext.');
        }

        $key = $this->keyRing[$keyId] ?? null;
        if ($key === null) {
            if ($keyId === $this->currentKeyId && $this->currentKeyError instanceof AtRestEncryptionException) {
                throw $this->currentKeyError;
            }

            throw new AtRestEncryptionException('Unknown at-rest key id.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, -self::TAG_BYTES);
        $body = substr($raw, self::NONCE_BYTES, -self::TAG_BYTES);

        if (strlen($nonce) !== self::NONCE_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw new AtRestEncryptionException('Malformed at-rest ciphertext.');
        }

        $plaintext = openssl_decrypt(
            $body,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad
        );

        if (! is_string($plaintext)) {
            throw new AtRestEncryptionException('Unable to decrypt at-rest field.');
        }

        return $plaintext;
    }

    private function hydrateKeyRing(): void
    {
        if ($this->keyRing !== null) {
            return;
        }

        $this->keyRing = [];
        $previous = $this->parsePreviousKeys($this->previousKeys);

        $currentPresent = is_string($this->currentKey) && $this->currentKey !== '';
        if ($currentPresent && isset($previous[$this->currentKeyId])) {
            throw new AtRestEncryptionException('Previous at-rest key id collides with the current key id.');
        }

        foreach ($previous as $id => $raw) {
            $this->keyRing[$id] = $raw;
        }

        if (! $currentPresent) {
            $this->currentKeyError = new AtRestEncryptionException('At-rest encryption key is missing or invalid.');

            return;
        }

        if (preg_match(self::KEY_ID_PATTERN, $this->currentKeyId) !== 1) {
            $this->currentKeyError = new AtRestEncryptionException('At-rest encryption key id is invalid.');

            return;
        }

        try {
            $this->currentRawKey = $this->decodeKey($this->currentKey);
            $this->keyRing[$this->currentKeyId] = $this->currentRawKey;
        } catch (AtRestEncryptionException $e) {
            $this->currentKeyError = $e;
        }
    }

    /**
     * @return array<string, string>
     */
    private function parsePreviousKeys(string $encoded): array
    {
        $trimmed = trim($encoded);
        if ($trimmed === '') {
            return [];
        }

        $ring = [];
        foreach (explode(',', $trimmed) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $eq = strpos($entry, '=');
            if ($eq === false || $eq === 0) {
                throw new AtRestEncryptionException('Previous at-rest keys are malformed.');
            }

            $id = substr($entry, 0, $eq);
            $key = substr($entry, $eq + 1);

            if (preg_match(self::KEY_ID_PATTERN, $id) !== 1) {
                throw new AtRestEncryptionException('Previous at-rest key id is invalid.');
            }

            if (isset($ring[$id])) {
                throw new AtRestEncryptionException('Previous at-rest key ids must be unique.');
            }

            $ring[$id] = $this->decodeKey($key);
        }

        return $ring;
    }

    private function decodeKey(string $encoded): string
    {
        if (! str_starts_with($encoded, 'base64:')) {
            throw new AtRestEncryptionException('At-rest encryption key is missing or invalid.');
        }

        $raw = base64_decode(substr($encoded, 7), true);
        if (! is_string($raw) || strlen($raw) !== self::KEY_BYTES) {
            throw new AtRestEncryptionException('At-rest encryption key is missing or invalid.');
        }

        $appRaw = $this->decodedAppKey();
        if ($appRaw !== null && hash_equals($appRaw, $raw)) {
            throw new AtRestEncryptionException('At-rest encryption key must not reuse APP_KEY.');
        }

        return $raw;
    }

    private function decodedAppKey(): ?string
    {
        $appKey = $this->appKey;
        if ($appKey === '') {
            return null;
        }

        if (str_starts_with($appKey, 'base64:')) {
            $raw = base64_decode(substr($appKey, 7), true);

            return is_string($raw) && $raw !== '' ? $raw : null;
        }

        return $appKey;
    }

    private static function payloadFromSealed(string $value): ?string
    {
        $parts = explode(':', $value, 4);

        return count($parts) === 4 ? $parts[3] : null;
    }
}
