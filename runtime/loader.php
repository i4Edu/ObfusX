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
    if (getenv('OBFUSX_ALLOW_DEBUG') === '1') {
        return;
    }

    foreach (obfusx_anti_debug_checks() as $check) {
        switch ($check) {
            case 'xdebug':
                if (extension_loaded('xdebug')) {
                    throw new RuntimeException('Debugging extension detected.');
                }
                break;

            case 'debugger':
                if (function_exists('xdebug_is_debugger_active') && xdebug_is_debugger_active()) {
                    throw new RuntimeException('Active debugger session detected.');
                }
                break;

            case 'phpdbg':
                if (PHP_SAPI === 'phpdbg' || defined('PHPDBG_VERSION')) {
                    throw new RuntimeException('phpdbg debugger detected.');
                }
                break;

            case 'trace':
                $traceFunctions = ['xdebug_start_trace', 'tideways_xhprof_enable', 'xhprof_enable'];
                foreach ($traceFunctions as $traceFn) {
                    if (function_exists($traceFn)) {
                        throw new RuntimeException('Tracing/profiling extension detected.');
                    }
                }
                break;

            default:
                // Unknown checks are ignored so configuration stays forward-compatible.
                break;
        }
    }
}

/**
 * Resolve the configured anti-debug checks.
 *
 * Configure with OBFUSX_ANTIDEBUG_CHECKS as a comma-separated list (for
 * example "xdebug,phpdbg"). Use "none" (or an empty list) to disable all
 * checks. Defaults to every available check.
 *
 * @return array<int,string>
 */
function obfusx_anti_debug_checks(): array
{
    $default = ['xdebug', 'debugger', 'phpdbg', 'trace'];
    $configured = getenv('OBFUSX_ANTIDEBUG_CHECKS');
    if (!is_string($configured) || trim($configured) === '') {
        return $default;
    }

    $checks = array_values(array_filter(array_map(
        static fn (string $check): string => strtolower(trim($check)),
        explode(',', $configured)
    ), static fn (string $check): bool => $check !== ''));

    if ($checks === [] || in_array('none', $checks, true)) {
        return [];
    }

    return $checks;
}

function obfusx_execute_payload(array $payload, ?string $licensePath = null, ?string $encodedFile = null): void
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
    $code = obfusx_decompress($payload, $code);
    $code = obfusx_bind_magic_paths($code, $encodedFile);
    obfusx_include_decrypted_code($code);
}

function obfusx_decompress(array $payload, string $code): string
{
    $compression = $payload['compression'] ?? null;
    if ($compression === null || $compression === '' || $compression === 'none') {
        return $code;
    }

    if ($compression !== 'gzip') {
        throw new RuntimeException('Unsupported payload compression: ' . (string) $compression);
    }

    if (!function_exists('gzdecode')) {
        throw new RuntimeException('zlib extension is required to decode compressed payloads.');
    }

    $decoded = gzdecode($code);
    if ($decoded === false) {
        throw new RuntimeException('Failed to decompress payload.');
    }

    return $decoded;
}

function obfusx_validate_signature(array $payload): void
{
    $signingKey = getenv('OBFUSX_SIGNING_KEY') ?: '';
    if ($signingKey !== '' && !isset($payload['signature'])) {
        throw new RuntimeException('Payload signature is required when OBFUSX_SIGNING_KEY is set.');
    }

    if (!isset($payload['signature'])) {
        return;
    }

    if ($signingKey === '') {
        throw new RuntimeException('OBFUSX_SIGNING_KEY is required for signed payloads.');
    }

    $message = implode('|', [
        (string) ($payload['ciphertext'] ?? ''),
        (string) ($payload['iv'] ?? ''),
        (string) ($payload['tag'] ?? ''),
        (string) ($payload['salt'] ?? ''),
        (string) ($payload['info'] ?? ''),
        (string) ($payload['compression'] ?? ''),
    ]);
    $expected = hash_hmac('sha256', $message, $signingKey);

    if (!hash_equals($expected, (string) $payload['signature'])) {
        throw new RuntimeException('Payload signature verification failed.');
    }
}

function obfusx_bind_magic_paths(string $code, ?string $encodedFile): string
{
    if ($encodedFile === null || !obfusx_has_php_tag($code)) {
        return $code;
    }

    $resolvedFile = realpath($encodedFile);
    $runtimeFile = $resolvedFile !== false ? $resolvedFile : $encodedFile;
    $runtimeDir = dirname($runtimeFile);
    $tokens = token_get_all($code);
    $out = [];

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            $out[] = $token;
            continue;
        }

        [$id, $text] = $token;

        if ($id === T_DIR) {
            $out[] = var_export($runtimeDir, true);
            continue;
        }

        if ($id === T_FILE) {
            $out[] = var_export($runtimeFile, true);
            continue;
        }

        $out[] = $text;
    }

    return implode('', $out);
}

function obfusx_include_decrypted_code(string $code): void
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'obfusx_exec_');
    if ($tmpFile === false) {
        throw new RuntimeException('Unable to create temporary runtime file.');
    }

    $wrapped = obfusx_has_php_tag($code) ? $code : "<?php\n" . $code;
    if (file_put_contents($tmpFile, $wrapped) === false) {
        @unlink($tmpFile);
        throw new RuntimeException('Unable to write temporary runtime file.');
    }

    try {
        include $tmpFile;
    } finally {
        @unlink($tmpFile);
    }
}

/**
 * Reliably determine whether the source already opens a PHP block.
 *
 * Uses the tokenizer rather than substring matching so a literal "<?" inside a
 * string or comment is not mistaken for an actual open tag.
 */
function obfusx_has_php_tag(string $code): bool
{
    foreach (token_get_all($code) as $token) {
        if (is_array($token) && ($token[0] === T_OPEN_TAG || $token[0] === T_OPEN_TAG_WITH_ECHO)) {
            return true;
        }
    }

    return false;
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

    obfusx_execute_payload($payload, $licensePath, $encodedFile);
}
