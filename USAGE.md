# CashuWallet PHP Library

A PHP implementation of the Cashu protocol for interacting with Cashu mints.

## Introduction

### What is Cashu?

Cashu is a Chaumian ecash protocol built for Bitcoin. It enables:
- **Privacy**: Blind signatures ensure the mint cannot link minting to spending
- **Offline transfers**: Tokens can be sent without internet connection
- **Lightning integration**: Mint and melt tokens via Lightning Network payments

### What this library does

This single-file PHP library (`CashuWallet.php`) provides:
- Wallet operations (mint, melt, send, receive tokens)
- Token serialization (V3/V4 formats)
- Deterministic secret generation (NUT-13) for wallet recovery
- BIP-39 mnemonic support for backup
- Full secp256k1 cryptography implementation

### Requirements

- PHP 8.0 or higher
- `ext-gmp` (recommended) OR `ext-bcmath` for big integer math
- `ext-curl` for HTTP requests
- `ext-json` (standard)
- `bip39-english.txt` wordlist file (for mnemonic support)

## Quick Start

### Installation

```php
require_once 'CashuWallet.php';

use Cashu\Wallet;
use Cashu\CashuException;
```

### Basic Example: Mint, Send, and Receive

```php
// 1. Create wallet and connect to mint
$wallet = new Wallet('https://testnut.cashu.space', 'sat');
$wallet->loadMint();

// 2. Initialize with seed (REQUIRED for recoverable tokens)
$seedPhrase = $wallet->generateMnemonic();
// SAVE THIS PHRASE - it's the only way to recover your tokens!

// 3. Request mint quote (get Lightning invoice)
$quote = $wallet->requestMintQuote(100);
echo "Pay this invoice: " . $quote->request . "\n";

// 4. Wait for payment and mint tokens
while (!$wallet->checkMintQuote($quote->quote)->isPaid()) {
    sleep(5);
}
$proofs = $wallet->mint($quote->quote, 100);

// 5. Serialize to send
$tokenString = $wallet->serializeToken($proofs, 'v4');
echo "Token to send: $tokenString\n";

// 6. Receive on another wallet
$receiverWallet = new Wallet('https://testnut.cashu.space', 'sat');
$receiverWallet->loadMint();
$receiverWallet->generateMnemonic();
$newProofs = $receiverWallet->receive($tokenString);
```

## Core Concepts

### Seed Phrases (CRITICAL)

The seed phrase is **required** and **critical** for wallet security:

```php
// Generate a new seed
$seedPhrase = $wallet->generateMnemonic();
// Returns: "abandon ability able about above absent..."

// Or use an existing seed
$wallet->initFromMnemonic('your twelve word seed phrase here');
```

**Why seeds matter:**
- Enable deterministic secret generation (NUT-13)
- Allow full wallet recovery from seed + mint
- Without the seed, lost tokens are unrecoverable

**Best practices:**
- Save the seed phrase immediately when generated
- Store securely offline (paper backup, hardware wallet)
- Never share the seed phrase
- Use the same seed for the same wallet across sessions

### Proofs (Tokens)

Proofs are the actual ecash tokens. Each proof contains:
- `amount`: The value in the smallest unit (e.g., satoshis)
- `secret`: Unique identifier (derived from seed)
- `C`: Blinded signature from the mint
- `id`: Keyset identifier

```php
// Working with proofs
$totalBalance = Wallet::sumProofs($proofs);   // Sum all amounts
$selected = Wallet::selectProofs($proofs, 50); // Select for amount

// Proof to array for storage
$proofArray = $proof->toArray(true); // true = include DLEQ
$proof = Proof::fromArray($proofArray);
```

### Units

The library supports multiple currency units:

| Unit | Decimals | Example |
|------|----------|---------|
| sat  | 0        | 100 sat |
| msat | 0        | 100 msat |
| usd  | 2        | $1.50   |
| eur  | 2        | 0.50    |
| btc  | 8        | 0.00000100 |

```php
// Create wallet for specific unit
$wallet = new Wallet('https://mint.example.com', 'usd');

// Format amounts
echo $wallet->formatAmount(150); // "$1.50"

// Parse user input
$amount = $wallet->parseAmount('1.50'); // 150 (cents)

// Check available units on a mint
$units = Wallet::getSupportedUnits('https://mint.example.com');
// ['sat' => ['activeCount' => 1, ...], 'usd' => [...]]
```

## Workflows

### Minting Tokens (Lightning to Cashu)

Convert Lightning payments into Cashu tokens:

