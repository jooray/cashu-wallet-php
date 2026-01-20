# CashuWallet PHP Library

⚠️ **HIGHLY EXPERIMENTAL - USE AT YOUR OWN RISK** ⚠️

This library is in early experimental development. Features may be incomplete, bugs may exist, and **YOU MAY LOSE FUNDS**. Only use with amounts you can afford to lose. Not recommended for production use.

## What is this?

CashuWallet is a PHP implementation of the [Cashu protocol](https://github.com/cashubtc/nuts) - a Chaumian ecash system for Bitcoin. It allows you to:

- Mint ecash tokens from Lightning payments
- Send and receive tokens offline
- Melt tokens back to Lightning
- Recover wallets from BIP-39 seed phrases
- Store tokens with SQLite persistence

## What is it used for?

This library enables PHP applications to integrate Cashu ecash functionality:

- **Payment gateways** - Accept Lightning via Cashu mints (see [CashuPayServer](https://github.com/jooray/cashupayserver))
- **Ecash wallets** - Build web-based or CLI Cashu wallets
- **Accept payments for your API / SaaS** - Cashu protocol is perfect for machine to machine and per API call payments
- **Offline payments** - Generate bearer tokens that work without internet

## Documentation

- **[USAGE.md](USAGE.md)** - Complete usage guide with examples
- **[REFERENCE.md](REFERENCE.md)** - Full API reference
- **[examples/](examples/)** - Working code examples

## Quick Start

```php
require_once 'CashuWallet.php';

use Cashu\Wallet;

$wallet = new Wallet('https://testnut.cashu.space', 'sat');
$wallet->loadMint();
$seedPhrase = $wallet->generateMnemonic();

// SAVE THIS SEED PHRASE! Without it, lost tokens cannot be recovered.
echo "Seed: $seedPhrase\n";

// Request mint quote and get Lightning invoice
$quote = $wallet->requestMintQuote(100);
echo "Pay: " . $quote->request . "\n";

// After payment, mint tokens
while (!$wallet->checkMintQuote($quote->quote)->isPaid()) {
    sleep(5);
}
$proofs = $wallet->mint($quote->quote, 100);
```

## Interactive Test Wallet

The library includes an interactive CLI testing tool:

```bash
# Make executable
chmod +x test-wallet.php

# Run the test wallet
./test-wallet.php
```

The test wallet provides a menu-driven interface to:
1. Mint tokens from Lightning invoices
2. Melt tokens to pay Lightning invoices
3. Send tokens (serialize to cashuA/cashuB format)
4. Receive tokens
5. Check proof states
6. Restore from seed phrase
7. Check balance

**Warning:** The test wallet stores proofs in memory only (unless you enable SQLite storage). Closing the program loses all tokens unless you have your seed phrase and mint URL.

## Requirements

- PHP 8.0 or higher
- `ext-gmp` (recommended) OR `ext-bcmath` for big integer math
- `ext-curl` for HTTP requests
- `ext-json` (standard)
- `bip39-english.txt` wordlist file (for mnemonic support)

## ⚠️ Security Warnings

**THIS LIBRARY IS HIGHLY EXPERIMENTAL**

- **You may lose funds** - Bugs in cryptography, persistence, or protocol implementation could result in permanent loss
- **Not audited** - No security audit has been performed
- **Alpha quality** - APIs may change without notice
- **Test with small amounts only** - Only use sats you can afford to lose
- **No warranty** - See license below

**Always backup your seed phrase!** Without it, tokens cannot be recovered.

## Features

- ✅ Full secp256k1 cryptography (no dependencies)
- ✅ BIP-39 mnemonic support
- ✅ Deterministic secrets (NUT-13) for wallet recovery
- ✅ Token serialization (V3 cashuA and V4 cashuB formats)
- ✅ Multi-currency support (sat, msat, usd, eur, btc)
- ✅ SQLite persistence layer
- ✅ Mint, melt, swap operations
- ✅ Wallet restore from seed
- ⚠️ NUT-18 payment requests (experimental, undocumented)

## Protocol Support

Implements these Cashu NUTs:
- [NUT-00](https://github.com/cashubtc/nuts/blob/main/00.md) - Token format (V3/V4)
- [NUT-03](https://github.com/cashubtc/nuts/blob/main/03.md) - Swap
- [NUT-04](https://github.com/cashubtc/nuts/blob/main/04.md) - Mint
- [NUT-05](https://github.com/cashubtc/nuts/blob/main/05.md) - Melt
- [NUT-07](https://github.com/cashubtc/nuts/blob/main/07.md) - Proof state
- [NUT-09](https://github.com/cashubtc/nuts/blob/main/09.md) - Restore
- [NUT-13](https://github.com/cashubtc/nuts/blob/main/13.md) - Deterministic secrets
- [NUT-18](https://github.com/cashubtc/nuts/blob/main/18.md) - Payment requests (experimental)

## License

This is free and unencumbered software released into the public domain.

Anyone is free to copy, modify, publish, use, compile, sell, or
distribute this software, either in source code form or as a compiled
binary, for any purpose, commercial or non-commercial, and by any
means.

In jurisdictions that recognize copyright laws, the author or authors
of this software dedicate any and all copyright interest in the
software to the public domain. We make this dedication for the benefit
of the public at large and to the detriment of our heirs and
successors. We intend this dedication to be an overt act of
relinquishment in perpetuity of all present and future rights to this
software under copyright law.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR
OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
OTHER DEALINGS IN THE SOFTWARE.

For more information, please refer to <http://unlicense.org/>

## Support and value4value

If you like this project, I would appreciate if you contributed time, talent or treasure.

Time and talent can be used in testing it out, fixing bugs or submitting pull requests.

Treasure can be [sent back through here](https://juraj.bednar.io/en/support-me/).
