#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$output = $root . '/obfusx.phar';
$alias = 'obfusx.phar';

if (!extension_loaded('phar')) {
    fwrite(STDERR, "The phar extension is required to build obfusx.phar.\n");
    exit(1);
}

@ini_set('phar.readonly', '0');
if ((string) ini_get('phar.readonly') !== '0') {
    fwrite(STDERR, "Phar creation is disabled (phar.readonly=1). Re-run with phar.readonly=0, for example: php -d phar.readonly=0 build/phar.php\n");
    exit(1);
}

if (file_exists($output) && !unlink($output)) {
    fwrite(STDERR, "Unable to remove existing obfusx.phar.\n");
    exit(1);
}

$phar = new Phar($output, 0, $alias);
$phar->startBuffering();
$phar->addFile($root . '/bin/obfusx', 'bin/obfusx');
$phar->addFile($root . '/runtime/loader.php', 'runtime/loader.php');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $localName = substr($file->getPathname(), strlen($root) + 1);
    $phar->addFile($file->getPathname(), $localName);
}

$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->setStub(<<<'PHP'
#!/usr/bin/env php
<?php
Phar::interceptFileFuncs();
Phar::mapPhar('obfusx.phar');
require 'phar://obfusx.phar/src/bootstrap.php';
require 'phar://obfusx.phar/bin/obfusx';
__HALT_COMPILER();
PHP);
$phar->stopBuffering();

chmod($output, 0755);

echo "Built {$output}\n";
