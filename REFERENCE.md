# API Reference

Complete API reference for the CashuWallet PHP library.

## Namespace

All classes are in the `Cashu` namespace:

```php
use Cashu\Wallet;
use Cashu\Proof;
use Cashu\Token;
use Cashu\TokenSerializer;
use Cashu\MintQuote;
use Cashu\MeltQuote;
use Cashu\Keyset;
use Cashu\Unit;
use Cashu\BigInt;
use Cashu\Mnemonic;
use Cashu\LightningAddress;
use Cashu\CashuException;
use Cashu\CashuProtocolException;
use Cashu\InsufficientBalanceException;
```

---

## Exceptions

### CashuException

Base exception for all Cashu errors.

```php
class CashuException extends \Exception {}
```

### CashuProtocolException

Protocol error returned from the mint.

```php
class CashuProtocolException extends CashuException
{
    public function __construct(string $message, ?int $code = null);
}
```

**Properties:**
- `getMessage()`: Error description from mint
- `getCode()`: Cashu protocol error code (if provided)

### InsufficientBalanceException

Thrown when token balance is insufficient for an operation.

```php
class InsufficientBalanceException extends CashuException {}
```

---

## Wallet Class

Main class for interacting with Cashu mints.

### Constructor

```php
public function __construct(string $mintUrl, string $unit = 'sat')
```

**Parameters:**
- `$mintUrl`: URL of the Cashu mint (e.g., `'https://testnut.cashu.space'`)
- `$unit`: Currency unit (default: `'sat'`)

**Example:**
```php
$wallet = new Wallet('https://testnut.cashu.space', 'sat');
```

---

### Initialization Methods

#### loadMint()

```php
public function loadMint(): void
```

Load mint information and keysets. Must be called before other operations.

**Throws:** `CashuException` if no active keysets found for unit.

**Example:**
```php
$wallet = new Wallet('https://testnut.cashu.space');
$wallet->loadMint();
```

---

#### getSupportedUnits()

```php
public static function getSupportedUnits(string $mintUrl): array
```

Query available units on a mint (static method, before wallet creation).

**Parameters:**
- `$mintUrl`: The mint URL to query

**Returns:** `array<string, array{keysets: array, activeCount: int, totalCount: int}>`

**Example:**
```php
$units = Wallet::getSupportedUnits('https://testnut.cashu.space');
// ['sat' => ['keysets' => [...], 'activeCount' => 1, 'totalCount' => 2]]
```

---

#### initFromMnemonic()

```php
public function initFromMnemonic(string $mnemonic, string $passphrase = ''): void
```

Initialize wallet from a BIP-39 mnemonic phrase.

**Parameters:**
- `$mnemonic`: 12/15/18/21/24-word BIP-39 phrase
- `$passphrase`: Optional BIP-39 passphrase extension

**Throws:** `CashuException` if mnemonic is invalid.

**Example:**
```php
$wallet->initFromMnemonic('abandon ability able about above absent...');
```

---

#### generateMnemonic()

```php
public function generateMnemonic(): string
```

Generate a new 12-word mnemonic and initialize the wallet.

**Returns:** The generated mnemonic phrase (back this up!).

**Example:**
```php
$seedPhrase = $wallet->generateMnemonic();
echo "Save this: $seedPhrase";
```

---

#### hasSeed()

```php
public function hasSeed(): bool
```

Check if wallet has a seed initialized.

**Returns:** `true` if seed is set.

---

#### getMnemonic()

```php
public function getMnemonic(): ?string
```

Get the current mnemonic phrase.

**Returns:** Mnemonic string or `null` if not initialized.

---

### Minting Methods (Lightning to Tokens)

#### requestMintQuote()

```php
public function requestMintQuote(int $amount): MintQuote
```

Request a mint quote (Lightning invoice to pay).

**Parameters:**
- `$amount`: Amount in smallest unit (satoshis, cents, etc.)

**Returns:** `MintQuote` with invoice details.

**Example:**
```php
$quote = $wallet->requestMintQuote(100);
echo "Pay: " . $quote->request; // Lightning invoice
```