```php
use Cashu\Wallet;
use Cashu\CashuException;

$wallet = new Wallet('https://testnut.cashu.space');
$wallet->loadMint();

// 1. Initialize seed (REQUIRED)
$seedPhrase = $wallet->generateMnemonic();
echo "SAVE THIS SEED: $seedPhrase\n";

// 2. Request mint quote
$amount = 100; // satoshis
$quote = $wallet->requestMintQuote($amount);

echo "Quote ID: " . $quote->quote . "\n";
echo "Invoice: " . $quote->request . "\n";
echo "State: " . $quote->state . "\n";

// 3. Wait for payment (poll the mint)
$paid = false;
for ($i = 0; $i < 60; $i++) {
    $status = $wallet->checkMintQuote($quote->quote);
    if ($status->isPaid()) {
        $paid = true;
        break;
    }
    sleep(5);
}

if (!$paid) {
    throw new CashuException('Payment timeout');
}

// 4. Mint tokens
$proofs = $wallet->mint($quote->quote, $amount);

echo "Minted " . count($proofs) . " proofs\n";
echo "Total: " . Wallet::sumProofs($proofs) . " sat\n";

// 5. Serialize for storage or sharing
$token = $wallet->serializeToken($proofs, 'v4');
echo "Token: $token\n";
```

### Sending Tokens

Split proofs to send a specific amount:

```php
use Cashu\Wallet;
use Cashu\Proof;
use Cashu\InsufficientBalanceException;

// Load existing proofs (from storage)
$proofsData = json_decode(file_get_contents('proofs.json'), true);
$proofs = array_map(fn($p) => Proof::fromArray($p), $proofsData);

$wallet = new Wallet('https://testnut.cashu.space');
$wallet->loadMint();
$wallet->initFromMnemonic('your seed phrase here');

// Check balance
$balance = Wallet::sumProofs($proofs);
echo "Balance: $balance sat\n";

$sendAmount = 50;

// Split proofs
try {
    $result = $wallet->split($proofs, $sendAmount);

    $sendProofs = $result['send'];  // Proofs to send
    $keepProofs = $result['keep'];  // Change to keep
    $fee = $result['fee'];          // Fee paid

    echo "Send: " . Wallet::sumProofs($sendProofs) . " sat\n";
    echo "Keep: " . Wallet::sumProofs($keepProofs) . " sat\n";
    echo "Fee: $fee sat\n";

    // Serialize for sending
    $tokenToSend = $wallet->serializeToken($sendProofs, 'v4');
    echo "Token to send: $tokenToSend\n";

    // Save remaining proofs
    $keepJson = json_encode(array_map(fn($p) => $p->toArray(true), $keepProofs));
    file_put_contents('proofs.json', $keepJson);

} catch (InsufficientBalanceException $e) {
    echo "Not enough balance: " . $e->getMessage() . "\n";
}
```

### Receiving Tokens

Receive a token string by swapping for fresh proofs:

```php
use Cashu\Wallet;
use Cashu\TokenSerializer;
use Cashu\CashuException;

$tokenString = 'cashuBo2F0gaJhaUgAJTn...'; // Token from sender

// First, inspect the token
$token = TokenSerializer::deserialize($tokenString);
echo "Mint: " . $token->mint . "\n";
echo "Unit: " . $token->unit . "\n";
echo "Amount: " . $token->getAmount() . " " . $token->unit . "\n";

// Create wallet for the token's mint and unit
$wallet = new Wallet($token->mint, $token->unit);
$wallet->loadMint();

// Initialize with your seed
$wallet->initFromMnemonic('your seed phrase here');

// Check if proofs are still valid (not already spent)
$states = $wallet->checkProofState($token->proofs);
foreach ($states as $state) {
    if ($state['state'] !== 'UNSPENT') {
        echo "Warning: Some proofs may be spent!\n";
    }
}

// Receive (swap for fresh proofs)
try {
    $newProofs = $wallet->receive($tokenString);

    echo "Received " . count($newProofs) . " proofs\n";
    echo "Total: " . Wallet::sumProofs($newProofs) . " " . $token->unit . "\n";

    // Save your new proofs
    $json = json_encode(array_map(fn($p) => $p->toArray(true), $newProofs));
    file_put_contents('proofs.json', $json);

} catch (CashuException $e) {
    echo "Failed to receive: " . $e->getMessage() . "\n";
}
```

### Melting Tokens (Cashu to Lightning)

Pay a Lightning invoice using Cashu tokens:

