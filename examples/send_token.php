<?php
/**
 * Send Token Example
 *
 * Splits the wallet's balance to an exact amount and serializes it as a token string.
 *
 * Usage:
 *   php send_token.php <amount> <db_path> [mint_url] [seed_phrase]
 *
 * Example:
 *   php send_token.php 50 ./wallet.sqlite https://testnut.cashu.space "twelve word seed …"
 */

require_once __DIR__ . '/bootstrap.php';

use Cashu\CashuException;
use Cashu\InsufficientBalanceException;
use Cashu\Wallet;

if ($argc < 3) {
    echo "Usage: php send_token.php <amount> <db_path> [mint_url] [seed_phrase]\n";
    exit(1);
}

$amount = (int)$argv[1];
$dbPath = $argv[2];
$mintUrl = $argv[3] ?? 'https://testnut.cashu.space';
$seedPhrase = $argv[4] ?? null;

echo "=== Cashu Token Send Example ===\n\n";

try {
    $wallet = open_example_wallet($mintUrl, 'sat', $dbPath, $seedPhrase);

    $balance = $wallet->getBalance();
    echo "Balance: $balance sat\n";
    if ($balance < $amount) {
        throw new InsufficientBalanceException("Have $balance sat, need $amount sat");
    }

    // split() swaps at the mint so the sent token is exactly $amount, and keeps the
    // remainder in local storage. The swap is journaled, so an interrupted split is
    // recovered rather than lost.
    $proofs = $wallet->getStoredProofs();
    $result = $wallet->split($proofs, $amount);
    $token = $wallet->serializeToken($result['send']);

    // The proofs in this token are bearer money the moment it is displayed. A real
    // application records the token durably and marks these proofs as no longer
    // spendable *before* showing it; see CashuPayServer's export flow.
    echo "\nToken ($amount sat):\n\n$token\n\n";
    echo "Remaining balance: " . $wallet->getBalance() . " sat\n";

} catch (CashuException | RuntimeException $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