---

#### checkMintQuote()

```php
public function checkMintQuote(string $quoteId): MintQuote
```

Check the payment status of a mint quote.

**Parameters:**
- `$quoteId`: Quote ID from `requestMintQuote()`

**Returns:** Updated `MintQuote` with current state.

**Example:**
```php
$status = $wallet->checkMintQuote($quote->quote);
if ($status->isPaid()) {
    // Ready to mint
}
```

---

#### mint()

```php
public function mint(string $quoteId, int $amount): array
```

Mint tokens after quote is paid.

**Parameters:**
- `$quoteId`: Quote ID from `requestMintQuote()`
- `$amount`: Amount to mint

**Returns:** `Proof[]` - Array of minted proofs.

**Throws:** `CashuException` if no seed initialized.

**Example:**
```php
$proofs = $wallet->mint($quote->quote, 100);
echo "Minted: " . Wallet::sumProofs($proofs) . " sat";
```

---

### Melting Methods (Tokens to Lightning)

#### requestMeltQuote()

```php
public function requestMeltQuote(string $invoice): MeltQuote
```

Request a melt quote (fee estimate for paying invoice).

**Parameters:**
- `$invoice`: Lightning invoice (BOLT11)

**Returns:** `MeltQuote` with amount and fee information.

**Example:**
```php
$quote = $wallet->requestMeltQuote('lnbc100n1p...');
echo "Amount: " . $quote->amount . ", Fee: " . $quote->feeReserve;
```

---

#### checkMeltQuote()

```php
public function checkMeltQuote(string $quoteId): MeltQuote
```

Check status of a melt quote.

**Parameters:**
- `$quoteId`: Quote ID from `requestMeltQuote()`

**Returns:** Updated `MeltQuote`.

---

#### melt()

```php
public function melt(string $quoteId, array $proofs): array
```

Melt tokens to pay a Lightning invoice.

**Parameters:**
- `$quoteId`: Quote ID from `requestMeltQuote()`
- `$proofs`: `Proof[]` to spend (must cover amount + fee reserve)

**Returns:** `array{paid: bool, preimage: ?string, change: Proof[]}`

**Example:**
```php
$result = $wallet->melt($quote->quote, $selectedProofs);
if ($result['paid']) {
    echo "Preimage: " . $result['preimage'];
    // Handle $result['change'] proofs
}
```

---

#### payToLightningAddress()

```php
public function payToLightningAddress(
    string $address,
    int $amountSats,
    ?string $comment = null
): array
```

Pay to a Lightning address using stored proofs. Combines LNURL-pay resolution with melt operation.

**Parameters:**
- `$address`: Lightning address (user@domain)
- `$amountSats`: Amount in satoshis to pay
- `$comment`: Optional payment comment (if supported by receiver)

**Returns:** `array{paid: bool, preimage: ?string, amount: int, fee: int, change: Proof[]}`

**Throws:**
- `CashuException` if storage is not configured
- `CashuException` if Lightning address resolution fails
- `CashuException` if Lightning payment fails
- `InsufficientBalanceException` if not enough balance

**Auto-sync behavior:**
- Input proofs marked as SPENT in storage
- Change proofs stored automatically
- No manual state management required

**Example:**
```php
// Requires storage
$wallet = new Wallet('https://testnut.cashu.space', 'sat', '/path/to/wallet.db');
$wallet->loadMint();
$wallet->initFromMnemonic('your seed phrase');

$result = $wallet->payToLightningAddress('user@getalby.com', 100, 'Thanks!');
if ($result['paid']) {
    echo "Paid " . $result['amount'] . " sats\n";
    echo "Fee: " . $result['fee'] . " sats\n";
    echo "Preimage: " . $result['preimage'] . "\n";
}
```

---

### Swap/Split Methods

#### swap()

```php
public function swap(array $proofs, array $amounts): array
```

Swap proofs for new proofs with specified amounts.

**Parameters:**
- `$proofs`: `Proof[]` input proofs
- `$amounts`: `int[]` desired output amounts (must equal input - fee)

