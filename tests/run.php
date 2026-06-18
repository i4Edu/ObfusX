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

$tmpIn = sys_get_temp_dir() . '/obfusx_test_in.php';
$tmpOut = sys_get_temp_dir() . '/obfusx_test_out.obx';
file_put_contents($tmpIn, "<?php\n\n\$v = 'hello';\necho \$v;\n");
Encoder::encodeFile($tmpIn, $tmpOut, $masterKey);
assertTrue(is_file($tmpOut), 'Encoded file was not created');

putenv('OBFUSX_MASTER_KEY=' . $masterKey);
putenv('OBFUSX_ALLOW_DEBUG=1');
ob_start();
obfusx_execute_file($tmpOut);
$output = trim((string) ob_get_clean());
assertTrue($output === 'hello', 'Runtime execution failed');

@unlink($tmpIn);
@unlink($tmpOut);

echo "All tests passed\n";
