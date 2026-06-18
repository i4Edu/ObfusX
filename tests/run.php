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

assertTrue(ObfusX\Version::current() !== '', 'Version must not be empty');

@unlink($tmpIn);
@unlink($tmpOut);
@unlink($signedOut);
@unlink($tamperedOut);
@unlink($unsignedOut);

echo "All tests passed\n";