**Returns:** `Proof[]` new proofs.

**Example:**
```php
$newProofs = $wallet->swap($proofs, [64, 32, 4]); // 100 sat in 3 proofs
```

---

#### split()

```php
public function split(array $proofs, int $amount): array
```

Split proofs to send a specific amount.

**Parameters:**
- `$proofs`: `Proof[]` input proofs
- `$amount`: Amount to send

**Returns:** `array{send: Proof[], keep: Proof[], fee: int}`

**Throws:** `InsufficientBalanceException` if not enough balance.

**Example:**
```php
$result = $wallet->split($proofs, 50);
$sendToken = $wallet->serializeToken($result['send']);
// Keep $result['keep'] proofs
```

---

### Token Methods

#### serializeToken()

```php
public function serializeToken(
    array $proofs,
    string $format = 'v4',
    ?string $memo = null,
    bool $includeDleq = false
): string
```

Serialize proofs to a token string.

**Parameters:**
- `$proofs`: `Proof[]` to serialize
- `$format`: `'v4'` (cashuB, CBOR) or `'v3'` (cashuA, JSON)
- `$memo`: Optional memo to include
- `$includeDleq`: Include DLEQ proofs (default: false)

**Returns:** Token string starting with `cashuA` or `cashuB`.

**Example:**
```php
$token = $wallet->serializeToken($proofs, 'v4', 'Payment for coffee');
```

---

#### deserializeToken()

```php
public function deserializeToken(string $tokenString): Token
```

Deserialize a token string.

**Parameters:**
- `$tokenString`: Token starting with `cashuA` or `cashuB`

**Returns:** `Token` object.

---

#### receive()

```php
public function receive(string $tokenString): array
```

Receive a token by swapping for fresh proofs.

**Parameters:**
- `$tokenString`: Token to receive

**Returns:** `Proof[]` new proofs owned by this wallet.

**Throws:**
- `CashuException` if token from different mint
- `CashuException` if amount <= fee

**Example:**
```php
$newProofs = $wallet->receive($tokenString);
```

---

### Proof State Methods

#### checkProofState()

```php
public function checkProofState(array $proofs): array
```

Check the spent/pending state of proofs.

**Parameters:**
- `$proofs`: `Proof[]` to check

**Returns:** Array of state objects with `'state'` key (`'UNSPENT'`, `'SPENT'`, `'PENDING'`).

**Example:**
```php
$states = $wallet->checkProofState($proofs);
foreach ($states as $state) {
    echo $state['state']; // UNSPENT, SPENT, or PENDING
}
```

---

### Seed/Counter Methods

#### getCounter()

```php
public function getCounter(string $keysetId): int
```

Get current counter for a keyset.

---

#### setCounter()

```php
public function setCounter(string $keysetId, int $counter): void
```

Set counter for a keyset.

---

#### getCounters()

```php
public function getCounters(): array
```

Get all keyset counters.

**Returns:** `array<string, int>` keyset ID to counter.

---

#### setCounters()

```php
public function setCounters(array $counters): void
```

Set all counters at once (useful for restore).

---

#### generateDeterministicSecret()

```php
public function generateDeterministicSecret(string $keysetId, int $counter): array
```

Generate deterministic secret and blinding factor for a keyset/counter.

**Returns:** `array{secret: string, r: BigInt}`

---

### Restore Methods

#### restoreBatch()

```php
public function restoreBatch(string $keysetId, int $fromCounter, int $batchSize): array
```

Restore tokens for a counter range in one batch.

**Parameters:**
- `$keysetId`: Keyset to restore
- `$fromCounter`: Starting counter
- `$batchSize`: Number of counters to check

**Returns:** `Proof[]` recovered proofs.

---

#### restore()

```php
public function restore(
    int $batchSize = 25,
    int $emptyBatches = 3,
    ?callable $progressCallback = null,
    bool $allUnits = true
): array
```

Full wallet restore - scan all keysets across all units.

