<?php

declare(strict_types=1);

namespace ObfusX;

final class Crypto
{
    public static function encrypt(string $plaintext, string $masterKey): array
    {
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = self::deriveKey($masterKey, $salt);

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
            'salt' => base64_encode($salt),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    public static function decrypt(array $payload, string $masterKey): string
    {
        foreach (['salt', 'iv', 'tag', 'ciphertext'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                throw new \RuntimeException("Invalid payload: missing {$field}.");
            }
        }

        $salt = base64_decode($payload['salt'], true);
        $iv = base64_decode($payload['iv'], true);
        $tag = base64_decode($payload['tag'], true);
        $ciphertext = base64_decode($payload['ciphertext'], true);

        if ($salt === false || $iv === false || $tag === false || $ciphertext === false) {
            throw new \RuntimeException('Invalid payload encoding.');
        }

        $key = self::deriveKey($masterKey, $salt);
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

    private static function deriveKey(string $masterKey, string $salt): string
    {
        if ($masterKey === '') {
            throw new \RuntimeException('Master key cannot be empty.');
        }

        return hash_hkdf('sha256', $masterKey, 32, 'obfusx-runtime-key', $salt);
    }
}
