<?php
/**
 * Mint Tokens Example
 *
 * Mints Cashu tokens by:
 * 1. Opening persistent storage and binding a seed to it (required — deterministic
 *    secrets come from the seed, and the counters that keep them unique are persisted)
 * 2. Requesting a mint quote (Lightning invoice)
 * 3. Waiting for the invoice to be paid
 * 4. Minting tokens once paid
 *
 * Usage:
 *   php mint_tokens.php [amount] [mint_url] [db_path] [seed_phrase]
 *
 * Example:
 *   php mint_tokens.php 100 https://testnut.cashu.space ./wallet.sqlite
 *   php mint_tokens.php 100 https://testnut.cashu.space ./wallet.sqlite "twelve word seed …"
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\CashuException;
use Cashu\Mnemonic;
use Cashu\Wallet;

$amount = (int)($argv[1] ?? 100);
$mintUrl = $argv[2] ?? 'https://testnut.cashu.space';
$dbPath = $argv[3] ?? __DIR__ . '/wallet.sqlite';
$seedPhrase = $argv[4] ?? null;

echo "=== Cashu Token Minting Example ===\n\n";

try {
    // Storage is not optional: minting reserves counters and journals the operation
    // before contacting the mint, so an interrupted mint can be recovered.
    echo "Connecting to mint: $mintUrl\n";
    echo "Wallet database:    $dbPath\n";
    $wallet = new Wallet($mintUrl, 'sat', $dbPath, 'example');
    $wallet->loadMint();

    echo "Loaded " . count($wallet->getKeysets()) . " keyset(s)\n";
    echo "Active keyset: " . $wallet->getActiveKeysetId() . "\n\n";

    $storage = $wallet->getStorage();
    if ($storage->getSeedFingerprint() === null) {
        // First run against this database.
        if ($seedPhrase === null) {
            $seedPhrase = Mnemonic::generate();
            echo "Generated a new seed phrase. WRITE IT DOWN — it is the only way to\n";
            echo "recover these tokens if the database is lost:\n\n";
            echo "    $seedPhrase\n\n";
            $wallet->initializeNewFromMnemonic($seedPhrase);
        } else {
            // A seed that has been used before needs a restore before it is safe to
            // spend from: issuing at counter 0 over already-used secrets loses funds.
            echo "Binding the supplied seed and scanning the mint for existing tokens…\n";
            $wallet->initializeForRestore($seedPhrase);
            $result = $wallet->restore();
            if (!empty($result['incomplete'])) {
                echo "Restore did not complete: " . json_encode($result['errors']) . "\n";
                echo "The wallet stays read-only until a full restore succeeds.\n";
                exit(1);
            }
            echo "Restored " . count($result['proofs']) . " proof(s).\n\n";
        }
    } else {
        // Re-opening an existing wallet.
        if ($seedPhrase === null) {
            echo "This database already has a wallet; pass its seed phrase to open it.\n";
            exit(1);
        }
        $wallet->initFromMnemonic($seedPhrase);
        echo "Opened existing wallet, balance: " . $wallet->getBalance() . " sat\n\n";
    }

    // Request the Lightning invoice.
    $quote = $wallet->requestMintQuote($amount);
    echo "Pay this invoice ($amount sat):\n\n{$quote->request}\n\n";
    echo "Waiting for payment (Ctrl-C to stop)…\n";

    $deadline = time() + 600;
    while (time() < $deadline) {
        sleep(3);
        $status = $wallet->checkMintQuote($quote->quote);
        if ($status->isPaid() || $status->isIssued()) {
            break;
        }
        echo '.';
    }
    echo "\n";

    $status = $wallet->checkMintQuote($quote->quote);
    if (!$status->isPaid() && !$status->isIssued()) {
        echo "Invoice was not paid in time. The quote id is {$quote->quote};\n";
        echo "re-run with the same database to resume it.\n";
        exit(1);
    }

    $proofs = $wallet->mint($quote->quote, $amount);
    echo "Minted " . count($proofs) . " proof(s), balance now " . $wallet->getBalance() . " sat\n";

} catch (CashuException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