**Parameters:**
- `$batchSize`: Counters per batch (default: 25)
- `$emptyBatches`: Stop after N consecutive empty batches (default: 3)
- `$progressCallback`: Called with `(keysetId, counter, proofsFound, unit)`
- `$allUnits`: Restore ALL units from the mint (default: true)

**Returns:** `array{proofs: Proof[], counters: array, byUnit: array}`
- `proofs`: All recovered proofs across all units
- `counters`: All keyset counters
- `byUnit`: Results grouped by unit: `['unit' => ['proofs' => Proof[], 'counters' => array]]`

**⚠️ WARNING:** Setting `$allUnits` to `false` is dangerous and can cause **proof reuse**.
Melt operations return fee reserve change in sats regardless of the original unit. If you
only restore your primary unit, those sat proofs are missed, and their counter values may
be reused when later minting sats - generating duplicate secrets and losing funds.

**Example:**
```php
$result = $wallet->restore(25, 3, function($ks, $ctr, $found, $unit) {
    echo "[$unit] Scanning $ks at $ctr: found $found\n";
});

// Results by unit
foreach ($result['byUnit'] as $unit => $data) {
    echo "$unit: " . count($data['proofs']) . " proofs\n";
}

// All proofs
$allProofs = $result['proofs'];
```

---

### Utility Methods (Static)

#### sumProofs()

```php
public static function sumProofs(array $proofs): int
```

Sum the amounts of proofs.

**Example:**
```php
$total = Wallet::sumProofs($proofs); // 150
```

---

#### selectProofs()

```php
public static function selectProofs(array $proofs, int $amount): array
```

Select proofs to meet a target amount (greedy, largest first).

**Throws:** `InsufficientBalanceException` if not enough.

**Example:**
```php
$selected = Wallet::selectProofs($proofs, 100);
```

---

#### splitAmount()

```php
public static function splitAmount(int $amount): array
```

Split an amount into powers of 2.

**Example:**
```php
Wallet::splitAmount(100); // [4, 32, 64]
```

---

#### formatAmountForUnit()

```php
public static function formatAmountForUnit(int $amount, string $unit): string
```

Format amount for any unit (static helper).

**Example:**
```php
Wallet::formatAmountForUnit(150, 'usd'); // "$1.50"
```

---

### Getter Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `getMintUrl()` | `string` | Mint URL |
| `getUnit()` | `string` | Currency unit |
| `getMintInfo()` | `?array` | Mint info from `/v1/info` |
| `getKeysets()` | `Keyset[]` | Loaded keysets |
| `getActiveKeysetId()` | `string` | Active keyset ID |
| `getPublicKey($keysetId, $amount)` | `string` | Public key (hex) |
| `getInputFeePpk($keysetId)` | `int` | Fee rate in PPK |
| `getUnitHelper()` | `Unit` | Unit formatting helper |

---

## Data Classes

### Proof

Represents an ecash token (value proof).

```php
class Proof
{
    public string $id;        // Keyset ID
    public int $amount;       // Amount in smallest unit
    public string $secret;    // Unique secret (hex)
    public string $C;         // Unblinded signature (hex)
    public string $Y;         // Y = hash_to_curve(secret) (computed)
    public ?DLEQWallet $dleq; // DLEQ proof (optional)
    public ?string $witness;  // Witness data (optional)
}
```

**Methods:**

```php
// Constructor
public function __construct(
    string $id,
    int $amount,
    string $secret,
    string $C,
    ?DLEQWallet $dleq = null,
    ?string $witness = null
);

// Serialize to array
public function toArray(bool $includeDleq = false): array;

// Create from array
public static function fromArray(array $data): self;
```

**Example:**
```php
$proof = Proof::fromArray([
    'id' => '00abc123...',
    'amount' => 64,
    'secret' => 'deadbeef...',
    'C' => '02abc...'
]);

$array = $proof->toArray(true); // Include DLEQ
```

---

### Token

Container for proofs from a single mint.

```php
class Token
{
    public string $mint;      // Mint URL
    public string $unit;      // Currency unit
    public array $proofs;     // Proof[]
    public ?string $memo;     // Optional memo
}
```

**Methods:**

