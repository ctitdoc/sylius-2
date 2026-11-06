<?php

declare(strict_types=1);

namespace Alma\Sylius\Security;

use SensitiveParameter;

/**
 * @implementation [[sylius-plugin-commons.md#key-encryption]] — the cipher itself: secretbox under a key derived
 * from the application secret, `v1:` prefixed payloads
 */
final class ApiKeyCipher
{
    private const VERSION = 'v1';

    private const VERSION_SEPARATOR = ':';

    private string $key;

    public function __construct(#[SensitiveParameter] string $appSecret)
    {
        if ($appSecret === '') {
            throw new \InvalidArgumentException('Application secret must not be empty.');
        }

        $this->key = sodium_crypto_generichash($appSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(#[SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plain, $nonce, $this->key);

        return self::VERSION . self::VERSION_SEPARATOR . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $parts = explode(self::VERSION_SEPARATOR, $payload, 2);
        if (\count($parts) !== 2 || $parts[0] !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported cipher payload format.');
        }

        $raw = base64_decode($parts[1], true);
        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \InvalidArgumentException('Malformed cipher payload.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt API key (key mismatch or tampered ciphertext).');
        }

        return $plain;
    }

    public function isEncrypted(string $payload): bool
    {
        return str_starts_with($payload, self::VERSION . self::VERSION_SEPARATOR);
    }
}
