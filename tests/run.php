<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../runtime/loader.php';

use ObfusX\Crypto;
use ObfusX\DirectoryEncoder;
use ObfusX\Encoder;
use ObfusX\LicenseManager;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function setEnvVar(string $name, ?string $value): void
{
    putenv($value === null ? $name : $name . '=' . $value);
}

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create directory: ' . $directory);
    }
}

function writeFile(string $path, string $contents): void
{
    ensureDirectory(dirname($path));
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Failed to write file: ' . $path);
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
            continue;
        }

        @unlink($item->getPathname());
    }

    @rmdir($path);
}

/**
 * @param array<int,string> $parts
 * @param array<string,string> $env
 */
function runCommand(array $parts, array $env = []): string
{
    $envPrefix = '';
    foreach ($env as $name => $value) {
        $envPrefix .= $name . '=' . escapeshellarg($value) . ' ';
    }

    $command = $envPrefix . implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $parts));
    $output = shell_exec($command);
    if (!is_string($output)) {
        throw new RuntimeException('Command failed: ' . $command);
    }

    return $output;
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
$emptyRevocationList = json_encode([], JSON_THROW_ON_ERROR);
assertTrue(is_string($emptyRevocationList), 'Empty revocation list JSON should encode');
$emptyRevocationFile = __DIR__ . '/.runtime/revocations-empty.json';
writeFile($emptyRevocationFile, $emptyRevocationList);
assertTrue(!LicenseManager::isRevoked($license, $emptyRevocationFile), 'Empty revocation list should not revoke the license');

$tmpPrefix = 'obfusx_test_' . getmypid() . '_' . str_replace('.', '', uniqid('', true));
$workspaceRoot = __DIR__ . '/.runtime/' . $tmpPrefix;
ensureDirectory($workspaceRoot);

