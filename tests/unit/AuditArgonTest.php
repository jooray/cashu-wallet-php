<?php

declare(strict_types=1);

namespace Cashu\Tests;

require_once dirname(__DIR__) . '/support/FakeRestoreMint.php';

use Cashu\BigInt;
use Cashu\CashuException;
use Cashu\CashuProtocolException;
use Cashu\CBOR;
use Cashu\CBORBytes;
use Cashu\Crypto;
use Cashu\DLEQWallet;
use Cashu\Keyset;
use Cashu\MintQuote;
use Cashu\PaymentRequest;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Secp256k1;
use Cashu\Tests\Support\FakeRestoreMint;
use Cashu\TokenSerializer;
use Cashu\Transport;
use Cashu\Wallet;
use Cashu\WalletStorage;
use PHPUnit\Framework\TestCase;

/**
 * Regressions for the cashu-wallet-php findings of AUDIT-ARGON-NOV2026 (CW-xx, and the
 * library parts of CPS-01/CPS-04).
 */
final class AuditArgonTest extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const KS = '009a1f293253e41e';
    private const MINT = 'https://mint.example';

    private string $db;
    private FakeRestoreMint $mint;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/cashu-argon-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->mint = new FakeRestoreMint([['id' => self::KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0]]);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->db . $suffix);
        }
    }

    private function wallet(bool $load = true): Wallet
    {
        $wallet = new Wallet(self::MINT, 'sat', $this->db, 'store-1');
        if ($wallet->getStorage()->getSeedFingerprint() === null) {
            $wallet->initializeNewFromMnemonic(self::MNEMONIC);
        } else {
            $wallet->initFromMnemonic(self::MNEMONIC);
        }
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $this->mint);
        if ($load) {
            $wallet->loadMint();
        }
        return $wallet;
    }

    private static function rejection(int $code, string $message = 'rejected'): CashuProtocolException
    {
        return new CashuProtocolException($message, $code, 400);
    }

    private static function foreignProofs(array $amounts): array
    {
        return array_map(
            fn($amount) => new Proof(self::KS, $amount, bin2hex(random_bytes(32)), FakeRestoreMint::G),
            $amounts
        );
    }

    private function rowCount(Wallet $wallet): int
    {
        return (int)$wallet->getStorage()->getPdo()->query('SELECT COUNT(*) FROM cashu_proofs')->fetchColumn();
    }

    /** A valid NUT-12 DLEQ for the fake mint (private key 1, so A = G and C_ = B_). */
    private static function dleqFor(string $B_): array
    {
        $G = Secp256k1::getGenerator();
        $B = Secp256k1::decompressPointHex($B_);
        $k = Secp256k1::randomScalar();
        $e = Crypto::hashE(Secp256k1::scalarMult($k, $G), Secp256k1::scalarMult($k, $B), $G, $B);
        $s = BigInt::fromHex($e)->add($k)->mod(Secp256k1::getOrder())->toHex(64);
        return ['e' => $e, 's' => $s];
    }

    private function mintPlanOutputs(Wallet $wallet, array $plan): array
    {
        $outputs = [];
        foreach ($plan['amounts'] as $i => $amount) {
            $blinded = $wallet->createDeterministicBlindedMessage($plan['keyset_id'], $plan['counter_start'] + $i);
            $outputs[] = ['amount' => $amount, 'id' => $plan['keyset_id'], 'B_' => $blinded['B_']];
        }
        return $outputs;
    }

    // ------------------------------------------------------------ CPS-01 / CW-02

    /** Background recovery of a refused received token deletes it, never absorbs it. */
    public function testRecoveryRemovesImportedTokenWhenResubmissionIsRefused(): void
    {
        $wallet = $this->wallet();
        $token = self::foreignProofs([64]);
        $this->mint->onPost = fn(string $path) => $path === 'swap' ? throw new CashuException('timeout') : null;
        try {
            $wallet->receive($wallet->serializeToken($token, 'v3'));
            $this->fail('lost response accepted');
        } catch (CashuException $e) {
        }
        $this->assertCount(1, $wallet->getStorage()->getPendingOperations('swap'));

        // A forged signature: the mint refuses it although checkstate says UNSPENT.
        $this->mint->onPost = fn(string $path) => $path === 'swap'
            ? throw self::rejection(CashuProtocolException::PROOF_VERIFICATION_FAILED) : null;
        $result = $wallet->recoverPendingSwaps();
        $this->assertSame(1, $result['released']);
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
        $this->assertSame(0, $this->rowCount($wallet), 'no UNSPENT junk row');
        $this->assertSame(0, $wallet->getBalance());
    }

    // ------------------------------------------------------------ CW-01

    /** A late signature on a replaced mint plan is accepted, not thrown away. */
    public function testLateResponseForReplacedMintPlanIsStored(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = fn(string $path) => $path === 'mint/quote/bolt11/q'
            ? ['quote' => 'q', 'request' => 'lnbc', 'state' => 'PAID'] : null;
        $this->mint->onPost = function (string $path, array $data) use ($wallet) {
            if ($path !== 'mint/bolt11') {
                return null;
            }
            // While this request is in flight another worker replaces the plan.
            $storage = $wallet->getStorage();
            $plan = $storage->getPendingOperationById('mint:q')['data'];
            $storage->replacePendingMintPlan('q', $plan, self::KS, $plan['amounts']);
            return $this->mint->signOutputs($data['outputs']);
        };

        $proofs = $wallet->mint('q', 3);
        $this->assertSame(3, Wallet::sumProofs($proofs));
        $this->assertSame(3, $wallet->getBalance());
        $this->assertNull($wallet->getStorage()->getPendingOperationById('mint:q'));
        $this->assertSame(4, $wallet->getStorage()->getCounter(self::KS), 'the replacement plan stays burned');
    }

    /** Recovery restores a replaced plan the mint signed, including legacy entries without amounts. */
    public function testRecoveryFindsOutputsOfAReplacedPlan(): void
    {
        foreach (['with amounts', 'legacy entry'] as $case) {
            $this->tearDown();
            $this->setUp();
            $wallet = $this->wallet();
            $storage = $wallet->getStorage();
            $storage->preparePendingSpend('mint:q', 'mint', [], self::KS, [1, 2]);
            $oldPlan = $storage->getPendingOperationById('mint:q')['data'];
            $newPlan = $storage->replacePendingMintPlan('q', $oldPlan, self::KS, [1, 2]);
            if ($case === 'legacy entry') {
                unset($newPlan['replaced_plans'][0]['amounts']);
                $storage->savePendingOperation('mint:q', 'mint', $newPlan);
            }
            // The mint signed the first plan and marked the quote issued.
            $this->mint->signOutputs($this->mintPlanOutputs($wallet, $oldPlan));
            $this->mint->onGet = fn(string $path) => $path === 'mint/quote/bolt11/q'
                ? ['quote' => 'q', 'request' => 'lnbc', 'amount' => 3, 'state' => 'ISSUED'] : null;

            $result = $wallet->recoverPendingMints();
            $this->assertSame(1, $result['recovered'], $case);
            $this->assertSame(3, $result['amount'], $case);
            $this->assertSame([], $storage->getPendingOperations(), $case);
            $this->assertSame(3, $wallet->getBalance(), $case);
            $this->assertCount(2, $storage->getProofsByQuoteId('q'), $case);
        }
    }

    /** A mint journal never discards proofs in hand, even for a plan it no longer holds. */
    public function testFinalizePendingMintStoresProofsWithoutJournal(): void
    {
        $storage = new WalletStorage($this->db, self::MINT, 'sat', 'store-1');
        $proof = new Proof(self::KS, 4, 'minted', FakeRestoreMint::G);
        $storage->finalizePendingMint('gone', ['keyset_id' => self::KS, 'counter_start' => 0, 'amounts' => [4]], [$proof]);
        $this->assertSame(4, $storage->getBalance());
        $this->assertCount(1, $storage->getProofsByQuoteId('gone'));
    }

    // ------------------------------------------------------------ CW-03

    public function testFinalizationWithoutJournalStillStoresOutputs(): void
    {
        $storage = new WalletStorage($this->db, self::MINT, 'sat', 'store-1');
        $input = new Proof(self::KS, 4, 'input', FakeRestoreMint::G);
        $storage->storeProofs([$input]);
        $storage->preparePendingSpend('swap:x', 'swap', [$input], self::KS, [4]);
        $storage->deletePendingOperation('swap:x');

        $storage->finalizePendingSpend('swap:x', ['input'], ProofState::SPENT, [new Proof(self::KS, 4, 'out', FakeRestoreMint::G)]);
        $storage->settlePendingSpend('swap:x', ['input'], ['input' => ProofState::SPENT], [new Proof(self::KS, 2, 'out2', FakeRestoreMint::G)]);
        $states = $storage->getProofsStatesBySecrets(['out', 'out2']);
        $this->assertSame(['out' => ProofState::UNSPENT, 'out2' => ProofState::UNSPENT], $states);
    }

    // ------------------------------------------------------------ CW-04

    public function testSecretListsLongerThanSqliteVariableLimitWork(): void
    {
        $storage = new WalletStorage($this->db, self::MINT, 'sat', 'store-1');
        $proofs = [];
        for ($i = 0; $i < 1200; $i++) {
            $proofs[] = new Proof(self::KS, 1, "s$i", FakeRestoreMint::G);
        }
        $storage->storeProofs($proofs);
        $secrets = array_map(fn($p) => $p->secret, $proofs);

        $this->assertCount(1200, $storage->getProofsStatesBySecrets($secrets));
        $objects = $storage->getProofsBySecretsAsObjects($secrets);
        $this->assertSame($secrets, array_map(fn($p) => $p->secret, $objects), 'order preserved');
        $storage->updateProofsState($secrets, ProofState::SPENT);
        $this->assertSame([ProofState::SPENT], array_values(array_unique($storage->getProofsStatesBySecrets($secrets))));
        $storage->deleteProofs($secrets);
        $this->assertSame([], $storage->getProofsStatesBySecrets($secrets));
    }

    // ------------------------------------------------------------ CW-05

    public function testV4TokenEncodesBinaryFieldsAsByteStringsAndPadsDleqScalars(): void
    {
        $this->assertSame("\x44AAAA", CBOR::encode(new CBORBytes('AAAA')), 'printable bytes stay a byte string');

        $e = '0' . str_repeat('41', 31) . '4'; // 63 hex chars once the leading zero is dropped
        $proof = new Proof(self::KS, 8, 'secret', FakeRestoreMint::G,
            new DLEQWallet(ltrim($e, '0'), str_repeat('41', 32), str_repeat('42', 32)));
        $token = TokenSerializer::serializeV4(self::MINT, [$proof], 'sat', null, true);

        $raw = base64_decode(strtr(substr($token, 6), '-_', '+/') . str_repeat('=', (4 - strlen($token) % 4 + 6) % 4));
        $this->assertStringContainsString("\x58\x20" . hex2bin(str_repeat('41', 32)), $raw, 's is a 32-byte byte string');
        $this->assertStringNotContainsString("\xf4", $raw, 'no CBOR false in place of a scalar');

        $decoded = TokenSerializer::deserialize($token)->proofs[0];
        $this->assertSame($e, $decoded->dleq->e);
        $this->assertSame(str_repeat('41', 32), $decoded->dleq->s);
    }

    // ------------------------------------------------------------ CW-06

    public function testV2KeysetIdsAreVerifiedOutsideLoadMint(): void
    {
        $wallet = $this->wallet();
        $real = Keyset::deriveKeysetIdV2(FakeRestoreMint::keys(), 'sat');
        $bogus = '01' . str_repeat('ab', 32);
        $this->mint->onGet = fn(string $path) => in_array($path, ['keys/' . $real, 'keys/' . $bogus], true)
            ? ['keysets' => [['id' => substr($path, 5), 'unit' => 'sat', 'keys' => FakeRestoreMint::keys()]]] : null;

        $this->assertSame(FakeRestoreMint::G, $wallet->getPublicKey($real, 1));
        $this->expectException(CashuException::class);
        $this->expectExceptionMessage('derive');
        $wallet->getPublicKey($bogus, 1);
    }

    public function testRestoreRefusesKeysThatDoNotDeriveToTheV2Id(): void
    {
        $bogus = '01' . str_repeat('ab', 32);
        $this->mint = new FakeRestoreMint([['id' => $bogus, 'unit' => 'sat', 'active' => true]]);
        $wallet = new Wallet(self::MINT, 'sat', $this->db, 'store-1');
        $wallet->initializeForRestore(self::MNEMONIC);
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $this->mint);

        $step = $wallet->restoreStep(10);
        $this->assertNotSame('complete', $step['status']);
        $this->assertStringContainsString('derive', (string)$step['progress'][$bogus]['error']);
        $this->assertTrue($wallet->requiresRecovery());
    }

    // ------------------------------------------------------------ CW-08

    public function testMalformedMintAndRequestDataRaiseCashuExceptions(): void
    {
        // C_ = r*A unblinds to the point at infinity.
        $r = BigInt::fromHex(str_pad('05', 64, '0', STR_PAD_LEFT));
        $C_ = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($r, Secp256k1::getGenerator())));
        try {
            Crypto::unblindSignature($C_, $r, FakeRestoreMint::G);
            $this->fail('point at infinity accepted');
        } catch (CashuException $e) {
            $this->assertStringContainsString('infinity', $e->getMessage());
        }

        foreach ([['t' => 5, 'a' => 'x'], ['t' => 'post', 'a' => []], ['t' => 'post', 'a' => 'x', 'g' => [['n', 7]]]] as $bad) {
            try {
                Transport::fromArray($bad);
                $this->fail('malformed transport accepted');
            } catch (CashuException $e) {
            }
        }
        $this->expectException(CashuException::class);
        PaymentRequest::fromArray(['a' => -5, 'u' => 'sat']);
    }

    public function testHugeInputFeeDoesNotOverflowIntoTypeError(): void
    {
        $this->mint->keysets[0]['input_fee_ppk'] = PHP_INT_MAX - 10;
        $wallet = $this->wallet();
        $this->expectException(CashuException::class);
        $wallet->calculateFee(self::foreignProofs([1, 1]));
    }

    public function testMalformedDleqOnASignatureIsACashuException(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = fn(string $path) => $path === 'mint/quote/bolt11/q'
            ? ['quote' => 'q', 'request' => 'lnbc', 'state' => 'PAID'] : null;
        $this->mint->onPost = function (string $path, array $data) {
            $response = $this->mint->signOutputs($data['outputs']);
            $response['signatures'][0]['dleq'] = 'garbage';
            return $response;
        };
        $this->expectException(CashuException::class);
        $this->expectExceptionMessage('invalid DLEQ');
        $wallet->mint('q', 1);
    }

    // ------------------------------------------------------------ CW-09

    public function testMeltChangeKeepsItsDleqProof(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = fn(string $path) => str_starts_with($path, 'mint/quote/bolt11/')
            ? ['quote' => substr($path, 18), 'request' => 'lnbc', 'state' => 'PAID'] : null;
        $this->mint->onPost = fn(string $path, array $data) => $path === 'mint/bolt11' ? $this->mint->signOutputs($data['outputs']) : null;
        $inputs = $wallet->mint('fund', 8);

        $this->mint->meltQuotes['m'] = ['quote' => 'm', 'amount' => 5, 'fee_reserve' => 2, 'state' => 'UNPAID'];
        $this->mint->onPost = function (string $path, array $data) {
            $B_ = $data['outputs'][0]['B_'];
            return ['quote' => 'm', 'state' => 'PAID', 'change' => [
                ['id' => self::KS, 'amount' => 2, 'C_' => $B_, 'dleq' => self::dleqFor($B_)],
            ]];
        };
        $result = $wallet->melt('m', $inputs);
        $this->assertTrue($result['paid']);
        $this->assertFalse($result['changeRecoveryPending']);
        $this->assertCount(1, $result['change']);
        $this->assertNotNull($result['change'][0]->dleq);
        $this->assertNotNull($result['change'][0]->dleq->r);
        $stored = $wallet->getStorage()->getProofsAsObjects();
        $this->assertCount(1, $stored);
        $this->assertNotNull($stored[0]->dleq, 'stored with its DLEQ');
        $this->assertTrue(Crypto::verifyProofDleq($stored[0], FakeRestoreMint::G));
    }

    // ------------------------------------------------------------ CW-10

    public function testSelectionSkipsProofsWorthLessThanTheirFee(): void
    {
        $this->mint->keysets = [
            ['id' => self::KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0],
            ['id' => '00ad268c4d1f5826', 'unit' => 'sat', 'active' => false, 'input_fee_ppk' => 2000],
        ];
        $wallet = $this->wallet();
        $dust = new Proof('00ad268c4d1f5826', 2, 'dust', FakeRestoreMint::G);
        $free = [new Proof(self::KS, 2, 'a', FakeRestoreMint::G), new Proof(self::KS, 1, 'b', FakeRestoreMint::G)];
        $selected = $wallet->selectProofsWithFees(array_merge([$dust], $free), 3);
        $this->assertSame(['a', 'b'], array_map(fn($p) => $p->secret, $selected));
    }

    public function testExpiredActiveKeysetIsNotUsedForNewOutputs(): void
    {
        $this->mint->keysets = [
            ['id' => self::KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0, 'final_expiry' => time() - 60],
            ['id' => '00ad268c4d1f5826', 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0],
        ];
        $this->assertSame('00ad268c4d1f5826', $this->wallet()->getActiveKeysetId());
    }

    // ------------------------------------------------------------ CW-11

    public function testSplitSendsOutputsInAscendingOrder(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = fn(string $path) => str_starts_with($path, 'mint/quote/bolt11/')
            ? ['quote' => substr($path, 18), 'request' => 'lnbc', 'state' => 'PAID'] : null;
        $sent = null;
        $this->mint->onPost = function (string $path, array $data) use (&$sent) {
            if ($path === 'swap') {
                $sent = array_column($data['outputs'], 'amount');
            }
            return $this->mint->signOutputs($data['outputs']);
        };
        $inputs = $wallet->mint('fund', 15);
        $result = $wallet->split($inputs, 12); // send 4+8, keep 1+2
        $this->assertSame([1, 2, 4, 8], $sent);
        $this->assertSame(12, Wallet::sumProofs($result['send']));
        $this->assertSame(3, Wallet::sumProofs($result['keep']));

        $this->expectException(CashuException::class);
        $wallet->swap([], [1]);
    }

    // ------------------------------------------------------------ CW-12

    public function testUppercaseHexKeysetIdInTokenIsNormalized(): void
    {
        $token = 'cashuA' . rtrim(strtr(base64_encode(json_encode(['token' => [[
            'mint' => self::MINT,
            'proofs' => [['id' => strtoupper(self::KS), 'amount' => 1, 'secret' => 's', 'C' => FakeRestoreMint::G]],
        ]], 'unit' => 'sat'])), '+/', '-_'), '=');
        $this->assertSame(self::KS, TokenSerializer::deserialize($token)->proofs[0]->id);
    }

    public function testMintingAnAlreadyIssuedQuoteLeavesNoJournal(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = fn(string $path) => $path === 'mint/quote/bolt11/q'
            ? ['quote' => 'q', 'request' => 'lnbc', 'amount' => 2, 'state' => 'ISSUED'] : null;
        try {
            $wallet->mint('q', 2);
            $this->fail('issued quote minted');
        } catch (CashuException $e) {
            $this->assertStringContainsString('already issued', $e->getMessage());
        }
        $this->assertSame([], $wallet->getStorage()->getPendingOperations());
        $this->assertSame(0, $wallet->getStorage()->getCounter(self::KS));
    }

    // ------------------------------------------------------------ CPS-04 (library part)

    public function testPartiallyPaidQuoteIsNotPaid(): void
    {
        $quote = MintQuote::fromArray(['quote' => 'q', 'request' => 'lnbc', 'amount' => 100,
            'amount_paid' => 40, 'amount_issued' => 0]);
        $this->assertFalse($quote->isPaid());
        $this->assertFalse($quote->isIssued());
        $full = MintQuote::fromArray(['quote' => 'q', 'request' => 'lnbc', 'amount' => 100,
            'amount_paid' => 100, 'amount_issued' => 0]);
        $this->assertTrue($full->isPaid());
    }
}
