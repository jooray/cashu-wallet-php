<?php
/**
 * Receive Token Example
 *
 * Swaps a received token at its mint so the sender can no longer spend it.
 *
 * Usage:
 *   php receive_token.php <token> <db_path> [seed_phrase]
 */

require_once __DIR__ . '/bootstrap.php';

use Cashu\CashuException;
use Cashu\TokenSerializer;
use Cashu\Wallet;

if ($argc < 3) {
    echo "Usage: php receive_token.php <token> <db_path> [seed_phrase]\n";
    exit(1);
}

$tokenString = $argv[1];
$dbPath = $argv[2];
$seedPhrase = $argv[3] ?? null;

echo "=== Cashu Token Receive Example ===\n\n";

try {
    // Inspect the token before doing anything with it. Its mint URL is chosen by the
    // sender, so this tells you which mint you are about to trust — it is not itself
    // evidence that the mint is trustworthy.
    $token = TokenSerializer::deserialize($tokenString);
    echo 'Mint:   ' . $token->mint . "\n";
    echo 'Unit:   ' . $token->unit . "\n";
    echo 'Amount: ' . $token->getAmount() . ' ' . $token->unit . "\n\n";

    $wallet = open_example_wallet($token->mint, $token->unit, $dbPath, $seedPhrase);

    // receive() swaps the proofs for fresh ones. Until that swap completes the sender
    // still holds a spendable copy, so an *offline* receive is a trust decision.
    $proofs = $wallet->receive($tokenString);

    echo 'Received ' . Wallet::sumProofs($proofs) . ' ' . $token->unit
        . ' (' . count($proofs) . " proof(s))\n";
    echo 'Balance: ' . $wallet->getBalance() . ' ' . $token->unit . "\n";

} catch (CashuException | RuntimeException $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
