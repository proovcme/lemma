<?php

declare(strict_types=1);

namespace App\Services;

final class SecretEncryptionService
{
    private const PREFIX = 'enc:v1:';
    private const CONTEXT = 'locia-secret-v1';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::CONTEXT,
            $nonce,
            self::key()
        );

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public static function decrypt(string $value): string
    {
        if ($value === '' || !self::isEncrypted($value)) {
            return $value;
        }

        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($payload === false || strlen($payload) <= $nonceBytes) {
            throw new \RuntimeException('Зашифрованный секрет повреждён.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($payload, $nonceBytes),
            self::CONTEXT,
            substr($payload, 0, $nonceBytes),
            self::key()
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Не удалось расшифровать секрет: проверьте APP_DATA_KEY.');
        }

        return $plaintext;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private static function key(): string
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('Для шифрования секретов требуется расширение PHP sodium.');
        }

        $configured = trim((string) config('security.data_key', ''));
        $environment = getenv('APP_DATA_KEY');
        $encoded = $configured !== ''
            ? $configured
            : trim($environment === false ? '' : $environment);
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException('APP_DATA_KEY должен содержать 32 случайных байта в Base64.');
        }

        return $key;
    }
}
