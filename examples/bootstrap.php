<?php
/**
 * Shared setup for the examples.
 *
 * Every money-moving operation in this library needs persistent storage: counters are
 * reserved there before a request is sent, and journals let an interrupted operation be
 * recovered. A wallet constructed without a database can only read public mint data.
 */

require_once __DIR__ . '/../CashuWallet.php';

use Cashu\Mnemonic;
use Cashu\Wallet;

/**
 * Open (or create) an example wallet backed by a SQLite file.
 *
 * Picks the right initialization for the situation, which is the part that most often
 * goes wrong:
 *
 *  - fresh database, no seed given  → generate one and bind it as new
 *  - fresh database, seed given     → bind for restore and scan the mint, because a
 *                                     previously used seed must not resume at counter 0
 *  - existing database              → open it with its own seed
 *
 * @param string      $mintUrl    Mint to talk to
 * @param string      $unit       Currency unit ('sat', 'eur', …)
 * @param string      $dbPath     SQLite file holding proofs and counters
 * @param string|null $seedPhrase BIP-39 mnemonic, or null to generate one
 * @param string      $accountId  Namespace within the database
 */
function open_example_wallet(
    string $mintUrl,
    string $unit,
    string $dbPath,
    ?string $seedPhrase,
    string $accountId = 'example'
): Wallet {
    $wallet = new Wallet($mintUrl, $unit, $dbPath, $accountId);
    $wallet->loadMint();

    if ($wallet->getStorage()->getSeedFingerprint() !== null) {
        if ($seedPhrase === null) {
            throw new RuntimeException(
                "$dbPath already holds a wallet; pass its seed phrase to open it."
            );
        }
        $wallet->initFromMnemonic($seedPhrase);
        return $wallet;
    }

    if ($seedPhrase === null) {
        $seedPhrase = Mnemonic::generate();
        echo "Generated a new seed phrase. WRITE IT DOWN — without it these tokens\n";
        echo "cannot be recovered if the database is lost:\n\n    $seedPhrase\n\n";
        $wallet->initializeNewFromMnemonic($seedPhrase);
        return $wallet;
    }

    echo "Binding the supplied seed and scanning the mint for existing tokens…\n";
    $wallet->initializeForRestore($seedPhrase);
    $result = $wallet->restore();
    if (!empty($result['incomplete'])) {
        throw new RuntimeException(
            'Restore did not complete (' . json_encode($result['errors']) . '); '
            . 'the wallet stays read-only until it does.'
        );
    }
    echo 'Restored ' . count($result['proofs']) . " proof(s).\n\n";

    return $wallet;
}