```php
public function __construct(
    string $mint,
    string $unit,
    array $proofs,
    ?string $memo = null
);

// Get total amount
public function getAmount(): int;

// Get unique keyset IDs
public function getKeysets(): array;
```

---

### MintQuote

Response from mint quote request.

```php
class MintQuote
{
    public string $quote;     // Quote ID
    public string $request;   // Lightning invoice (BOLT11)
    public int $amount;       // Amount
    public string $state;     // UNPAID, PAID, ISSUED
    public ?int $expiry;      // Expiry timestamp
    public ?string $unit;     // Unit
}
```

**Methods:**

```php
public static function fromArray(array $data): self;
public function isPaid(): bool;    // state === 'PAID'
public function isIssued(): bool;  // state === 'ISSUED'
```

---

### MeltQuote

Response from melt quote request.

```php
class MeltQuote
{
    public string $quote;            // Quote ID
    public int $amount;              // Invoice amount
    public int $feeReserve;          // Fee reserve
    public string $state;            // UNPAID, PENDING, PAID
    public ?int $expiry;             // Expiry timestamp
    public ?string $paymentPreimage; // Payment preimage (when paid)
    public ?array $change;           // Change outputs
    public ?string $unit;            // Unit
    public ?string $request;         // Original invoice
}
```

**Methods:**

```php
public static function fromArray(array $data): self;
public function isPaid(): bool;     // state === 'PAID'
public function isPending(): bool;  // state === 'PENDING'
```

---

### Keyset

Keyset information from mint.

```php
class Keyset
{
    public string $id;        // Keyset ID (hex)
    public string $unit;      // Currency unit
    public array $keys;       // amount => public key (hex)
    public bool $active;      // Is active
    public int $inputFeePpk;  // Input fee (parts per thousand)
}
```

**Methods:**

```php
public function __construct(
    string $id,
    string $unit,
    array $keys,
    bool $active = true,
    int $inputFeePpk = 0
);

// Derive keyset ID from public keys
public static function deriveKeysetId(array $keys): string;
```

---

### Unit

Helper for amount formatting by currency unit.

```php
class Unit
{
    public readonly string $code;      // 'sat', 'usd', etc.
    public readonly int $decimals;     // 0, 2, 8
    public readonly string $symbol;    // 'sat', '$', '₿'
    public readonly string $position;  // 'before' or 'after'
}
```

**Methods:**

```php
// Create from unit code
public static function fromCode(string $code): self;

// Format amount to display string
public function format(int $amount): string;
// sat: 100 -> "100 sat"
// usd: 150 -> "$1.50"

// Parse display string to amount
public function parse(string $input): int;
// usd: "1.50" -> 150

// Get unit display name (uppercase)
public function getName(): string;

// Get example amount for prompts
public function getExampleAmount(): string;
```

**Known Units:**

| Code | Decimals | Symbol | Example |
|------|----------|--------|---------|
| sat  | 0        | sat    | 100 sat |
| msat | 0        | msat   | 100 msat |
| usd  | 2        | $      | $1.50 |
| eur  | 2        |  |0.50 |
| btc  | 8        |  | 0.00000100 |

Unknown units default to 0 decimals with code as symbol.

---

## Utility Classes

### TokenSerializer

Serialize/deserialize token strings.

```php
class TokenSerializer
{
    // Serialize to V3 (cashuA) format
    public static function serializeV3(
        string $mint,
        array $proofs,
        string $unit = 'sat',
        ?string $memo = null,
        bool $includeDleq = false
    ): string;

    // Serialize to V4 (cashuB) format
    public static function serializeV4(
        string $mint,
        array $proofs,
        string $unit = 'sat',
        ?string $memo = null,
        bool $includeDleq = false
    ): string;

    // Deserialize any format
    public static function deserialize(string $tokenString): Token;
}
```

**Example:**
```php
// Serialize
$v4 = TokenSerializer::serializeV4($mintUrl, $proofs, 'sat', 'memo');
$v3 = TokenSerializer::serializeV3($mintUrl, $proofs);

// Deserialize (auto-detects format)
$token = TokenSerializer::deserialize('cashuBo2F0gaJha...');
```

