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
- Xdebug check (`OBFUSX_ALLOW_DEBUG=1` can bypass for local testing)
- License enforcement before decryption
- Optional payload HMAC verification via `OBFUSX_SIGNING_KEY`
- AES-256-GCM decryption only in memory

## Local validation
- `composer test`
- `composer stan`
