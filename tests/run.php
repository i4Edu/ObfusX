<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../runtime/loader.php';

use ObfusX\Crypto;
use ObfusX\Encoder;
use ObfusX\LicenseManager;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$masterKey = 'test_master_key_32_bytes_value__';
$payload = Crypto::encrypt('<?php echo "ok";', $masterKey);
$plain = Crypto::decrypt($payload, $masterKey);
assertTrue($plain === '<?php echo "ok";', 'Crypto roundtrip failed');

$licenseKey = 'signing_key';
$license = LicenseManager::create([
    'domain' => 'localhost',
    'ip' => '127.0.0.1',
    'expires_at' => gmdate('c', time() + 3600),
    'hardware_fingerprint' => 'abc',
], $licenseKey);
LicenseManager::validateFromString($license, [
    'domain' => 'localhost',
    'ip' => '127.0.0.1',
    'hardware_fingerprint' => 'abc',
], $licenseKey);

$tmpPrefix = 'obfusx_test_' . getmypid() . '_' . str_replace('.', '', uniqid('', true));
$tmpIn = sys_get_temp_dir() . '/' . $tmpPrefix . '_in.php';
$tmpOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_out.obx';
$tmpMixedIn = sys_get_temp_dir() . '/' . $tmpPrefix . '_mixed.php';
$tmpMixedOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_mixed.obx';
$tmpInvalidIn = sys_get_temp_dir() . '/' . $tmpPrefix . '_invalid.txt';
$composerLock = __DIR__ . '/../composer.lock';
file_put_contents($tmpIn, "<?php\n\n\$v = 'hello';\necho \$v;\n");
Encoder::encodeFile($tmpIn, $tmpOut, $masterKey);
assertTrue(is_file($tmpOut), 'Encoded file was not created');

putenv('OBFUSX_MASTER_KEY=' . $masterKey);
putenv('OBFUSX_ALLOW_DEBUG=1');
ob_start();
obfusx_execute_file($tmpOut);
$output = trim((string) ob_get_clean());
assertTrue($output === 'hello', 'Runtime execution failed');

$signedOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_signed.obx';
$tamperedOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_tampered.obx';
$unsignedOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_unsigned.obx';

putenv('OBFUSX_SIGNING_KEY=test_payload_signing_key');
Encoder::encodeFile($tmpIn, $signedOut, $masterKey);
ob_start();
obfusx_execute_file($signedOut);
$signedOutput = trim((string) ob_get_clean());
assertTrue($signedOutput === 'hello', 'Signed runtime execution failed');

$signedPayload = json_decode((string) file_get_contents($signedOut), true, 512, JSON_THROW_ON_ERROR);
assertTrue(is_array($signedPayload), 'Signed payload decode failed');

