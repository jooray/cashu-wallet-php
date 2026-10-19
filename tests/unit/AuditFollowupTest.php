<?php

declare(strict_types=1);

namespace Cashu\Tests;

require_once dirname(__DIR__) . '/support/FakeRestoreMint.php';

use Cashu\BigInt;
use Cashu\CashuException;
use Cashu\CashuProtocolException;
use Cashu\Crypto;
use Cashu\MeltQuote;
use Cashu\MintQuote;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Tests\Support\FakeRestoreMint;
use Cashu\TokenSerializer;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * Regressions for the 2026-10 audits (OPUS-*, DS41-*, SPB-LIB-*) against the library.
 */
final class AuditFollowupTest extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const KS = '009a1f293253e41e';
    private const MINT = 'https://mint.example';

    private string $db;
    private FakeRestoreMint $mint;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/cashu-followup-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->mint = new FakeRestoreMint([['id' => self::KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0]]);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->db . $suffix);
        }
    }

    private function wallet(?array $info = null): Wallet
    {
        if ($info !== null) {
            $this->mint->onGet = fn(string $path) => $path === 'info' ? $info : null;
        }
        $wallet = new Wallet(self::MINT, 'sat', $this->db, 'store-1');
        $wallet->initializeNewFromMnemonic(self::MNEMONIC);
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $this->mint);
        $wallet->loadMint();
        if ($info !== null) {
            $this->mint->onGet = null;
        }
        return $wallet;
    }

    /** @return Proof[] */
    private function fund(Wallet $wallet, int $amount): array
    {
        $previous = $this->mint->onPost;
        $this->mint->onPost = fn(string $path, array $data) => $path === 'mint/bolt11'
            ? $this->mint->signOutputs($data['outputs']) : null;
        $proofs = $wallet->mint('fund-' . bin2hex(random_bytes(4)), $amount);
        $this->mint->onPost = $previous;
        return $proofs;
    }

    /** @return Proof[] */
    private static function foreignProofs(array $amounts): array
    {
        return array_map(
            fn($amount) => new Proof(self::KS, $amount, bin2hex(random_bytes(32)), FakeRestoreMint::G),
            $amounts
        );
    }

    private function postsTo(string $path): int
    {
        return count(array_filter($this->mint->calls, fn($call) => $call[0] === 'POST' && $call[1] === $path));
    }

    private static function secrets(array $proofs): array
    {
        return array_map(fn(Proof $p) => $p->secret, $proofs);
    }

    // ------------------------------------------------------------ OPUS-011

    public function testOutputsPendingIsNotDefinitive(): void
    {
        $this->assertFalse((new CashuProtocolException('Outputs are pending', 11004, 400))->isDefinitive());
        $this->assertFalse((new CashuProtocolException('Outputs are pending', 11004))->isDefinitive());
    }

    // ------------------------------------------------------------ OPUS-001 / SPB-LIB-008

    public function testBolt11AmountWorksOnTheBcmathBackendToo(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/CashuWallet.php', true) . ';'
            . '(new ReflectionProperty(Cashu\BigInt::class, "useGmp"))->setValue(null, false);'
            . '(new ReflectionProperty(Cashu\BigInt::class, "initialized"))->setValue(null, true);'
            . 'echo json_encode([Cashu\Bolt11::amountSats("lnbc2500u1x"), Cashu\Bolt11::amountSats("lnbc1p1x"),'
            . 'Cashu\Bolt11::amountSats("lnbc20m1x"), Cashu\Bolt11::amountSats("lnbc1x")]);';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertSame('[250000,1,2000000,0]', trim((string)$out));
        $this->assertSame(250000, \Cashu\Bolt11::amountSats('lnbc2500u1x'));
    }

    // ------------------------------------------------------------ DS41-11 / SPB-CORE-011

    public function testCheckProofStateMatchesByYAndKeepsInputOrder(): void
    {
        $wallet = $this->wallet();
        $proofs = self::foreignProofs([1, 2, 4]);
        $this->mint->setState([$proofs[0]->secret], 'SPENT');
        $this->mint->onPost = function (string $path, array $data) {
            if ($path !== 'checkstate') {
                return null;
            }
            $states = $this->mint->checkstate($data['Ys'])['states'];
            return ['states' => array_reverse($states)]; // a nonconforming order
        };

        $states = $wallet->checkProofState($proofs);

        $this->assertCount(3, $states);
        $this->assertSame([ProofState::SPENT, ProofState::UNSPENT, ProofState::UNSPENT], array_column($states, 'state'));
        $this->assertSame(array_map(fn($p) => strtolower($p->Y), $proofs), array_column($states, 'Y'));
    }

    public function testCheckProofStateRejectsShortOrForeignAnswers(): void
    {
        $wallet = $this->wallet();
        $proofs = self::foreignProofs([1, 2]);
        foreach ([
            fn(array $Ys) => ['states' => [['Y' => $Ys[0], 'state' => 'UNSPENT']]],
            fn(array $Ys) => ['states' => [['Y' => $Ys[0], 'state' => 'UNSPENT'], ['Y' => '02' . str_repeat('ab', 32), 'state' => 'UNSPENT']]],
            fn(array $Ys) => ['states' => [['Y' => $Ys[0], 'state' => 'UNSPENT'], ['Y' => $Ys[0], 'state' => 'UNSPENT']]],
        ] as $i => $answer) {
            $this->mint->onPost = fn(string $path, array $data) => $path === 'checkstate' ? $answer($data['Ys']) : null;
            try {
                $wallet->checkProofState($proofs);
                $this->fail("bad answer $i accepted");
            } catch (CashuException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCheckProofStateTreatsMissingStateAsUnknown(): void
    {
        $wallet = $this->wallet();
        $proofs = self::foreignProofs([1]);
        $this->mint->onPost = fn(string $path, array $data) => $path === 'checkstate'
            ? ['states' => [['Y' => $data['Ys'][0]]]] : null;

        $this->assertSame(ProofState::UNKNOWN, $wallet->checkProofState($proofs)[0]['state']);
    }

    // ------------------------------------------------------------ OPUS-012: array limits

    public function testStateChecksAndRestoreHonourAdvertisedArrayLimit(): void
    {
        $wallet = $this->wallet(['name' => 'fake', 'nuts' => ['6' => ['max_array_length' => 2]]]);
        $proofs = self::foreignProofs([1, 1, 1, 1, 1]);
        $this->assertCount(5, $wallet->checkProofState($proofs));
        $this->assertSame(3, $this->postsTo('checkstate'), '5 Ys in chunks of 2');

        $wallet->restoreBatch(self::KS, 0, 5);
        $this->assertSame(3, $this->postsTo('restore'));
        foreach ($this->mint->calls as $call) {
            if ($call[0] === 'POST') {
                $this->assertLessThanOrEqual(2, $call[2]);
            }
        }
    }

    // ------------------------------------------------------------ DS41-22 / SPB-UNIT-001

    public function testMintQuoteMustMatchTheRequest(): void
    {
        $wallet = $this->wallet();
        foreach ([
            ['quote' => 'q', 'request' => 'lnbc1x', 'amount' => 999, 'unit' => 'sat', 'state' => 'UNPAID'],
            ['quote' => 'q', 'request' => 'lnbc1x', 'amount' => 100, 'unit' => 'usd', 'state' => 'UNPAID'],
            ['quote' => 'q', 'request' => 'lnbc2u1x', 'amount' => 100, 'unit' => 'sat', 'state' => 'UNPAID'],
            ['quote' => 'q', 'request' => 'lnbc2u1x', 'state' => 'UNPAID'], // invoice for 200 sat
        ] as $i => $answer) {
            $this->mint->onPost = fn(string $path) => $path === 'mint/quote/bolt11' ? $answer : null;
            try {
                $wallet->requestMintQuote(100);
                $this->fail("mismatching quote $i accepted");
            } catch (CashuException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->mint->onPost = fn(string $path) => $path === 'mint/quote/bolt11'
            ? ['quote' => 'q', 'request' => 'lnbc1u1x', 'amount' => 100, 'unit' => 'SAT', 'state' => 'UNPAID'] : null;
        $this->assertSame('q', $wallet->requestMintQuote(100)->quote);
    }

    public function testMintRefusesAQuoteInAnotherUnit(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = fn(string $path) => $path === 'mint/quote/bolt11/q'
            ? ['quote' => 'q', 'request' => 'lnbc1x', 'amount' => 4, 'unit' => 'usd', 'state' => 'PAID'] : null;
        $this->mint->onPost = fn(string $path, array $data) => $path === 'mint/bolt11'
            ? $this->mint->signOutputs($data['outputs']) : null;

        try {
            $wallet->mint('q', 4);
            $this->fail('usd quote minted into a sat wallet');
        } catch (CashuException $e) {
            $this->assertStringContainsString('usd', $e->getMessage());
        }
        $this->assertSame(0, $this->postsTo('mint/bolt11'));
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
        $this->assertSame(0, $wallet->getStorage()->getCounter(self::KS));
    }

    // ------------------------------------------------------------ SPB-LIB-004

    public function testMalformedQuotesAndSignaturesAreCashuExceptions(): void
    {
        foreach ([
            fn() => MintQuote::fromArray(['quote' => 'q', 'request' => 'r', 'amount' => 'lots']),
            fn() => MintQuote::fromArray(['quote' => 'q', 'request' => 'r', 'expiry' => 'soon']),
            fn() => MintQuote::fromArray(['quote' => 'q', 'request' => 'r', 'unit' => 5]),
            fn() => MeltQuote::fromArray(['quote' => 'q', 'amount' => 1, 'change' => 'x']),
            fn() => MeltQuote::fromArray(['quote' => 'q', 'amount' => [1]]),
            fn() => MeltQuote::fromArray(['quote' => 'q', 'amount' => 1, 'expiry' => 1.5]),
            fn() => Crypto::unblindSignature('zz' . str_repeat('0', 64), BigInt::one(), FakeRestoreMint::G),
            fn() => Crypto::unblindSignature(12345, BigInt::one(), FakeRestoreMint::G),
            fn() => Crypto::unblindSignature('02abc', BigInt::one(), FakeRestoreMint::G),
        ] as $i => $parse) {
            try {
                $parse();
                $this->fail("garbage $i accepted");
            } catch (CashuException $e) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFalse(Crypto::verifyDleq(null, 5, FakeRestoreMint::G, FakeRestoreMint::G, []));

        $quote = MintQuote::fromArray(['quote' => 'q', 'request' => 'r', 'amount' => '21', 'expiry' => 1700000000]);
        $this->assertSame(21, $quote->amount);
        $this->assertSame(1700000000, $quote->expiry);
    }

    // ------------------------------------------------------------ SPB-LIB-002

    public function testExpiredUnpaidMintJournalWhoseOutputsWereSignedIsRecovered(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = fn(string $path) => $path === 'mint/quote/bolt11/q'
            ? ['quote' => 'q', 'request' => 'lnbc1x', 'state' => 'PAID'] : null;
        $this->mint->onPost = function (string $path, array $data) {
            if ($path === 'mint/bolt11') {
                $this->mint->signOutputs($data['outputs']); // signed, then the reply is lost
                throw new CashuException('timeout');
            }
            return null;
        };
        try {
            $wallet->mint('q', 3);
        } catch (CashuException $e) {
        }
        // The mint lost the quote-state update: it now says UNPAID and expired.
        $this->mint->onGet = fn(string $path) => $path === 'mint/quote/bolt11/q'
            ? ['quote' => 'q', 'request' => 'lnbc1x', 'state' => 'UNPAID', 'expiry' => time() - 60] : null;
        $this->mint->onPost = null;

        $result = $wallet->recoverPendingMints();

        $this->assertSame(1, $result['recovered']);
        $this->assertSame(0, $result['retired']);
        $this->assertSame(3, $wallet->getBalance());
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
    }

    // ------------------------------------------------------------ SPB-LIB-003

    public function testPaidMeltWithUnmappableChangeStillFinalizes(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 8);
        $this->mint->meltQuotes['m'] = ['quote' => 'm', 'amount' => 4, 'fee_reserve' => 2, 'state' => 'UNPAID'];
        $this->mint->onPost = fn(string $path) => $path === 'melt/bolt11' ? throw new CashuException('timeout') : null;
        try {
            $wallet->melt('m', $inputs);
        } catch (CashuException $e) {
        }
        $this->assertNotNull($wallet->getStorage()->getPendingOperationById('melt:m'));

        // PAID, with change for an amount the keyset has no key for (3 is not a denomination).
        $this->mint->meltQuotes['m'] = ['quote' => 'm', 'amount' => 4, 'fee_reserve' => 2, 'state' => 'PAID',
            'change' => [['id' => self::KS, 'amount' => 3, 'C_' => FakeRestoreMint::G]]];
        $this->mint->onPost = null;

        $result = $wallet->recoverPendingMelts();

        $this->assertSame(1, $result['paid']);
        $this->assertSame(1, $result['change_unrecoverable']);
        $this->assertArrayHasKey('m', $result['warnings']);
        $this->assertSame([], $result['errors']);
        $this->assertNull($wallet->getStorage()->getPendingOperationById('melt:m'), 'journal is terminal');
        $this->assertSame(ProofState::SPENT,
            $wallet->getStorage()->getProofsStatesBySecrets([$inputs[0]->secret])[$inputs[0]->secret]);
    }

    public function testPaidMeltChangeRecoveryKeepsJournalOnTransportFailure(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 8);
        $this->mint->meltQuotes['m'] = ['quote' => 'm', 'amount' => 4, 'fee_reserve' => 2, 'state' => 'UNPAID'];
        $this->mint->onPost = fn(string $path) => $path === 'melt/bolt11' ? throw new CashuException('timeout') : null;
        try {
            $wallet->melt('m', $inputs);
        } catch (CashuException $e) {
        }
        $this->mint->meltQuotes['m']['state'] = 'PAID';
        $this->mint->onPost = fn(string $path) => $path === 'restore' ? throw new CashuException('offline') : null;

        $result = $wallet->recoverPendingMelts();

        $this->assertNotEmpty($result['errors']);
        $this->assertNotNull($wallet->getStorage()->getPendingOperationById('melt:m'));
    }

    // ------------------------------------------------------------ SPB-LIB-006

    public function testNut19ReplayOnlyForAdvertisedEndpoints(): void
    {
        foreach ([
            [['ttl' => 60, 'cached_endpoints' => [['method' => 'POST', 'path' => '/v1/swap']]], 2],
            [['ttl' => 60, 'cached_endpoints' => [['method' => 'POST', 'path' => '/v1/mint/bolt11']]], 1],
            [['ttl' => 60], 1],
        ] as $i => [$nut19, $expectedPosts]) {
            $this->tearDown();
            $this->mint = new FakeRestoreMint([['id' => self::KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0]]);
            $wallet = $this->wallet(['name' => 'fake', 'nuts' => ['19' => $nut19]]);
            $inputs = $this->fund($wallet, 4);
            $first = true;
            $this->mint->onPost = function (string $path, array $data) use (&$first) {
                if ($path !== 'swap') {
                    return null;
                }
                if ($first) {
                    $first = false;
                    throw new CashuException('HTTP request failed: timeout');
                }
                return $this->mint->signOutputs($data['outputs']);
            };
            try {
                $wallet->swap($inputs, [2, 2]);
            } catch (CashuException $e) {
            }
            $this->assertSame($expectedPosts, $this->postsTo('swap'), "case $i");
        }
    }

    // ------------------------------------------------------------ SPB-LIB-007

    public function testFeeUsesIntegerArithmetic(): void
    {
        $this->mint->keysets[0]['input_fee_ppk'] = 17592186044416001; // 1000 * 2^44 + 1
        $wallet = $this->wallet();
        $proof = new Proof(self::KS, 1, 'x', FakeRestoreMint::G);
        $this->assertSame(17592186044417, $wallet->calculateFee([$proof]));
    }

    // ------------------------------------------------------------ SPB-LIB-009

    public function testWitnessSurvivesImportAndGetStoredProofs(): void
    {
        $wallet = $this->wallet();
        $storage = $wallet->getStorage();
        $proof = new Proof(self::KS, 2, 'w-secret', FakeRestoreMint::G, null, '{"signatures":["ab"]}');
        $storage->preparePendingSpend('swap:w', 'swap', [$proof], self::KS, [2]);
        $row = $storage->getPdo()->query("SELECT witness FROM cashu_proofs WHERE secret = 'w-secret'")->fetchColumn();
        $this->assertSame('{"signatures":["ab"]}', $row);

        $stored = new Proof(self::KS, 4, 'w-stored', FakeRestoreMint::G, null, '{"preimage":"00"}');
        $storage->storeProofs([$stored]);
        $found = array_values(array_filter($wallet->getStoredProofs(), fn($p) => $p->secret === 'w-stored'));
        $this->assertSame('{"preimage":"00"}', $found[0]->witness);
    }

    // ------------------------------------------------------------ SPB-LIB-010

    public function testMeltReportsTheMintState(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 4);
        $this->mint->meltQuotes['m'] = ['quote' => 'm', 'amount' => 3, 'fee_reserve' => 0, 'state' => 'UNPAID'];
        $this->mint->onPost = fn(string $path) => $path === 'melt/bolt11' ? ['quote' => 'm', 'state' => 'pending'] : null;

        $result = $wallet->melt('m', $inputs);

        $this->assertFalse($result['paid']);
        $this->assertSame('PENDING', $result['state']);
    }

    // ------------------------------------------------------------ SPB-LIB-011

    public function testAgedPendingSwapIsReportedNotReleased(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 4);
        $this->mint->onPost = fn(string $path) => $path === 'swap' ? throw new CashuException('timeout') : null;
        try {
            $wallet->swap($inputs, [2, 2]);
        } catch (CashuException $e) {
        }
        $this->mint->setState(self::secrets($inputs), 'PENDING');
        $this->mint->onPost = null;

        $fresh = $wallet->recoverPendingSwaps();
        $this->assertSame(1, $fresh['still_pending']);
        $this->assertSame(0, $fresh['aged_pending']);

        $wallet->getStorage()->getPdo()->exec(
            'UPDATE cashu_pending_operations SET created_at = created_at - ' . (Wallet::SWAP_PENDING_WARN_AFTER + 60)
        );
        $aged = $wallet->recoverPendingSwaps();
        $this->assertSame(1, $aged['still_pending']);
        $this->assertSame(1, $aged['aged_pending']);
        $this->assertCount(1, $aged['warnings']);
        $this->assertCount(1, $wallet->getStorage()->getPendingOperations('swap'), 'inputs stay reserved');
    }

    // ------------------------------------------------------------ SPB-LIB-001

    public function testSpentTokenIsRefusedBeforeAnyCounterIsReserved(): void
    {
        $wallet = $this->wallet();
        $token = self::foreignProofs([4, 2]);
        $this->mint->setState([$token[1]->secret], 'SPENT');

        for ($i = 0; $i < 3; $i++) {
            try {
                $wallet->receive($wallet->serializeToken($token, 'v3'));
                $this->fail('spent token accepted');
            } catch (CashuProtocolException $e) {
                $this->assertSame(CashuProtocolException::PROOFS_ALREADY_SPENT, $e->getCode());
            }
        }
        $this->assertSame(0, $this->postsTo('swap'));
        $this->assertSame(0, $wallet->getStorage()->getCounter(self::KS));
    }

    public function testRejectedSwapGivesItsCountersBackOnlyIfNothingFollowed(): void
    {
        $wallet = $this->wallet();
        $token = self::foreignProofs([4]);
        // checkstate says UNSPENT, then the sender wins the race: the swap is refused.
        $this->mint->onPost = function (string $path) use ($token) {
            if ($path === 'swap') {
                $this->mint->setState([$token[0]->secret], 'SPENT');
                throw new CashuProtocolException('Token already spent.', 11001, 400);
            }
            return null;
        };
        try {
            $wallet->receive($wallet->serializeToken($token, 'v3'));
            $this->fail('refused swap accepted');
        } catch (CashuProtocolException $e) {
        }
        $this->assertSame(0, $wallet->getStorage()->getCounter(self::KS), 'counters returned');

        // Another allocation lands while the refused swap is in flight: no rollback.
        $token = self::foreignProofs([4]);
        $this->mint->onPost = function (string $path) use ($token, $wallet) {
            if ($path === 'swap') {
                $wallet->getStorage()->incrementCounter(self::KS);
                $this->mint->setState([$token[0]->secret], 'SPENT');
                throw new CashuProtocolException('Token already spent.', 11001, 400);
            }
            return null;
        };
        try {
            $wallet->receive($wallet->serializeToken($token, 'v3'));
        } catch (CashuProtocolException $e) {
        }
        $this->assertSame(2, $wallet->getStorage()->getCounter(self::KS), 'counters after another allocation stay burned');
    }

    // ------------------------------------------------------------ OPUS-020 / SPB-LIB-005

    public function testTokenFromTheSameMintSpelledDifferentlyIsReceivable(): void
    {
        $wallet = $this->wallet();
        $token = self::foreignProofs([4]);
        $this->mint->onPost = fn(string $path, array $data) => $path === 'swap'
            ? $this->mint->signOutputs($data['outputs']) : null;

        $proofs = $wallet->receive(TokenSerializer::serializeV3('https://MINT.example:443/', $token, 'sat'));
        $this->assertSame(4, Wallet::sumProofs($proofs));

        $this->expectExceptionMessage('different mint');
        $wallet->receive(TokenSerializer::serializeV3('https://other.example', self::foreignProofs([4]), 'sat'));
    }

    // ------------------------------------------------------------ OPUS-003

    public function testSwapJournalWhoseInputsWereTakenIsReported(): void
    {
        $wallet = $this->wallet();
        $inputs = $this->fund($wallet, 4);
        $this->mint->onPost = fn(string $path) => $path === 'swap' ? throw new CashuException('timeout') : null;
        try {
            $wallet->swap($inputs, [2, 2]);
        } catch (CashuException $e) {
        }
        $wallet->getStorage()->updateProofsState(self::secrets($inputs), ProofState::EXPORTED);
        $calls = count($this->mint->calls);

        $result = $wallet->recoverPendingSwaps();

        $this->assertSame(1, $result['orphaned']);
        $this->assertStringContainsString('EXPORTED', reset($result['errors']));
        $this->assertSame($calls, count($this->mint->calls), 'the mint is not asked');
    }

    // ------------------------------------------------------------ reclaim: EXPORTED inputs

    public function testSwapCanReserveExportedInputsAtomically(): void
    {
        $wallet = $this->wallet();
        $storage = $wallet->getStorage();
        $inputs = $this->fund($wallet, 4);
        $storage->updateProofsState(self::secrets($inputs), ProofState::EXPORTED);

        try {
            $wallet->swap($inputs, [2, 2]);
            $this->fail('EXPORTED proofs reserved without being asked to');
        } catch (CashuException $e) {
            $this->assertStringContainsString('EXPORTED', $e->getMessage());
        }

        // Definitive refusal: back to EXPORTED, not into the spendable balance.
        $this->mint->onPost = fn(string $path) => $path === 'swap'
            ? throw new CashuProtocolException('keyset inactive', 12002, 400) : null;
        try {
            $wallet->swap($inputs, [2, 2], [ProofState::EXPORTED]);
            $this->fail('refusal swallowed');
        } catch (CashuProtocolException $e) {
        }
        $this->assertSame([ProofState::EXPORTED],
            array_values(array_unique($storage->getProofsStatesBySecrets(self::secrets($inputs)))));
        $this->assertSame(0, $wallet->getBalance());

        // Success: the reclaimed value is spendable, the exported proofs are SPENT.
        $this->mint->onPost = fn(string $path, array $data) => $path === 'swap'
            ? $this->mint->signOutputs($data['outputs']) : null;
        $wallet->swap($inputs, [2, 2], [ProofState::EXPORTED]);
        $this->assertSame(4, $wallet->getBalance());
        $this->assertSame([ProofState::SPENT],
            array_values(array_unique($storage->getProofsStatesBySecrets(self::secrets($inputs)))));
    }

    public function testExportedInputsAlreadySpentAreMarkedSpent(): void
    {
        $wallet = $this->wallet();
        $storage = $wallet->getStorage();
        $inputs = $this->fund($wallet, 4);
        $storage->updateProofsState(self::secrets($inputs), ProofState::EXPORTED);
        $this->mint->onPost = function (string $path) use ($inputs) {
            if ($path === 'swap') {
                $this->mint->setState(self::secrets($inputs), 'SPENT');
                throw new CashuProtocolException('Token already spent.', 11001, 400);
            }
            return null;
        };
        try {
            $wallet->swap($inputs, [4], [ProofState::EXPORTED]);
        } catch (CashuProtocolException $e) {
        }
        $this->assertSame(ProofState::SPENT, $storage->getProofsStatesBySecrets([$inputs[0]->secret])[$inputs[0]->secret]);
        $this->assertSame([], $storage->getPendingOperations());
    }

    public function testPendingAndSpentAreNeverReservable(): void
    {
        $wallet = $this->wallet();
        $this->expectException(CashuException::class);
        $wallet->getStorage()->preparePendingSpend('swap:x', 'swap', [], self::KS, [], [], [ProofState::PENDING]);
    }
}
