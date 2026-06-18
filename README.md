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
/home/runner/work/ObfusX/ObfusX/bin/obfusx encode \
  --in=/home/runner/work/ObfusX/ObfusX/examples/plain.php \
  --out=/home/runner/work/ObfusX/ObfusX/examples/plain.obx

OBFUSX_ALLOW_DEBUG=1 /home/runner/work/ObfusX/ObfusX/bin/obfusx run \
  --file=/home/runner/work/ObfusX/ObfusX/examples/plain.obx
```

## Licensing

```bash
export OBFUSX_LICENSE_KEY='license-signing-secret'
/home/runner/work/ObfusX/ObfusX/bin/obfusx make-license \
  --out=/home/runner/work/ObfusX/ObfusX/examples/license.json \
  --domain=localhost \
  --ip=127.0.0.1 \
  --expires=2030-01-01T00:00:00Z \
  --hw="$(/home/runner/work/ObfusX/ObfusX/bin/obfusx fingerprint)"
```

Then pass `--license=/path/license.json` to the `run` command.

## Testing

```bash
php /home/runner/work/ObfusX/ObfusX/tests/run.php
```

## Docs

- `/home/runner/work/ObfusX/ObfusX/docs/FAQ.md`
- `/home/runner/work/ObfusX/ObfusX/docs/developer-guide.md`
