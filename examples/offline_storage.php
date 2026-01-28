<?php
/**
 * Offline Storage Operations - Work with stored proofs without connecting to mint
 *
 * This script demonstrates standalone storage access for scenarios where
 * the mint is unreachable or you want to avoid network calls.
 *
 * Usage:
 *   php offline_storage.php <db_path> <mint_url> <unit> balance    Show local balance
 *   php offline_storage.php <db_path> <mint_url> <unit> proofs     List unspent proofs
 *   php offline_storage.php <db_path> <mint_url> <unit> export <N> Export N units as token
 *
 * Examples:
 *   php offline_storage.php wallet.db https://mint.example.com sat balance
 *   php offline_storage.php wallet.db https://mint.example.com sat proofs
 *   php offline_storage.php wallet.db https://mint.example.com sat export 100
 *
 * Note: Offline operations do NOT verify proofs with the mint. The balance
 * shown may be stale if proofs were spent elsewhere.
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\WalletStorage;
use Cashu\TokenSerializer;
use Cashu\ProofState;
use Cashu\Wallet;

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
 * Show local balance
 */
function showBalance(WalletStorage $storage, string $unit): void {
    $balance = $storage->getBalance();
    output("\nOffline Balance", CYAN);
    output(str_repeat("-", 40));
    output("Balance: " . Wallet::formatAmountForUnit($balance, $unit));
    output("\n" . YELLOW . "Warning: This is the local balance only." . RESET);
    output("Proofs may have been spent elsewhere.");
}

/**
 * List unspent proofs
 */
function listProofs(WalletStorage $storage, string $unit): void {
    $proofs = $storage->getProofsAsObjects();

    output("\nUnspent Proofs", CYAN);
    output(str_repeat("-", 60));

    if (empty($proofs)) {
        output("No unspent proofs found.", YELLOW);
        return;
    }

    output(sprintf("%-10s %-20s %s", "Amount", "Keyset", "Secret (prefix)"));
    output(str_repeat("-", 60));

    $total = 0;
    foreach ($proofs as $proof) {
        $total += $proof->amount;
        output(sprintf(
            "%-10s %-20s %s...",
            Wallet::formatAmountForUnit($proof->amount, $unit),
            substr($proof->id, 0, 16) . '...',
            substr($proof->secret, 0, 24)
        ));
    }

    output(str_repeat("-", 60));
    output("Total: " . Wallet::formatAmountForUnit($total, $unit) . " (" . count($proofs) . " proofs)");
    output("\n" . YELLOW . "Warning: Proofs may have been spent elsewhere." . RESET);
}

/**
 * Export proofs as token
 */
function exportToken(WalletStorage $storage, string $mintUrl, string $unit, int $amount): void {
    $proofs = $storage->getProofsAsObjects();

    if (empty($proofs)) {
        output("Error: No unspent proofs available.", RED);
        exit(1);
    }

    $balance = array_sum(array_map(fn($p) => $p->amount, $proofs));

    if ($balance < $amount) {
        output("Error: Insufficient balance.", RED);
        output("  Requested: " . Wallet::formatAmountForUnit($amount, $unit));
        output("  Available: " . Wallet::formatAmountForUnit($balance, $unit));
        exit(1);
    }

    // Select proofs to export (greedy, largest first)
    usort($proofs, fn($a, $b) => $b->amount - $a->amount);

    $toExport = [];
    $remaining = $amount;

    foreach ($proofs as $proof) {
        if ($remaining <= 0) break;
        $toExport[] = $proof;
        $remaining -= $proof->amount;
    }

    $exportedAmount = array_sum(array_map(fn($p) => $p->amount, $toExport));

    output("\nExporting Token", CYAN);
    output(str_repeat("-", 60));
    output("Requested: " . Wallet::formatAmountForUnit($amount, $unit));
    output("Exporting: " . Wallet::formatAmountForUnit($exportedAmount, $unit) . " (" . count($toExport) . " proofs)");

    // Serialize to V4 token
    $token = TokenSerializer::serializeV4($mintUrl, $toExport, $unit);

    output("\n" . GREEN . "Token:" . RESET);
    output($token);

    // Mark proofs as PENDING
    $secrets = array_map(fn($p) => $p->secret, $toExport);
    $storage->updateProofsState($secrets, ProofState::PENDING);

    output("\n" . YELLOW . "Proofs marked as PENDING in storage." . RESET);
    output("If the token is not redeemed, you can recover proofs by");
    output("marking them as UNSPENT again or syncing with the mint.");

    // Show remaining balance
    $newBalance = $storage->getBalance();
    output("\nRemaining balance: " . Wallet::formatAmountForUnit($newBalance, $unit));
}

