<?php
/**
 * Pay Lightning Address Example
 *
 * This example demonstrates how to pay a Lightning address using Cashu tokens:
 * 1. Initializing wallet with SQLite storage (REQUIRED for auto proof management)
 * 2. Resolving Lightning address via LNURL-pay
 * 3. Paying to the address with automatic proof selection and state management
 *
 * Usage:
 *   php pay_lightning_address.php <lightning_address> <amount_sats> [mint_url] [seed_phrase]
 *
 * Example:
 *   php pay_lightning_address.php user@getalby.com 21 https://testnut.cashu.space
 *   php pay_lightning_address.php user@getalby.com 21 https://testnut.cashu.space "your seed"
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\Wallet;
use Cashu\LightningAddress;
use Cashu\CashuException;
use Cashu\InsufficientBalanceException;

// Get arguments
if ($argc < 3) {
    echo "Usage: php pay_lightning_address.php <lightning_address> <amount_sats> [mint_url] [seed_phrase]\n";
    echo "\nExample:\n";
    echo "  php pay_lightning_address.php user@getalby.com 21 https://testnut.cashu.space\n";
    echo "  php pay_lightning_address.php user@getalby.com 21 https://testnut.cashu.space \"your seed\"\n";
    exit(1);
}

$address = $argv[1];
$amountSats = (int)$argv[2];
$mintUrl = $argv[3] ?? 'https://testnut.cashu.space';
$seedPhrase = $argv[4] ?? null;

echo "=== Cashu Pay Lightning Address Example ===\n\n";

try {
    // Validate Lightning address format
    echo "Validating Lightning address...\n";
    if (!LightningAddress::isValid($address)) {
        throw new CashuException("Invalid Lightning address format: $address");
    }
    echo "Address format: OK\n\n";

    // Resolve Lightning address to get payment parameters
    echo "=== RESOLVING LIGHTNING ADDRESS ===\n";
    echo "Address: $address\n";

    $metadata = LightningAddress::resolve($address);
    if ($metadata === null) {
        throw new CashuException("Failed to resolve Lightning address. Check that it exists.");
    }

    echo "Callback: " . $metadata['callback'] . "\n";
    echo "Min sendable: " . ($metadata['minSendable'] / 1000) . " sats\n";
    echo "Max sendable: " . ($metadata['maxSendable'] / 1000) . " sats\n";
    echo "Comment allowed: " . ($metadata['commentAllowed'] > 0 ? $metadata['commentAllowed'] . " chars" : "No") . "\n\n";

    // Check amount limits
    $amountMsats = $amountSats * 1000;
    if ($amountMsats < $metadata['minSendable']) {
        throw new CashuException("Amount too low. Minimum: " . ($metadata['minSendable'] / 1000) . " sats");
    }
    if ($amountMsats > $metadata['maxSendable']) {
        throw new CashuException("Amount too high. Maximum: " . ($metadata['maxSendable'] / 1000) . " sats");
    }

    // Initialize wallet with SQLite storage
    $dbPath = __DIR__ . '/wallet.sqlite';
    echo "=== INITIALIZING WALLET ===\n";
    echo "Mint: $mintUrl\n";
    echo "Database: $dbPath\n";

    $wallet = new Wallet($mintUrl, 'sat', $dbPath);
    $wallet->loadMint();
    echo "Mint loaded successfully\n\n";

    // Initialize or generate seed
    if ($seedPhrase) {
        echo "Using provided seed phrase\n";
        $wallet->initFromMnemonic($seedPhrase);
    } else {
        echo "Generating new seed phrase...\n";
        $seedPhrase = $wallet->generateMnemonic();
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║  IMPORTANT: SAVE YOUR SEED PHRASE!                          ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  $seedPhrase\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        echo "║  Without this phrase, you CANNOT recover your tokens!       ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    // Check current balance
    $proofs = $wallet->getStoredProofs();
    $balance = Wallet::sumProofs($proofs);

    echo "=== WALLET BALANCE ===\n";
    echo "Current balance: $balance sats\n";
    echo "Proofs count: " . count($proofs) . "\n\n";

    if ($balance === 0) {
        echo "=== NO FUNDS ===\n";
        echo "Your wallet is empty. You need to mint or receive tokens first.\n";
        echo "Use mint_tokens.php or receive_token.php to add funds.\n";
        exit(1);
    }

    // Get melt quote to check total needed
    echo "=== GETTING MELT QUOTE ===\n";
    $invoice = LightningAddress::getInvoice($address, $amountSats);
    echo "Invoice: " . substr($invoice, 0, 50) . "...\n";

    $quote = $wallet->requestMeltQuote($invoice);
    echo "Quote ID: " . $quote->quote . "\n";
    echo "Amount: " . $quote->amount . " sats\n";
    echo "Fee reserve: " . $quote->feeReserve . " sats\n";
    echo "Total needed: " . ($quote->amount + $quote->feeReserve) . " sats\n\n";

    $totalNeeded = $quote->amount + $quote->feeReserve;
    if ($balance < $totalNeeded) {
        throw new InsufficientBalanceException(
            "Insufficient balance. Have: $balance sats, Need: $totalNeeded sats"
        );
    }

    // Confirm payment
    echo "=== CONFIRM PAYMENT ===\n";
    echo "Paying to: $address\n";
    echo "Amount: $amountSats sats\n";
    echo "Max fee: " . $quote->feeReserve . " sats\n";
    echo "Your balance: $balance sats\n\n";

    // Pay to Lightning address (auto-manages proof state)
    echo "=== PAYING ===\n";
    echo "Sending payment...\n";

    $result = $wallet->payToLightningAddress($address, $amountSats);

    echo "\n=== PAYMENT RESULT ===\n";
    echo "Paid: " . ($result['paid'] ? 'YES' : 'NO') . "\n";
    echo "Amount: " . $result['amount'] . " sats\n";
    echo "Fee: " . $result['fee'] . " sats\n";

    if ($result['preimage']) {
        echo "Preimage: " . $result['preimage'] . "\n";
    }

    // Show change info
    if (!empty($result['change'])) {
        $changeAmount = Wallet::sumProofs($result['change']);
        echo "\n=== CHANGE ===\n";
        echo "Change proofs: " . count($result['change']) . "\n";
        echo "Change amount: $changeAmount sats\n";
    }

    // Show new balance
    $newProofs = $wallet->getStoredProofs();
    $newBalance = Wallet::sumProofs($newProofs);

    echo "\n=== UPDATED BALANCE ===\n";
    echo "Previous balance: $balance sats\n";
    echo "New balance: $newBalance sats\n";
    echo "Total spent: " . ($balance - $newBalance) . " sats\n";

    echo "\n=== PAYMENT SUCCESSFUL ===\n";
    echo "Paid $amountSats sats to $address\n";

} catch (InsufficientBalanceException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (CashuException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
