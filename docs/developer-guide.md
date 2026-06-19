# Developer Guide

## Workflow
1. Create or prepare a PHP source file with PHP tags.
2. Export `OBFUSX_MASTER_KEY`.
3. Encode via `bin/obfusx encode`.
4. Execute via `bin/obfusx run`.

Mixed HTML/PHP sources and multiple `<?php ... ?>` blocks are supported during encoding and runtime execution.

## License generation
Use `OBFUSX_LICENSE_KEY` and `bin/obfusx make-license`.

## Runtime hardening hooks
- Configurable anti-debug checks via `OBFUSX_ANTIDEBUG_CHECKS`
  (`xdebug,debugger,phpdbg,trace`; `none` disables them). `OBFUSX_ALLOW_DEBUG=1`
  bypasses all checks for local testing.
- License enforcement before decryption
- Optional payload HMAC verification via `OBFUSX_SIGNING_KEY`
- Optional gzip payload compression via `OBFUSX_COMPRESS=1`
- Configurable HKDF key-rotation identifier via `OBFUSX_KEY_INFO`
- AES-256-GCM decryption only in memory

## Multi-block sources
The encoder preserves inline HTML and multiple `<?php` blocks, obfuscating the
PHP segments while leaving template markup intact. Sources without any PHP tag
are rejected with a clear error.

## Local validation
- `composer test`
- `composer stan`

## Threat model
See `./docs/threat-model.md` for the security goals, attacker model, and known
limitations.
