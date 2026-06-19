# Windows compatibility

ObfusX runs on Windows in two supported ways:

- **WSL2 (recommended)** for the closest Linux-like development and runtime environment.
- **Native PHP for Windows** when you need to run the CLI directly from PowerShell or Command Prompt.

## Option 1: WSL2 (recommended)

WSL2 gives ObfusX the same behavior you get on Linux and in CI.

1. Install WSL2 and an Ubuntu distribution.
2. Install PHP 8.1+ inside WSL2 (PHP 8.3 recommended).
3. Make sure the `openssl` and `zlib` extensions are enabled.
4. Install Composer.
5. Clone the repository or unpack the release archive inside your WSL filesystem.

Example:

```bash
sudo apt update
sudo apt install -y php-cli php-openssl php-zip php-mbstring composer
cd /path/to/ObfusX
composer install
export OBFUSX_MASTER_KEY='replace-with-a-strong-secret'
php bin/obfusx version
```

You can also run the packaged Phar:

```bash
php obfusx.phar version
```

### WSL2 notes

- Prefer storing projects under the Linux filesystem (for example, `~/src/ObfusX`) for better I/O performance.
- Use normal POSIX-style paths such as `/home/you/project/examples/plain.php`.
- If you generate licenses bound to host properties, generate and validate them in the same environment where the protected code will run.

## Option 2: Native PHP for Windows

ObfusX also works with the official PHP for Windows builds.

1. Install PHP 8.1+ for Windows (PHP 8.3 recommended).
2. Enable the `openssl` and `zlib` extensions in `php.ini`.
3. Install Composer for Windows.
4. Open PowerShell in the project directory.
5. Install dependencies and run the CLI.

PowerShell example:

```powershell
cd C:\path\to\ObfusX
composer install
$env:OBFUSX_MASTER_KEY = 'replace-with-a-strong-secret'
php .\bin\obfusx version
php .\bin\obfusx encode --in=examples\plain.php --out=examples\plain.obx
php .\bin\obfusx inspect --file=examples\plain.obx
```

If you are using a release build instead of a checkout, run the Phar directly:

```powershell
php .\obfusx.phar version
```

### Native Windows notes

- Windows paths with spaces should be quoted when passed to `--in`, `--out`, or `--file`.
- When setting environment variables in Command Prompt, use `set OBFUSX_MASTER_KEY=...` before invoking PHP.
- Protected payloads that rely on `__DIR__` and `__FILE__` continue to resolve relative to the encoded `.obx` file, so keep related application files together when moving deployments.
- Hardware fingerprints and networking metadata can differ between Windows, WSL2, and containerized environments; create licenses for the exact target environment.

## Troubleshooting

### `OBFUSX_MASTER_KEY is required at runtime`

Set the variable in the same shell session before running `encode` or `run`.

### `zlib extension is required to decode compressed payloads`

Enable `extension=zlib` in `php.ini` (native Windows) or install the zlib-enabled PHP package in WSL2.

### `Debugging extension detected`

Disable Xdebug or set `OBFUSX_ALLOW_DEBUG=1` only for local development and testing.
