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

## Quick start

```bash
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

## Inspecting encoded files

Print non-sensitive metadata (algorithm, signing status, recorded meta) of an
encoded payload without decrypting the protected source:

```bash
./bin/obfusx inspect --file=./examples/plain.obx
```

Run `./bin/obfusx help` (or pass `--help`) to see all commands, and
`./bin/obfusx version` to print the toolkit version.

## Testing

```bash
php ./tests/run.php
```

## Docs

- `./docs/FAQ.md`
- `./docs/developer-guide.md`