---

### BigInt

Arbitrary-precision integer arithmetic (GMP or BCMath backend).

```php
class BigInt
{
    // Factory methods
    public static function fromHex(string $hex): self;
    public static function fromDec(string|int $dec): self;
    public static function zero(): self;
    public static function one(): self;

    // Arithmetic
    public function add(BigInt $other): BigInt;
    public function sub(BigInt $other): BigInt;
    public function mul(BigInt $other): BigInt;
    public function div(BigInt $other): BigInt;
    public function mod(BigInt $m): BigInt;
    public function pow(int $exp): BigInt;
    public function powMod(BigInt $exp, BigInt $m): BigInt;
    public function neg(): BigInt;
    public function shiftRight(int $bits): BigInt;

    // Comparison
    public function cmp(BigInt $other): int;  // -1, 0, 1
    public function isZero(): bool;
    public function isOdd(): bool;
    public function isNegative(): bool;

    // Conversion
    public function toHex(int $padLength = 0): string;
    public function toDec(): string;

    // Special
    public function modInverse(BigInt $m): BigInt;
    public function bitAnd(BigInt $other): BigInt;

    // Utility
    public static function init(): void;
    public static function isUsingGmp(): bool;
}
```

---

### Mnemonic

BIP-39 mnemonic phrase handling.

```php
class Mnemonic
{
    // Generate new 12-word mnemonic
    public static function generate(): string;

    // Validate mnemonic phrase
    public static function validate(string $mnemonic): bool;

    // Convert to seed (PBKDF2)
    public static function toSeed(string $mnemonic, string $passphrase = ''): string;

    // Get word at index (for testing)
    public static function getWord(int $index): string;
}
```

**Example:**
```php
$mnemonic = Mnemonic::generate();
// "abandon ability able about..."

if (Mnemonic::validate($mnemonic)) {
    $seed = Mnemonic::toSeed($mnemonic);
}
```

---

## Constants and Formats

### Token Format Prefixes

| Prefix | Version | Encoding | Description |
|--------|---------|----------|-------------|
| `cashuA` | V3 | Base64 JSON | Legacy format |
| `cashuB` | V4 | Base64 CBOR | Modern format (recommended) |

### Derivation Paths (NUT-13)

```
m/129372'/0'/{keyset_id}'/{counter}'/0  → secret
m/129372'/0'/{keyset_id}'/{counter}'/1  → blinding factor (r)
```

- `129372'` = Cashu purpose (hardened)
- `0'` = Coin type
- `keyset_id'` = Keyset ID mod (2^31 - 1) (hardened)
- `counter'` = Counter (hardened)
- `0` or `1` = Secret or blinding factor

---

## WalletStorage Class

SQLite-based storage for wallet persistence. Created automatically when a `dbPath` is provided to the Wallet constructor.

### Constructor

```php
public function __construct(string $dbPath, string $mintUrl, string $unit = 'sat')
```

**Parameters:**
- `$dbPath`: Path to SQLite database file
- `$mintUrl`: Mint URL (used to derive wallet ID for multi-wallet support)
- `$unit`: Currency unit (default: `'sat'`) - different units have separate wallets

---

### Static Methods

#### listWallets()

```php
public static function listWallets(string $dbPath): array
```

List all wallets in the database with their statistics. Static method that can be called without creating a wallet instance. Useful for discovery and debugging when you don't know which mints are stored.

**Parameters:**
- `$dbPath`: Path to SQLite database file

**Returns:** Array of wallet info arrays, each containing:
- `wallet_id`: string (hash of mint URL + unit)
- `total_proofs`: int
- `unspent`: int (count of unspent proofs)
- `spent`: int (count of spent proofs)
- `pending`: int (count of pending proofs)
- `balance`: int (sum of unspent amounts)
- `keyset_ids`: string[] (unique keyset IDs for this wallet)

