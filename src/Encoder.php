<?php

declare(strict_types=1);

namespace ObfusX;

final class Encoder
{
    public static function encodeFile(string $inputFile, string $outputFile, string $masterKey): void
    {
        if (!is_file($inputFile)) {
            throw new \RuntimeException('Input file does not exist.');
        }

        $code = file_get_contents($inputFile);
        if ($code === false) {
            throw new \RuntimeException('Failed to read input file.');
        }

        $normalized = self::normalizeForRuntime($code);
        $obfuscated = Obfuscator::obfuscate($normalized);
        $encrypted = Crypto::encrypt($obfuscated['code'], $masterKey);
        $encrypted['meta'] = [
            'obfuscated_at' => gmdate('c'),
            'identifier_count' => count($obfuscated['map']),
        ];
        $encrypted = self::addSignature($encrypted);

        $json = json_encode($encrypted, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($outputFile, $json) === false) {
            throw new \RuntimeException('Failed to write output file.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function addSignature(array $payload): array
    {
        $signingKey = getenv('OBFUSX_SIGNING_KEY') ?: '';
        if ($signingKey === '') {
            return $payload;
        }

        $message = implode('|', [
            (string) ($payload['ciphertext'] ?? ''),
            (string) ($payload['iv'] ?? ''),
            (string) ($payload['tag'] ?? ''),
            (string) ($payload['salt'] ?? ''),
        ]);
        $payload['signature'] = hash_hmac('sha256', $message, $signingKey);
        $payload['signed'] = true;

        return $payload;
    }

    private static function normalizeForRuntime(string $code): string
    {
        $normalized = preg_replace('/^<\\?(php)?/i', '', ltrim($code));
        if (!is_string($normalized)) {
            throw new \RuntimeException('Failed to normalize source.');
        }

        $normalized = preg_replace('/^\\s*declare\\s*\\(\\s*strict_types\\s*=\\s*1\\s*\\)\\s*;\\s*/i', '', $normalized);
        if (!is_string($normalized)) {
            throw new \RuntimeException('Failed to normalize strict_types declaration.');
        }

        return $normalized;
    }
}
