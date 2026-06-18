# Developer Guide

## Workflow
1. Create or prepare a PHP source file.
2. Export `OBFUSX_MASTER_KEY`.
3. Encode via `bin/obfusx encode`.
4. Execute via `bin/obfusx run`.

## License generation
Use `OBFUSX_LICENSE_KEY` and `bin/obfusx make-license`.

## Runtime hardening hooks
- Xdebug check (`OBFUSX_ALLOW_DEBUG=1` can bypass for local testing)
- License enforcement before decryption
- AES-256-GCM decryption only in memory
