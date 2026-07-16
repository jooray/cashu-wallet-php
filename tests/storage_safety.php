<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/CashuWallet.php';

use Cashu\CashuException;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Wallet;
use Cashu\WalletStorage;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectCashuException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (CashuException $e) {
        return;
    }
    throw new RuntimeException($message);
}

$dbPath = sys_get_temp_dir() . '/cashu-wallet-storage-' . bin2hex(random_bytes(8)) . '.sqlite';
$seedA = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
$seedB = 'legal winner thank year wave sausage worth useful legal winner thank yellow';

try {
    $accountA = new WalletStorage($dbPath, 'https://mint.example', 'sat', 'merchant-a');
    $accountB = new WalletStorage($dbPath, 'https://mint.example', 'sat', 'merchant-b');
    assertTrue($accountA->getWalletId() !== $accountB->getWalletId(), 'Explicit account identities must isolate storage');

    $proofA = new Proof('0011223344556677', 1, 'secret-a', '02' . str_repeat('11', 32));
    $accountA->storeProofs([$proofA]);
    assertTrue($accountA->getBalance() === 1, 'Account A proof was not stored');
    assertTrue($accountB->getBalance() === 0, 'Account B observed account A proof');
    $accountA->savePendingOperation('melt:same-quote', 'melt', ['account' => 'a']);
    $accountB->savePendingOperation('melt:same-quote', 'melt', ['account' => 'b']);
    assertTrue($accountA->getPendingOperationById('melt:same-quote')['data']['account'] === 'a', 'Account A journal was overwritten');
    assertTrue($accountB->getPendingOperationById('melt:same-quote')['data']['account'] === 'b', 'Account B journal was overwritten');

    $freshPath = sys_get_temp_dir() . '/cashu-wallet-fresh-' . bin2hex(random_bytes(8)) . '.sqlite';
    $fresh = new Wallet('https://mint.example', 'sat', $freshPath, 'merchant');
    expectCashuException(fn() => $fresh->initFromMnemonic($seedA), 'Fresh storage silently accepted a potentially used seed');
    $fresh->initializeNewFromMnemonic($seedA);
    $reopened = new Wallet('https://mint.example', 'sat', $freshPath, 'merchant');
    $reopened->initFromMnemonic($seedA);
    $wrongSeed = new Wallet('https://mint.example', 'sat', $freshPath, 'merchant');
    expectCashuException(fn() => $wrongSeed->initFromMnemonic($seedB), 'Seed mismatch was not rejected');

    $restorePath = sys_get_temp_dir() . '/cashu-wallet-restore-' . bin2hex(random_bytes(8)) . '.sqlite';
    $restoring = new Wallet('https://mint.example', 'sat', $restorePath, 'merchant');
    $restoring->initializeForRestore($seedB);
    assertTrue($restoring->requiresRecovery(), 'Restore initialization allowed spending before restore');
    $interruptedRestore = new Wallet('https://mint.example', 'sat', $restorePath, 'merchant');
    $interruptedRestore->initFromMnemonic($seedB);
    assertTrue($interruptedRestore->requiresRecovery(), 'Interrupted restore was treated as ready after reopen');

    $input = new Proof('0011223344556677', 4, 'reserve-me', '02' . str_repeat('22', 32));
    $accountA->storeProofs([$input]);
    $data = $accountA->preparePendingSpend('swap:test', 'swap', [$input], '0011223344556677', [4]);
    assertTrue($data['counter_start'] === 0, 'Unexpected reserved counter start');
    assertTrue($accountA->getCounter('0011223344556677') === 1, 'Output counter was not reserved atomically');
    assertTrue($accountA->getProofsStatesBySecrets(['reserve-me'])['reserve-me'] === ProofState::PENDING, 'Input was not reserved');
    assertTrue(in_array('reserve-me', $accountA->getReservedInputSecrets(), true), 'Reserved input was not discoverable');
    expectCashuException(
        fn() => $accountA->preparePendingSpend('swap:other', 'swap', [$input], '0011223344556677', [4]),
        'A reserved input could be reserved by another operation'
    );

    $noChangeInput = new Proof('0011223344556677', 2, 'no-change', '02' . str_repeat('55', 32));
    $accountA->storeProofs([$noChangeInput]);
    $counterBefore = $accountA->getCounter('0011223344556677');
    $accountA->preparePendingSpend('melt:no-change', 'melt', [$noChangeInput], '0011223344556677', []);
    assertTrue($accountA->getPendingOperationById('melt:no-change') !== null, 'No-change melt was not journaled');
    assertTrue($accountA->getCounter('0011223344556677') === $counterBefore, 'No-change melt consumed an output counter');
    assertTrue($accountA->getProofsStatesBySecrets(['no-change'])['no-change'] === ProofState::PENDING, 'No-change melt input was not reserved');

    $pdo = $accountA->getPdo();
    $pdo->exec("CREATE TRIGGER fail_test_output BEFORE INSERT ON cashu_proofs WHEN NEW.secret = 'fail-output' BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    $output = new Proof('0011223344556677', 4, 'fail-output', '02' . str_repeat('33', 32));
    try {
        $accountA->finalizePendingSpend('swap:test', ['reserve-me'], ProofState::SPENT, [$output]);
        throw new RuntimeException('Injected finalization failure did not fail');
    } catch (PDOException $e) {
        // Expected: the entire finalization transaction must roll back.
    }
    assertTrue($accountA->getProofsStatesBySecrets(['reserve-me'])['reserve-me'] === ProofState::PENDING, 'Failed finalization changed input state');
    assertTrue($accountA->getPendingOperationById('swap:test') !== null, 'Failed finalization deleted recovery journal');

    $pdo->exec('DROP TRIGGER fail_test_output');
    $goodOutput = new Proof('0011223344556677', 4, 'good-output', '02' . str_repeat('44', 32));
    $accountA->finalizePendingSpend('swap:test', ['reserve-me'], ProofState::SPENT, [$goodOutput]);
    assertTrue($accountA->getProofsStatesBySecrets(['reserve-me'])['reserve-me'] === ProofState::SPENT, 'Successful finalization did not spend input');
    assertTrue($accountA->getPendingOperationById('swap:test') === null, 'Successful finalization retained journal');
    assertTrue($accountA->getProofsStatesBySecrets(['good-output'])['good-output'] === ProofState::UNSPENT, 'Successful finalization did not store output');

    unlink($freshPath);
    @unlink($freshPath . '-wal');
    @unlink($freshPath . '-shm');
    @unlink($restorePath);
    @unlink($restorePath . '-wal');
    @unlink($restorePath . '-shm');
    fwrite(STDOUT, "storage_safety: OK\n");
} finally {
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
}
