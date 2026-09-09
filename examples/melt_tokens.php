<?php
/**
 * Melt Tokens Example
 *
 * Pays a BOLT-11 invoice from the wallet's balance.
 *
 * Usage:
 *   php melt_tokens.php <bolt11_invoice> <db_path> [mint_url] [seed_phrase]
 */

require_once __DIR__ . '/bootstrap.php';

use Cashu\CashuException;
use Cashu\InsufficientBalanceException;
use Cashu\Wallet;

if ($argc < 3) {
    echo "Usage: php melt_tokens.php <bolt11_invoice> <db_path> [mint_url] [seed_phrase]\n";
    exit(1);
}

$invoice = $argv[1];
$dbPath = $argv[2];
$mintUrl = $argv[3] ?? 'https://testnut.cashu.space';
$seedPhrase = $argv[4] ?? null;

echo "=== Cashu Melt (Lightning Payment) Example ===\n\n";

try {
    $wallet = open_example_wallet($mintUrl, 'sat', $dbPath, $seedPhrase);

    $quote = $wallet->requestMeltQuote($invoice);
    $totalNeeded = $quote->amount + $quote->feeReserve;

    echo "Quote:       {$quote->quote}\n";
    echo "Amount:      {$quote->amount} sat\n";
    echo "Fee reserve: {$quote->feeReserve} sat\n\n";

    $balance = $wallet->getBalance();
    if ($balance < $totalNeeded) {
        throw new InsufficientBalanceException("Have $balance sat, need $totalNeeded sat");
    }

    // Cover the NUT-02 input fee the selection itself incurs, not just the quote:
    // selecting for the quote alone can come up short and the mint rejects the melt
    // after the journal has already reserved the inputs.
    $selected = $wallet->selectProofsWithFees($wallet->getStoredProofs(), $totalNeeded);
    $inputAmount = Wallet::sumProofs($selected);

    $result = $wallet->melt($quote->quote, $selected);

    if (!$result['paid']) {
        // Not a failure: a Lightning payment can complete after its response is lost.
        // Resume this quote id rather than paying the invoice again.
        echo "Payment did not confirm. Quote {$quote->quote} is unresolved;\n";
        echo "re-run against the same database to let recovery settle it.\n";
        exit(1);
    }

    // What actually left the wallet, rather than the quoted reserve: change also
    // carries any denomination excess from over-selection.
    $change = Wallet::sumProofs($result['change'] ?? []);
    echo "Paid. Preimage: " . ($result['preimage'] ?? 'n/a') . "\n";
    echo 'Cost: ' . ($inputAmount - $change) . " sat (invoice {$quote->amount} sat + "
        . ($inputAmount - $change - $quote->amount) . " sat fees)\n";
    echo 'Balance: ' . $wallet->getBalance() . " sat\n";

} catch (CashuException | RuntimeException $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
