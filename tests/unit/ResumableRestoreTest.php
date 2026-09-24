<?php

declare(strict_types=1);

namespace Cashu\Tests;

require_once dirname(__DIR__) . '/support/FakeRestoreMint.php';

use Cashu\CashuException;
use Cashu\Keyset;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Tests\Support\FakeRestoreMint;
use Cashu\Wallet;
use Cashu\WalletStorage;
use PHPUnit\Framework\TestCase;

/**
 * Seed provenance and the bounded, resumable restore (review 2026-09-25 R1–R5; audit
 * L-3, L-5, L-7, L-9, L-11), against an in-process mint that really answers NUT-09 for
 * this seed's history.
 */
final class ResumableRestoreTest extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const OTHER = 'legal winner thank year wave sausage worth useful legal winner thank yellow';
    private const V1 = '009a1f293253e41e';
    private const MINT = 'https://mint.example';

    private string $db;
    private string $v2;
    private Wallet $seed;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/cashu-resumable-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->v2 = '01' . str_repeat('ab', 32);
        $this->seed = new Wallet(self::MINT);
        $this->seed->initFromMnemonic(self::MNEMONIC);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->db . $suffix);
        }
    }

    private function mint(array $units = ['sat' => null]): FakeRestoreMint
    {
        $keysets = [];
        foreach ($units as $unit => $id) {
            $keysets[] = ['id' => $id ?? $this->v2, 'unit' => $unit, 'active' => true];
        }
        return new FakeRestoreMint($keysets);
    }

    /** Open the account like an application would, binding it on first use. */
    private function wallet(FakeRestoreMint $mint, string $bind = 'restore', string $unit = 'sat', string $mnemonic = self::MNEMONIC): Wallet
    {
        $wallet = new Wallet(self::MINT, $unit, $this->db, 'store-1');
        if ($wallet->getStorage()->getSeedFingerprint() !== null) {
            $wallet->initFromMnemonic($mnemonic);
        } elseif ($bind === 'new') {
            $wallet->initializeNewFromMnemonic($mnemonic);
        } elseif ($bind === 'adopt') {
            $wallet->adoptSeedForExistingStorage($mnemonic);
        } else {
            $wallet->initializeForRestore($mnemonic);
        }
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $mint);
        return $wallet;
    }

    /** Give a wallet loaded keys for spending tests (as loadMint() would). */
    private function loadKeys(Wallet $wallet, string $keysetId): void
    {
        $keys = array_map(fn() => FakeRestoreMint::G, array_flip(array_map('intval', array_keys(FakeRestoreMint::keys()))));
        (new \ReflectionProperty(Wallet::class, 'keys'))->setValue($wallet, [$keysetId => $keys]);
        (new \ReflectionProperty(Wallet::class, 'keysets'))->setValue($wallet, [new Keyset($keysetId, 'sat', $keys)]);
    }

    /** Run restoreStep until it stops being pending. */
    private function drain(Wallet $wallet, int $max = 100, int $gap = 20, int $limit = 200): array
    {
        for ($i = 0; $i < $limit; $i++) {
            $step = $wallet->restoreStep($max, null, $gap);
            if ($step['status'] !== 'pending') {
                return $step;
            }
        }
        $this->fail('restoreStep never finished');
    }

    // ------------------------------------------------------------ R1: provenance

    /** Regression 1: a freshly generated seed transacts without any historical scan. */
    public function testFreshSeedIsUsableWithoutAnyScan(): void
    {
        $mint = $this->mint();
        $mint->onPost = function (string $path, array $data): array {
            $this->assertNotSame('restore', $path, 'a fresh seed must not scan');
            $this->assertSame('mint/bolt11', $path);
            return ['signatures' => array_map(
                fn($o) => ['id' => $o['id'], 'amount' => $o['amount'], 'C_' => $o['B_']],
                $data['outputs']
            )];
        };
        $mint->onGet = fn() => throw new CashuException('offline');
        $wallet = $this->wallet($mint, 'new');
        $this->loadKeys($wallet, $this->v2);

        $this->assertFalse($wallet->requiresRecovery());
        $provenance = $wallet->getStorage()->getSeedProvenance();
        $this->assertSame(WalletStorage::INIT_FRESH_SEED, $provenance['init_reason']);
        $this->assertSame(WalletStorage::READY_FRESH_SEED, $provenance['ready_reason']);

        $wallet->mint('paid-invoice', 5);
        $this->assertSame(5, $wallet->getBalance());
        $this->assertSame(2, $wallet->getStorage()->getCounter($this->v2));
    }

    /**
     * Regression 2 (the R1 reproduction): an imported seed with history at the mint whose
     * first scans fail before writing anything must never become ready at counter 0 —
     * not through repeated steps, and not by marking it ready directly.
     */
    public function testImportedSeedIsNeverReadiedAtCounterZeroWhenScansFail(): void
    {
        $mint = $this->mint();
        $mint->sign($this->seed, $this->v2, 0, 8);
        $mint->sign($this->seed, $this->v2, 1, 2);
        $outage = true;
        $mint->onPost = function (string $path) use (&$outage): ?array {
            if ($outage && $path === 'restore') {
                throw new CashuException('Connection timed out');
            }
            return null;
        };

        for ($tick = 0; $tick < 3; $tick++) {
            $wallet = $this->wallet($mint);
            $step = $wallet->restoreStep(10, null, 20);
            $this->assertSame('error', $step['status']);
            $this->assertStringContainsString('timed out', (string)$step['error']);
            $this->assertTrue($wallet->requiresRecovery());
            $this->assertSame(0, $wallet->getStorage()->getCounter($this->v2));
            $this->assertSame('error', $wallet->getRestoreStatus()['status']);
            try {
                $wallet->getStorage()->markSeedReady();
                $this->fail('markSeedReady() readied an unrecovered imported seed');
            } catch (CashuException $e) {
                $this->assertFalse($wallet->getStorage()->isSeedReady());
            }
            try {
                $wallet->mint('q', 1);
                $this->fail('An unrecovered seed issued outputs');
            } catch (CashuException $e) {
                $this->assertSame(0, $wallet->getStorage()->getCounter($this->v2));
            }
        }

        $outage = false;
        $wallet = $this->wallet($mint);
        $step = $this->drain($wallet, 10);
        $this->assertSame('complete', $step['status']);
        $this->assertFalse($wallet->requiresRecovery());
        $this->assertSame(2, $wallet->getStorage()->getCounter($this->v2));
        $this->assertSame(10, $wallet->getBalance());
        $provenance = $wallet->getStorage()->getSeedProvenance();
        $this->assertSame(WalletStorage::INIT_RESTORE_REQUIRED, $provenance['init_reason']);
        $this->assertSame(WalletStorage::READY_RECOVERY_COMPLETE, $provenance['ready_reason']);
        $this->assertSame(WalletStorage::RECOVERY_VERSION, $provenance['recovery_version']);
        $this->assertNotNull($provenance['recovered_at']);
    }

    /** L-11: adopting empty storage is recovery, not readiness at counter 0. */
    public function testAdoptingEmptyStorageRequiresRecovery(): void
    {
        $mint = $this->mint();
        $mint->sign($this->seed, $this->v2, 3, 4);
        $wallet = $this->wallet($mint, 'adopt');
        $this->assertTrue($wallet->requiresRecovery());
        $this->assertSame('complete', $this->drain($wallet)['status']);
        $this->assertSame(4, $wallet->getStorage()->getCounter($this->v2));
        $this->assertFalse($wallet->requiresRecovery());
    }

    /** Regression 3: all-spent history still moves the counters past it. */
    public function testAllSpentHistoryStillAdvancesCounters(): void
    {
        $mint = $this->mint();
        $secrets = [];
        foreach ([0 => 1, 1 => 2, 4 => 8] as $counter => $amount) {
            $secrets[] = $mint->sign($this->seed, $this->v2, $counter, $amount, 'SPENT');
        }
        $wallet = $this->wallet($mint);
        $step = $this->drain($wallet, 3);

        $this->assertSame('complete', $step['status']);
        $this->assertSame(0, $wallet->getBalance());
        $this->assertSame(5, $wallet->getStorage()->getCounter($this->v2));
        $this->assertSame(4, $step['progress'][$this->v2]['highestSigned']);
        $states = $wallet->getStorage()->getProofsStatesBySecrets($secrets);
        $this->assertSame([ProofState::SPENT], array_values(array_unique($states)));
        $this->assertFalse($wallet->requiresRecovery());
    }

    /** L-9: the 500-counter gap is counted in counters, so small steps do not shrink it. */
    public function testDefaultGapIsMeasuredInCountersAcrossSmallSteps(): void
    {
        $mint = $this->mint();
        $mint->sign($this->seed, $this->v2, 0, 1);
        $mint->sign($this->seed, $this->v2, 499, 16);
        $wallet = $this->wallet($mint);

        $steps = 0;
        do {
            $step = $wallet->restoreStep(10);
            $steps++;
            $this->assertLessThanOrEqual(10, $step['recovered']['outputs']);
        } while ($step['status'] === 'pending' && $steps < 200);

        $this->assertSame('complete', $step['status']);
        $this->assertSame(Wallet::RESTORE_GAP, $step['gap']);
        $this->assertSame(1000, $step['progress'][$this->v2]['nextCounter']);
        $this->assertSame(500, $wallet->getStorage()->getCounter($this->v2));
        $this->assertSame(17, $wallet->getBalance());
        $this->assertSame(100, $steps);
    }

    /** A deadline stops deriving further outputs, but every call makes progress. */
    public function testDeadlineBoundsTheStepButAlwaysProgresses(): void
    {
        $mint = $this->mint();
        $wallet = $this->wallet($mint);
        $step = $wallet->restoreStep(100, microtime(true) - 1, 20);
        $this->assertSame('pending', $step['status']);
        $this->assertSame(1, $step['recovered']['outputs']);
        $this->assertSame(1, $step['progress'][$this->v2]['nextCounter']);
    }

    // ------------------------------------------------------------ crash / resume

    /**
     * Regression 5: a failure at any point inside a checkpoint rolls the whole batch
     * back; resuming (in a new process) never lowers a counter or duplicates a proof.
     */
    public function testCrashAtAnyCheckpointResumesWithoutLossOrDuplication(): void
    {
        $mint = $this->mint();
        $history = [0 => 1, 3 => 2, 7 => 4, 12 => 8, 13 => 16, 21 => 32];
        foreach ($history as $counter => $amount) {
            $mint->sign($this->seed, $this->v2, $counter, $amount, $counter === 3 ? 'SPENT' : 'UNSPENT');
        }
        $expectedBalance = array_sum($history) - 2;
        $triggers = [
            'proofs' => 'BEFORE INSERT ON cashu_proofs',
            'counters' => 'BEFORE INSERT ON cashu_counters',
            'counter-update' => 'BEFORE UPDATE ON cashu_counters',
            'checkpoint' => 'BEFORE UPDATE OF next_counter ON cashu_restore_progress',
        ];

        $lastCounter = 0;
        $lastNext = 0;
        $round = 0;
        $status = 'pending';
        while ($status !== 'complete' && $round < 100) {
            $fault = array_keys($triggers)[$round % count($triggers)];
            $wallet = $this->wallet($mint);
            $pdo = $wallet->getStorage()->getPdo();
            $pdo->exec("CREATE TRIGGER crash {$triggers[$fault]} BEGIN SELECT RAISE(ABORT, 'crash'); END");
            $crashed = null;
            try {
                $crashed = $wallet->restoreStep(4, null, 20);
            } catch (\PDOException $e) {
                // Simulated crash inside the checkpoint transaction.
            }
            $pdo->exec('DROP TRIGGER crash');
            $this->assertGreaterThanOrEqual($lastCounter, $wallet->getStorage()->getCounter($this->v2));
            if ($crashed !== null && $crashed['status'] === 'complete') {
                $status = 'complete';
                break;
            }

            // A clean step in a fresh instance (a new request) resumes from the checkpoint.
            $wallet = $this->wallet($mint);
            $step = $wallet->restoreStep(4, null, 20);
            $status = $step['status'];
            $counter = $wallet->getStorage()->getCounter($this->v2);
            $next = $step['progress'][$this->v2]['nextCounter'];
            $this->assertGreaterThanOrEqual($lastCounter, $counter, 'counter lowered');
            $this->assertGreaterThanOrEqual($lastNext, $next, 'checkpoint moved back');
            $lastCounter = $counter;
            $lastNext = $next;
            $round++;
        }

        $this->assertSame('complete', $status);
        $this->assertSame(22, $wallet->getStorage()->getCounter($this->v2));
        $this->assertSame($expectedBalance, $wallet->getBalance());
        $rows = $wallet->getStorage()->getPdo()->query('SELECT COUNT(*) FROM cashu_proofs')->fetchColumn();
        $this->assertSame(count($history), (int)$rows, 'every proof stored exactly once');
    }

    /** A crash before a checkpoint commits leaves nothing from that batch behind. */
    public function testFailedCheckpointWritesNothing(): void
    {
        $mint = $this->mint();
        $mint->sign($this->seed, $this->v2, 1, 4);
        $wallet = $this->wallet($mint);
        $wallet->getStorage()->getPdo()->exec(
            "CREATE TRIGGER crash BEFORE UPDATE OF next_counter ON cashu_restore_progress BEGIN SELECT RAISE(ABORT, 'crash'); END"
        );
        try {
            $wallet->restoreStep(10, null, 20);
            $this->fail('Injected failure missed');
        } catch (\PDOException $e) {
        }
        $this->assertSame(0, $wallet->getBalance());
        $this->assertSame(0, $wallet->getStorage()->getCounter($this->v2));
        $this->assertSame(0, $wallet->getRestoreStatus()['progress'][$this->v2]['nextCounter']);
        $this->assertTrue($wallet->requiresRecovery());
    }

    // ------------------------------------------------------------ R2: malformed replies

    /** Regression 6 (R2): a malformed restore reply is an error, never an empty history. */
    public function testMalformedRestoreRepliesAreErrorsNotEmptyHistory(): void
    {
        $faults = [
            'empty object' => fn(array $r) => [],
            'no signatures' => fn(array $r) => ['outputs' => $r['outputs']],
            'signatures not a list' => fn(array $r) => ['outputs' => [], 'signatures' => 'none'],
            'length mismatch' => fn(array $r) => ['outputs' => $r['outputs'], 'signatures' => []],
            'foreign output' => function (array $r) {
                $r['outputs'][0]['B_'] = '02' . str_repeat('77', 32);
                return $r;
            },
            'duplicate' => fn(array $r) => ['outputs' => [$r['outputs'][0], $r['outputs'][0]],
                'signatures' => [$r['signatures'][0], $r['signatures'][0]]],
            'other keyset' => function (array $r) {
                $r['signatures'][0]['id'] = '00ad268c4d1f5826';
                return $r;
            },
            'negative amount' => function (array $r) {
                $r['signatures'][0]['amount'] = -4;
                return $r;
            },
            'string amount' => function (array $r) {
                $r['signatures'][0]['amount'] = '4';
                return $r;
            },
            'unknown denomination' => function (array $r) {
                $r['signatures'][0]['amount'] = 3;
                return $r;
            },
            'garbled C_' => function (array $r) {
                $r['signatures'][0]['C_'] = 'zz';
                return $r;
            },
        ];
        foreach ($faults as $name => $mutate) {
            $this->tearDown();
            $mint = $this->mint();
            $mint->sign($this->seed, $this->v2, 0, 4);
            $mint->onPost = function (string $path, array $data) use ($mint, $mutate): ?array {
                return $path === 'restore' ? $mutate($mint->restore($data['outputs'])) : null;
            };
            $wallet = $this->wallet($mint);
            $step = $wallet->restoreStep(10, null, 20);
            $this->assertSame('error', $step['status'], $name);
            $this->assertTrue($wallet->requiresRecovery(), $name);
            $this->assertSame(0, $wallet->getStorage()->getCounter($this->v2), $name);
            $this->assertSame(0, $step['progress'][$this->v2]['nextCounter'], "$name: no progress accepted");
            $this->assertSame(0, $wallet->getBalance(), $name);
        }
    }

    /** The legacy restore() reports the same fault as incomplete and stays blocked. */
    public function testLegacyRestoreTreatsEmptyObjectAsIncomplete(): void
    {
        $mint = $this->mint();
        $mint->onPost = fn(string $path) => $path === 'restore' ? [] : null;
        $wallet = $this->wallet($mint);
        $result = $wallet->restore();
        $this->assertTrue($result['incomplete']);
        $this->assertArrayHasKey($this->v2, $result['errors']);
        $this->assertTrue($wallet->requiresRecovery());
        $this->assertSame([], $result['counters']);
    }

    /** restoreBatch() itself throws on a malformed reply instead of returning []. */
    public function testRestoreBatchRejectsMalformedReply(): void
    {
        $mint = $this->mint();
        $mint->onPost = fn() => ['outputs' => []];
        $wallet = $this->wallet($mint);
        $this->expectException(CashuException::class);
        $wallet->restoreBatch($this->v2, 0, 5);
    }

    // ------------------------------------------------------------ R5: zero amounts

    /** R5: zero-value signatures are used counters, not balance and not an error. */
    public function testZeroAmountSignaturesCountAsUsedCounters(): void
    {
        $mint = $this->mint(['sat' => self::V1]);
        $mint->sign($this->seed, self::V1, 1, 4);
        $mint->sign($this->seed, self::V1, 2, 0);
        $mint->sign($this->seed, self::V1, 6, 0);
        $wallet = $this->wallet($mint);

        $highest = null;
        $this->assertCount(1, $wallet->restoreBatch(self::V1, 0, 10, $highest));
        $this->assertSame(6, $highest);

        $step = $this->drain($wallet, 5, 10);
        $this->assertSame('complete', $step['status']);
        $this->assertSame(4, $wallet->getBalance());
        $this->assertSame(7, $wallet->getStorage()->getCounter(self::V1));
        $this->assertSame(6, $step['progress'][self::V1]['highestSigned']);
        $this->assertSame(1, (int)$wallet->getStorage()->getPdo()->query('SELECT COUNT(*) FROM cashu_proofs')->fetchColumn());

        $range = new Wallet(self::MINT);
        $range->initFromMnemonic(self::MNEMONIC);
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($range, $mint);
        (new \ReflectionProperty(Wallet::class, 'keys'))->setValue($range, [self::V1 => [4 => FakeRestoreMint::G]]);
        $this->assertSame([4], array_map(fn(Proof $p) => $p->amount, $range->restoreTokensForRange(self::V1, 0, 10)));
    }

    // ------------------------------------------------------------ R3: UNKNOWN proofs

    /** R3/L-3: a checkstate outage parks proofs UNKNOWN and blocks readiness until verified. */
    public function testCheckstateOutageBlocksReadinessUntilUnknownProofsAreReconciled(): void
    {
        $mint = $this->mint();
        $mint->sign($this->seed, $this->v2, 0, 16);
        $spentSecret = $mint->sign($this->seed, $this->v2, 1, 8, 'SPENT');
        $outage = true;
        $mint->onPost = function (string $path) use (&$outage): ?array {
            if ($outage && $path === 'checkstate') {
                throw new CashuException('checkstate unavailable');
            }
            return null;
        };
        $wallet = $this->wallet($mint);
        $step = $this->drain($wallet, 50, 20);

        $this->assertSame('error', $step['status']);
        $this->assertTrue($wallet->requiresRecovery());
        $this->assertSame(2, $wallet->getStorage()->getCounter($this->v2), 'counters advance regardless');
        $this->assertSame(0, $wallet->getBalance());
        $this->assertSame(2, $wallet->getStorage()->countProofsInState(ProofState::UNKNOWN));
        $this->assertSame(2, $wallet->getRestoreStatus()['unknownProofs']);

        // Still unreachable: repeated steps stay blocked without rescanning.
        $before = $mint->restoredOutputs();
        $this->assertSame('error', $wallet->restoreStep(50, null, 20)['status']);
        $this->assertSame($before, $mint->restoredOutputs());

        // Reconcile without a seed scan, then the next step finalizes.
        $outage = false;
        $reconciled = $wallet->reconcileUnknownProofs();
        $this->assertSame(['checked' => 2, 'unspent' => 1, 'spent' => 1, 'still_unknown' => 0, 'errors' => []], $reconciled);
        $this->assertSame(16, $wallet->getBalance());
        $this->assertSame(ProofState::SPENT, $wallet->getStorage()->getProofsStatesBySecrets([$spentSecret])[$spentSecret]);
        $this->assertTrue($wallet->requiresRecovery(), 'reconciling does not by itself ready the account');
        $this->assertSame('complete', $wallet->restoreStep(50, null, 20)['status']);
        $this->assertFalse($wallet->requiresRecovery());
        $this->assertSame($before, $mint->restoredOutputs(), 'finalizing needed no rescan');
    }

    /** The R3 probe: a retried restore() must promote UNKNOWN rows it now verifies. */
    public function testRetriedRestorePromotesUnknownProofs(): void
    {
        $mint = $this->mint();
        $mint->sign($this->seed, $this->v2, 0, 8);
        $mint->sign($this->seed, $this->v2, 1, 8);
        $outage = true;
        $mint->onPost = function (string $path) use (&$outage): ?array {
            if ($outage && $path === 'checkstate') {
                throw new CashuException('timeout');
            }
            return null;
        };
        $wallet = $this->wallet($mint);
        $first = $wallet->restore(10, 2);
        $this->assertTrue($first['incomplete']);
        $this->assertTrue($wallet->requiresRecovery());

        $outage = false;
        $second = $wallet->restore(10, 2);
        $this->assertFalse($second['incomplete']);
        $this->assertSame(16, $wallet->getBalance());
        $this->assertSame(0, $wallet->getStorage()->countProofsInState(ProofState::UNKNOWN));
        $this->assertFalse($wallet->requiresRecovery());
    }

    /** A proof the mint reports PENDING stays UNKNOWN and keeps recovery open. */
    public function testMintPendingStateKeepsProofUnknown(): void
    {
        $mint = $this->mint();
        $mint->sign($this->seed, $this->v2, 0, 8, 'PENDING');
        $wallet = $this->wallet($mint);
        $this->assertSame('error', $this->drain($wallet, 50, 20)['status']);
        $this->assertSame(1, $wallet->getStorage()->countProofsInState(ProofState::UNKNOWN));
        $this->assertTrue($wallet->requiresRecovery());
    }

    /**
     * syncProofStates() also reconciles UNKNOWN rows, and neither it nor restore touches
     * SPENT, EXPORTED or journal-owned PENDING rows (L-7).
     */
    public function testReconciliationAndRestoreLeaveProtectedStatesAlone(): void
    {
        $mint = $this->mint();
        $wallet = $this->wallet($mint, 'new');
        $storage = $wallet->getStorage();
        $secrets = [
            'unknown' => $mint->sign($this->seed, $this->v2, 0, 1),
            'spent' => $mint->sign($this->seed, $this->v2, 1, 2),
            'exported' => $mint->sign($this->seed, $this->v2, 2, 4),
            'journal' => $mint->sign($this->seed, $this->v2, 3, 8, 'SPENT'),
        ];
        $proofs = [];
        foreach ($secrets as $label => $secret) {
            $proofs[$label] = new Proof($this->v2, [1, 2, 4, 8][count($proofs)], $secret, FakeRestoreMint::y($secret));
        }
        $storage->storeProofs(array_values($proofs));
        $storage->updateProofsState([$secrets['unknown']], ProofState::UNKNOWN);
        $storage->updateProofsState([$secrets['spent']], ProofState::SPENT);
        $storage->updateProofsState([$secrets['exported']], ProofState::EXPORTED);
        $storage->preparePendingSpend('swap:inflight', 'swap', [$proofs['journal']], $this->v2, [8]);

        $sync = $wallet->syncProofStates();
        $this->assertSame(1, $sync['unknown']['unspent']);

        $wallet->restore(10, 2);
        $states = $storage->getProofsStatesBySecrets(array_values($secrets));
        $this->assertSame(ProofState::UNSPENT, $states[$secrets['unknown']]);
        $this->assertSame(ProofState::SPENT, $states[$secrets['spent']], 'mint UNSPENT never resurrects a SPENT row');
        $this->assertSame(ProofState::EXPORTED, $states[$secrets['exported']]);
        $this->assertSame(ProofState::PENDING, $states[$secrets['journal']], 'journal-owned input left to its journal');
        $this->assertNotNull($storage->getPendingOperationById('swap:inflight'));
        $this->assertSame(4, $storage->getCounter($this->v2), 'counter raised past the scanned history');
    }

    // ------------------------------------------------------------ units, API

    /** Keysets of every unit are scanned; each unit's proofs land in its own namespace. */
    public function testAllUnitsAreRecoveredAndReadiedTogether(): void
    {
        $usd = '01' . str_repeat('cd', 32);
        $mint = $this->mint(['sat' => null, 'usd' => $usd]);
        $mint->sign($this->seed, $this->v2, 0, 2);
        $mint->sign($this->seed, $usd, 5, 64);
        $wallet = $this->wallet($mint);
        $this->assertSame('complete', $this->drain($wallet, 7, 10)['status']);

        $this->assertSame(2, $wallet->getBalance());
        $usdWallet = $this->wallet($mint, 'restore', 'usd');
        $this->assertFalse($usdWallet->requiresRecovery(), 'the usd namespace was covered by the scan');
        $this->assertSame(64, $usdWallet->getBalance());
        $this->assertSame(6, $usdWallet->getStorage()->getCounter($usd));
    }

    /** Another seed's namespace is never written into. */
    public function testSiblingNamespaceOfAnotherSeedIsNotTouched(): void
    {
        $usd = '01' . str_repeat('cd', 32);
        $mint = $this->mint(['sat' => null, 'usd' => $usd]);
        $mint->sign($this->seed, $usd, 0, 64);
        $this->wallet($mint, 'new', 'usd', self::OTHER);
        $wallet = $this->wallet($mint);
        $step = $this->drain($wallet, 50, 10);
        $this->assertSame('error', $step['status']);
        $this->assertStringContainsString('different seed', (string)$step['error']);
        $this->assertTrue($wallet->requiresRecovery());
        $other = new WalletStorage($this->db, self::MINT, 'usd', 'store-1');
        $this->assertSame(0, $other->getBalance());
        $this->assertSame(0, $other->getCounter($usd));
    }

    public function testRestoreStatusAndLegacyWrapperShape(): void
    {
        $mint = $this->mint();
        $mint->sign($this->seed, $this->v2, 2, 32);
        $wallet = $this->wallet($mint);
        $this->assertSame('none', $wallet->getRestoreStatus()['status']);

        $step = $wallet->restoreStep(3, null, 10);
        $this->assertSame('pending', $step['status']);
        $this->assertSame(['outputs' => 3, 'proofs' => 1, 'unspent' => 1, 'amount' => 32, 'spent' => 0,
            'unknown' => 0, 'zeroAmount' => 0], $step['recovered']);
        $this->assertFalse($step['ready']);
        $status = $wallet->getRestoreStatus();
        $this->assertSame('pending', $status['status']);
        $this->assertSame(['unit' => 'sat', 'nextCounter' => 3, 'highestSigned' => 2, 'emptyRun' => 0,
            'done' => false, 'error' => null], $status['progress'][$this->v2]);
        $this->assertSame(WalletStorage::INIT_RESTORE_REQUIRED, $status['provenance']['init_reason']);

        // restore() resumes the session and keeps its historical return shape.
        $calls = [];
        $result = $wallet->restore(5, 2, function (...$args) use (&$calls) {
            $calls[] = $args;
        });
        foreach (['incomplete', 'errors', 'proofs', 'counters', 'byUnit', 'skippedKeysets'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertFalse($result['incomplete']);
        $this->assertSame([$this->v2 => 3], $result['counters']);
        $this->assertSame([], $result['proofs'], 'proofs found by the earlier step are in storage');
        $this->assertSame([$this->v2, 3, 0, 'sat'], $calls[0]);
        $this->assertSame('complete', $wallet->getRestoreStatus()['status']);
        $this->assertSame(32, $wallet->getBalance());

        // A later full restore() rescans from zero and reports what it found.
        $again = $wallet->restore(5, 2);
        $this->assertCount(1, $again['proofs']);
        $this->assertSame(32, $again['byUnit']['sat']['unspent'][0]->amount);
        $this->assertSame([$this->v2 => 3], $again['byUnit']['sat']['counters']);
        $this->assertSame(32, $wallet->getBalance(), 'no duplicate proof');
    }

    /** Regression 7: an existing ready account reopens ready without a scan or reset. */
    public function testLegacyReadyWalletStaysReadyAfterUpgrade(): void
    {
        $storage = new WalletStorage($this->db, self::MINT, 'sat', 'store-1');
        $storage->getPdo()->prepare(
            'INSERT INTO cashu_wallet_metadata (wallet_id, seed_fingerprint, ready, created_at) VALUES (?, ?, 1, 1)'
        )->execute([$storage->getWalletId(), Wallet::calculateSeedFingerprint(self::MNEMONIC)]);
        $storage->raiseCounter($this->v2, 77);

        $mint = $this->mint();
        $wallet = $this->wallet($mint);
        $this->assertFalse($wallet->requiresRecovery());
        $this->assertSame(77, $wallet->getStorage()->getCounter($this->v2));
        $this->assertTrue($wallet->getStorage()->getSeedProvenance()['legacy']);
        $this->assertSame([], $mint->calls);
    }

    // ------------------------------------------------------------ R4: inline melt change

    /**
     * R4/L-5: 4-sat input, 1-sat payment, POST response lost, GET says PAID without
     * change — the 3 sats of change are recovered through NUT-09 on the blank outputs.
     */
    public function testInlineMeltRecoversChangeWhenQuoteOmitsIt(): void
    {
        [$wallet, $mint, $input] = $this->meltSetup();
        $mint->onPost = function (string $path, array $data) use ($mint): ?array {
            if ($path === 'melt/bolt11') {
                $this->signChange($mint, $data['outputs']);
                throw new CashuException('Operation timed out after 120000 milliseconds');
            }
            return null;
        };

        $result = $wallet->melt('q', [$input]);

        $this->assertTrue($result['paid']);
        $this->assertFalse($result['changeRecoveryPending']);
        $this->assertSame(3, Wallet::sumProofs($result['change']));
        $this->assertSame(3, $wallet->getBalance());
        $this->assertNull($wallet->getStorage()->getPendingOperationById('melt:q'));
        $this->assertSame(ProofState::SPENT, $wallet->getStorage()->getProofsStatesBySecrets([$input->secret])[$input->secret]);
    }

    /** If change recovery itself fails, the journal stays for recoverPendingMelts(). */
    public function testInlineMeltKeepsJournalUntilChangeIsRecovered(): void
    {
        [$wallet, $mint, $input] = $this->meltSetup();
        $restoreDown = true;
        $mint->onPost = function (string $path, array $data) use ($mint, &$restoreDown): ?array {
            if ($path === 'melt/bolt11') {
                $this->signChange($mint, $data['outputs']);
                throw new CashuException('Response lost');
            }
            if ($path === 'restore' && $restoreDown) {
                return [];
            }
            return null;
        };

        $result = $wallet->melt('q', [$input]);
        $this->assertTrue($result['paid']);
        $this->assertTrue($result['changeRecoveryPending']);
        $this->assertSame([], $result['change']);
        $this->assertNotNull($wallet->getStorage()->getPendingOperationById('melt:q'));
        $this->assertSame(0, $wallet->getBalance());

        $restoreDown = false;
        $recovered = $wallet->recoverPendingMelts();
        $this->assertSame(3, $recovered['change_recovered']);
        $this->assertSame(3, $wallet->getBalance());
        $this->assertNull($wallet->getStorage()->getPendingOperationById('melt:q'));
    }

    /** @return array{0: Wallet, 1: FakeRestoreMint, 2: Proof} */
    private function meltSetup(): array
    {
        $mint = $this->mint();
        $wallet = $this->wallet($mint, 'new');
        $this->loadKeys($wallet, $this->v2);
        $mint->meltQuotes['q'] = ['quote' => 'q', 'amount' => 1, 'fee_reserve' => 0, 'state' => 'UNPAID'];
        $input = new Proof($this->v2, 4, 'melt-input-secret', FakeRestoreMint::G);
        $wallet->getStorage()->storeProofs([$input]);
        return [$wallet, $mint, $input];
    }

    /** The mint pays, signs 3 sats of change on the blank outputs, and GET omits it. */
    private function signChange(FakeRestoreMint $mint, array $outputs): void
    {
        $this->assertCount(2, $outputs, 'max change 3 needs two blank outputs');
        foreach ([2, 1] as $i => $amount) {
            $mint->signed[strtolower($outputs[$i]['B_'])] = ['amount' => $amount, 'id' => $outputs[$i]['id']];
        }
        $mint->meltQuotes['q'] = ['quote' => 'q', 'amount' => 1, 'fee_reserve' => 0, 'state' => 'PAID',
            'payment_preimage' => 'ab'];
    }

    // ------------------------------------------------------------ L-9: BCMath

    /**
     * Regression 4: with BCMath forced (no GMP), bounded steps in separate processes each
     * finish within a few seconds and together complete the scan from persisted progress.
     */
    public function testBcmathBoundedStepsCompleteAcrossProcesses(): void
    {
        $script = dirname(__DIR__) . '/support/bcmath_restore_step.php';
        $history = json_encode(['0' => 4, '2' => 8, '5' => 1]);
        $steps = [];
        for ($i = 0; $i < 10; $i++) {
            $out = shell_exec(implode(' ', array_map('escapeshellarg', [
                PHP_BINARY, $script, $this->db, '10', '4', '20', $this->v2, $history,
            ])) . ' 2>&1');
            $step = json_decode((string)$out, true);
            $this->assertIsArray($step, (string)$out);
            $this->assertFalse($step['gmp'], 'BCMath must be forced');
            $this->assertLessThan(5.0, $step['elapsed'], 'a bounded step must fit a request');
            $this->assertLessThanOrEqual(10, $step['outputs']);
            $steps[] = $step;
            if ($step['status'] !== 'pending') {
                break;
            }
            $this->assertFalse($step['ready']);
        }
        $last = end($steps);
        $this->assertSame('complete', $last['status'], json_encode($steps));
        $this->assertGreaterThan(1, count($steps));
        $this->assertTrue($last['ready']);
        $this->assertSame(6, $last['counter']);
        $this->assertSame(13, $last['balance']);
        $this->assertSame(26, $last['progress']['nextCounter']);
    }
}
