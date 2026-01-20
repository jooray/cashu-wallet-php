<?php
/**
 * Melt Tokens Example
 *
 * This example demonstrates how to pay a Lightning invoice using Cashu tokens:
 * 1. Loading proofs from a file
 * 2. Initializing wallet with seed (REQUIRED for change handling)
 * 3. Requesting a melt quote for an invoice
 * 4. Selecting proofs to cover the amount + fees
 * 5. Melting the tokens to pay the invoice
 * 6. Handling change
 *
 * Usage:
 *   php melt_tokens.php <lightning_invoice> <proofs_file> [mint_url] [seed_phrase]
 *
 * Example:
 *   php melt_tokens.php lnbc100n1p... proofs.json
 *   php melt_tokens.php lnbc100n1p... proofs.json https://testnut.cashu.space "your seed"
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\Wallet;
use Cashu\Proof;
use Cashu\CashuException;
use Cashu\InsufficientBalanceException;

// Get arguments
if ($argc < 3) {
    echo "Usage: php melt_tokens.php <lightning_invoice> <proofs_file> [mint_url] [seed_phrase]\n";
    echo "\nExample:\n";
    echo "  php melt_tokens.php lnbc100n1p... proofs.json\n";
    echo "  php melt_tokens.php lnbc100n1p... proofs.json https://testnut.cashu.space \"your seed\"\n";
    exit(1);
}

$invoice = $argv[1];
$proofsFile = $argv[2];
$mintUrl = $argv[3] ?? 'https://testnut.cashu.space';
$seedPhrase = $argv[4] ?? null;

echo "=== Cashu Token Melt Example ===\n\n";

try {
    // Load proofs from file
    echo "Loading proofs from: $proofsFile\n";

    if (!file_exists($proofsFile)) {
        throw new CashuException("Proofs file not found: $proofsFile");
    }

    $proofsJson = file_get_contents($proofsFile);
    $proofsData = json_decode($proofsJson, true);

    if (!$proofsData) {
        throw new CashuException("Invalid proofs file format");
    }

    $proofs = array_map(fn($p) => Proof::fromArray($p), $proofsData);
    $totalBalance = Wallet::sumProofs($proofs);

    echo "Loaded " . count($proofs) . " proof(s)\n";
    echo "Total balance: $totalBalance sats\n\n";

    // Initialize wallet
    echo "Connecting to mint: $mintUrl\n";
    $wallet = new Wallet($mintUrl);
    $wallet->loadMint();
    echo "Mint loaded successfully\n\n";

    // Initialize seed (REQUIRED for receiving change)
    if ($seedPhrase) {
        echo "Using provided seed phrase\n\n";
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

    // Request melt quote
    echo "=== MELT QUOTE ===\n";
    echo "Getting quote for invoice...\n";
    echo "Invoice: " . substr($invoice, 0, 50) . "...\n\n";

    $quote = $wallet->requestMeltQuote($invoice);

    echo "Quote ID: " . $quote->quote . "\n";
    echo "Amount: " . $quote->amount . " sats\n";
    echo "Fee Reserve: " . $quote->feeReserve . " sats\n";
    echo "Total needed: " . ($quote->amount + $quote->feeReserve) . " sats\n";
    echo "State: " . $quote->state . "\n\n";

    $totalNeeded = $quote->amount + $quote->feeReserve;

    if ($totalBalance < $totalNeeded) {
        throw new InsufficientBalanceException(
            "Insufficient balance: have $totalBalance sats, need $totalNeeded sats"
        );
    }

    // Select proofs to cover the amount
    echo "=== SELECTING PROOFS ===\n";
    $selectedProofs = Wallet::selectProofs($proofs, $totalNeeded);
    $selectedAmount = Wallet::sumProofs($selectedProofs);

    echo "Selected " . count($selectedProofs) . " proof(s)\n";
    echo "Selected amount: $selectedAmount sats\n";
    echo "Expected change: " . ($selectedAmount - $totalNeeded) . " sats\n\n";

    // Confirm before melting
    echo "=== CONFIRM PAYMENT ===\n";
    echo "You are about to pay:\n";
    echo "  Invoice amount: " . $quote->amount . " sats\n";
    echo "  Fee reserve: " . $quote->feeReserve . " sats\n";
    echo "  Total: $totalNeeded sats\n";
    echo "  Your balance: $selectedAmount sats\n";
    echo "  Expected change: " . ($selectedAmount - $totalNeeded) . " sats\n\n";

    // In a real app, you'd prompt for confirmation here
    // For this example, we proceed automatically

    // Melt tokens
    echo "=== MELTING TOKENS ===\n";
    echo "Paying invoice...\n";

    $result = $wallet->melt($quote->quote, $selectedProofs);

    echo "\n=== PAYMENT RESULT ===\n";
    echo "Paid: " . ($result['paid'] ? 'YES' : 'NO') . "\n";

    if ($result['preimage']) {
        echo "Preimage: " . $result['preimage'] . "\n";
    }

    if (!empty($result['change'])) {
        echo "\n=== CHANGE RECEIVED ===\n";
        $changeAmount = Wallet::sumProofs($result['change']);
        echo "Received " . count($result['change']) . " change proof(s)\n";
        echo "Change amount: $changeAmount sats\n\n";

        foreach ($result['change'] as $i => $proof) {
            echo "Change " . ($i + 1) . ":\n";
            echo "  Amount: " . $proof->amount . " sats\n";
            echo "  Keyset: " . $proof->id . "\n";
        }

        // Save change proofs
        $changeJson = json_encode(
            array_map(fn($p) => $p->toArray(true), $result['change']),
            JSON_PRETTY_PRINT
        );
        $changeFile = __DIR__ . '/change_' . time() . '.json';
        file_put_contents($changeFile, $changeJson);
        echo "\nChange proofs saved to: $changeFile\n";
    } else {
        echo "\nNo change (exact amount spent)\n";
    }

    // Calculate remaining balance
    $usedProofSecrets = array_map(fn($p) => $p->secret, $selectedProofs);
    $remainingProofs = array_filter(
        $proofs,
        fn($p) => !in_array($p->secret, $usedProofSecrets)
    );

    if (!empty($remainingProofs)) {
        $remainingBalance = Wallet::sumProofs(array_values($remainingProofs));
        echo "\n=== REMAINING BALANCE ===\n";
        echo "Unused proofs: " . count($remainingProofs) . "\n";
        echo "Remaining balance: $remainingBalance sats\n";

        // Save remaining proofs
        $remainingJson = json_encode(
            array_map(fn($p) => $p->toArray(true), array_values($remainingProofs)),
            JSON_PRETTY_PRINT
        );
        $remainingFile = __DIR__ . '/remaining_' . time() . '.json';
        file_put_contents($remainingFile, $remainingJson);
        echo "Remaining proofs saved to: $remainingFile\n";
    }

    if ($result['paid']) {
        echo "\n=== PAYMENT SUCCESSFUL ===\n";
    } else {
        echo "\n=== PAYMENT PENDING/FAILED ===\n";
        echo "Check the melt quote status: " . $quote->quote . "\n";
    }

} catch (InsufficientBalanceException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (CashuException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
