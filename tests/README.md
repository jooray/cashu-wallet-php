# Tests

The library itself is zero-dependency (require `CashuWallet.php` directly);
Composer is used **only** for dev tooling (PHPUnit).

## Running

```bash
composer install        # installs PHPUnit (dev dependency only)
composer test           # PHPUnit suite + legacy plain-PHP test
```

Individual pieces:

```bash
composer test:unit              # PHPUnit suite only (tests/unit)
composer test:legacy            # original plain-PHP test
php tests/storage_safety.php    # same, invoked directly
vendor/bin/phpunit --filter Nut13   # single test class / pattern
```

All tests run **offline** — no mint or network access is required.
Requires PHP 8.x with `pdo_sqlite` and `gmp` (or `bcmath`).

## Layout

- `unit/` — PHPUnit test classes.
- `fixtures/` — vendored official test vectors (JSON). Each file records its
  provenance (upstream repo + commit) under the `_provenance` key.
- `storage_safety.php` — original plain-PHP regression test for storage
  isolation and crash-safety; kept as-is and run by `composer test`.
- `bootstrap.php` — loads `CashuWallet.php` and the `cashu_fixture()` helper.

## Official test-vector coverage

| Fixture | Source | Covers |
|---|---|---|
| `nut00-bdhke.json` | [cashubtc/nuts tests/00-tests.md] | hash_to_curve, blinded messages (BDHKE), blind signatures |
| `nut00-token-v3.json` | [cashubtc/nuts tests/00-tests.md] | V3 (`cashuA`) serialization, invalid tokens, padding variants |
| `nut00-token-v4.json` | [cashubtc/nuts tests/00-tests.md] | V4 (`cashuB`) CBOR serialization, raw binary framing |
| `nut02-keyset-ids.json` | [cashubtc/nuts tests/02-tests.md] | keyset ID derivation (versions 1 and 2) |
| `nut13-derivation.json` | [cashubtc/nuts tests/13-tests.md] | deterministic secret/blinding-factor derivation (versions 1 and 2) |
| `nut18-payment-requests.json` | [cashubtc/nuts tests/18-tests.md] | payment request (`creqA`) decoding |
| `bip39-vectors.json` | [trezor/python-mnemonic vectors.json] | BIP-39 mnemonic validation and seed derivation |

NUT-12 DLEQ verification and version-2 keyset IDs *are* implemented and covered;
an earlier version of this file said otherwise.

Not covered because the library does not implement them: spending conditions
(NUT-10/11/14), MPP (NUT-15), WebSockets (NUT-17), mint authentication
(NUT-21/22), BOLT12 (NUT-25) and bech32m payment requests (NUT-26).

[cashubtc/nuts tests/00-tests.md]: https://github.com/cashubtc/nuts/blob/master/tests/00-tests.md
[cashubtc/nuts tests/02-tests.md]: https://github.com/cashubtc/nuts/blob/master/tests/02-tests.md
[cashubtc/nuts tests/13-tests.md]: https://github.com/cashubtc/nuts/blob/master/tests/13-tests.md
[cashubtc/nuts tests/18-tests.md]: https://github.com/cashubtc/nuts/blob/master/tests/18-tests.md
[trezor/python-mnemonic vectors.json]: https://github.com/trezor/python-mnemonic/blob/master/vectors.json

## Conventions

- Tests must not touch the network. Anything that would need a mint is either
  exercised through `WalletStorage` (throwaway SQLite files in the system temp
  dir), simulated mint keys (`MeltChangeRecoveryTest`), or reflection-injected
  keysets (`FeeCalculationTest`).
- Official vectors live in `fixtures/` — never inline vector data in tests
  without provenance.
- When a vector exposes a library bug, do not adjust the vector; mark the test
  skipped with an explanation and file the bug.
