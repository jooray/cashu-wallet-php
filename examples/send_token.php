<?php
/**
 * Send Token Example
 *
 * This example demonstrates how to send Cashu tokens:
 * 1. Loading proofs from a file
 * 2. Initializing wallet with seed (REQUIRED for splitting)
 * 3. Splitting proofs to exact amount
 * 4. Serializing to a token string
 *
 * Usage:
 *   php send_token.php <amount> <proofs_file> [mint_url] [seed_phrase]
 *
 * Example:
 *   php send_token.php 50 proofs.json https://testnut.cashu.space
 *   php send_token.php 50 proofs.json https://testnut.cashu.space "your seed phrase"
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\Wallet;
use Cashu\Proof;
use Cashu\CashuException;
use Cashu\InsufficientBalanceException;

// Get arguments
if ($argc < 3) {
    echo "Usage: php send_token.php <amount> <proofs_file> [mint_url] [seed_phrase]\n";
    echo "\nExample:\n";
    echo "  php send_token.php 50 proofs.json https://testnut.cashu.space\n";
    echo "  php send_token.php 50 proofs.json https://testnut.cashu.space \"your seed phrase\"\n";
    exit(1);
}

$amount = (int)$argv[1];
$proofsFile = $argv[2];
$mintUrl = $argv[3] ?? 'https://testnut.cashu.space';
$seedPhrase = $argv[4] ?? null;

echo "=== Cashu Token Send Example ===\n\n";

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

    // Check if we have enough balance
    if ($totalBalance < $amount) {
        throw new InsufficientBalanceException(
            "Insufficient balance: have $totalBalance sats, want to send $amount sats"
        );
    }

    // Initialize wallet
    echo "Connecting to mint: $mintUrl\n";
    $wallet = new Wallet($mintUrl);
    $wallet->loadMint();
    echo "Mint loaded successfully\n\n";

    // Initialize seed (REQUIRED for splitting/swapping tokens)
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

    // Show current proofs
    echo "=== CURRENT PROOFS ===\n";
    $denominations = [];
    foreach ($proofs as $proof) {
        $amt = $proof->amount;
        $denominations[$amt] = ($denominations[$amt] ?? 0) + 1;
    }
    ksort($denominations);
    foreach ($denominations as $amt => $count) {
        echo "  $count x $amt sats\n";
    }
    echo "  Total: $totalBalance sats\n\n";

    // Check if we can send exact amount without splitting
    $exactMatch = false;
    $sendProofs = [];
    $keepProofs = [];

    // Try to find exact combination
    $selectedSum = 0;
    usort($proofs, fn($a, $b) => $b->amount - $a->amount);

    foreach ($proofs as $proof) {
        if ($selectedSum + $proof->amount <= $amount) {
            $sendProofs[] = $proof;
            $selectedSum += $proof->amount;
        } else {
            $keepProofs[] = $proof;
        }
    }

    if ($selectedSum === $amount) {
        $exactMatch = true;
        echo "=== EXACT MATCH FOUND ===\n";
        echo "Can send exact amount without splitting\n\n";
    } else {
        echo "=== SPLITTING REQUIRED ===\n";
        echo "Need to split proofs to get exact amount\n";
        echo "Performing swap operation...\n\n";

        // Need to split - use wallet's split function
        $result = $wallet->split($proofs, $amount);
        $sendProofs = $result['send'];
        $keepProofs = $result['keep'];
    }

    // Display send proofs
    $sendAmount = Wallet::sumProofs($sendProofs);
    echo "=== PROOFS TO SEND ===\n";
    echo "Count: " . count($sendProofs) . " proof(s)\n";
    foreach ($sendProofs as $i => $proof) {
        echo "  " . ($i + 1) . ". " . $proof->amount . " sats\n";
    }
    echo "Total: $sendAmount sats\n\n";

    // Display keep proofs
    if (!empty($keepProofs)) {
        $keepAmount = Wallet::sumProofs($keepProofs);
        echo "=== PROOFS TO KEEP ===\n";
        echo "Count: " . count($keepProofs) . " proof(s)\n";
        foreach ($keepProofs as $i => $proof) {
            echo "  " . ($i + 1) . ". " . $proof->amount . " sats\n";
        }
        echo "Total: $keepAmount sats\n\n";
    }

    // Serialize to token string (V4 format)
    $tokenV4 = $wallet->serializeToken($sendProofs, 'v4');

    echo "=== TOKEN TO SEND (V4 - Recommended) ===\n";
    echo $tokenV4 . "\n\n";

    // Also show V3 format for compatibility
    $tokenV3 = $wallet->serializeToken($sendProofs, 'v3');
    echo "=== TOKEN TO SEND (V3 - Legacy) ===\n";
    echo $tokenV3 . "\n\n";

    // Token info
    echo "=== TOKEN INFO ===\n";
    echo "Amount: $sendAmount sats\n";
    echo "Proofs: " . count($sendProofs) . "\n";
    echo "Format: cashuB (V4 CBOR)\n";
    echo "V4 Length: " . strlen($tokenV4) . " chars\n";
    echo "V3 Length: " . strlen($tokenV3) . " chars\n\n";

    // Save send proofs to file
    $sendJson = json_encode(
        array_map(fn($p) => $p->toArray(true), $sendProofs),
        JSON_PRETTY_PRINT
    );
    $sendFile = __DIR__ . '/send_' . time() . '.json';
    file_put_contents($sendFile, $sendJson);
    echo "Send proofs saved to: $sendFile\n";

    // Save keep proofs to file
    if (!empty($keepProofs)) {
        $keepJson = json_encode(
            array_map(fn($p) => $p->toArray(true), $keepProofs),
            JSON_PRETTY_PRINT
        );
        $keepFile = __DIR__ . '/keep_' . time() . '.json';
        file_put_contents($keepFile, $keepJson);
        echo "Keep proofs saved to: $keepFile\n";
    }

    echo "\n=== IMPORTANT ===\n";
    echo "The token above contains $sendAmount sats.\n";
    echo "Share it with the recipient - whoever has the token can claim it.\n";
    echo "Once claimed, the proofs will be spent and cannot be used again.\n";

} catch (InsufficientBalanceException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (CashuException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
