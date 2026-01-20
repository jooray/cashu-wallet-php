<?php
/**
 * Mint Tokens Example
 *
 * This example demonstrates how to mint Cashu tokens by:
 * 1. Initializing wallet with a seed (REQUIRED for recoverable tokens)
 * 2. Requesting a mint quote (Lightning invoice)
 * 3. Waiting for the invoice to be paid
 * 4. Minting tokens once paid
 *
 * Usage:
 *   php mint_tokens.php [amount] [mint_url] [seed_phrase]
 *
 * Example:
 *   php mint_tokens.php 100 https://testnut.cashu.space
 *   php mint_tokens.php 100 https://testnut.cashu.space "your twelve word seed phrase here"
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\Wallet;
use Cashu\CashuException;

// Configuration
$amount = (int)($argv[1] ?? 100);
$mintUrl = $argv[2] ?? 'https://testnut.cashu.space';
$seedPhrase = $argv[3] ?? null;

echo "=== Cashu Token Minting Example ===\n\n";

try {
    // Initialize wallet
    echo "Connecting to mint: $mintUrl\n";
    $wallet = new Wallet($mintUrl);
    $wallet->loadMint();

    $keysets = $wallet->getKeysets();
    echo "Loaded " . count($keysets) . " keyset(s)\n";
    echo "Active keyset: " . $wallet->getActiveKeysetId() . "\n\n";

    // Initialize seed (REQUIRED)
    // The seed enables deterministic secret generation, allowing token recovery
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

    // Request mint quote
    echo "Requesting mint quote for $amount sats...\n";
    $quote = $wallet->requestMintQuote($amount);

    echo "\n=== MINT QUOTE ===\n";
    echo "Quote ID: " . $quote->quote . "\n";
    echo "Amount: " . $quote->amount . " sats\n";
    echo "State: " . $quote->state . "\n";
    echo "\nLightning Invoice:\n";
    echo $quote->request . "\n\n";

    // In a real application, you would display the invoice as a QR code
    // and wait for the user to pay it

    echo "=== WAITING FOR PAYMENT ===\n";
    echo "Please pay the Lightning invoice above.\n";
    echo "Polling for payment status...\n\n";

    // Poll for payment
    $maxAttempts = 60;
    $attempt = 0;
    $paid = false;

    while ($attempt < $maxAttempts) {
        $attempt++;
        $quoteStatus = $wallet->checkMintQuote($quote->quote);

        echo "Attempt $attempt: State = " . $quoteStatus->state . "\n";

        if ($quoteStatus->isPaid()) {
            $paid = true;
            break;
        }

        // Wait 5 seconds between polls
        sleep(5);
    }

    if (!$paid) {
        echo "\nPayment not received within timeout.\n";
        echo "Save the quote ID to mint later: " . $quote->quote . "\n";
        exit(1);
    }

    echo "\n=== PAYMENT RECEIVED ===\n";
    echo "Minting tokens...\n\n";

    // Mint tokens
    $proofs = $wallet->mint($quote->quote, $amount);

    echo "=== TOKENS MINTED ===\n";
    echo "Received " . count($proofs) . " proof(s)\n\n";

    $totalAmount = 0;
    foreach ($proofs as $i => $proof) {
        echo "Proof " . ($i + 1) . ":\n";
        echo "  Amount: " . $proof->amount . " sats\n";
        echo "  Keyset: " . $proof->id . "\n";
        echo "  Secret: " . substr($proof->secret, 0, 16) . "...\n";
        $totalAmount += $proof->amount;
    }

    echo "\nTotal: $totalAmount sats\n\n";

    // Serialize to token string
    $token = $wallet->serializeToken($proofs, 'v4');

    echo "=== TOKEN STRING (V4) ===\n";
    echo $token . "\n\n";

    // Also show V3 format
    $tokenV3 = $wallet->serializeToken($proofs, 'v3');
    echo "=== TOKEN STRING (V3) ===\n";
    echo $tokenV3 . "\n\n";

    // Save proofs to file for later use
    $proofsJson = json_encode(array_map(fn($p) => $p->toArray(true), $proofs), JSON_PRETTY_PRINT);
    $filename = __DIR__ . '/proofs_' . time() . '.json';
    file_put_contents($filename, $proofsJson);
    echo "Proofs saved to: $filename\n";

} catch (CashuException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
