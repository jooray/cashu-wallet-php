<?php
/**
 * Refresh Wallet - Sync proof states and restore counters
 *
 * This script checks all stored proofs against the mint and updates local state
 * to match. It also optionally runs restore() to recover correct counter positions.
 *
 * Usage:
 *   php refresh_wallet.php <db_path> <mint_url> <unit> <seed_phrase>           # Sync proof states
 *   php refresh_wallet.php <db_path> <mint_url> <unit> <seed_phrase> --restore # Also restore counters
 *   php refresh_wallet.php <db_path> --list                                    # List all wallets
 *
 * Examples:
 *   php refresh_wallet.php wallet.db https://mint.example.com sat "your seed phrase here"
 *   php refresh_wallet.php wallet.db https://mint.example.com sat "your seed phrase here" --restore
 *   php refresh_wallet.php wallet.db --list
 *
 * The --restore flag runs a full wallet restore which scans the mint for any
 * proofs that were created with this seed. This recovers the correct counter
 * positions, preventing "outputs already signed" errors.
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\Wallet;
use Cashu\WalletStorage;
use Cashu\CashuException;

// ANSI colors for terminal output
define('RED', "\033[31m");
define('GREEN', "\033[32m");
define('YELLOW', "\033[33m");
define('CYAN', "\033[36m");
define('RESET', "\033[0m");

/**
 * Print colored output
 */
function output(string $message, string $color = ''): void {
    echo $color . $message . ($color ? RESET : '') . "\n";
}

/**
 * List all wallets in the database
 */
function listWallets(string $dbPath): void {
    $wallets = WalletStorage::listWallets($dbPath);

    if (empty($wallets)) {
        output("No wallets found in database.", YELLOW);
        return;
    }

    output("\nWallets in database:", CYAN);
    output(str_repeat("-", 80));
    output(sprintf("%-20s %8s %8s %8s %8s %10s", "Wallet ID", "Total", "Unspent", "Spent", "Pending", "Balance"));
    output(str_repeat("-", 80));

    foreach ($wallets as $wallet) {
        output(sprintf(
            "%-20s %8d %8d %8d %8d %10d",
            substr($wallet['wallet_id'], 0, 20),
            $wallet['total_proofs'],
            $wallet['unspent'],
            $wallet['spent'],
            $wallet['pending'],
            $wallet['balance']
        ));
    }

    output("\n\nKeyset IDs by wallet:", CYAN);
    output(str_repeat("-", 80));

    foreach ($wallets as $wallet) {
        foreach ($wallet['keyset_ids'] as $keysetId) {
            output(sprintf(
                "  %s... -> keyset %s",
                substr($wallet['wallet_id'], 0, 16),
                $keysetId
            ));
        }
    }

    output("\nNote: wallet_id is a hash of (mint_url, unit). To refresh, you need to know the mint URL.");
    output("Check your logs or token sources to identify which mint URL corresponds to each wallet.\n");
}

/**
 * Main refresh logic
 */
function refreshWallet(string $dbPath, string $mintUrl, string $unit, string $seedPhrase, bool $runRestore): void {
    output("\nRefresh Wallet", CYAN);
    output(str_repeat("=", 60));
    output("Database: $dbPath");
    output("Mint: $mintUrl");
    output("Unit: $unit");
    output("Restore: " . ($runRestore ? "Yes" : "No"));
    output(str_repeat("-", 60));

    // Initialize wallet
    output("\nInitializing wallet...", YELLOW);

    try {
        $wallet = new Wallet($mintUrl, $unit, $dbPath);
        $wallet->loadMint();
        $wallet->initFromMnemonic($seedPhrase);
    } catch (CashuException $e) {
        output("Error initializing wallet: " . $e->getMessage(), RED);
        exit(1);
    }

    output("Wallet initialized successfully.", GREEN);

    // Get initial state
    $storedProofs = $wallet->getStoredProofs();
    $proofCount = count($storedProofs);
    $balance = Wallet::sumProofs($storedProofs);

    output("\nLocal state before sync:", CYAN);
    output("  Unspent proofs: $proofCount");
    output("  Balance: $balance $unit");

    if ($proofCount === 0) {
        output("\nNo unspent proofs to check.", YELLOW);

        if ($runRestore) {
            runRestoreProcess($wallet, $unit);
        }
        return;
    }

    // Sync proof states with mint
    output("\nSyncing proof states with mint...", YELLOW);

    $syncResult = $wallet->syncProofStates();

    if (isset($syncResult['error'])) {
        output("Error during sync: " . $syncResult['error'], RED);
    } else {
        output("Sync complete:", GREEN);
        output("  Checked: " . $syncResult['checked'] . " proofs");
        output("  Updated: " . $syncResult['updated'] . " proofs marked as SPENT", $syncResult['updated'] > 0 ? YELLOW : '');
        if ($syncResult['errors'] > 0) {
            output("  Errors: " . $syncResult['errors'], RED);
        }
    }

    // Show final state
    $finalProofs = $wallet->getStoredProofs();
    $finalBalance = Wallet::sumProofs($finalProofs);

    output("\nLocal state after sync:", CYAN);
    output("  Unspent proofs: " . count($finalProofs));
    output("  Balance: $finalBalance $unit");

    // Run restore if requested
    if ($runRestore) {
        runRestoreProcess($wallet, $unit);
    }

    output("\nDone!", GREEN);
}