$tamperedPayload = $signedPayload;
$tamperedPayload['ciphertext'] = (string) ($tamperedPayload['ciphertext'] ?? '') . 'x';
file_put_contents($tamperedOut, json_encode($tamperedPayload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$tamperedFailed = false;
try {
    obfusx_execute_file($tamperedOut);
} catch (RuntimeException $e) {
    $tamperedFailed = str_contains($e->getMessage(), 'signature');
}
assertTrue($tamperedFailed, 'Tampered signed payload should fail signature verification');

$unsignedPayload = $signedPayload;
unset($unsignedPayload['signature']);
file_put_contents($unsignedOut, json_encode($unsignedPayload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$missingSignatureFailed = false;
try {
    obfusx_execute_file($unsignedOut);
} catch (RuntimeException $e) {
    $missingSignatureFailed = str_contains($e->getMessage(), 'signature');
}
assertTrue($missingSignatureFailed, 'Missing signature should fail when OBFUSX_SIGNING_KEY is set');

putenv('OBFUSX_SIGNING_KEY');

$describe = Encoder::describeFile($tmpOut);
assertTrue(($describe['alg'] ?? null) === 'AES-256-GCM', 'describeFile should report algorithm');
assertTrue(($describe['signed'] ?? null) === false, 'Unsigned payload should report signed=false');
assertTrue(is_array($describe['meta'] ?? null), 'describeFile should expose meta array');
assertTrue(!isset($describe['ciphertext']), 'describeFile must not expose ciphertext');

$signedDescribe = Encoder::describeFile($signedOut);
assertTrue(($signedDescribe['signed'] ?? null) === true, 'Signed payload should report signed=true');

// Phase 2: reject input that contains no PHP code.
$htmlIn = sys_get_temp_dir() . '/' . $tmpPrefix . '_nophp.html';
$htmlOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_nophp.obx';
file_put_contents($htmlIn, "<html><body>just markup</body></html>\n");
$nonPhpRejected = false;
try {
    Encoder::encodeFile($htmlIn, $htmlOut, $masterKey);
} catch (RuntimeException $e) {
    $nonPhpRejected = str_contains($e->getMessage(), 'does not contain any PHP');
}
assertTrue($nonPhpRejected, 'Input without PHP code should be rejected');

// Phase 2: multi-block sources (inline HTML + multiple <?php tags).
$multiIn = sys_get_temp_dir() . '/' . $tmpPrefix . '_multi.php';
$multiOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_multi.obx';
file_put_contents($multiIn, "<?php \$name = 'World'; ?>\n<p>Hello <?php echo \$name; ?></p>\n");
Encoder::encodeFile($multiIn, $multiOut, $masterKey);
ob_start();
obfusx_execute_file($multiOut);
$multiOutput = trim((string) ob_get_clean());
assertTrue($multiOutput === '<p>Hello World</p>', 'Multi-block runtime execution failed: ' . $multiOutput);

// Phase 2: configurable HKDF info / key-rotation identifier.
putenv('OBFUSX_KEY_INFO=tenant-2026');
$rotatedOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_rotated.obx';
Encoder::encodeFile($tmpIn, $rotatedOut, $masterKey);
putenv('OBFUSX_KEY_INFO');
$rotatedDescribe = Encoder::describeFile($rotatedOut);
assertTrue(($rotatedDescribe['info'] ?? null) === 'tenant-2026', 'describeFile should report key-rotation info');
ob_start();
obfusx_execute_file($rotatedOut);
$rotatedOutput = trim((string) ob_get_clean());
assertTrue($rotatedOutput === 'hello', 'Key-rotation runtime execution failed');

$rotatedPayload = json_decode((string) file_get_contents($rotatedOut), true, 512, JSON_THROW_ON_ERROR);
assertTrue(is_array($rotatedPayload), 'Rotated payload decode failed');
$badInfoPayload = $rotatedPayload;
$badInfoPayload['info'] = 'wrong-info';
$badInfoOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_badinfo.obx';
file_put_contents($badInfoOut, json_encode($badInfoPayload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$badInfoFailed = false;
try {
    obfusx_execute_file($badInfoOut);
} catch (RuntimeException $e) {
    $badInfoFailed = str_contains($e->getMessage(), 'Decryption');
}
assertTrue($badInfoFailed, 'Mismatched key-rotation info should fail decryption');

// Phase 4: optional payload compression before encryption.
putenv('OBFUSX_COMPRESS=1');
$compressedOut = sys_get_temp_dir() . '/' . $tmpPrefix . '_compressed.obx';
Encoder::encodeFile($tmpIn, $compressedOut, $masterKey);
putenv('OBFUSX_COMPRESS');
$compressedDescribe = Encoder::describeFile($compressedOut);
assertTrue(($compressedDescribe['compression'] ?? null) === 'gzip', 'describeFile should report gzip compression');
ob_start();
obfusx_execute_file($compressedOut);
$compressedOutput = trim((string) ob_get_clean());
assertTrue($compressedOutput === 'hello', 'Compressed runtime execution failed');

// Laravel-style entrypoints should resolve paths relative to the encoded .obx file.
$laravelRoot = sys_get_temp_dir() . '/' . $tmpPrefix . '_laravel';
$laravelPublic = $laravelRoot . '/public';
$laravelBootstrap = $laravelRoot . '/bootstrap';
$laravelVendor = $laravelRoot . '/vendor';
assertTrue(@mkdir($laravelPublic, 0777, true) || is_dir($laravelPublic), 'Failed to create Laravel public directory');
assertTrue(@mkdir($laravelBootstrap, 0777, true) || is_dir($laravelBootstrap), 'Failed to create Laravel bootstrap directory');
assertTrue(@mkdir($laravelVendor, 0777, true) || is_dir($laravelVendor), 'Failed to create Laravel vendor directory');

$laravelAutoload = $laravelVendor . '/autoload.php';
$laravelApp = $laravelBootstrap . '/app.php';
$laravelIndex = $laravelPublic . '/index.php';
$laravelOut = $laravelPublic . '/index.obx';

file_put_contents($laravelAutoload, <<<'PHP'
<?php
function laravel_test_message(): string
{
    return 'laravel';
}
PHP);

file_put_contents($laravelApp, <<<'PHP'
<?php
return new class {
    public function handleRequest(string $message): void
    {
        echo $message . '|app=' . dirname(__DIR__);
    }
};
PHP);

file_put_contents($laravelIndex, <<<'PHP'
<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->handleRequest(laravel_test_message() . '|public=' . __DIR__ . '|file=' . basename(__FILE__));
PHP);

Encoder::encodeFile($laravelIndex, $laravelOut, $masterKey);
ob_start();
obfusx_execute_file($laravelOut);
$laravelOutput = trim((string) ob_get_clean());
assertTrue(
    $laravelOutput === 'laravel|public=' . $laravelPublic . '|file=index.obx|app=' . $laravelRoot,
    'Laravel-style entrypoint should preserve encoded file paths: ' . $laravelOutput
);

assertTrue(ObfusX\Version::current() !== '', 'Version must not be empty');
assertTrue(is_file(__DIR__ . '/../composer.json'), 'Composer metadata should exist');
assertTrue(is_file($composerLock), 'Composer lockfile should exist after dependency installation');

file_put_contents($tmpMixedIn, <<<'PHP'
<section>
<?php
$message = 'hello';
echo strtoupper($message);
?>
<footer>
<?php echo 'done'; ?>
</footer>
PHP);
Encoder::encodeFile($tmpMixedIn, $tmpMixedOut, $masterKey);
ob_start();
obfusx_execute_file($tmpMixedOut);
$mixedOutput = trim(preg_replace('/\s+/', ' ', (string) ob_get_clean()) ?? '');
assertTrue($mixedOutput === '<section> HELLO<footer> done</footer>', 'Mixed PHP/HTML source should execute correctly');

file_put_contents($tmpInvalidIn, "plain text only\n");
$invalidFailed = false;
try {
    Encoder::encodeFile($tmpInvalidIn, $tmpOut, $masterKey);
} catch (RuntimeException $e) {
    $invalidFailed = str_contains($e->getMessage(), 'contain PHP code');
}
assertTrue($invalidFailed, 'Non-PHP input should fail with a clear validation error');

// Phase 4: anti-debug checks are configurable.
$savedAllowDebug = getenv('OBFUSX_ALLOW_DEBUG');
putenv('OBFUSX_ALLOW_DEBUG');
putenv('OBFUSX_ANTIDEBUG_CHECKS=none');
$disabledChecksThrew = false;
try {
    obfusx_anti_debug();
} catch (RuntimeException $e) {
    $disabledChecksThrew = true;
}
assertTrue(!$disabledChecksThrew, 'Anti-debug "none" should disable all checks');

if (extension_loaded('xdebug')) {
    putenv('OBFUSX_ANTIDEBUG_CHECKS=xdebug');
    $xdebugDetected = false;
    try {
        obfusx_anti_debug();
    } catch (RuntimeException $e) {
        $xdebugDetected = true;
    }
    assertTrue($xdebugDetected, 'Anti-debug should detect a loaded xdebug extension when configured');
}
putenv('OBFUSX_ANTIDEBUG_CHECKS');
putenv('OBFUSX_ALLOW_DEBUG=' . ($savedAllowDebug === false ? '' : $savedAllowDebug));

@unlink($tmpIn);
@unlink($tmpOut);
@unlink($tmpMixedIn);
@unlink($tmpMixedOut);
@unlink($tmpInvalidIn);
@unlink($signedOut);
@unlink($tamperedOut);
@unlink($unsignedOut);
@unlink($htmlIn);
@unlink($multiIn);
@unlink($multiOut);
@unlink($rotatedOut);
@unlink($badInfoOut);
@unlink($compressedOut);
@unlink($laravelOut);
@unlink($laravelIndex);
@unlink($laravelApp);
@unlink($laravelAutoload);
@rmdir($laravelPublic);
@rmdir($laravelBootstrap);
@rmdir($laravelVendor);
@rmdir($laravelRoot);

echo "All tests passed\n";
