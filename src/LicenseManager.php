<?php

declare(strict_types=1);

namespace ObfusX;

final class LicenseManager
{
    /**
     * @param array{domain?:string,ip?:string,expires_at?:string,hardware_fingerprint?:string,licensee?:string,max_machines?:int} $options
     */
    public static function create(array $options, string $signingKey): string
    {
        if ($signingKey === '') {
            throw new \RuntimeException('Signing key cannot be empty.');
        }

        $payload = [
            'domain' => $options['domain'] ?? null,
            'expires_at' => $options['expires_at'] ?? null,
            'hardware_fingerprint' => $options['hardware_fingerprint'] ?? null,
            'ip' => $options['ip'] ?? null,
            'issued_at' => gmdate('c'),
            'licensee' => $options['licensee'] ?? null,
            'max_machines' => $options['max_machines'] ?? null,
        ];
        $payload = self::canonicalize($payload);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $json, $signingKey);

        return json_encode(['payload' => $payload, 'signature' => $sig], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public static function isRevoked(string $licenseJson, string $revocationListUrl): bool
    {
        $license = json_decode($licenseJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($license) || !isset($license['payload']) || !is_array($license['payload'])) {
            throw new \RuntimeException('Invalid license format.');
        }

        $payload = self::canonicalize($license['payload']);
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
            ],
        ]);
        $revocationJson = @file_get_contents($revocationListUrl, false, $context);
        if ($revocationJson === false) {
            throw new \RuntimeException('Unable to download revocation list.');
        }

        $revocations = json_decode($revocationJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($revocations)) {
            throw new \RuntimeException('Invalid revocation list format.');
        }

        foreach ($revocations as $revocation) {
            if (is_string($revocation) && hash_equals($revocation, $fingerprint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{domain?:string,ip?:string,hardware_fingerprint?:string,now?:int} $context
     */
    public static function validateFromString(string $licenseJson, array $context, string $signingKey): void
    {
        if ($signingKey === '') {
            throw new \RuntimeException('Signing key cannot be empty.');
        }

        $license = json_decode($licenseJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($license) || !isset($license['payload'], $license['signature']) || !is_array($license['payload'])) {
            throw new \RuntimeException('Invalid license format.');
        }

        $payload = self::canonicalize($license['payload']);
        $expected = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $signingKey);
        if (!hash_equals($expected, (string) $license['signature'])) {
            throw new \RuntimeException('Invalid license signature.');
        }

        $now = (int) ($context['now'] ?? time());
        if (isset($payload['expires_at']) && is_string($payload['expires_at']) && $payload['expires_at'] !== '') {
            $expires = strtotime($payload['expires_at']);
            if ($expires === false || $expires < $now) {
                throw new \RuntimeException('License expired.');
            }
        }

        foreach (['domain', 'ip', 'hardware_fingerprint'] as $key) {
            if (!empty($payload[$key]) && ($context[$key] ?? null) !== $payload[$key]) {
                throw new \RuntimeException("License validation failed for {$key}.");
            }
        }
    }

    public static function hardwareFingerprint(): string
    {
        return hash('sha256', php_uname('n') . '|' . PHP_OS_FAMILY . '|' . PHP_VERSION);
    }

    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private static function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        ksort($value);

        return $value;
    }
}
