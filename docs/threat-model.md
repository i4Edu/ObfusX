# ObfusX Threat Model

This document describes the security goals of ObfusX, the attackers it is
designed to resist, the protections it provides, and its known limitations.
It is intentionally honest about what a software-only source-protection
toolkit can and cannot guarantee.

## Security goals

ObfusX aims to:

- Keep encoded PHP source confidential at rest (on disk, in version control,
  in build artifacts).
- Ensure encoded payloads are tamper-evident (detect modification before
  execution).
- Bind execution to a license (domain, IP, expiry, hardware fingerprint).
- Raise the cost and effort of reverse engineering through layered controls.

## Assets

- **Protected source code** — the original PHP being encoded.
- **Master key** (`OBFUSX_MASTER_KEY`) — derives the AES-256-GCM content key.
- **Signing key** (`OBFUSX_SIGNING_KEY`) — authenticates payload integrity.
- **License signing key** (`OBFUSX_LICENSE_KEY`) — authenticates licenses.

## Trust boundaries

- The encoding host is trusted; it holds the plaintext source and master key.
- The encoded `.obx` artifact is considered to travel through untrusted
  channels and storage.
- The runtime host is **partially** trusted: it must be supplied the master
  key at runtime, and it ultimately executes decrypted code in memory, so a
  fully compromised runtime host (root/admin, or a debugger attached to the
  PHP process) can recover the plaintext.

## Attacker model

| Attacker | Capability | ObfusX resistance |
| --- | --- | --- |
| Casual recipient | Reads the `.obx` file | Strong — payload is AES-256-GCM encrypted; only metadata is readable via `inspect`. |
| Tamperer | Edits the `.obx` file | Strong when `OBFUSX_SIGNING_KEY` is set — HMAC over ciphertext, IV, tag, salt, key-info, and compression flag detects changes. |
| License sharer | Copies a license to another host | Mitigated — domain/IP/expiry/hardware-fingerprint binding, HMAC-signed license. |
| Runtime observer | Attaches a debugger/profiler | Partial — configurable anti-debug checks (`xdebug`, `phpdbg`, active debugger, tracing/profiling extensions). |
| Privileged host operator | Controls the PHP process and the master key | **Out of scope** — can always recover plaintext. |

## Protections

### Confidentiality
- AES-256-GCM authenticated encryption of the obfuscated payload.
- Per-payload random 16-byte salt and 12-byte IV.
- Key derived with HKDF-SHA256 from the master key. The HKDF *info* /
  key-rotation identifier is configurable (`OBFUSX_KEY_INFO`) and stored in
  the payload, allowing keys to be rotated from a single master secret.
- The master key is provided at runtime and never embedded in output.

### Integrity / authenticity
- AES-GCM authentication tag detects ciphertext corruption.
- Optional HMAC-SHA256 payload signature (`OBFUSX_SIGNING_KEY`) covering
  ciphertext, IV, tag, salt, key-info, and compression marker.
- Licenses are HMAC-SHA256 signed over canonicalized claims.

### Obfuscation
- Identifier renaming and literal-string encoding via `obfusx_s()`.
- Opaque, no-op control flow inserted into the emitted source.
- Multi-block sources (inline HTML and multiple `<?php` tags) are preserved
  and obfuscated consistently.

### Runtime hardening
- Configurable anti-debug checks (`OBFUSX_ANTIDEBUG_CHECKS`, with
  `OBFUSX_ALLOW_DEBUG=1` to bypass for local testing).
- License enforcement before any decryption occurs.
- Decryption and execution happen in memory; the temporary runtime file is
  removed immediately after `include`.

### Optional compression
- Payloads may be gzip-compressed before encryption (`OBFUSX_COMPRESS=1`),
  reducing artifact size. The compression marker is covered by the signature.

## Known limitations

- **Software-only**: a determined attacker who fully controls the runtime
  host (and therefore the master key and PHP process) can always recover the
  plaintext. ObfusX raises the bar; it does not provide a guarantee.
- **Key management is the operator's responsibility**: leaking
  `OBFUSX_MASTER_KEY`, `OBFUSX_SIGNING_KEY`, or `OBFUSX_LICENSE_KEY`
  invalidates the corresponding protections.
- **Anti-debug is best-effort**: these checks deter casual analysis but are
  not a robust defense against a skilled reverse engineer.
- **Temporary runtime file**: decrypted code is briefly written to the system
  temp directory before execution; ensure that directory is not world-readable
  on multi-tenant hosts.

## Operational recommendations

- Always set `OBFUSX_SIGNING_KEY` in production for tamper detection.
- Use distinct keys for encryption, payload signing, and license signing.
- Rotate `OBFUSX_KEY_INFO` periodically to roll derived keys.
- Store secrets in a dedicated secret manager, never in the repository.
- Restrict permissions on the system temporary directory.
