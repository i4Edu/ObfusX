<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use ObfusX\Crypto;
use ObfusX\LicenseManager;

function obfusx_s(string $encoded): string
{
    $decoded = base64_decode($encoded, true);
    if ($decoded === false) {
        throw new RuntimeException('Invalid obfuscated string payload.');
    }

    return $decoded;
}

function obfusx_anti_debug(): void
{
    $debugAllowed = getenv('OBFUSX_ALLOW_DEBUG') === '1';
    if (!$debugAllowed && extension_loaded('xdebug')) {
        throw new RuntimeException('Debugging extension detected.');
    }
}

function obfusx_execute_payload(array $payload, ?string $licensePath = null): void
{
    obfusx_anti_debug();
    obfusx_validate_signature($payload);

    if ($licensePath !== null) {
        $licenseSigningKey = getenv('OBFUSX_LICENSE_KEY') ?: '';
        if ($licenseSigningKey === '') {
            throw new RuntimeException('OBFUSX_LICENSE_KEY is required when using licenses.');
        }

        $licenseJson = file_get_contents($licensePath);
        if ($licenseJson === false) {
            throw new RuntimeException('Unable to read license file.');
        }

        LicenseManager::validateFromString(
            $licenseJson,
            [
                'domain' => $_SERVER['SERVER_NAME'] ?? null,
                'ip' => $_SERVER['SERVER_ADDR'] ?? null,
                'hardware_fingerprint' => LicenseManager::hardwareFingerprint(),
            ],
            $licenseSigningKey
        );
    }

    $masterKey = getenv('OBFUSX_MASTER_KEY') ?: '';
    if ($masterKey === '') {
        throw new RuntimeException('OBFUSX_MASTER_KEY is required at runtime.');
    }

    $code = Crypto::decrypt($payload, $masterKey);
    eval($code);
}

function obfusx_validate_signature(array $payload): void
{
    if (!isset($payload['signature'])) {
        return;
    }

    $signingKey = getenv('OBFUSX_SIGNING_KEY') ?: '';
    if ($signingKey === '') {
        throw new RuntimeException('OBFUSX_SIGNING_KEY is required for signed payloads.');
    }

    $message = implode('|', [
        (string) ($payload['ciphertext'] ?? ''),
        (string) ($payload['iv'] ?? ''),
        (string) ($payload['tag'] ?? ''),
        (string) ($payload['salt'] ?? ''),
    ]);
    $expected = hash_hmac('sha256', $message, $signingKey);

    if (!hash_equals($expected, (string) $payload['signature'])) {
        throw new RuntimeException('Payload signature verification failed.');
    }
}

function obfusx_execute_file(string $encodedFile, ?string $licensePath = null): void
{
    if (!is_file($encodedFile)) {
        throw new RuntimeException('Encoded file not found.');
    }

    $json = file_get_contents($encodedFile);
    if ($json === false) {
        throw new RuntimeException('Unable to read encoded file.');
    }

    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid encoded file format.');
    }

    obfusx_execute_payload($payload, $licensePath);
}
