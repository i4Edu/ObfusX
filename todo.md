# ObfusX TODO

A prioritized roadmap of improvements for the ObfusX PHP source-protection toolkit.

## Phase 1 — CLI usability (in progress)
- [x] Introduce a single source-of-truth version constant (`ObfusX\Version`).
- [x] Add a `version` command that prints the toolkit version.
- [x] Add a `help` command and a `--help` flag that print usage.
- [x] Add an `inspect` command that prints non-sensitive metadata of an
      encoded payload (algorithm, signed status, meta) without decrypting the
      protected source.
- [x] Cover the new behavior with tests.

## Phase 2 — Encoder/runtime robustness
- [ ] Validate that encoder input contains PHP code and produce a clearer
      error when it does not.
- [ ] Support multi-block PHP sources (inline HTML / multiple `<?php` tags).
- [ ] Add a configurable HKDF info / key-rotation identifier.

## Phase 3 — Packaging & tooling
- [ ] Add a `composer.json` with PSR-4 autoloading and a `test` script.
- [ ] Add a CI workflow that runs the test suite on supported PHP versions.
- [ ] Add static analysis (PHPStan/Psalm) configuration.

## Phase 4 — Hardening
- [ ] Expand anti-debug checks and make them configurable.
- [ ] Add optional payload compression before encryption.
- [ ] Document a threat model in `docs/`.
