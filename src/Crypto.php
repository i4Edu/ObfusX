<?php

declare(strict_types=1);

namespace ObfusX;

final class Crypto
{
    /**
     * Default HKDF "info" / key-rotation identifier.
     *
     * The value is not secret: it is stored alongside the ciphertext so the
     * runtime can derive the same key. Rotating it (via the $info argument or
     * the OBFUSX_KEY_INFO environment variable) produces a fresh derived key
     * from the same master key, enabling key rotation without re-keying.
     */
    public const DEFAULT_KEY_INFO = 'obfusx-runtime-key';

    /**
     * @return array<string,string>
     */
    public static function encrypt(string $plaintext, string $masterKey, ?string $info = null): array
    {
        $info = self::resolveKeyInfo($info);
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = self::deriveKey($masterKey, $salt, $info);

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return [
            'alg' => 'AES-256-GCM',
            'info' => $info,
            'salt' => base64_encode($salt),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function decrypt(array $payload, string $masterKey): string
    {
        foreach (['salt', 'iv', 'tag', 'ciphertext'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                throw new \RuntimeException("Invalid payload: missing {$field}.");
            }
        }

        $info = (isset($payload['info']) && is_string($payload['info']) && $payload['info'] !== '')
            ? $payload['info']
            : self::DEFAULT_KEY_INFO;

        $salt = base64_decode($payload['salt'], true);
        $iv = base64_decode($payload['iv'], true);
        $tag = base64_decode($payload['tag'], true);
        $ciphertext = base64_decode($payload['ciphertext'], true);

        if ($salt === false || $iv === false || $tag === false || $ciphertext === false) {
            throw new \RuntimeException('Invalid payload encoding.');
        }

        $key = self::deriveKey($masterKey, $salt, $info);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }

    private static function resolveKeyInfo(?string $info): string
    {
        if ($info !== null && $info !== '') {
            return $info;
        }

        $envInfo = getenv('OBFUSX_KEY_INFO');
        if (is_string($envInfo) && $envInfo !== '') {
            return $envInfo;
        }

        return self::DEFAULT_KEY_INFO;
    }

    private static function deriveKey(string $masterKey, string $salt, string $info): string
    {
        if ($masterKey === '') {
            throw new \RuntimeException('Master key cannot be empty.');
        }

        return hash_hkdf('sha256', $masterKey, 32, $info, $salt);
    }
}
