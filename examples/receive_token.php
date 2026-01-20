<?php
/**
 * Receive Token Example
 *
 * This example demonstrates how to receive a Cashu token by:
 * 1. Initializing wallet with a seed (REQUIRED)
 * 2. Deserializing the token string
 * 3. Verifying it's from a trusted mint
 * 4. Swapping for fresh proofs (claiming the token)
 *
 * Usage:
 *   php receive_token.php <token_string> [seed_phrase]
 *
 * Example:
 *   php receive_token.php cashuBo2F0gaJha...
 *   php receive_token.php cashuBo2F0gaJha... "your twelve word seed phrase"
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\Wallet;
use Cashu\TokenSerializer;
use Cashu\CashuException;

// Get token from argument
if ($argc < 2) {
    echo "Usage: php receive_token.php <token_string> [seed_phrase]\n";
    echo "\nExample:\n";
    echo "  php receive_token.php cashuBo2F0gaJhaUgAJTn...\n";
    echo "  php receive_token.php cashuBo2F0gaJhaUgAJTn... \"your seed phrase\"\n";
    exit(1);
}

$tokenString = $argv[1];
$seedPhrase = $argv[2] ?? null;

echo "=== Cashu Token Receive Example ===\n\n";

try {
    // First, deserialize the token to inspect it
    echo "Deserializing token...\n";
    $token = TokenSerializer::deserialize($tokenString);

    echo "\n=== TOKEN INFO ===\n";
    echo "Mint: " . $token->mint . "\n";
    echo "Unit: " . $token->unit . "\n";
    echo "Amount: " . $token->getAmount() . " " . $token->unit . "\n";
    echo "Proofs: " . count($token->proofs) . "\n";
    echo "Keysets: " . implode(', ', $token->getKeysets()) . "\n";
    if ($token->memo) {
        echo "Memo: " . $token->memo . "\n";
    }
    echo "\n";

    // Show individual proofs
    echo "=== INCOMING PROOFS ===\n";
    foreach ($token->proofs as $i => $proof) {
        echo "Proof " . ($i + 1) . ":\n";
        echo "  Amount: " . $proof->amount . " " . $token->unit . "\n";
        echo "  Keyset: " . $proof->id . "\n";
    }
    echo "\n";

    // Initialize wallet with the token's mint
    echo "Connecting to mint: " . $token->mint . "\n";
    $wallet = new Wallet($token->mint, $token->unit);
    $wallet->loadMint();
    echo "Mint loaded successfully\n\n";

    // Initialize seed (REQUIRED for receiving tokens)
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

    // Check if proofs are already spent
    echo "Checking proof states...\n";
    $states = $wallet->checkProofState($token->proofs);

    $allUnspent = true;
    foreach ($states as $state) {
        $stateStr = $state['state'] ?? 'UNKNOWN';
        if ($stateStr !== 'UNSPENT') {
            echo "Warning: Proof state is $stateStr\n";
            $allUnspent = false;
        }
    }

    if (!$allUnspent) {
        echo "\nSome proofs may already be spent!\n";
        echo "Attempting to receive anyway...\n\n";
    } else {
        echo "All proofs are unspent\n\n";
    }

    // Receive the token (swap for fresh proofs)
    echo "=== CLAIMING TOKEN ===\n";
    echo "Swapping for fresh proofs...\n";

    $newProofs = $wallet->receive($tokenString);

    echo "\n=== TOKENS RECEIVED ===\n";
    echo "Received " . count($newProofs) . " new proof(s)\n\n";

    $totalAmount = 0;
    foreach ($newProofs as $i => $proof) {
        echo "Proof " . ($i + 1) . ":\n";
        echo "  Amount: " . $proof->amount . " " . $token->unit . "\n";
        echo "  Keyset: " . $proof->id . "\n";
        echo "  Secret: " . substr($proof->secret, 0, 16) . "...\n";
        $totalAmount += $proof->amount;
    }

    echo "\nTotal received: $totalAmount " . $token->unit . "\n\n";

    // Create a new token with the received proofs
    $newToken = $wallet->serializeToken($newProofs, 'v4');
    echo "=== YOUR NEW TOKEN ===\n";
    echo $newToken . "\n\n";

    // Save proofs to file
    $proofsJson = json_encode(array_map(fn($p) => $p->toArray(true), $newProofs), JSON_PRETTY_PRINT);
    $filename = __DIR__ . '/received_proofs_' . time() . '.json';
    file_put_contents($filename, $proofsJson);
    echo "Proofs saved to: $filename\n";

} catch (CashuException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
