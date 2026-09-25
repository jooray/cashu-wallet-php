<?php

declare(strict_types=1);

namespace Cashu\Tests;

require_once dirname(__DIR__) . '/support/FakeRestoreMint.php';

use Cashu\CashuException;
use Cashu\CashuProtocolException;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Tests\Support\FakeRestoreMint;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * Every swap, mint and melt journal reaches a terminal state once the mint has
 * definitively answered (audit L-2, L-4, L-8, N6, N19); ambiguous failures keep it.
 */
final class JournalTerminationTest extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const KS = '009a1f293253e41e';
    private const NEW_KS = '00ad268c4d1f5826';
    private const MINT = 'https://mint.example';

    private string $db;
    private FakeRestoreMint $mint;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/cashu-journal-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->mint = new FakeRestoreMint([['id' => self::KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0]]);
    }

    protected function tearDown(): void
    {
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

    private static function rejection(int $code, string $message = 'rejected', int $status = 400): CashuProtocolException
    {
        return new CashuProtocolException($message, $code, $status);
    }

    /** @return Proof[] a token's proofs from someone else */
    private static function foreignProofs(array $amounts): array
    {
        return array_map(
            fn($amount) => new Proof(self::KS, $amount, bin2hex(random_bytes(32)), FakeRestoreMint::G),
            $amounts
        );
    }

    /** Give the wallet own UNSPENT proofs by minting through the fake mint. */
    private function fund(Wallet $wallet, int $amount): array
    {
        $previous = $this->mint->onPost;
        $this->mint->onPost = fn(string $path, array $data) => $path === 'mint/bolt11'
            ? $this->mint->signOutputs($data['outputs']) : null;
        $proofs = $wallet->mint('fund-' . bin2hex(random_bytes(4)), $amount);
        $this->mint->onPost = $previous;
        return $proofs;
    }

    private function rowCount(Wallet $wallet): int
    {
        return (int)$wallet->getStorage()->getPdo()->query('SELECT COUNT(*) FROM cashu_proofs')->fetchColumn();
    }

    // ------------------------------------------------------------ classification

    public function testDefinitiveVersusAmbiguousClassification(): void
    {
        $this->assertTrue(self::rejection(11001)->isDefinitive());
        $this->assertTrue((new CashuProtocolException('bad', 11001))->isDefinitive(), 'code without status');
        $this->assertTrue((new CashuProtocolException('bad', null, 400))->isDefinitive());
        $this->assertFalse((new CashuProtocolException('HTTP error 502', null, 502))->isDefinitive());
        $this->assertFalse((new CashuProtocolException('x', 11001, 503))->isDefinitive(), '5xx stays ambiguous');
        $this->assertFalse((new CashuProtocolException('slow down', null, 429))->isDefinitive());
        $this->assertFalse(self::rejection(CashuProtocolException::PROOFS_PENDING)->isDefinitive());
        $this->assertFalse(self::rejection(CashuProtocolException::QUOTE_PENDING)->isDefinitive());
        $this->assertFalse((new CashuProtocolException('no code, no status'))->isDefinitive());
        $this->assertTrue((new CashuProtocolException('Not Found', null, 404))->isQuoteNotFound());
        $this->assertTrue(self::rejection(20000, 'quote not found')->isQuoteNotFound());
        $this->assertFalse((new CashuProtocolException('quote not found', null, 502))->isQuoteNotFound());
    }

    // ------------------------------------------------------------ L-2 / N6: receive

    /** Anyone can paste an already-spent token; it must leave nothing behind. */
    public function testReceivingAnAlreadySpentTokenLeavesNoJournalOrRows(): void
    {
        $wallet = $this->wallet();
        $token = self::foreignProofs([4, 2]);
        $this->mint->setState(array_map(fn($p) => $p->secret, $token), 'SPENT');
        $this->mint->onPost = fn(string $path) => $path === 'swap'
            ? throw self::rejection(CashuProtocolException::PROOFS_ALREADY_SPENT, 'Token already spent.') : null;

        try {
            $wallet->receive($wallet->serializeToken($token, 'v3'));
            $this->fail('Spent token accepted');
        } catch (CashuProtocolException $e) {
            $this->assertSame(CashuProtocolException::PROOFS_ALREADY_SPENT, $e->getCode());
        }
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
        $this->assertSame(0, $this->rowCount($wallet), 'no junk rows');
        $this->assertSame(0, $wallet->getBalance());
        $this->assertGreaterThan(0, $wallet->getStorage()->getCounter(self::KS), 'counters stay burned');
        $this->assertSame(0, $wallet->recoverPendingSwaps()['checked']);
    }

    /** A refused but valid token (e.g. inactive keyset) is not kept either. */
    public function testRefusedUnspentTokenIsRemovedNotAbsorbed(): void
    {
        $wallet = $this->wallet();
        $token = self::foreignProofs([8]);
        $this->mint->onPost = fn(string $path) => $path === 'swap'
            ? throw self::rejection(CashuProtocolException::KEYSET_INACTIVE) : null;

        $this->expectException(CashuProtocolException::class);
        try {
            $wallet->receive($wallet->serializeToken($token, 'v3'));
        } finally {
            $this->assertSame([], $wallet->getStorage()->getPendingOperations());
            $this->assertSame(0, $this->rowCount($wallet));
        }
    }

    /**
     * Ambiguous failures keep the journal; recovery later learns the token was
     * double-spent and reports it distinctly.
     */
    public function testAmbiguousReceiveKeepsJournalThenRecoveryReportsDoubleSpend(): void
    {
        $wallet = $this->wallet();
        $token = self::foreignProofs([4]);
        foreach ([new CashuException('HTTP request failed: timeout'), new CashuProtocolException('HTTP error 502', null, 502)] as $i => $error) {
            $this->mint->onPost = fn(string $path) => $path === 'swap' ? throw $error : null;
            try {
                $wallet->receive($wallet->serializeToken($token, 'v3'));
                $this->fail('ambiguous failure swallowed');
            } catch (CashuException $e) {
            }
            $this->assertCount(1, $wallet->getStorage()->getPendingOperations('swap'), "attempt $i");
            $this->assertSame(ProofState::PENDING, $wallet->getStorage()->getProofsStatesBySecrets([$token[0]->secret])[$token[0]->secret]);
        }

        // The sender spent it elsewhere meanwhile; none of our outputs were signed.
        $this->mint->setState([$token[0]->secret], 'SPENT');
        $this->mint->onPost = null;
        $result = $wallet->recoverPendingSwaps();
        $this->assertSame(1, $result['double_spent']);
        $this->assertSame(0, $result['still_pending']);
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
        $this->assertSame(ProofState::SPENT, $wallet->getStorage()->getProofsStatesBySecrets([$token[0]->secret])[$token[0]->secret]);
        $this->assertSame(0, $wallet->getBalance());
    }

    /** A rejected replay of a swap that already succeeded recovers its outputs. */
    public function testRejectedReplayOfCompletedSwapRecoversOutputs(): void
    {
        $wallet = $this->wallet();
        $token = self::foreignProofs([4]);
        $this->mint->onPost = function (string $path, array $data) use ($token) {
            if ($path === 'swap') {
                // First delivery executed (response lost); our retry is refused.
                $this->mint->signOutputs($data['outputs']);
                $this->mint->setState([$token[0]->secret], 'SPENT');
                throw self::rejection(CashuProtocolException::PROOFS_ALREADY_SPENT);
            }
            return null;
        };
        $proofs = $wallet->receive($wallet->serializeToken($token, 'v3'));
        $this->assertSame(4, Wallet::sumProofs($proofs));
        $this->assertSame(4, $wallet->getBalance());
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
    }

    // ------------------------------------------------------------ L-2: own swaps

    public function testRecoveryReleasesOwnInputsWhenResubmittedPlanIsRejected(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 8);
        $this->mint->onPost = fn(string $path) => $path === 'swap' ? throw new CashuException('timeout') : null;
        try {
            $wallet->swap($inputs, [4, 4]);
            $this->fail('lost response accepted');
        } catch (CashuException $e) {
        }
        $this->assertSame(0, $wallet->getBalance());
        $counter = $wallet->getStorage()->getCounter(self::KS);

        // Transport failure on resubmission: kept.
        $result = $wallet->recoverPendingSwaps();
        $this->assertSame(0, $result['released']);
        $this->assertNotEmpty($result['errors']);
        $this->assertCount(1, $wallet->getStorage()->getPendingOperations('swap'));

        // Definitive refusal of the recorded plan: inputs come back, counters stay burned.
        $this->mint->onPost = fn(string $path) => $path === 'swap'
            ? throw self::rejection(CashuProtocolException::TRANSACTION_UNBALANCED) : null;
        $result = $wallet->recoverPendingSwaps();
        $this->assertSame(1, $result['released']);
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
        $this->assertSame(8, $wallet->getBalance());
        $this->assertSame($counter, $wallet->getStorage()->getCounter(self::KS));
    }

    /** N19: mixed states never make a SPENT input spendable; PENDING keeps the journal. */
    public function testMixedInputStatesAreSettledSafely(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 3); // 1 + 2
        $this->mint->onPost = fn(string $path) => $path === 'swap' ? throw new CashuException('timeout') : null;
        try {
            $wallet->swap($inputs, [2, 1]);
        } catch (CashuException $e) {
        }
        [$a, $b] = [$inputs[0]->secret, $inputs[1]->secret];

        $this->mint->setState([$a], 'SPENT');
        $this->mint->setState([$b], 'PENDING');
        $this->mint->onPost = null;
        $result = $wallet->recoverPendingSwaps();
        $this->assertSame(1, $result['still_pending']);
        $this->assertCount(1, $wallet->getStorage()->getPendingOperations('swap'));

        $this->mint->setState([$b], 'UNSPENT');
        $result = $wallet->recoverPendingSwaps();
        $this->assertSame(1, $result['double_spent']);
        $states = $wallet->getStorage()->getProofsStatesBySecrets([$a, $b]);
        $this->assertSame(ProofState::SPENT, $states[$a]);
        $this->assertSame(ProofState::UNSPENT, $states[$b]);
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
    }

    // ------------------------------------------------------------ L-4: mint plans

    /** A paid quote whose plan's keyset rotated out gets a fresh plan and is issued. */
    public function testRejectedMintPlanIsReplacedAfterKeysetRotation(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = fn(string $path) => $path === 'mint/quote/bolt11/q'
            ? ['quote' => 'q', 'request' => 'lnbc', 'state' => 'PAID'] : null;

        // First attempt: response lost — the journal records a plan on the old keyset.
        $this->mint->onPost = fn(string $path) => $path === 'mint/bolt11' ? throw new CashuException('timeout') : null;
        try {
            $wallet->mint('q', 5);
            $this->fail('lost response accepted');
        } catch (CashuException $e) {
        }
        $oldPlan = $wallet->getStorage()->getPendingOperationById('mint:q')['data'];
        $this->assertSame(self::KS, $oldPlan['keyset_id']);

        // The mint rotates keysets and refuses outputs on the old one.
        $this->mint->keysets = [
            ['id' => self::KS, 'unit' => 'sat', 'active' => false, 'input_fee_ppk' => 0],
            ['id' => self::NEW_KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0],
        ];
        $this->mint->onPost = function (string $path, array $data) {
            if ($path !== 'mint/bolt11') {
                return null;
            }
            if ($data['outputs'][0]['id'] === self::KS) {
                throw self::rejection(CashuProtocolException::KEYSET_INACTIVE, 'keyset inactive');
            }
            return $this->mint->signOutputs($data['outputs']);
        };
        $proofs = $wallet->mint('q', 5);

        $this->assertSame(5, Wallet::sumProofs($proofs));
        $this->assertSame([self::NEW_KS], array_values(array_unique(array_map(fn($p) => $p->id, $proofs))));
        $this->assertNull($wallet->getStorage()->getPendingOperationById('mint:q'));
        $this->assertSame(2, $wallet->getStorage()->getCounter(self::KS), 'old plan counters stay burned');
        $this->assertSame(2, $wallet->getStorage()->getCounter(self::NEW_KS));
        $this->assertSame(5, $wallet->getBalance());
    }

    public function testMintPlanIsKeptOnAmbiguousFailureOrWhenOutputsWereSigned(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = fn(string $path) => $path === 'mint/quote/bolt11/q'
            ? ['quote' => 'q', 'request' => 'lnbc', 'state' => 'PAID'] : null;
        $this->mint->onPost = fn(string $path) => $path === 'mint/bolt11'
            ? throw new CashuProtocolException('HTTP error 504', null, 504) : null;
        try {
            $wallet->mint('q', 3);
        } catch (CashuException $e) {
        }
        $plan = $wallet->getStorage()->getPendingOperationById('mint:q')['data'];

        // Definitive refusal, but one planned output is already signed: never replace.
        $this->mint->onPost = function (string $path, array $data) {
            if ($path === 'mint/bolt11') {
                $this->mint->signOutputs([$data['outputs'][0]]);
                throw self::rejection(CashuProtocolException::OUTPUTS_ALREADY_SIGNED);
            }
            return null;
        };
        try {
            $wallet->mint('q', 3);
            $this->fail('partially signed plan replaced');
        } catch (CashuException $e) {
        }
        $this->assertSame($plan, $wallet->getStorage()->getPendingOperationById('mint:q')['data']);
    }

    public function testRecoverPendingMintsRetiresUnknownQuoteAndMintsPaidOne(): void
    {
        $wallet = $this->wallet();
        $this->mint->onPost = fn(string $path) => $path === 'mint/bolt11' ? throw new CashuException('timeout') : null;
        $this->mint->onGet = fn(string $path) => throw new CashuException('offline');
        foreach (['gone', 'paid'] as $quote) {
            try {
                $wallet->mint($quote, 2);
            } catch (CashuException $e) {
            }
        }

        // 5xx on the quote lookup is ambiguous: nothing retired.
        $this->mint->onGet = fn(string $path) => throw new CashuProtocolException('Bad gateway', null, 502);
        $result = $wallet->recoverPendingMints();
        $this->assertSame(0, $result['retired']);
        $this->assertCount(2, $wallet->getStorage()->getPendingOperations('mint'));

        $this->mint->onGet = fn(string $path) => match ($path) {
            'mint/quote/bolt11/gone' => throw new CashuProtocolException('Quote not found', null, 404),
            'mint/quote/bolt11/paid' => ['quote' => 'paid', 'request' => 'lnbc', 'state' => 'PAID'],
            default => null,
        };
        $this->mint->onPost = fn(string $path, array $data) => $path === 'mint/bolt11'
            ? $this->mint->signOutputs($data['outputs']) : null;
        $result = $wallet->recoverPendingMints();
        $this->assertSame(1, $result['unknown_quote']);
        $this->assertSame(1, $result['retired']);
        $this->assertSame(1, $result['recovered']);
        $this->assertSame(2, $result['amount']);
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
        $this->assertSame(2, $wallet->getBalance());
    }

    // ------------------------------------------------------------ melt: unknown quote, L-8

    public function testMeltJournalWithUnknownQuoteResolvesByInputState(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 12); // 4 + 8
        $this->mint->meltQuotes['m1'] = ['quote' => 'm1', 'amount' => 3, 'fee_reserve' => 0, 'state' => 'UNPAID'];
        $this->mint->meltQuotes['m2'] = ['quote' => 'm2', 'amount' => 5, 'fee_reserve' => 0, 'state' => 'UNPAID'];
        $blank = [];
        $this->mint->onPost = function (string $path, array $data) use (&$blank) {
            if ($path === 'melt/bolt11') {
                $blank[$data['quote']] = $data['outputs'];
                throw new CashuException('timeout');
            }
            return null;
        };
        foreach ([['m1', $inputs[0]], ['m2', $inputs[1]]] as [$quote, $proof]) {
            try {
                $wallet->melt($quote, [$proof]);
            } catch (CashuException $e) {
            }
        }
        $this->assertCount(2, $wallet->getStorage()->getPendingOperations('melt'));

        // m1 never ran (input unspent); m2 ran and signed 3 sats change (8 - 5).
        $this->mint->setState([$inputs[1]->secret], 'SPENT');
        $this->mint->signed[strtolower($blank['m2'][0]['B_'])] = ['amount' => 2, 'id' => self::KS];
        $this->mint->signed[strtolower($blank['m2'][1]['B_'])] = ['amount' => 1, 'id' => self::KS];
        $this->mint->onPost = null;
        $this->mint->onGet = fn(string $path) => str_starts_with($path, 'melt/quote/')
            ? throw new CashuProtocolException('Quote not found', null, 404) : null;

        $result = $wallet->recoverPendingMelts();
        $this->assertSame(2, $result['unknown_quote']);
        $this->assertSame(1, $result['restored']);
        $this->assertSame(1, $result['paid']);
        $this->assertSame(3, $result['change_recovered']);
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
        $this->assertSame(4 + 3, $wallet->getBalance());
    }

    /** L-8: input fees count toward the melt amount check and the change bound. */
    public function testMeltAccountsForInputFees(): void
    {
        $this->mint->keysets[0]['input_fee_ppk'] = 1000;
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 4);
        $this->mint->meltQuotes['short'] = ['quote' => 'short', 'amount' => 3, 'fee_reserve' => 1, 'state' => 'UNPAID'];
        try {
            $wallet->melt('short', $inputs);
            $this->fail('melt short of the input fee was submitted');
        } catch (CashuException $e) {
            $this->assertStringContainsString('input fee', $e->getMessage());
        }
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
        $this->assertSame(4, $wallet->getBalance());

        $submitted = null;
        $this->mint->meltQuotes['ok'] = ['quote' => 'ok', 'amount' => 2, 'fee_reserve' => 1, 'state' => 'UNPAID'];
        $this->mint->onPost = function (string $path, array $data) use (&$submitted) {
            $submitted = $data['outputs'];
            return ['quote' => 'ok', 'state' => 'PAID', 'change' => []];
        };
        $this->assertTrue($wallet->melt('ok', $inputs)['paid']);
        $this->assertCount(1, $submitted, 'max change 4 - 2 - 1 = 1 needs one blank output');

        $selected = $wallet->selectProofsWithFees($this->fund($wallet, 8), 3);
        $this->assertGreaterThanOrEqual(3 + $wallet->calculateFee($selected), Wallet::sumProofs($selected));
    }

    /** L-8: a definitive melt rejection on an unpaid quote releases the inputs at once. */
    public function testDefinitiveMeltRejectionReleasesInputsImmediately(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 4);
        $this->mint->meltQuotes['q'] = ['quote' => 'q', 'amount' => 3, 'fee_reserve' => 0, 'state' => 'UNPAID'];

        // Ambiguous: kept reserved.
        $this->mint->onPost = fn(string $path) => $path === 'melt/bolt11' ? throw new CashuException('timeout') : null;
        try {
            $wallet->melt('q', $inputs);
        } catch (CashuException $e) {
        }
        $this->assertNotNull($wallet->getStorage()->getPendingOperationById('melt:q'));
        $this->assertSame(0, $wallet->getBalance());

        // Definitive (retry with the same inputs): released.
        $this->mint->onPost = fn(string $path) => $path === 'melt/bolt11'
            ? throw self::rejection(20004, 'Lightning payment failed') : null;
        try {
            $wallet->melt('q', $inputs);
            $this->fail('rejection swallowed');
        } catch (CashuProtocolException $e) {
        }
        $this->assertNull($wallet->getStorage()->getPendingOperationById('melt:q'));
        $this->assertSame(4, $wallet->getBalance());
    }

    /** An UNPAID quote without expiry no longer locks inputs forever. */
    public function testUnpaidQuoteWithoutExpiryReleasesAfterGracePeriod(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 4);
        $this->mint->meltQuotes['q'] = ['quote' => 'q', 'amount' => 3, 'fee_reserve' => 0, 'state' => 'UNPAID'];
        $this->mint->onPost = fn(string $path) => $path === 'melt/bolt11' ? throw new CashuException('timeout') : null;
        try {
            $wallet->melt('q', $inputs);
        } catch (CashuException $e) {
        }
        $this->mint->onPost = null;
        $this->assertSame(1, $wallet->recoverPendingMelts()['still_pending'], 'too recent');

        $wallet->getStorage()->getPdo()->exec(
            'UPDATE cashu_pending_operations SET created_at = created_at - ' . (Wallet::MELT_RELEASE_AFTER + 1)
        );
        $this->assertSame(1, $wallet->recoverPendingMelts()['restored']);
        $this->assertSame(4, $wallet->getBalance());
    }
}
