# ObfusX TODO

A prioritized roadmap of improvements for the ObfusX PHP source-protection toolkit.

## Phase 1 — CLI usability ✅
- [x] Introduce a single source-of-truth version constant (`ObfusX\Version`).
- [x] Add a `version` command that prints the toolkit version.
- [x] Add a `help` command and a `--help` flag that print usage.
- [x] Add an `inspect` command that prints non-sensitive metadata of an
      encoded payload (algorithm, signed status, meta) without decrypting the
      protected source.
- [x] Add an `about` command that prints an ionCube/SourceGuardian-style
      overview of capabilities, version, license, and available commands.
- [x] Cover the new behavior with tests.

## Phase 2 — Encoder/runtime robustness ✅
- [x] Validate that encoder input contains PHP code and produce a clearer
      error when it does not.
- [x] Support multi-block PHP sources (inline HTML / multiple `<?php` tags).
- [x] Add a configurable HKDF info / key-rotation identifier.

## Phase 3 — Packaging & tooling ✅
- [x] Add a `composer.json` with PSR-4 autoloading and a `test` script.
- [x] Add a CI workflow that runs the test suite on supported PHP versions.
- [x] Add static analysis (PHPStan/Psalm) configuration.
- [x] Add a repository `.gitignore` for generated payloads and local tooling.
- [x] Choose and publish an explicit project license.
- [x] Add a release changelog / upgrade notes document.

## Phase 4 — Hardening ✅
- [x] Expand anti-debug checks and make them configurable.
- [x] Add optional payload compression before encryption.
- [x] Document a threat model in `docs/`.

## Phase 5 — Distribution & packaging ✅
- [x] Build a self-contained Phar binary (`obfusx.phar`) for zero-dependency distribution.
- [x] Publish a Docker image to GitHub Container Registry.
- [x] Add a GitHub Actions release workflow that publishes the Phar and Docker
      image on tag push.
- [x] Verify and document Windows (WSL / native PHP) compatibility.

## Phase 6 — Advanced obfuscation
- [x] Control-flow flattening (switch-based dispatcher) for protected code blocks.
- [x] String-array encoding — hoist all string literals into an encoded lookup
      array resolved at runtime.
- [x] Dead-code injection / junk-instruction insertion to thwart static analysis.
- [x] Verify full compatibility with PHP 8.2 readonly classes and backed enums.
- [x] Verify full compatibility with PHP 8.3 typed class constants and `json_validate`.

## Phase 7 — Multi-file & enterprise
- [ ] Add an `encode-dir` command for batch encoding an entire directory tree,
      preserving structure and skipping non-PHP assets.
- [ ] Incremental re-encoding: only re-process files whose source has changed
      since the last run (checksum cache).
- [ ] White-label / rebranding support: configurable loader header and branding
      string injected into encoded output.
- [ ] `.obxignore` file support to exclude paths from batch encoding.

## Phase 8 — Remote licensing
- [ ] Remote license validation endpoint: loader optionally POSTs a signed
      challenge to a configurable URL and verifies the response.
- [ ] License revocation support: server-side revocation list checked at runtime.
- [ ] Grace period / offline tolerance window: allow N days of offline use before
      re-validation is required.
- [ ] `make-license` enhancements: machine-count caps, named licensee field,
      and human-readable expiry formatting.