**Example:**
```php
$wallets = WalletStorage::listWallets('/path/to/wallet.db');
foreach ($wallets as $wallet) {
    echo "Wallet {$wallet['wallet_id']}: ";
    echo "{$wallet['unspent']} unspent proofs, ";
    echo "balance: {$wallet['balance']}\n";
    echo "  Keysets: " . implode(', ', $wallet['keyset_ids']) . "\n";
}
```

---

### Proof Management

#### storeProofs()

```php
public function storeProofs(array $proofs, ?string $quoteId = null): void
```

Store proofs in the database.

**Parameters:**
- `$proofs`: `Proof[]` to store
- `$quoteId`: Optional mint quote ID for tracking

---

#### getProofs()

```php
public function getProofs(string $state = 'UNSPENT'): array
```

Get proofs by state.

**Parameters:**
- `$state`: `'UNSPENT'`, `'PENDING'`, or `'SPENT'`

**Returns:** Array of proof data arrays.

---

#### getProofsByQuoteId()

```php
public function getProofsByQuoteId(string $quoteId): array
```

Find proofs that were minted for a specific quote. Useful for orphan recovery when an invoice's status wasn't updated after minting.

**Parameters:**
- `$quoteId`: Mint quote ID

**Returns:** Array of proof data arrays.

**Example:**
```php
// Check if proofs exist for a stuck invoice
$proofs = $storage->getProofsByQuoteId($invoice['quote_id']);
if (!empty($proofs)) {
    // Proofs exist - invoice can be marked as settled
}
```

---

#### updateProofsState()

```php
public function updateProofsState(array $secrets, string $state): void
```

Update the state of proofs by their secrets.

**Parameters:**
- `$secrets`: Array of secret strings
- `$state`: New state (`'UNSPENT'`, `'PENDING'`, `'SPENT'`)

---

#### deleteProofs()

```php
public function deleteProofs(array $secrets): void
```

Delete proofs by their secrets.

---

### Counter Management

#### getCounter() / setCounter()

```php
public function getCounter(string $keysetId): int
public function setCounter(string $keysetId, int $counter): void
```

Get/set counter for a keyset.

---

#### incrementCounter()

```php
public function incrementCounter(string $keysetId): int
```

Atomically increment counter and return the old value.

---

#### getAllCounters()

```php
public function getAllCounters(): array
```

Get all keyset counters.

**Returns:** `array<string, int>` keyset ID to counter.

---

### Pending Operations

#### savePendingOperation()

```php
public function savePendingOperation(string $id, string $type, array $data, ?int $expiresAt = null): void
```

Save a pending operation for crash recovery.

**Parameters:**
- `$id`: Unique operation ID
- `$type`: Operation type (e.g., `'mint'`, `'melt'`, `'swap'`)
- `$data`: Operation data
- `$expiresAt`: Optional expiration timestamp

---

#### getPendingOperations()

```php
public function getPendingOperations(?string $type = null): array
```

Get pending operations, optionally filtered by type.

---

#### deletePendingOperation()

```php
public function deletePendingOperation(string $id): void
```

Delete a pending operation.

---

#### cleanExpiredPendingOperations()

```php
public function cleanExpiredPendingOperations(): int
```

Remove pending operations that have passed their expiration time. Useful for periodic cleanup.

**Returns:** Number of deleted operations.

**Example:**
```php
// Periodic cleanup
$cleaned = $storage->cleanExpiredPendingOperations();
echo "Cleaned $cleaned expired operations\n";
```

---

### Transaction Support

```php
public function inTransaction(): bool
public function beginTransaction(): bool
public function commit(): bool
public function rollBack(): bool
```

Standard PDO transaction methods for atomic operations.

---

### Utility Methods

#### getWalletId()

```php
public function getWalletId(): string
```

Get the wallet ID (hash of mint URL and unit).

---

#### getPdo()

```php
public function getPdo(): \PDO
```

Get the PDO instance for advanced operations.

---

## LightningAddress Class

Static class for LNURL-pay (Lightning Address) resolution and invoice generation.

### isValid()

```php
public static function isValid(string $address): bool
```

Validate a Lightning address format.

**Parameters:**
- `$address`: Lightning address to validate