try {
    $tmpIn = $workspaceRoot . '/in.php';
    $tmpOut = $workspaceRoot . '/out.obx';
    $tmpMixedIn = $workspaceRoot . '/mixed.php';
    $tmpMixedOut = $workspaceRoot . '/mixed.obx';
    $tmpInvalidIn = $workspaceRoot . '/invalid.txt';
    $tmpAdvancedIn = $workspaceRoot . '/advanced.php';
    $tmpAdvancedOut = $workspaceRoot . '/advanced.obx';
    $tmpCompatIn = $workspaceRoot . '/compat.php';
    $tmpCompatOut = $workspaceRoot . '/compat.obx';
    $composerLock = __DIR__ . '/../composer.lock';

    writeFile($tmpIn, "<?php\n\n\$v = 'hello';\necho \$v;\n");
    Encoder::encodeFile($tmpIn, $tmpOut, $masterKey);
    assertTrue(is_file($tmpOut), 'Encoded file was not created');
    $encodedPayload = json_decode((string) file_get_contents($tmpOut), true, 512, JSON_THROW_ON_ERROR);
    assertTrue(is_array($encodedPayload), 'Encoded payload decode failed');
    try {
        $decodedSource = Crypto::decrypt($encodedPayload, $masterKey);
    } catch (Throwable $e) {
        throw new RuntimeException('Encoded payload should decrypt successfully.', 0, $e);
    }
    assertTrue($decodedSource !== '', 'Decoded source should not be empty');
    assertTrue(
        str_contains($decodedSource, 'Protected by ObfusX'),
        'Obfuscated source should include protection notice'
    );

    putenv('OBFUSX_MASTER_KEY=' . $masterKey);
    putenv('OBFUSX_ALLOW_DEBUG=1');
    ob_start();
    obfusx_execute_file($tmpOut);
    $output = trim((string) ob_get_clean());
    assertTrue($output === 'hello', 'Runtime execution failed');

    $signedOut = $workspaceRoot . '/signed.obx';
    $tamperedOut = $workspaceRoot . '/tampered.obx';
    $unsignedOut = $workspaceRoot . '/unsigned.obx';

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
    writeFile($tamperedOut, json_encode($tamperedPayload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $tamperedFailed = false;
    try {
        obfusx_execute_file($tamperedOut);
    } catch (RuntimeException $e) {
        $tamperedFailed = str_contains($e->getMessage(), 'signature');
    }
    assertTrue($tamperedFailed, 'Tampered signed payload should fail signature verification');

    $unsignedPayload = $signedPayload;
    unset($unsignedPayload['signature']);
    writeFile($unsignedOut, json_encode($unsignedPayload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

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
    assertTrue(($describe['branding'] ?? null) === '', 'Unsigned payload should report empty branding by default');
    assertTrue(is_array($describe['meta'] ?? null), 'describeFile should expose meta array');
    assertTrue(!isset($describe['ciphertext']), 'describeFile must not expose ciphertext');

    setEnvVar('OBFUSX_BRANDING', 'Powered by AcmePHP');
    $brandedOut = $workspaceRoot . '/branded.obx';
    Encoder::encodeFile($tmpIn, $brandedOut, $masterKey);
    setEnvVar('OBFUSX_BRANDING', null);
    $brandedPayload = json_decode((string) file_get_contents($brandedOut), true, 512, JSON_THROW_ON_ERROR);
    assertTrue(is_array($brandedPayload), 'Branded payload decode failed');
    assertTrue(($brandedPayload['branding'] ?? null) === 'Powered by AcmePHP', 'Branding should be stored in payload');
    $brandedDescribe = Encoder::describeFile($brandedOut);
    assertTrue(($brandedDescribe['branding'] ?? null) === 'Powered by AcmePHP', 'describeFile should expose branding metadata');

    $signedDescribe = Encoder::describeFile($signedOut);
    assertTrue(($signedDescribe['signed'] ?? null) === true, 'Signed payload should report signed=true');

    // Phase 2: reject input that contains no PHP code.
    $htmlIn = $workspaceRoot . '/nophp.html';
    $htmlOut = $workspaceRoot . '/nophp.obx';
    writeFile($htmlIn, "<html><body>just markup</body></html>\n");
    $nonPhpRejected = false;
    try {
        Encoder::encodeFile($htmlIn, $htmlOut, $masterKey);
    } catch (RuntimeException $e) {
        $nonPhpRejected = str_contains($e->getMessage(), 'does not contain any PHP');
    }
    assertTrue($nonPhpRejected, 'Input without PHP code should be rejected');

    // Phase 2: multi-block sources (inline HTML + multiple <?php tags).
    $multiIn = $workspaceRoot . '/multi.php';
    $multiOut = $workspaceRoot . '/multi.obx';
    writeFile($multiIn, "<?php \$name = 'World'; ?>\n<p>Hello <?php echo \$name; ?></p>\n");
    Encoder::encodeFile($multiIn, $multiOut, $masterKey);
    ob_start();
    obfusx_execute_file($multiOut);
    $multiOutput = trim((string) ob_get_clean());
    assertTrue($multiOutput === '<p>Hello World</p>', 'Multi-block runtime execution failed: ' . $multiOutput);

    // Phase 2: configurable HKDF info / key-rotation identifier.
    putenv('OBFUSX_KEY_INFO=tenant-2026');
    $rotatedOut = $workspaceRoot . '/rotated.obx';
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
    $badInfoOut = $workspaceRoot . '/badinfo.obx';
    writeFile($badInfoOut, json_encode($badInfoPayload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $badInfoFailed = false;
    try {
        obfusx_execute_file($badInfoOut);
    } catch (RuntimeException $e) {
        $badInfoFailed = str_contains($e->getMessage(), 'Decryption');
    }
    assertTrue($badInfoFailed, 'Mismatched key-rotation info should fail decryption');

    // Phase 4: optional payload compression before encryption.
    putenv('OBFUSX_COMPRESS=1');
    $compressedOut = $workspaceRoot . '/compressed.obx';
    Encoder::encodeFile($tmpIn, $compressedOut, $masterKey);
    putenv('OBFUSX_COMPRESS');
    $compressedDescribe = Encoder::describeFile($compressedOut);
    assertTrue(($compressedDescribe['compression'] ?? null) === 'gzip', 'describeFile should report gzip compression');
    ob_start();
    obfusx_execute_file($compressedOut);
    $compressedOutput = trim((string) ob_get_clean());
    assertTrue($compressedOutput === 'hello', 'Compressed runtime execution failed');

    // Laravel-style entrypoints should resolve paths relative to the encoded .obx file.
    $laravelRoot = $workspaceRoot . '/laravel';
    $laravelPublic = $laravelRoot . '/public';
    $laravelBootstrap = $laravelRoot . '/bootstrap';
    $laravelVendor = $laravelRoot . '/vendor';
    ensureDirectory($laravelPublic);
    ensureDirectory($laravelBootstrap);
    ensureDirectory($laravelVendor);

    $laravelAutoload = $laravelVendor . '/autoload.php';
    $laravelApp = $laravelBootstrap . '/app.php';
    $laravelIndex = $laravelPublic . '/index.php';
    $laravelOut = $laravelPublic . '/index.obx';

    writeFile($laravelAutoload, <<<'PHP2'
<?php
function laravel_test_message(): string
{
    return 'laravel';
}
PHP2);

    writeFile($laravelApp, <<<'PHP2'
<?php
return new class {
    public function handleRequest(string $message): void
    {
        echo $message . '|app=' . dirname(__DIR__);
    }
};
PHP2);

    writeFile($laravelIndex, <<<'PHP2'
<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->handleRequest(laravel_test_message() . '|public=' . __DIR__ . '|file=' . basename(__FILE__));
PHP2);

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

    $cliPath = __DIR__ . '/../bin/obfusx';
    $aboutOutput = runCommand([PHP_BINARY, $cliPath, 'about']);
    assertTrue(str_contains($aboutOutput, 'ObfusX v' . ObfusX\Version::current()), 'about command should include version');
    assertTrue(str_contains($aboutOutput, 'ionCube/SourceGuardian'), 'about command should mention ionCube/SourceGuardian style');
    assertTrue(str_contains($aboutOutput, 'encode-dir'), 'about command should list encode-dir');

    $helpOutput = runCommand([PHP_BINARY, $cliPath, 'help']);
    assertTrue(str_contains($helpOutput, 'about'), 'help output should include about command');
    assertTrue(str_contains($helpOutput, 'encode-dir'), 'help output should include encode-dir');

    $cliLicenseOut = $workspaceRoot . '/cli-license.json';
    $cliExpires = '2030-01-01T00:00:00Z';
    $cliLicenseOutput = runCommand(
        [
            PHP_BINARY,
            $cliPath,
            'make-license',
            '--out=' . $cliLicenseOut,
            '--expires=' . $cliExpires,
            '--licensee=Acme Corp',
            '--max-machines=5',
        ],
        ['OBFUSX_LICENSE_KEY' => $licenseKey]
    );
    $cliLicenseData = json_decode((string) file_get_contents($cliLicenseOut), true, 512, JSON_THROW_ON_ERROR);
    assertTrue(is_array($cliLicenseData), 'make-license output should decode');
    assertTrue(($cliLicenseData['payload']['licensee'] ?? null) === 'Acme Corp', 'make-license should store the licensee in the payload');
    assertTrue(($cliLicenseData['payload']['max_machines'] ?? null) === 5, 'make-license should store max_machines in the payload');
    assertTrue(str_contains($cliLicenseOutput, 'License created: ' . $cliLicenseOut), 'make-license should report the output path');
    assertTrue(
        str_contains($cliLicenseOutput, 'Expires: ' . date('D, d M Y H:i:s T', strtotime($cliExpires))),
        'make-license should print a human-readable expiry timestamp'
    );

    $batchSource = $workspaceRoot . '/batch-src';
    $batchDist = $workspaceRoot . '/batch-dist';
    writeFile($batchSource . '/Foo.php', "<?php echo 'foo';\n");
    writeFile($batchSource . '/Nested/Bar.php', "<?php echo 'bar';\n");
    writeFile($batchSource . '/assets/logo.txt', "logo\n");

    $encodeDirOutput = runCommand(
        [
            PHP_BINARY,
            $cliPath,
            'encode-dir',
            '--in=' . $batchSource,
            '--out=' . $batchDist,
        ],
        ['OBFUSX_MASTER_KEY' => $masterKey]
    );
    assertTrue(str_contains($encodeDirOutput, 'Encoded 2 files, copied 1 files.'), 'encode-dir should report encoded/copied summary');
    $batchRoot = $batchDist . '/' . basename($batchSource);
    assertTrue(is_file($batchRoot . '/Foo.obx'), 'encode-dir should encode top-level PHP files');
    assertTrue(is_file($batchRoot . '/Nested/Bar.obx'), 'encode-dir should encode nested PHP files');
    assertTrue(is_file($batchRoot . '/assets/logo.txt'), 'encode-dir should copy non-PHP assets');
    assertTrue((string) file_get_contents($batchRoot . '/assets/logo.txt') === "logo\n", 'Copied asset contents should be preserved');

    $cacheSource = $workspaceRoot . '/cache-src';
    $cacheDist = $workspaceRoot . '/cache-dist';
    $cacheFile = $workspaceRoot . '/cache/cache.json';
    writeFile($cacheSource . '/One.php', "<?php echo 'one';\n");
    writeFile($cacheSource . '/note.txt', "note\n");
    setEnvVar('OBFUSX_CACHE_FILE', $cacheFile);
    $firstCacheRun = DirectoryEncoder::encode($cacheSource, $cacheDist, $masterKey);
    $secondCacheRun = DirectoryEncoder::encode($cacheSource, $cacheDist, $masterKey);
    setEnvVar('OBFUSX_CACHE_FILE', null);
    assertTrue($firstCacheRun['encoded'] === 1 && $firstCacheRun['copied'] === 1, 'Initial cache run should process all files');
    assertTrue($secondCacheRun['encoded'] === 0 && $secondCacheRun['copied'] === 0, 'Incremental cache run should avoid reprocessing unchanged files');
    assertTrue(($secondCacheRun['skipped'] ?? null) === 2, 'Incremental cache run should skip unchanged files');
    $cachePayload = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
    assertTrue(is_array($cachePayload) && count($cachePayload) === 2, 'Cache file should track checksums for all source files');

    $ignoreSource = $workspaceRoot . '/ignore-src';
    $ignoreDist = $workspaceRoot . '/ignore-dist';
    writeFile($ignoreSource . '/Keep.php', "<?php echo 'keep';\n");
    writeFile($ignoreSource . '/Skip.php', "<?php echo 'skip';\n");
    writeFile($ignoreSource . '/private/Secret.php', "<?php echo 'secret';\n");
    writeFile($ignoreSource . '/README.md', "ignore me\n");
    writeFile($ignoreSource . '/.obxignore', "# Ignore private code\nSkip.php\nprivate/\n*.md\n");

    $ignoreOutput = runCommand(
        [
            PHP_BINARY,
            $cliPath,
            'encode-dir',
            '--in=' . $ignoreSource,
            '--out=' . $ignoreDist,
        ],
        ['OBFUSX_MASTER_KEY' => $masterKey]
    );
    assertTrue(str_contains($ignoreOutput, 'Encoded 1 files, copied 1 files.'), '.obxignore should reduce the encoded/copied totals');
    $ignoreRoot = $ignoreDist . '/' . basename($ignoreSource);
    assertTrue(is_file($ignoreRoot . '/Keep.obx'), '.obxignore should keep unignored PHP files');
    assertTrue(!is_file($ignoreRoot . '/Skip.obx'), '.obxignore should exclude matching files');
    assertTrue(!is_file($ignoreRoot . '/private/Secret.obx'), '.obxignore should exclude matching directories');
    assertTrue(!is_file($ignoreRoot . '/README.md'), '.obxignore should exclude matching assets');
    assertTrue(is_file($ignoreRoot . '/.obxignore'), 'Non-PHP files not matched by ignore rules should still be copied');

    writeFile($tmpMixedIn, <<<'PHP2'
<section>
<?php
$message = 'hello';
echo strtoupper($message);
?>
<footer>
<?php echo 'done'; ?>
</footer>
PHP2);
    Encoder::encodeFile($tmpMixedIn, $tmpMixedOut, $masterKey);
    ob_start();
    obfusx_execute_file($tmpMixedOut);
    $mixedOutput = trim(preg_replace('/\s+/', ' ', (string) ob_get_clean()) ?? '');
    assertTrue($mixedOutput === '<section> HELLO<footer> done</footer>', 'Mixed PHP/HTML source should execute correctly');

    writeFile($tmpInvalidIn, "plain text only\n");
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

    // Phase 8: offline grace-period cache permits temporary remote validation failures.
    $savedLicenseUrl = getenv('OBFUSX_LICENSE_URL');
    $savedGraceDays = getenv('OBFUSX_LICENSE_GRACE_DAYS');
    $savedLicenseCache = getenv('OBFUSX_LICENSE_CACHE');
    $savedLicenseKey = getenv('OBFUSX_LICENSE_KEY');
    $savedRevocationUrl = getenv('OBFUSX_REVOCATION_URL');
    $savedAntiDebugChecks = getenv('OBFUSX_ANTIDEBUG_CHECKS');
    setEnvVar('OBFUSX_ALLOW_DEBUG', null);
    setEnvVar('OBFUSX_ANTIDEBUG_CHECKS', 'none');
    setEnvVar('OBFUSX_LICENSE_URL', 'http://127.0.0.1:9/license');
    setEnvVar('OBFUSX_LICENSE_GRACE_DAYS', '1');
    setEnvVar('OBFUSX_LICENSE_CACHE', $workspaceRoot . '/license-cache.json');
    setEnvVar('OBFUSX_LICENSE_KEY', $licenseKey);
    setEnvVar('OBFUSX_REVOCATION_URL', null);
    writeFile(
        $workspaceRoot . '/license-cache.json',
        json_encode([
            'last_valid' => time(),
            'hw' => LicenseManager::hardwareFingerprint(),
        ], JSON_THROW_ON_ERROR)
    );
    ob_start();
    obfusx_execute_file($tmpOut);
    $graceOutput = trim((string) ob_get_clean());
    assertTrue($graceOutput === 'hello', 'Fresh license cache should allow offline grace-period execution');
    setEnvVar('OBFUSX_LICENSE_URL', $savedLicenseUrl === false ? null : $savedLicenseUrl);
    setEnvVar('OBFUSX_LICENSE_GRACE_DAYS', $savedGraceDays === false ? null : $savedGraceDays);
    setEnvVar('OBFUSX_LICENSE_CACHE', $savedLicenseCache === false ? null : $savedLicenseCache);
    setEnvVar('OBFUSX_LICENSE_KEY', $savedLicenseKey === false ? null : $savedLicenseKey);
    setEnvVar('OBFUSX_REVOCATION_URL', $savedRevocationUrl === false ? null : $savedRevocationUrl);
    setEnvVar('OBFUSX_ANTIDEBUG_CHECKS', $savedAntiDebugChecks === false ? null : $savedAntiDebugChecks);
    setEnvVar('OBFUSX_ALLOW_DEBUG', $savedAllowDebug === false ? null : (string) $savedAllowDebug);

    // Phase 6: optional string-array encoding, control-flow flattening, and junk injection.
    $advancedFeatureEnv = [
        'OBFUSX_STRARRAY' => getenv('OBFUSX_STRARRAY'),
        'OBFUSX_FLATTEN' => getenv('OBFUSX_FLATTEN'),
        'OBFUSX_JUNK' => getenv('OBFUSX_JUNK'),
    ];
    writeFile($tmpAdvancedIn, <<<'PHP2'
<?php
$greeting = 'hello';
$subject = 'world';
echo $greeting . ' ' . $subject;
PHP2);
    setEnvVar('OBFUSX_STRARRAY', '1');
    setEnvVar('OBFUSX_FLATTEN', '1');
    setEnvVar('OBFUSX_JUNK', '1');
    Encoder::encodeFile($tmpAdvancedIn, $tmpAdvancedOut, $masterKey);
    foreach ($advancedFeatureEnv as $name => $value) {
        setEnvVar($name, $value === false ? null : (string) $value);
    }
    $advancedDescribe = Encoder::describeFile($tmpAdvancedOut);
    assertTrue(($advancedDescribe['meta']['strarray'] ?? null) === true, 'Advanced encode should record strarray metadata');
    assertTrue(($advancedDescribe['meta']['flatten'] ?? null) === true, 'Advanced encode should record flatten metadata');
    $advancedPayload = json_decode((string) file_get_contents($tmpAdvancedOut), true, 512, JSON_THROW_ON_ERROR);
    assertTrue(is_array($advancedPayload), 'Advanced payload decode failed');
    $advancedSource = Crypto::decrypt($advancedPayload, $masterKey);
    assertTrue(str_contains($advancedSource, '$__obfx_sa = array_map(\'base64_decode\''), 'String-array declaration should be hoisted to the top of the PHP block');
    assertTrue(!str_contains($advancedSource, 'obfusx_s('), 'String-array mode should replace per-call obfusx_s() wrappers');
    assertTrue(str_contains($advancedSource, 'while (true) {') && str_contains($advancedSource, 'switch ($__obfx_st_'), 'Flattened source should contain the dispatcher loop');
    assertTrue(str_contains($advancedSource, 'sha1(uniqid('), 'Junk mode should inject dead code blocks');
    ob_start();
    obfusx_execute_file($tmpAdvancedOut);
    $advancedOutput = trim((string) ob_get_clean());
    assertTrue($advancedOutput === 'hello world', 'Advanced obfuscation runtime execution failed');

    // Phase 6: PHP 8.2/8.3 syntax compatibility (readonly class, enums).
    // readonly class was introduced in PHP 8.2; skip on earlier runtimes.
    if (PHP_VERSION_ID >= 80200) {
        writeFile($tmpCompatIn, <<<'PHP2'
<?php
readonly class ReadonlyValue
{
    public function __construct(public string $value)
    {
    }
}

enum DeliveryState: string
{
    case READY = 'ready';
}

final class TypedConstantBag
{
    public const LABEL = 'typed';
}

new ReadonlyValue(DeliveryState::READY->value);
echo DeliveryState::READY->value . '|' . TypedConstantBag::LABEL;
PHP2);
        Encoder::encodeFile($tmpCompatIn, $tmpCompatOut, $masterKey);
        ob_start();
        obfusx_execute_file($tmpCompatOut);
        $compatOutput = trim((string) ob_get_clean());
        assertTrue($compatOutput === 'ready|typed', 'PHP 8.2/8.3 compatibility execution failed: ' . $compatOutput);
    }
} finally {
    removeTree($workspaceRoot);
    removeTree(__DIR__ . '/.runtime');
}

echo "All tests passed\n";
