<?php

declare(strict_types=1);

namespace Cashu\Tests;

require_once dirname(__DIR__) . '/support/FakeRestoreMint.php';

use Cashu\CashuException;
use Cashu\CashuProtocolException;
use Cashu\Keyset;
use Cashu\MintClient;
use Cashu\ProofState;
use Cashu\Tests\Support\FakeRestoreMint;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit follow-ups: short melt change, melt release while a POST may be in
 * flight, restore of keysets the mint stopped listing, invoice shape, https-only option.
 */
final class AuditKimiK31Test extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const KS = '009a1f293253e41e';
    private const MINT = 'https://mint.example';

    private string $db;
    private FakeRestoreMint $mint;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/cashu-k31-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->mint = new FakeRestoreMint([['id' => self::KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0]]);
    }

    protected function tearDown(): void
    {
        MintClient::setRequireHttps(false);
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->db . $suffix);
        }
    }

    private function wallet(): Wallet
    {
        $wallet = new Wallet(self::MINT, 'sat', $this->db, 'store-1');
        if ($wallet->getStorage()->getSeedFingerprint() === null) {
            $wallet->initializeNewFromMnemonic(self::MNEMONIC);
        } else {
            $wallet->initFromMnemonic(self::MNEMONIC);
        }
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $this->mint);
        $wallet->loadMint();
        return $wallet;
    }

    private function fund(Wallet $wallet, int $amount): array
    {
        $previous = $this->mint->onPost;
        $this->mint->onPost = fn(string $path, array $data) => $path === 'mint/bolt11'
            ? $this->mint->signOutputs($data['outputs']) : null;
        $proofs = $wallet->mint('fund-' . bin2hex(random_bytes(4)), $amount);
        $this->mint->onPost = $previous;
        return $proofs;
    }

    private function restoreCalls(): int
    {
        return count(array_filter($this->mint->calls, fn($call) => $call[0] === 'POST' && $call[1] === 'restore'));
    }

    /**
     * Melt 8 sat for a 3 sat invoice with 2 sat fee reserve: up to 5 sat change, of which
     * 3 (the overpayment) is guaranteed. The mint really signs 4 + 1 on our blanks and
     * answers with $change (a callable of the blank outputs).
     */
    private function meltWithResponse(Wallet $wallet, callable $change): array
    {
        $inputs = $this->fund($wallet, 8);
        $this->mint->meltQuotes['q'] = ['quote' => 'q', 'amount' => 3, 'fee_reserve' => 2, 'state' => 'UNPAID'];
        $this->mint->onPost = function (string $path, array $data) use ($change) {
            if ($path !== 'melt/bolt11') {
                return null;
            }
            $blank = $data['outputs'];
            $this->mint->signed[strtolower($blank[0]['B_'])] = ['amount' => 4, 'id' => self::KS];
            $this->mint->signed[strtolower($blank[1]['B_'])] = ['amount' => 1, 'id' => self::KS];
            return ['quote' => 'q', 'state' => 'PAID', 'change' => $change($blank)];
        };
        $this->mint->calls = [];
        return $wallet->melt('q', $inputs);
    }

    private static function sig(array $output, int $amount): array
    {
        return ['id' => self::KS, 'amount' => $amount, 'C_' => $output['B_']];
    }

    // ------------------------------------------------------------ 1: short melt change

    public function testPaidMeltWithEmptyChangeRecoversItViaRestore(): void
    {
        $wallet = $this->wallet();
        $result = $this->meltWithResponse($wallet, fn(array $blank) => []);
        $this->assertTrue($result['paid']);
        $this->assertFalse($result['changeRecoveryPending']);
        $this->assertSame(5, Wallet::sumProofs($result['change']));
        $this->assertSame(1, $this->restoreCalls());
        $this->assertSame(5, $wallet->getBalance());
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
    }

    public function testPaidMeltWithChangeBelowTheOverpaymentRecoversTheRest(): void
    {
        $wallet = $this->wallet();
        $result = $this->meltWithResponse($wallet, fn(array $blank) => [self::sig($blank[0], 2)]);
        $this->assertSame(5, Wallet::sumProofs($result['change']), 'the larger NUT-09 result wins');
        $this->assertSame(5, $wallet->getBalance());
    }

    public function testCompleteMeltChangeCostsNoExtraRequest(): void
    {
        $wallet = $this->wallet();
        $result = $this->meltWithResponse($wallet, fn(array $blank) => [self::sig($blank[0], 4), self::sig($blank[1], 1)]);
        $this->assertSame(5, Wallet::sumProofs($result['change']));
        $this->assertSame(0, $this->restoreCalls());

        // 3 sat (the guaranteed overpayment) with the reserve spent on fees is complete too.
        $wallet2 = $this->wallet();
        unset($this->mint->meltQuotes['q']);
        $result = $this->meltWithResponse($wallet2, fn(array $blank) => [self::sig($blank[0], 2), self::sig($blank[1], 1)]);
        $this->assertSame(3, Wallet::sumProofs($result['change']));
        $this->assertSame(0, $this->restoreCalls());
    }

    public function testShortChangeWithRestoreDownKeepsTheJournalForRecovery(): void
    {
        $wallet = $this->wallet();
        $this->mint->meltQuotes['q2'] = ['quote' => 'q2', 'amount' => 3, 'fee_reserve' => 2, 'state' => 'UNPAID'];
        $inputs = $this->fund($wallet, 8);
        $this->mint->onPost = function (string $path, array $data) {
            if ($path === 'restore') {
                throw new CashuException('mint unreachable');
            }
            if ($path !== 'melt/bolt11') {
                return null;
            }
            $this->mint->signed[strtolower($data['outputs'][0]['B_'])] = ['amount' => 4, 'id' => self::KS];
            $this->mint->signed[strtolower($data['outputs'][1]['B_'])] = ['amount' => 1, 'id' => self::KS];
            return ['quote' => 'q2', 'state' => 'PAID', 'change' => []];
        };
        $before = $wallet->getBalance();
        $result = $wallet->melt('q2', $inputs);
        $this->assertTrue($result['paid']);
        $this->assertTrue($result['changeRecoveryPending']);
        $this->assertNotNull($wallet->getStorage()->getPendingOperationById('melt:q2'));

        // Recovery collects the change once the mint answers again.
        $this->mint->onPost = null;
        $this->mint->meltQuotes['q2']['state'] = 'PAID';
        $recovered = $wallet->recoverPendingMelts();
        $this->assertSame(1, $recovered['paid']);
        $this->assertSame(5, $recovered['change_recovered']);
        $this->assertSame($before - 8 + 5, $wallet->getBalance());
    }

    // ------------------------------------------------------------ 2: melt release timing

    public function testExpiredUnpaidQuoteIsNotReleasedWhileTheMeltMayBeInFlight(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 4);
        $this->mint->meltQuotes['q'] = ['quote' => 'q', 'amount' => 3, 'fee_reserve' => 0, 'state' => 'UNPAID',
            'expiry' => time() + 60];
        $this->mint->onPost = fn(string $path) => $path === 'melt/bolt11' ? throw new CashuException('timeout') : null;
        try {
            $wallet->melt('q', $inputs);
        } catch (CashuException $e) {
        }
        $this->mint->onPost = null;
        $this->mint->meltQuotes['q']['expiry'] = time() - 1;
        $this->assertSame(1, $wallet->recoverPendingMelts()['still_pending'], 'expired, but the journal is too young');
        $this->assertSame(0, $wallet->getBalance());

        $wallet->getStorage()->getPdo()->exec(
            'UPDATE cashu_pending_operations SET created_at = created_at - ' . (Wallet::MELT_RELEASE_AFTER + 1)
        );
        $this->assertSame(1, $wallet->recoverPendingMelts()['restored']);
        $this->assertSame(4, $wallet->getBalance());
    }

    public function testOldJournalOnUnexpiredQuoteIsKept(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 4);
        $this->mint->meltQuotes['q'] = ['quote' => 'q', 'amount' => 3, 'fee_reserve' => 0, 'state' => 'UNPAID',
            'expiry' => time() + 3600];
        $this->mint->onPost = fn(string $path) => $path === 'melt/bolt11' ? throw new CashuException('timeout') : null;
        try {
            $wallet->melt('q', $inputs);
        } catch (CashuException $e) {
        }
        $this->mint->onPost = null;
        $wallet->getStorage()->getPdo()->exec(
            'UPDATE cashu_pending_operations SET created_at = created_at - ' . (Wallet::MELT_RELEASE_AFTER + 1)
        );
        $this->assertSame(1, $wallet->recoverPendingMelts()['still_pending']);
    }

    /** The journal was released under a POST the mint then completed: inputs end SPENT. */
    public function testPaidMeltAfterItsJournalWasReleasedMarksInputsSpent(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 4);
        $this->mint->meltQuotes['q'] = ['quote' => 'q', 'amount' => 4, 'fee_reserve' => 0, 'state' => 'UNPAID'];
        $storage = $wallet->getStorage();
        $this->mint->onPost = function (string $path) use ($storage, $inputs) {
            if ($path !== 'melt/bolt11') {
                return null;
            }
            $storage->finalizePendingSpend('melt:q', [$inputs[0]->secret], ProofState::UNSPENT);
            return ['quote' => 'q', 'state' => 'PAID', 'change' => []];
        };
        $this->assertTrue($wallet->melt('q', $inputs)['paid']);
        $this->assertSame(ProofState::SPENT, $storage->getProofsStatesBySecrets([$inputs[0]->secret])[$inputs[0]->secret]);
        $this->assertSame(0, $wallet->getBalance());
    }

    public function testOrphanSpendLeavesInputsOfOtherOperationsAlone(): void
    {
        $wallet = $this->wallet();
        [$a, $b] = $this->fund($wallet, 3); // 1 + 2
        $storage = $wallet->getStorage();
        $storage->updateProofsState([$b->secret], ProofState::PENDING);
        $storage->finalizePendingSpend('melt:gone', [$a->secret, $b->secret], ProofState::SPENT);
        $this->assertSame(ProofState::UNSPENT, $storage->getProofsStatesBySecrets([$a->secret])[$a->secret], 'opt-in only');
        $storage->finalizePendingSpend('melt:gone', [$a->secret, $b->secret], ProofState::SPENT, [], true);
        $states = $storage->getProofsStatesBySecrets([$a->secret, $b->secret]);
        $this->assertSame(ProofState::SPENT, $states[$a->secret]);
        $this->assertSame(ProofState::PENDING, $states[$b->secret]);
    }

    // ------------------------------------------------------------ 3: unlisted keysets in restore

    /**
     * Start a restore over two keysets (budget runs out in the first), then the mint stops
     * listing the second, which holds 8 sat at counter 1. Returns [seed wallet, V2 id].
     */
    private function restoreThenDelist(callable $keysAnswer): array
    {
        $v2 = Keyset::deriveKeysetIdV2(FakeRestoreMint::keys(), 'sat');
        $mint = new FakeRestoreMint([
            ['id' => $v2, 'unit' => 'sat', 'active' => true],
            ['id' => self::KS, 'unit' => 'sat', 'active' => false],
        ]);
        $seed = new Wallet(self::MINT);
        $seed->initFromMnemonic(self::MNEMONIC);
        $mint->sign($seed, self::KS, 1, 8);
        $this->mint = $mint;

        $wallet = new Wallet(self::MINT, 'sat', $this->db, 'store-1');
        $wallet->initializeForRestore(self::MNEMONIC);
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $mint);
        $this->assertSame('pending', $wallet->restoreStep(3, null, 10)['status']);

        $mint->keysets = [$mint->keysets[0]];
        $mint->onGet = fn(string $path) => $path === 'keys/' . self::KS ? $keysAnswer() : null;

        $wallet = new Wallet(self::MINT, 'sat', $this->db, 'store-1');
        $wallet->initFromMnemonic(self::MNEMONIC);
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $mint);
        return [$wallet, $v2];
    }

    private static function runSteps(Wallet $wallet): array
    {
        for ($i = 0; $i < 20; $i++) {
            $step = $wallet->restoreStep(100, null, 10);
            if ($step['status'] !== 'pending') {
                return $step;
            }
        }
        return $step;
    }

    public function testRestoreKeepsScanningAKeysetTheMintStoppedListing(): void
    {
        [$wallet] = $this->restoreThenDelist(fn() => ['keysets' => [['id' => self::KS, 'unit' => 'sat', 'keys' => FakeRestoreMint::keys()]]]);
        $step = self::runSteps($wallet);
        $this->assertSame('complete', $step['status']);
        $this->assertTrue($step['ready']);
        $this->assertArrayHasKey(self::KS, $step['progress']);
        $this->assertSame([], $step['abandonedKeysets']);
        $this->assertSame(8, $wallet->getBalance(), 'the unlisted keyset was scanned');
    }

    public function testTransientKeyFailureOnAnUnlistedKeysetKeepsTheWalletNotReady(): void
    {
        [$wallet] = $this->restoreThenDelist(fn() => throw new CashuException('Connection timed out'));
        $step = self::runSteps($wallet);
        $this->assertSame('error', $step['status']);
        $this->assertFalse($step['ready']);
        $this->assertArrayHasKey(self::KS, $step['errors']);
        $this->assertTrue($wallet->requiresRecovery());

        // A 5xx is not "unknown keyset" either.
        $this->mint->onGet = fn(string $path) => $path === 'keys/' . self::KS
            ? throw new CashuProtocolException('Bad gateway', null, 502) : null;
        $this->assertSame('error', self::runSteps($wallet)['status']);
    }

    public function testKeysetTheMintNoLongerKnowsIsAbandonedWithAWarning(): void
    {
        [$wallet] = $this->restoreThenDelist(
            fn() => throw new CashuProtocolException('keyset not known', CashuProtocolException::KEYSET_UNKNOWN, 400)
        );
        $step = self::runSteps($wallet);
        $this->assertSame('complete', $step['status']);
        $this->assertTrue($step['ready']);
        $this->assertArrayHasKey(self::KS, $step['abandonedKeysets']);
        $this->assertStringContainsString('no longer knows', $step['abandonedKeysets'][self::KS]);
        $this->assertTrue($step['progress'][self::KS]['abandoned']);
        $this->assertNull($step['progress'][self::KS]['error']);
        $this->assertArrayHasKey(self::KS, $wallet->getRestoreStatus()['abandonedKeysets']);
    }

    public function testFinalizeRecoveryChecksEveryKeysetTheSessionStarted(): void
    {
        [$wallet, $v2] = $this->restoreThenDelist(fn() => throw new CashuException('down'));
        // Complete the listed keyset only, then try to finalize with just it.
        $storage = $wallet->getStorage();
        $storage->getPdo()->exec("UPDATE cashu_restore_progress SET empty_run = 10, last_error = NULL WHERE keyset_id = '$v2'");
        $this->expectException(CashuException::class);
        $this->expectExceptionMessage('not complete');
        $storage->finalizeRecovery([$v2], ['sat']);
    }

    // ------------------------------------------------------------ 4: invoice shape

    public function testMintQuoteInvoiceMustBeShortBech32(): void
    {
        $wallet = $this->wallet();
        foreach ([
            'oversized' => 'lnbc1u1' . str_repeat('q', 5000),
            'outside charset (b)' => 'lnbc1u1qqbqq',
            'outside charset (o)' => 'lnbc1u1qqoqq',
            'mixed case' => 'lnbc1u1qqQqq',
            'no data part' => 'lnbc1u1',
            'markup' => 'lnbc1u1qq<script>',
        ] as $case => $request) {
            $this->mint->onPost = fn(string $path) => $path === 'mint/quote/bolt11'
                ? ['quote' => 'q', 'request' => $request, 'amount' => 100, 'state' => 'UNPAID'] : null;
            try {
                $wallet->requestMintQuote(100);
                $this->fail("$case accepted");
            } catch (CashuException $e) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['LNBC1U1QQXQQ', 'lightning:lnbc1u1qqxqq'] as $request) {
            $this->mint->onPost = fn(string $path) => $path === 'mint/quote/bolt11'
                ? ['quote' => 'q', 'request' => $request, 'amount' => 100, 'state' => 'UNPAID'] : null;
            $this->assertSame('q', $wallet->requestMintQuote(100)->quote, $request);
        }
    }

    // ------------------------------------------------------------ 5: https-only option

    public function testOptionalHttpsRequirement(): void
    {
        new MintClient('http://mint.example'); // default: allowed
        foreach (['http://localhost:3338', 'http://127.0.0.1:3338', 'http://[::1]:3338', 'https://mint.example'] as $url) {
            new MintClient($url, 30, true);
            $this->addToAssertionCount(1);
        }
        foreach (['http://mint.example', 'http://192.168.1.10:3338', 'http://127.0.0.1.evil.example'] as $url) {
            try {
                new MintClient($url, 30, true);
                $this->fail("$url accepted");
            } catch (CashuException $e) {
                $this->addToAssertionCount(1);
            }
        }
        MintClient::setRequireHttps(true);
        $this->expectException(CashuException::class);
        new Wallet('http://mint.example');
    }
}
