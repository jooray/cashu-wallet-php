<?php

/**
 * One bounded restoreStep() in a fresh process with the BCMath backend forced, as a
 * shared host without GMP would run it from a request or cron tick.
 *
 * Usage: php bcmath_restore_step.php <db> <maxOutputs> <deadlineSeconds> <gap> <keysetId> <historyJson>
 * historyJson: {"counter": amount, ...} outputs the mint signed for this seed.
 * Prints one JSON object.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/CashuWallet.php';
require __DIR__ . '/FakeRestoreMint.php';

use Cashu\BigInt;
use Cashu\Wallet;
use Cashu\Tests\Support\FakeRestoreMint;

// Select BCMath before any BigInt exists (curve constants are cached per process).
(new ReflectionProperty(BigInt::class, 'useGmp'))->setValue(null, false);
(new ReflectionProperty(BigInt::class, 'initialized'))->setValue(null, true);

[, $db, $maxOutputs, $deadlineSeconds, $gap, $keysetId, $historyJson] = $argv;
$mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

$mint = new FakeRestoreMint([['id' => $keysetId, 'unit' => 'sat', 'active' => true]]);
$seed = new Wallet('https://mint.example');
$seed->initFromMnemonic($mnemonic);
foreach (json_decode($historyJson, true) as $counter => $amount) {
    $mint->sign($seed, $keysetId, (int)$counter, (int)$amount);
}

$wallet = new Wallet('https://mint.example', 'sat', $db);
if ($wallet->getStorage()->getSeedFingerprint() === null) {
    $wallet->initializeForRestore($mnemonic);
} else {
    $wallet->initFromMnemonic($mnemonic);
}
(new ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $mint);

$started = microtime(true);
$step = $wallet->restoreStep((int)$maxOutputs, $started + (float)$deadlineSeconds, (int)$gap);
$elapsed = microtime(true) - $started;

echo json_encode([
    'gmp' => BigInt::isUsingGmp(),
    'status' => $step['status'],
    'error' => $step['error'],
    'elapsed' => $elapsed,
    'outputs' => $step['recovered']['outputs'],
    'found' => $step['recovered']['proofs'],
    'progress' => $step['progress'][$keysetId] ?? null,
    'ready' => !$wallet->requiresRecovery(),
    'counter' => $wallet->getStorage()->getCounter($keysetId),
    'balance' => $wallet->getStorage()->getBalance(),
]), "\n";