**Returns:** `true` if format is valid (user@domain).

**Example:**
```php
if (LightningAddress::isValid('user@getalby.com')) {
    echo "Valid Lightning address\n";
}
```

---

### resolve()

```php
public static function resolve(string $address): ?array
```

Resolve Lightning address to LNURL-pay metadata.

**Parameters:**
- `$address`: Lightning address (user@domain)

**Returns:** Array with LNURL metadata or `null` if resolution fails:
```php
[
    'callback' => string,      // URL to request invoice from
    'minSendable' => int,      // Minimum amount in millisatoshis
    'maxSendable' => int,      // Maximum amount in millisatoshis
    'commentAllowed' => int,   // Max comment length (0 = no comments)
    'metadata' => string,      // Service metadata
    'tag' => string,           // LNURL tag (usually 'payRequest')
]
```

**Example:**
```php
$metadata = LightningAddress::resolve('user@getalby.com');
if ($metadata !== null) {
    echo "Min: " . ($metadata['minSendable'] / 1000) . " sats\n";
    echo "Max: " . ($metadata['maxSendable'] / 1000) . " sats\n";
    echo "Comment: " . ($metadata['commentAllowed'] ?: 'Not allowed') . "\n";
}
```

---

### getInvoice()

```php
public static function getInvoice(
    string $address,
    int $amountSats,
    ?string $comment = null
): string
```

Get a BOLT11 invoice from a Lightning address.

**Parameters:**
- `$address`: Lightning address (user@domain)
- `$amountSats`: Amount in satoshis
- `$comment`: Optional payment comment (truncated to allowed length)

**Returns:** BOLT11 invoice string.

**Throws:**
- `CashuException` if address resolution fails
- `CashuException` if amount is outside min/max range
- `CashuException` if invoice request fails

**Example:**
```php
try {
    $invoice = LightningAddress::getInvoice('user@getalby.com', 100, 'Thanks!');
    echo "Invoice: $invoice\n";
} catch (CashuException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

---

## Internal Classes

These classes are used internally but exposed for advanced use:

### Secp256k1

Elliptic curve operations on secp256k1.

```php
class Secp256k1
{
    public static function getGenerator(): array;           // [BigInt, BigInt]
    public static function getOrder(): BigInt;              // Curve order n
    public static function getPrime(): BigInt;              // Field prime p
    public static function pointAdd(?array $p1, ?array $p2): ?array;
    public static function scalarMult(BigInt $k, ?array $point): ?array;
    public static function pointSub(?array $p1, ?array $p2): ?array;
    public static function compressPoint(array $point): string;    // 33 bytes
    public static function decompressPoint(string $compressed): array;
    public static function isOnCurve(array $point): bool;
    public static function randomScalar(): BigInt;
}
```

### Crypto

BDHKE (Blind Diffie-Hellman Key Exchange) implementation.

```php
class Crypto
{
    public static function hashToCurve(string $message): array;
    public static function generateSecret(): string;
    public static function generateBlindingFactor(): BigInt;
    public static function createBlindedMessage(string $secret): array;
    public static function unblindSignature(string $C_, BigInt $r, string $A): string;
    public static function computeY(string $secret): string;
}
```

### BIP32

HD key derivation.

```php
class BIP32
{
    public static function fromSeed(string $seed): self;
    public function derivePath(string $path): string;  // Returns private key hex
    public function getPrivateKeyHex(): string;
}
```

### CBOR

Minimal CBOR encoder/decoder for V4 tokens.

```php
class CBOR
{
    public static function encode($value): string;
    public static function decode(string $data);
}
```

---

## Error Codes

Common Cashu protocol error codes (returned in `CashuProtocolException`):

| Code | Description |
|------|-------------|
| 10000 | Token already spent |
| 10001 | Quote not paid |
| 10002 | Quote expired |
| 10003 | Invalid keyset |
| 11001 | Insufficient funds in mint |
| 11002 | Lightning payment failed |

Check the [Cashu NUT specifications](https://github.com/cashubtc/nuts) for complete error codes.