/**
 * Print help message
 */
function printHelp(): void {
    echo <<<HELP
Offline Storage Operations - Work with stored proofs without connecting to mint

Usage:
  php offline_storage.php <db_path> <mint_url> <unit> balance     Show local balance
  php offline_storage.php <db_path> <mint_url> <unit> proofs      List unspent proofs
  php offline_storage.php <db_path> <mint_url> <unit> export <N>  Export N units as token

Arguments:
  db_path      Path to SQLite database file
  mint_url     URL of the Cashu mint
  unit         Currency unit (sat, usd, eur, etc.)

Commands:
  balance      Show the local balance (sum of unspent proofs)
  proofs       List all unspent proofs with their amounts
  export <N>   Export N units worth of proofs as a token string
               Proofs are marked as PENDING to prevent double-spend

Examples:
  php offline_storage.php wallet.db https://mint.example.com sat balance
  php offline_storage.php wallet.db https://mint.example.com sat proofs
  php offline_storage.php wallet.db https://mint.example.com sat export 100

Warning:
  Offline operations do NOT verify proofs with the mint. The balance
  shown may be stale if proofs were spent elsewhere. When connectivity
  is restored, use refresh_wallet.php to sync proof states.

HELP;
}

// =============================================================================
// Main
// =============================================================================

$args = array_slice($argv, 1);

// Check for help flag
if (in_array('--help', $args) || in_array('-h', $args) || empty($args)) {
    printHelp();
    exit(0);
}

// Need at least 4 arguments: db_path, mint_url, unit, command
if (count($args) < 4) {
    output("Error: Missing required arguments.", RED);
    output("Usage: php offline_storage.php <db_path> <mint_url> <unit> <command> [args]");
    output("       php offline_storage.php --help");
    exit(1);
}

$dbPath = $args[0];
$mintUrl = rtrim($args[1], '/');
$unit = $args[2];
$command = $args[3];

// Validate database exists
if (!file_exists($dbPath)) {
    output("Error: Database not found at $dbPath", RED);
    exit(1);
}

// Validate mint URL format
if (!filter_var($mintUrl, FILTER_VALIDATE_URL)) {
    output("Error: Invalid mint URL: $mintUrl", RED);
    exit(1);
}

// Create standalone storage (no mint connection!)
$storage = WalletStorage::forOffline($dbPath, $mintUrl, $unit);

// Execute command
switch ($command) {
    case 'balance':
        showBalance($storage, $unit);
        break;

    case 'proofs':
        listProofs($storage, $unit);
        break;

    case 'export':
        if (!isset($args[4])) {
            output("Error: export command requires an amount.", RED);
            output("Usage: php offline_storage.php <db_path> <mint_url> <unit> export <amount>");
            exit(1);
        }
        $amount = (int)$args[4];
        if ($amount <= 0) {
            output("Error: Amount must be positive.", RED);
            exit(1);
        }
        exportToken($storage, $mintUrl, $unit, $amount);
        break;

    default:
        output("Error: Unknown command: $command", RED);
        output("Valid commands: balance, proofs, export");
        exit(1);
}
