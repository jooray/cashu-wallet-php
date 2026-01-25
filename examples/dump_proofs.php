<?php
/**
 * Dump Proofs - Export unspent proofs as individual cashu tokens
 *
 * Reads the database and outputs each unspent proof as a separate cashu token
 * (one per line) on stdout. Uses cashuB (V4) for modern hex keyset IDs,
 * cashuA (V3) for legacy base64 keyset IDs.
 *
 * Usage:
 *   php dump_proofs.php <db_path> <mint_url> <unit>
 *
 * Examples:
 *   php dump_proofs.php wallet.db https://mint.example.com sat
 *   php dump_proofs.php wallet.db https://mint.example.com eur
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\WalletStorage;
use Cashu\TokenSerializer;
use Cashu\Proof;
use Cashu\ProofState;

if (count($argv) < 4 || in_array($argv[1] ?? '', ['--help', '-h'])) {
    fprintf(STDERR, "Usage: php dump_proofs.php <db_path> <mint_url> <unit>\n");
    fprintf(STDERR, "\nExports each unspent proof as a separate cashu token (one per line).\n");
    fprintf(STDERR, "Uses cashuB (V4) for hex keyset IDs, cashuA (V3) for legacy.\n");
    exit($argc > 1 && in_array($argv[1], ['--help', '-h']) ? 0 : 1);
}

$dbPath = $argv[1];
$mintUrl = rtrim($argv[2], '/');
$unit = $argv[3];

if (!file_exists($dbPath)) {
    fprintf(STDERR, "Error: Database not found at %s\n", $dbPath);
    exit(1);
}

if (!filter_var($mintUrl, FILTER_VALIDATE_URL)) {
    fprintf(STDERR, "Error: Invalid mint URL: %s\n", $mintUrl);
    exit(1);
}

$storage = new WalletStorage($dbPath, $mintUrl, $unit);
$rows = $storage->getProofs(ProofState::UNSPENT);

if (empty($rows)) {
    fprintf(STDERR, "No unspent proofs found for mint=%s unit=%s\n", $mintUrl, $unit);
    exit(0);
}

fprintf(STDERR, "Found %d unspent proofs, dumping tokens...\n", count($rows));

$totalAmount = 0;
foreach ($rows as $row) {
    $proof = new Proof(
        id: $row['keyset_id'],
        amount: (int) $row['amount'],
        secret: $row['secret'],
        C: $row['C']
    );

    if (TokenSerializer::isHexKeysetId($proof->id)) {
        $token = TokenSerializer::serializeV4($mintUrl, [$proof], $unit);
    } else {
        $token = TokenSerializer::serializeV3($mintUrl, [$proof], $unit);
    }

    echo $token . "\n";
    $totalAmount += $proof->amount;
}

fprintf(STDERR, "Done. Dumped %d proofs, total amount: %d %s\n", count($rows), $totalAmount, $unit);
