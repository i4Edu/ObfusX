# ObfusX

ObfusX is a PHP source-protection toolkit (ionCube/SourceGuardian-style) that provides encryption, obfuscation, runtime loading, and license checks.

## Features

- **AES-256-GCM encryption** for encoded PHP payloads.
- **Obfuscation pipeline** for identifiers and literal strings, plus opaque dummy flow.
- **Bootstrap runtime loader** that decrypts and executes in memory.
- **License controls**: domain/IP binding, expiry checks, hardware fingerprint support.
- **Developer CLI** for encode/run/license/fingerprint workflows.

## Requirements

- PHP 8.1+
- OpenSSL extension
- Composer 2 (for local tooling)

## Quick start

```bash
composer install
export OBFUSX_MASTER_KEY='your-long-secret'
./bin/obfusx encode \
  --in=./examples/plain.php \
  --out=./examples/plain.obx

OBFUSX_ALLOW_DEBUG=1 ./bin/obfusx run \
  --file=./examples/plain.obx
```

## Licensing

```bash
export OBFUSX_LICENSE_KEY='license-signing-secret'
./bin/obfusx make-license \
  --out=./examples/license.json \
  --domain=localhost \
  --ip=127.0.0.1 \
  --expires=2030-01-01T00:00:00Z \
  --hw="$(./bin/obfusx fingerprint)"
```

Then pass `--license=/path/license.json` to the `run` command.

For signed payload integrity verification, optionally set `OBFUSX_SIGNING_KEY` during both encode and run.

## Configuration

ObfusX is configured through environment variables:

| Variable | Used by | Purpose |
| --- | --- | --- |
| `OBFUSX_MASTER_KEY` | encode, run | Master secret used to derive the AES-256-GCM key. |
| `OBFUSX_SIGNING_KEY` | encode, run | Optional HMAC key for payload tamper detection. |
| `OBFUSX_LICENSE_KEY` | make-license, run | HMAC key for license signing/validation. |
| `OBFUSX_KEY_INFO` | encode | HKDF info / key-rotation identifier (default `obfusx-runtime-key`); stored in the payload so the runtime derives the matching key. |
| `OBFUSX_COMPRESS` | encode | Set to `1` to gzip-compress the payload before encryption. |
| `OBFUSX_ANTIDEBUG_CHECKS` | run | Comma-separated anti-debug checks (`xdebug,debugger,phpdbg,trace`); use `none` to disable. Defaults to all. |
| `OBFUSX_ALLOW_DEBUG` | run | Set to `1` to bypass all anti-debug checks (local testing). |

The encoder accepts multi-block sources — files mixing inline HTML with one or
more `<?php` tags are preserved and obfuscated. Files containing no PHP code at
all are rejected with a clear error.

Laravel entrypoints are supported natively: when you encode files such as
`public/index.php` or `artisan`, runtime execution preserves `__DIR__` and
`__FILE__` relative to the `.obx` file so Laravel's relative bootstrap paths
continue to work.

## Inspecting encoded files

Print non-sensitive metadata (algorithm, key-rotation identifier, compression,
signing status, recorded meta) of an encoded payload without decrypting the
protected source:

```bash
./bin/obfusx inspect --file=./examples/plain.obx
```

Run `./bin/obfusx help` (or pass `--help`) to see all commands,
`./bin/obfusx about` for an ionCube/SourceGuardian-style overview, and
`./bin/obfusx version` to print the toolkit version.

## Source compatibility

- Input files must contain PHP tags.
- Mixed HTML/PHP sources and multiple PHP blocks are supported.

## Testing

```bash
composer test
```

## Static analysis

```bash
composer stan
```

Or, with Composer installed:

```bash
composer install
composer test   # runs the test suite
composer stan   # runs PHPStan static analysis
```

Continuous integration runs the suite and static analysis on PHP 8.1, 8.2,
and 8.3 (see `.github/workflows/ci.yml`).

## Docs

- `./docs/FAQ.md`
- `./docs/developer-guide.md`
- `./docs/threat-model.md`

## License

ObfusX is released under the [MIT License](LICENSE).
