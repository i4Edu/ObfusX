# Changelog

All notable changes to ObfusX are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
ObfusX uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [0.6.0] — 2026-06-19

### Added
- Optional remote license validation in `runtime/loader.php` via `OBFUSX_LICENSE_URL`, including signed challenge verification, 5-second timeouts, and debug-bypass support.
- Offline grace-period caching with `OBFUSX_LICENSE_GRACE_DAYS` and `OBFUSX_LICENSE_CACHE`, allowing recent successful validations to tolerate temporary network failures.
- `LicenseManager::isRevoked()` and runtime revocation-list checks via `OBFUSX_REVOCATION_URL`.
- `make-license` support for `licensee` metadata and `max_machines` limits in the signed payload.

### Changed
- `make-license` now prints a human-readable expiry timestamp when `--expires` is provided.
- `ObfusX\Version::VERSION` bumped to `0.6.0`.

---

## [0.5.0] — 2026-06-19

### Added
- `encode-dir` CLI command for recursively encoding PHP trees into mirrored `.obx` outputs while copying non-PHP assets as-is.
- `ObfusX\DirectoryEncoder` with optional checksum-cache support for incremental multi-file builds.
- `.obxignore` parsing for fnmatch-based path exclusions during batch encoding.
- White-label branding metadata via `OBFUSX_BRANDING`, exposed through `Encoder::describeFile()`.

### Changed
- `runtime/loader.php` now tolerates and discards branding metadata keys in encoded payloads.
- `ObfusX\Version::VERSION` bumped to `0.5.0`.

---

## [0.4.0] — 2026-06-19

### Added
- Optional control-flow flattening (`OBFUSX_FLATTEN=1`) using a switch-based dispatcher for the first protected `<?php` block.
- Optional string-array encoding (`OBFUSX_STRARRAY=1`) that hoists obfuscated string literals into a single base64-decoded lookup table.
- Optional junk/dead-code injection (`OBFUSX_JUNK=1`) with multiple opaque never-executed blocks appended after the protected source.

### Changed
- `Encoder::encodeFile()` now records `flatten` and `strarray` metadata flags when those Phase 6 obfuscation features are applied.
- `ObfusX\Version::VERSION` bumped to `0.4.0`.

### Fixed
- PHP 8.2 compatibility for `readonly` class/property token streams during obfuscation.
- PHP 8.1+ backed enum compatibility by preserving enum-case literal values.
- PHP 8.3 typed class constant compatibility by preserving typed `const` declarations and their literal values.

---

## [0.3.0] — 2026-06-19

### Added
- `build/phar.php` to compile a self-contained `obfusx.phar` CLI release artifact.
- Root `Dockerfile` for packaging ObfusX on `php:8.3-cli-alpine`.
- GitHub Actions release automation that publishes the Phar asset and pushes
  versioned and `latest` Docker images to GHCR.
- `docs/windows-compatibility.md` covering WSL2 and native PHP for Windows usage.

---

## [0.2.0] — 2026-06-19

### Added
- `about` command — prints an ionCube/SourceGuardian-style overview of ObfusX capabilities,
  including version, PHP requirement, license, project URL, full feature list, and available commands.

---

## [0.1.0] — 2026-06-19

Initial public release of the ObfusX PHP source-protection toolkit.

### Added

#### CLI
- `encode` command — encrypt and obfuscate a PHP source file into a `.obx` payload.
- `run` command — decrypt and execute an encoded `.obx` payload in memory.
- `inspect` command — print non-sensitive metadata (algorithm, key-rotation id,
  compression flag, signing status, recorded meta) without decrypting the source.
- `make-license` command — generate a signed JSON license file with domain/IP/expiry
  and hardware-fingerprint binding.
- `fingerprint` command — print the hardware fingerprint of the current host.
- `version` command — print the toolkit version.
- `help` command / `--help` flag — print usage information for all commands.

#### Encoder
- AES-256-GCM encryption of PHP source payloads.
- HKDF key derivation from `OBFUSX_MASTER_KEY` with a configurable info string
  (`OBFUSX_KEY_INFO`, default `obfusx-runtime-key`).
- Optional gzip compression before encryption (`OBFUSX_COMPRESS=1`).
- Optional HMAC payload-signing for tamper detection (`OBFUSX_SIGNING_KEY`).
- Identifier and string-literal obfuscation pipeline with opaque dummy flow.
- Multi-block PHP source support — files mixing inline HTML with multiple `<?php`
  tags are preserved and obfuscated correctly.
- Input validation — files containing no PHP code are rejected with a clear error.

#### Runtime loader
- In-memory decrypt-and-eval execution; protected source is never written to disk.
- Configurable anti-debug checks (`OBFUSX_ANTIDEBUG_CHECKS`): `xdebug`, `debugger`,
  `phpdbg`, `trace`. Pass `none` to disable; defaults to all checks.
- `OBFUSX_ALLOW_DEBUG=1` bypass for local development.
- Laravel-compatible `__DIR__` / `__FILE__` rewriting relative to the `.obx` file,
  enabling encoded `public/index.php` and `artisan` entrypoints.

#### Licensing
- Domain, IP, and hardware-fingerprint binding enforced at runtime.
- Configurable expiry date validation.
- License files signed with `OBFUSX_LICENSE_KEY`; signature verified on load.

#### Tooling & CI
- `composer.json` with PSR-4 autoloading, a `test` script, and a `stan` script.
- PHPStan static-analysis configuration (`phpstan.neon.dist`).
- GitHub Actions CI workflow running tests and static analysis on PHP 8.1, 8.2,
  and 8.3.
- `.gitignore` for generated payloads and local tooling.

#### Documentation
- `README.md` with quick-start, configuration reference, and command overview.
- `docs/FAQ.md` — frequently asked questions.
- `docs/developer-guide.md` — guide for contributors and integrators.
- `docs/threat-model.md` — documented threat model and security assumptions.

---

## Upgrade notes

### 0.1.0 (initial release)

This is the first public release; no migration from a previous version is required.

**Environment variables introduced in this release:**

| Variable | Purpose |
| --- | --- |
| `OBFUSX_MASTER_KEY` | Master secret for AES-256-GCM key derivation (required for encode/run). |
| `OBFUSX_SIGNING_KEY` | Optional HMAC key for payload tamper detection. |
| `OBFUSX_LICENSE_KEY` | HMAC key for license signing and validation. |
| `OBFUSX_KEY_INFO` | HKDF info / key-rotation identifier (default `obfusx-runtime-key`). |
| `OBFUSX_COMPRESS` | Set to `1` to gzip-compress before encryption. |
| `OBFUSX_ANTIDEBUG_CHECKS` | Comma-separated checks (`xdebug,debugger,phpdbg,trace`) or `none`. |
| `OBFUSX_ALLOW_DEBUG` | Set to `1` to bypass anti-debug checks (local testing only). |

[Unreleased]: https://github.com/i4Edu/ObfusX/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/i4Edu/ObfusX/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/i4Edu/ObfusX/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/i4Edu/ObfusX/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/i4Edu/ObfusX/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/i4Edu/ObfusX/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/i4Edu/ObfusX/releases/tag/v0.1.0