```php
use Cashu\Wallet;
use Cashu\Proof;
use Cashu\InsufficientBalanceException;

$invoice = 'lnbc100n1p...'; // Lightning invoice to pay

// Load proofs
$proofsData = json_decode(file_get_contents('proofs.json'), true);
$proofs = array_map(fn($p) => Proof::fromArray($p), $proofsData);

$wallet = new Wallet('https://testnut.cashu.space');
$wallet->loadMint();
$wallet->initFromMnemonic('your seed phrase here');

// 1. Get melt quote (shows fees)
$quote = $wallet->requestMeltQuote($invoice);

echo "Amount: " . $quote->amount . " sat\n";
echo "Fee Reserve: " . $quote->feeReserve . " sat\n";
echo "Total needed: " . ($quote->amount + $quote->feeReserve) . " sat\n";

$totalNeeded = $quote->amount + $quote->feeReserve;

// 2. Select proofs to cover amount + fees
try {
    $selectedProofs = Wallet::selectProofs($proofs, $totalNeeded);
    echo "Using " . count($selectedProofs) . " proofs\n";
} catch (InsufficientBalanceException $e) {
    echo "Not enough balance\n";
    exit(1);
}

// 3. Melt tokens
$result = $wallet->melt($quote->quote, $selectedProofs);

if ($result['paid']) {
    echo "Payment successful!\n";
    echo "Preimage: " . $result['preimage'] . "\n";

    // Handle change
    if (!empty($result['change'])) {
        $changeAmount = Wallet::sumProofs($result['change']);
        echo "Change received: $changeAmount sat\n";

        // Combine change with unused proofs
        $usedSecrets = array_map(fn($p) => $p->secret, $selectedProofs);
        $unusedProofs = array_filter($proofs, fn($p) => !in_array($p->secret, $usedSecrets));
        $allProofs = array_merge(array_values($unusedProofs), $result['change']);

        // Save updated proofs
        $json = json_encode(array_map(fn($p) => $p->toArray(true), $allProofs));
        file_put_contents('proofs.json', $json);
    }
} else {
    echo "Payment failed or pending\n";
}
```

### Restoring a Wallet

Recover tokens using your seed phrase:

```php
use Cashu\Wallet;

$wallet = new Wallet('https://testnut.cashu.space');
$wallet->loadMint();

// Initialize with your backup seed
$wallet->initFromMnemonic('your twelve word seed phrase here');

// Restore tokens
echo "Scanning for tokens...\n";

$result = $wallet->restore(
    batchSize: 25,      // Counters per batch
    emptyBatches: 3,    // Stop after N empty batches
    progressCallback: function($keysetId, $counter, $found) {
        echo "Keyset $keysetId, counter $counter: found $found proofs\n";
    }
);

$proofs = $result['proofs'];
$counters = $result['counters'];

echo "Recovered " . count($proofs) . " proofs\n";
echo "Total: " . Wallet::sumProofs($proofs) . " sat\n";

// Save recovered proofs
$json = json_encode(array_map(fn($p) => $p->toArray(true), $proofs));
file_put_contents('recovered_proofs.json', $json);
```

## Error Handling

The library provides three exception types:

```php
use Cashu\CashuException;
use Cashu\CashuProtocolException;
use Cashu\InsufficientBalanceException;

try {
    $proofs = $wallet->mint($quoteId, $amount);
} catch (InsufficientBalanceException $e) {
    // Not enough tokens for the operation
    echo "Balance too low: " . $e->getMessage();
} catch (CashuProtocolException $e) {
    // Error from the mint (invalid quote, spent proof, etc.)
    echo "Mint error: " . $e->getMessage();
    echo "Error code: " . $e->getCode();
} catch (CashuException $e) {
    // General library error (network, crypto, etc.)
    echo "Error: " . $e->getMessage();
}
```

Common error scenarios:
- **No seed initialized**: Call `initFromMnemonic()` or `generateMnemonic()` before minting/swapping
- **Invalid mnemonic**: Ensure 12/15/18/21/24 words from BIP-39 wordlist
- **Token from different mint**: Use the mint URL from the token
- **Proofs already spent**: Check proof state before receiving
- **Insufficient balance**: Select more proofs or reduce amount

## Examples

Complete working examples are available in the `examples/` directory:

- `mint_tokens.php` - Mint new tokens from Lightning payment
- `send_token.php` - Split and send tokens
- `receive_token.php` - Receive and claim tokens
- `melt_tokens.php` - Pay Lightning invoice with tokens

Run examples from the command line:

```bash
# Mint 100 sats
php examples/mint_tokens.php 100 https://testnut.cashu.space

# Send 50 sats from proofs file
php examples/send_token.php 50 proofs.json https://testnut.cashu.space "seed phrase"

# Receive a token
php examples/receive_token.php cashuBo2F0gaJha...

# Pay Lightning invoice
php examples/melt_tokens.php lnbc100n1p... proofs.json
```

## Token Formats

The library supports two token formats:

### V4 (cashuB) - Recommended

- CBOR-encoded, binary-efficient
- Shorter token strings
- Modern format (NUT-00 V4)

```php
$token = $wallet->serializeToken($proofs, 'v4');
// cashuBo2F0gaJhaUgAJTn...
```

### V3 (cashuA) - Legacy

- JSON-encoded, base64
- Wider compatibility
- Older format

```php
$token = $wallet->serializeToken($proofs, 'v3');
// cashuAeyJ0b2tlbiI6W3si...
```

Both formats are supported for deserialization:

```php
$token = TokenSerializer::deserialize($anyTokenString);
// Works with both cashuA and cashuB prefixes
```

## Fees

Mints may charge input fees for swap operations. The library handles this automatically:

```php
// Check fee rate
$feePpk = $wallet->getInputFeePpk(); // Parts per thousand

// Calculate fee for specific proofs
$fee = $wallet->calculateFee($proofs);

// Fees are automatically deducted in split/swap operations
$result = $wallet->split($proofs, $amount);
echo "Fee paid: " . $result['fee'] . " sat\n";
```

## SQLite Storage (Persistence)

The library supports automatic proof and counter persistence via SQLite:

### Basic Setup

```php
use Cashu\Wallet;

// Pass database path as third argument
$wallet = new Wallet('https://testnut.cashu.space', 'sat', '/path/to/wallet.db');
$wallet->loadMint();
$wallet->initFromMnemonic('your seed phrase here');

// Proofs and counters are now auto-persisted!
$proofs = $wallet->mint($quote->quote, 100);
// Proofs automatically stored in database
```

### Multi-Wallet Support

Different mints get separate storage within the same database:

```php
// Each wallet gets its own namespace based on mint URL
$wallet1 = new Wallet('https://mint1.example.com', 'sat', '/path/to/wallet.db');
$wallet2 = new Wallet('https://mint2.example.com', 'sat', '/path/to/wallet.db');

// Proofs are kept separate per mint
```

### Checking Storage

```php
// Check if storage is enabled
if ($wallet->hasStorage()) {
    $storage = $wallet->getStorage();

    // Get all unspent proofs
    $proofs = $storage->getProofs('UNSPENT');

    // Get proofs for a specific quote (for recovery)
    $invoiceProofs = $storage->getProofsByQuoteId($quoteId);

    // Clean up expired pending operations
    $cleaned = $storage->cleanExpiredPendingOperations();
}
```

### Crash Recovery

The storage enables recovery from crashes during minting:

```php
// If a crash happened during minting, proofs may exist in storage
// but the calling code didn't receive them

$storage = $wallet->getStorage();
$proofs = $storage->getProofsByQuoteId($quoteId);

if (!empty($proofs)) {
    // Proofs exist - operation completed before crash
    echo "Found " . count($proofs) . " proofs for quote\n";
} else {
    // Check quote status and retry if needed
    $status = $wallet->checkMintQuote($quoteId);
    if ($status->isPaid() && !$status->isIssued()) {
        // Safe to retry minting
        $proofs = $wallet->mint($quoteId, $amount);
    }
}
```

## Security Notes

1. **Always save your seed phrase** before minting tokens
2. **Verify mint URLs** - only use trusted mints
3. **Check proof states** before accepting tokens
4. **Store proofs securely** - they are bearer instruments
5. **Keep seeds offline** - use hardware wallets if possible

## Protocol References

This library implements the Cashu NUT specifications:
- [NUT-00](https://github.com/cashubtc/nuts/blob/main/00.md) - Token format
- [NUT-03](https://github.com/cashubtc/nuts/blob/main/03.md) - Swap
- [NUT-04](https://github.com/cashubtc/nuts/blob/main/04.md) - Mint
- [NUT-05](https://github.com/cashubtc/nuts/blob/main/05.md) - Melt
- [NUT-07](https://github.com/cashubtc/nuts/blob/main/07.md) - Proof state
- [NUT-09](https://github.com/cashubtc/nuts/blob/main/09.md) - Restore
- [NUT-13](https://github.com/cashubtc/nuts/blob/main/13.md) - Deterministic secrets