/**
 * Run the restore process to sync counters
 */
function runRestoreProcess(Wallet $wallet, string $unit): void {
    output("\n" . str_repeat("-", 60), CYAN);
    output("Running restore to sync counters...", YELLOW);
    output("This may take a while depending on how many tokens were created.");
    output(str_repeat("-", 60));

    try {
        $result = $wallet->restore(25, 3, function($keysetId, $counter, $found, $restoreUnit) {
            if ($found > 0) {
                output("  [$restoreUnit] Keyset $keysetId @ counter $counter: found $found proofs", GREEN);
            } elseif ($counter % 100 === 0) {
                output("  [$restoreUnit] Keyset $keysetId @ counter $counter: scanning...");
            }
        }, true);

        output("\nRestore complete!", GREEN);

        output("\nRecovered proofs by unit:", CYAN);
        foreach ($result['byUnit'] as $recoveredUnit => $data) {
            $proofCount = count($data['proofs']);
            $total = Wallet::sumProofs($data['proofs']);
            output("  $recoveredUnit: $proofCount proofs, $total total");
        }

        output("\nFinal counters:", CYAN);
        foreach ($result['counters'] as $keysetId => $counter) {
            output("  $keysetId: $counter");
        }

    } catch (CashuException $e) {
        output("Error during restore: " . $e->getMessage(), RED);
    }
}

/**
 * Print help message
 */
function printHelp(): void {
    echo <<<HELP
Refresh Wallet - Sync proof states and restore counters

Usage:
  php refresh_wallet.php <db_path> <mint_url> <unit> <seed_phrase>           Sync proof states
  php refresh_wallet.php <db_path> <mint_url> <unit> <seed_phrase> --restore Also restore counters
  php refresh_wallet.php <db_path> --list                                    List all wallets

Arguments:
  db_path      Path to SQLite database file
  mint_url     URL of the Cashu mint
  unit         Currency unit (sat, usd, eur, etc.)
  seed_phrase  BIP-39 mnemonic seed phrase (in quotes)

Options:
  --restore    Run full wallet restore to recover correct counter positions
  --list       List all wallets in the database
  --help, -h   Show this help message

Examples:
  php refresh_wallet.php wallet.db https://mint.example.com sat "abandon ability able..."
  php refresh_wallet.php wallet.db https://mint.example.com sat "abandon ability able..." --restore
  php refresh_wallet.php wallet.db --list

The --restore flag is useful when you see "outputs already signed" errors.
It scans the mint for all proofs created with your seed and recovers the
correct counter positions.

HELP;
}

// =============================================================================
// Main
// =============================================================================

$args = array_slice($argv, 1);
$runRestore = false;
$listMode = false;

// Check for flags
foreach ($args as $i => $arg) {
    if ($arg === '--restore') {
        $runRestore = true;
        unset($args[$i]);
    } elseif ($arg === '--list') {
        $listMode = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        printHelp();
        exit(0);
    }
}

$args = array_values($args);

// Need at least db_path
if (count($args) < 1) {
    output("Error: Missing database path.", RED);
    output("Usage: php refresh_wallet.php <db_path> [options]");
    output("       php refresh_wallet.php --help");
    exit(1);
}

$dbPath = $args[0];

// List mode
if ($listMode) {
    if (!file_exists($dbPath)) {
        output("Error: Database not found at $dbPath", RED);
        exit(1);
    }
    listWallets($dbPath);
    exit(0);
}

// Refresh mode - need all arguments
if (count($args) < 4) {
    output("Error: Missing required arguments.", RED);
    output("Usage: php refresh_wallet.php <db_path> <mint_url> <unit> <seed_phrase> [--restore]");
    output("       php refresh_wallet.php <db_path> --list");
    output("       php refresh_wallet.php --help");
    exit(1);
}

$mintUrl = rtrim($args[1], '/');
$unit = $args[2];
$seedPhrase = $args[3];

// Validate mint URL
if (!filter_var($mintUrl, FILTER_VALIDATE_URL)) {
    output("Error: Invalid mint URL: $mintUrl", RED);
    exit(1);
}

refreshWallet($dbPath, $mintUrl, $unit, $seedPhrase, $runRestore);
