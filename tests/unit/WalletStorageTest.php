<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\CashuException;
use Cashu\DLEQWallet;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\WalletStorage;
use PHPUnit\Framework\TestCase;

/**
 * WalletStorage: proof lifecycle, counters, pending-operation journal and
 * reservation semantics, using throwaway SQLite databases.
 */
final class WalletStorageTest extends TestCase
{
    private const MINT = 'https://mint.example';
    private const KEYSET = '009a1f293253e41e';

    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/cashu-phpunit-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->dbPath . $suffix);
        }
    }

    private function storage(string $unit = 'sat', ?string $identity = null): WalletStorage
    {
        return new WalletStorage($this->dbPath, self::MINT, $unit, $identity);
    }

    private static function proof(int $amount, string $secret, ?DLEQWallet $dleq = null): Proof
    {
        return new Proof(self::KEYSET, $amount, $secret, '02' . str_repeat('11', 32), $dleq);
    }

    // ------------------------------------------------------------ wallet id

    public function testDeriveWalletIdIsDeterministicAndScoped(): void
    {
        $base = WalletStorage::deriveWalletId(self::MINT);

        $this->assertSame($base, WalletStorage::deriveWalletId(self::MINT, 'sat'));
        $this->assertSame($base, WalletStorage::deriveWalletId(self::MINT . '/', 'sat'), 'trailing slash must not change the wallet id');
        $this->assertSame($base, WalletStorage::deriveWalletId(self::MINT, 'SAT'), 'unit must be case-insensitive');

        $this->assertNotSame($base, WalletStorage::deriveWalletId(self::MINT, 'eur'));
        $this->assertNotSame($base, WalletStorage::deriveWalletId('https://other.example'));
        $this->assertNotSame($base, WalletStorage::deriveWalletId(self::MINT, 'sat', 'merchant-a'));
        $this->assertNotSame(
            WalletStorage::deriveWalletId(self::MINT, 'sat', 'merchant-a'),
            WalletStorage::deriveWalletId(self::MINT, 'sat', 'merchant-b')
        );
    }

    // -------------------------------------------------------- proof lifecycle

    public function testProofLifecycle(): void
    {
        $storage = $this->storage();
        $storage->storeProofs([self::proof(1, 's1'), self::proof(4, 's2')], 'quote-1');

        $this->assertSame(5, $storage->getBalance());
        $this->assertCount(2, $storage->getProofs(ProofState::UNSPENT));
        $this->assertCount(0, $storage->getProofs(ProofState::SPENT));

        $storage->updateProofsState(['s1'], ProofState::SPENT);
        $this->assertSame(4, $storage->getBalance());
        $states = $storage->getProofsStatesBySecrets(['s1', 's2', 'missing']);
        $this->assertSame(ProofState::SPENT, $states['s1']);
        $this->assertSame(ProofState::UNSPENT, $states['s2']);
        $this->assertArrayNotHasKey('missing', $states);

        // spent_at is not part of the getProofs() projection; check it directly
        $stmt = $storage->getPdo()->prepare('SELECT spent_at FROM cashu_proofs WHERE wallet_id = ? AND secret = ?');
        $stmt->execute([$storage->getWalletId(), 's1']);
        $this->assertNotNull($stmt->fetchColumn(), 'spent_at must be recorded');

        $storage->deleteProofs(['s2']);
        $this->assertSame(0, $storage->getBalance());
        $this->assertCount(1, $storage->getProofs(ProofState::SPENT), 'deleting s2 must not touch s1');
    }

    public function testStoreProofsIsIdempotentPerSecret(): void
    {
        $storage = $this->storage();
        $storage->storeProofs([self::proof(2, 'dup')]);
        $storage->storeProofs([self::proof(2, 'dup')]);

        $this->assertSame(2, $storage->getBalance());
        $this->assertCount(1, $storage->getProofs(ProofState::UNSPENT));
    }

    public function testGetProofsByQuoteId(): void
    {
        $storage = $this->storage();
        $storage->storeProofs([self::proof(1, 'q1a'), self::proof(2, 'q1b')], 'quote-1');
        $storage->storeProofs([self::proof(4, 'q2a')], 'quote-2');

        $this->assertCount(2, $storage->getProofsByQuoteId('quote-1'));
        $this->assertCount(1, $storage->getProofsByQuoteId('quote-2'));
        $this->assertCount(0, $storage->getProofsByQuoteId('unknown'));
    }

    public function testProofObjectsRoundtripWithDleq(): void
    {
        $storage = $this->storage();
        $dleq = new DLEQWallet(str_repeat('aa', 32), str_repeat('bb', 32), str_repeat('cc', 32));
        $storage->storeProofs([self::proof(8, 'with-dleq', $dleq), self::proof(1, 'plain')]);

        $objects = $storage->getProofsAsObjects();
        $bySecret = [];
        foreach ($objects as $p) {
            $bySecret[$p->secret] = $p;
        }

        $this->assertNotNull($bySecret['with-dleq']->dleq);
        $this->assertSame($dleq->e, $bySecret['with-dleq']->dleq->e);
        $this->assertSame($dleq->s, $bySecret['with-dleq']->dleq->s);
        $this->assertSame($dleq->r, $bySecret['with-dleq']->dleq->r);
        $this->assertNull($bySecret['plain']->dleq);
    }

    public function testGetProofsBySecretsAsObjectsPreservesOrderAndDetectsMissing(): void
    {
        $storage = $this->storage();
        $storage->storeProofs([self::proof(1, 'a'), self::proof(2, 'b')]);

        $proofs = $storage->getProofsBySecretsAsObjects(['b', 'a']);
        $this->assertSame(['b', 'a'], array_map(fn($p) => $p->secret, $proofs));

        $this->expectException(CashuException::class);
        $storage->getProofsBySecretsAsObjects(['a', 'gone']);
    }

    // -------------------------------------------------------------- isolation

    public function testWalletsAreIsolatedByUnitAndIdentity(): void
    {
        $sat = $this->storage('sat');
        $eur = $this->storage('eur');
        $merchant = $this->storage('sat', 'merchant-a');

        $sat->storeProofs([self::proof(8, 'sat-proof')]);

        $this->assertSame(8, $sat->getBalance());
        $this->assertSame(0, $eur->getBalance());
        $this->assertSame(0, $merchant->getBalance());
    }

    // --------------------------------------------------------------- counters

    public function testCounters(): void
    {
        $storage = $this->storage();

        $this->assertSame(0, $storage->getCounter(self::KEYSET));

        $this->assertSame(0, $storage->incrementCounter(self::KEYSET), 'increment returns the pre-increment value');
        $this->assertSame(1, $storage->incrementCounter(self::KEYSET));
        $this->assertSame(2, $storage->getCounter(self::KEYSET));

        $storage->setCounter('00ad268c4d1f5826', 42);
        $this->assertSame(['009a1f293253e41e' => 2, '00ad268c4d1f5826' => 42], $storage->getAllCounters());
    }

    // ----------------------------------------------------- pending operations

    public function testPendingOperationJournal(): void
    {
        $storage = $this->storage();
        $storage->savePendingOperation('mint:q1', 'mint', ['amounts' => [1, 2]], time() + 60);

        $op = $storage->getPendingOperationById('mint:q1');
        $this->assertNotNull($op);
        $this->assertSame('mint', $op['type']);
        $this->assertSame('mint:q1', $op['id'], 'public (unscoped) id must be returned');
        $this->assertSame([1, 2], $op['data']['amounts']);

        $this->assertCount(1, $storage->getPendingOperations('mint'));
        $this->assertCount(0, $storage->getPendingOperations('melt'));
        $this->assertCount(1, $storage->getPendingOperations());

        $storage->deletePendingOperation('mint:q1');
        $this->assertNull($storage->getPendingOperationById('mint:q1'));
    }

    public function testLegacyUnscopedJournalEntriesAreStillFound(): void
    {
        $storage = $this->storage();

        // Journals written before account-scoped IDs stored the public ID directly
        $stmt = $storage->getPdo()->prepare(
            'INSERT INTO cashu_pending_operations (id, wallet_id, type, data, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['melt:legacy', $storage->getWalletId(), 'melt', json_encode(['x' => 1]), time()]);

        $op = $storage->getPendingOperationById('melt:legacy');
        $this->assertNotNull($op);
        $this->assertSame(1, $op['data']['x']);

        $storage->deletePendingOperation('melt:legacy');
        $this->assertNull($storage->getPendingOperationById('melt:legacy'));
    }

    public function testCleanExpiredNeverTouchesMoneyMovingJournals(): void
    {
        $storage = $this->storage();
        $past = time() - 10;

        $storage->savePendingOperation('mint:old', 'mint', [], $past);
        $storage->savePendingOperation('melt:old', 'melt', ['input_secrets' => ['s']], $past);
        $storage->savePendingOperation('swap:old', 'swap', ['input_secrets' => ['t']], $past);
        $storage->savePendingOperation('mint:fresh', 'mint', [], time() + 3600);
        $storage->savePendingOperation('mint:forever', 'mint', []);

        $this->assertSame(1, $storage->cleanExpiredPendingOperations(), 'only the expired mint journal may be removed');
        $this->assertNull($storage->getPendingOperationById('mint:old'));
        $this->assertNotNull($storage->getPendingOperationById('melt:old'), 'expired melt journal must survive');
        $this->assertNotNull($storage->getPendingOperationById('swap:old'), 'expired swap journal must survive');
        $this->assertNotNull($storage->getPendingOperationById('mint:fresh'));
        $this->assertNotNull($storage->getPendingOperationById('mint:forever'));
    }

    public function testGetReservedInputSecretsCoversMeltAndSwap(): void
    {
        $storage = $this->storage();
        $storage->savePendingOperation('melt:a', 'melt', ['input_secrets' => ['s1', 's2']]);
        $storage->savePendingOperation('swap:b', 'swap', ['input_secrets' => ['s2', 's3']]);
        $storage->savePendingOperation('mint:c', 'mint', ['input_secrets' => ['not-reserved']]);

        $reserved = $storage->getReservedInputSecrets();
        sort($reserved);
        $this->assertSame(['s1', 's2', 's3'], $reserved);
    }

    // ------------------------------------------------- prepare/finalize spend

    public function testPreparePendingSpendReservesInputsAndCounters(): void
    {
        $storage = $this->storage();
        $input = self::proof(4, 'input');
        $storage->storeProofs([$input]);
        $storage->setCounter(self::KEYSET, 3);

        $data = $storage->preparePendingSpend('swap:op', 'swap', [$input], self::KEYSET, [2, 2]);

        $this->assertSame(3, $data['counter_start']);
        $this->assertSame([2, 2], $data['amounts']);
        $this->assertSame(['input'], $data['input_secrets']);
        $this->assertSame(5, $storage->getCounter(self::KEYSET), 'two output counters must be reserved');
        $this->assertSame(ProofState::PENDING, $storage->getProofsStatesBySecrets(['input'])['input']);
    }

    public function testPreparePendingSpendIsIdempotentForRetries(): void
    {
        $storage = $this->storage();
        $input = self::proof(4, 'input');
        $storage->storeProofs([$input]);

        $first = $storage->preparePendingSpend('swap:op', 'swap', [$input], self::KEYSET, [4]);
        $second = $storage->preparePendingSpend('swap:op', 'swap', [$input], self::KEYSET, [4]);

        $this->assertSame($first, $second, 'retry must return the original journal, not a new reservation');
        $this->assertSame(1, $storage->getCounter(self::KEYSET), 'retry must not burn additional counters');
    }

    public function testPreparePendingSpendRejectsIdCollision(): void
    {
        $storage = $this->storage();
        $a = self::proof(1, 'a');
        $b = self::proof(1, 'b');
        $storage->storeProofs([$a, $b]);
        $storage->preparePendingSpend('swap:op', 'swap', [$a], self::KEYSET, [1]);

        $this->expectException(CashuException::class);
        $this->expectExceptionMessageMatches('/collision/');
        $storage->preparePendingSpend('swap:op', 'swap', [$b], self::KEYSET, [1]);
    }

    public function testPreparePendingSpendRejectsDuplicateInputs(): void
    {
        $storage = $this->storage();
        $input = self::proof(1, 'dup');
        $storage->storeProofs([$input]);

        $this->expectException(CashuException::class);
        $storage->preparePendingSpend('swap:op', 'swap', [$input, $input], self::KEYSET, [2]);
    }

    public function testPreparePendingSpendImportsForeignProofsAsPending(): void
    {
        $storage = $this->storage();
        $received = self::proof(2, 'received-token'); // not in storage (e.g. receive())

        $storage->preparePendingSpend('swap:recv', 'swap', [$received], self::KEYSET, [2]);

        $this->assertSame(
            ProofState::PENDING,
            $storage->getProofsStatesBySecrets(['received-token'])['received-token'],
            'foreign input must be durably imported in PENDING state'
        );
    }

    public function testPreparePendingSpendRejectsSpentInputs(): void
    {
        $storage = $this->storage();
        $input = self::proof(1, 'spent');
        $storage->storeProofs([$input]);
        $storage->updateProofsState(['spent'], ProofState::SPENT);

        $this->expectException(CashuException::class);
        $storage->preparePendingSpend('swap:op', 'swap', [$input], self::KEYSET, [1]);
    }

    public function testFinalizePendingSpendReleasesInputsOnFailure(): void
    {
        // UNPAID+expired melt: inputs go back to UNSPENT, journal removed
        $storage = $this->storage();
        $input = self::proof(4, 'input');
        $storage->storeProofs([$input]);
        $storage->preparePendingSpend('melt:q', 'melt', [$input], self::KEYSET, []);

        $storage->finalizePendingSpend('melt:q', ['input'], ProofState::UNSPENT);

        $this->assertSame(ProofState::UNSPENT, $storage->getProofsStatesBySecrets(['input'])['input']);
        $this->assertNull($storage->getPendingOperationById('melt:q'));
        $this->assertSame(4, $storage->getBalance());
    }

    public function testFinalizePendingSpendStoresOutputsAndSpendsInputs(): void
    {
        $storage = $this->storage();
        $input = self::proof(4, 'input');
        $storage->storeProofs([$input]);
        $storage->preparePendingSpend('swap:q', 'swap', [$input], self::KEYSET, [1, 2]);

        $outputs = [self::proof(1, 'out1'), self::proof(2, 'out2')];
        $storage->finalizePendingSpend('swap:q', ['input'], ProofState::SPENT, $outputs);

        $this->assertSame(ProofState::SPENT, $storage->getProofsStatesBySecrets(['input'])['input']);
        $this->assertSame(3, $storage->getBalance());
        $this->assertNull($storage->getPendingOperationById('swap:q'));
    }

    // ------------------------------------------------------------- metadata

    public function testDuplicateImportsNeverResetPendingSpentOrExportedStates(): void
    {
        $storage = $this->storage();
        foreach ([ProofState::PENDING, ProofState::SPENT, 'EXPORTED'] as $state) {
            $proof = self::proof(2, $state);
            $storage->storeProofs([$proof], 'original');
            $storage->updateProofsState([$proof->secret], $state);
            $storage->storeProofs([$proof], 'duplicate');
            $this->assertSame($state, $storage->getProofsStatesBySecrets([$state])[$state]);
        }
        $this->assertSame(0, $storage->getBalance());
        $this->assertCount(3, $storage->getProofsByQuoteId('original'));
    }

    public function testConflictingImportRollsBackWholeBatch(): void
    {
        $storage = $this->storage();
        $storage->storeProofs([self::proof(2, 'existing')]);
        try {
            $storage->storeProofs([self::proof(1, 'new'), self::proof(4, 'existing')]);
            $this->fail('Conflicting import accepted');
        } catch (CashuException $e) {
            $this->assertSame(2, $storage->getBalance());
            $this->assertSame([], $storage->getProofsStatesBySecrets(['new']));
        }
    }

    public function testStaleFinalizationCannotResurrectNextOperationsOutput(): void
    {
        $storage = $this->storage();
        $input = self::proof(4, 'input');
        $output = self::proof(4, 'output');
        $storage->preparePendingSpend('swap:first', 'swap', [$input], self::KEYSET, [4]);
        $storage->finalizePendingSpend('swap:first', ['input'], ProofState::SPENT, [$output]);
        $storage->preparePendingSpend('swap:next', 'swap', [$output], self::KEYSET, [4]);
        $storage->finalizePendingSpend('swap:first', ['input'], ProofState::SPENT, [$output]);
        $this->assertSame(ProofState::PENDING, $storage->getProofsStatesBySecrets(['output'])['output']);
        $this->assertNotNull($storage->getPendingOperationById('swap:next'));
        $this->assertSame(0, $storage->getBalance());
    }

    public function testFinalizationRejectsWrongInputsAndLostReservation(): void
    {
        $storage = $this->storage();
        $storage->preparePendingSpend('swap:q', 'swap', [self::proof(4, 'input')], self::KEYSET, [4]);
        foreach ([['other'], ['input']] as $secrets) {
            if ($secrets === ['input']) {
                $storage->updateProofsState($secrets, ProofState::SPENT);
            }
            try {
                $storage->finalizePendingSpend('swap:q', $secrets, ProofState::SPENT, [self::proof(4, 'out')]);
                $this->fail('Invalid owner accepted');
            } catch (CashuException $e) {
                $this->assertNotNull($storage->getPendingOperationById('swap:q'));
                $this->assertSame([], $storage->getProofsStatesBySecrets(['out']));
            }
        }
    }

    public function testConflictingFinalizationRollsBackOutputsAndRetainsJournal(): void
    {
        $storage = $this->storage();
        $storage->storeProofs([self::proof(2, 'existing')]);
        $storage->preparePendingSpend('swap:q', 'swap', [self::proof(4, 'input')], self::KEYSET, [2, 2]);
        try {
            $storage->finalizePendingSpend('swap:q', ['input'], ProofState::SPENT,
                [self::proof(2, 'new'), self::proof(4, 'existing')]);
            $this->fail('Conflicting output accepted');
        } catch (CashuException $e) {
            $this->assertSame(ProofState::PENDING, $storage->getProofsStatesBySecrets(['input'])['input']);
            $this->assertNotNull($storage->getPendingOperationById('swap:q'));
            $this->assertSame([], $storage->getProofsStatesBySecrets(['new']));
        }
    }

    public function testSeedFingerprintBinding(): void
    {
        $storage = $this->storage();

        $this->assertNull($storage->getSeedFingerprint());
        $this->assertFalse($storage->isSeedReady());
        $this->assertFalse($storage->hasWalletData());

        $storage->bindSeedFingerprint('fp-1', true);
        $this->assertSame('fp-1', $storage->getSeedFingerprint());
        $this->assertTrue($storage->isSeedReady());

        // Same fingerprint is a no-op; a different one must be rejected
        $storage->bindSeedFingerprint('fp-1', true);
        $this->expectException(CashuException::class);
        $storage->bindSeedFingerprint('fp-2', false);
    }

    public function testBindNewSeedRejectedWhenStorageHasData(): void
    {
        $storage = $this->storage();
        $storage->storeProofs([self::proof(1, 'existing')]);
        $this->assertTrue($storage->hasWalletData());

        $this->expectException(CashuException::class);
        $storage->bindSeedFingerprint('fp-new', true);
    }

    public function testMarkSeedReadyRequiresBoundSeed(): void
    {
        $storage = $this->storage();

        $this->expectException(CashuException::class);
        $storage->markSeedReady();
    }

    public function testMarkSeedReadyPromotesRestoreAccounts(): void
    {
        $storage = $this->storage();
        $storage->bindSeedFingerprint('fp-restore', true, false);
        $this->assertFalse($storage->isSeedReady());

        $storage->markSeedReady();
        $this->assertTrue($storage->isSeedReady());
    }

    public function testListWallets(): void
    {
        $this->assertSame([], WalletStorage::listWallets($this->dbPath), 'missing db yields empty list');

        $sat = $this->storage('sat');
        $eur = $this->storage('eur');
        $sat->storeProofs([self::proof(1, 'a'), self::proof(2, 'b')]);
        $sat->updateProofsState(['a'], ProofState::SPENT);
        $eur->storeProofs([self::proof(8, 'c')]);

        $wallets = WalletStorage::listWallets($this->dbPath);
        $this->assertCount(2, $wallets);

        $byId = array_column($wallets, null, 'wallet_id');
        $satInfo = $byId[$sat->getWalletId()];
        $this->assertSame(2, (int)$satInfo['total_proofs']);
        $this->assertSame(1, (int)$satInfo['unspent']);
        $this->assertSame(1, (int)$satInfo['spent']);
        $this->assertSame(2, (int)$satInfo['balance']);
        $this->assertSame([self::KEYSET], $satInfo['keyset_ids']);

        $this->assertSame(8, (int)$byId[$eur->getWalletId()]['balance']);
    }
}
