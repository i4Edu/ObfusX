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
     * Return non-sensitive metadata describing an encoded file.
     *
     * The protected source is never decrypted: only the algorithm, signing
     * status and recorded meta information are exposed.
     *
     * @return array<string,mixed>
     */
    public static function describeFile(string $encodedFile): array
    {
        if (!is_file($encodedFile)) {
            throw new \RuntimeException('Encoded file does not exist.');
        }

        $json = file_get_contents($encodedFile);
        if ($json === false) {
            throw new \RuntimeException('Failed to read encoded file.');
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid encoded file format.');
        }

        $meta = (isset($payload['meta']) && is_array($payload['meta'])) ? $payload['meta'] : [];

        return [
            'alg' => $payload['alg'] ?? 'unknown',
            'signed' => isset($payload['signature']),
            'meta' => $meta,
        ];
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

        $normalized = preg_replace('/\\?>\\s*$/', '', $normalized);
        if (!is_string($normalized)) {
            throw new \RuntimeException('Failed to normalize closing tag.');
        }

        return $normalized;
    }
}
