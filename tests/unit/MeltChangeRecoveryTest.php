<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\BigInt;
use Cashu\CashuException;
use Cashu\Crypto;
use Cashu\MeltQuote;
use Cashu\MintQuote;
use Cashu\Secp256k1;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * NUT-08 melt change recovery (recoverMeltChange), exercised offline through
 * reflection with a simulated mint keyset.
 *
 * Regression focus: per NUT-08 the mint assigns its own amounts to change
 * outputs (decomposing overpaid Lightning fees) and ignores the amounts on
 * the wallet's prepared blank outputs. Recovery must therefore accept change
 * whose amounts differ from the prepared outputs; a previous bug rejected
 * such change with strict amount matching.
 */
final class MeltChangeRecoveryTest extends TestCase
{
    private const KEYSET = '009a1f293253e41e';

    /** @var array<int, BigInt> mint private key per amount */
    private array $mintKeys = [];

    private Wallet $wallet;

    protected function setUp(): void
    {
        $this->wallet = new Wallet('https://mint.example');
        $this->wallet->initFromMnemonic(cashu_fixture('nut13-derivation')['mnemonic']);

        // Simulated mint keyset: one private key per power-of-two amount
        $keys = [];
        foreach ([1, 2, 4, 8] as $i => $amount) {
            $k = BigInt::fromHex(str_repeat((string)($i + 1), 2) . str_repeat('0f', 31));
            $this->mintKeys[$amount] = $k;
            $A = Secp256k1::scalarMult($k, Secp256k1::getGenerator());
            $keys[$amount] = bin2hex(Secp256k1::compressPoint($A));
        }

        $prop = new \ReflectionProperty(Wallet::class, 'keys');
        $prop->setValue($this->wallet, [self::KEYSET => $keys]);
    }

    private function recoverMeltChange(array $pendingData, MeltQuote $quote): array
    {
        $method = new \ReflectionMethod(Wallet::class, 'recoverMeltChange');
        return $method->invoke($this->wallet, $pendingData, $quote);
    }

    /** Simulate the mint signing the wallet's deterministic blank output. */
    private function changeSignature(int $counterIndex, int $amount): array
    {
        $blinded = $this->wallet->createDeterministicBlindedMessage(self::KEYSET, $counterIndex);
        $B = Secp256k1::decompressPoint(hex2bin($blinded['B_']));
        $C_ = Secp256k1::scalarMult($this->mintKeys[$amount], $B);

        return [
            'id' => self::KEYSET,
            'amount' => $amount,
            'C_' => bin2hex(Secp256k1::compressPoint($C_)),
        ];
    }

    private function pendingData(array $amounts, int $counterStart = 0): array
    {
        return [
            'counter_start' => $counterStart,
            'keyset_id' => self::KEYSET,
            'amounts' => $amounts,
            'input_secrets' => ['irrelevant-for-change'],
        ];
    }

    private function quoteWithChange(?array $change): MeltQuote
    {
        return MeltQuote::fromArray([
            'quote' => 'q1',
            'amount' => 100,
            'fee_reserve' => 7,
            'state' => 'PAID',
            'change' => $change,
        ]);
    }

    public function testRecoversChangeWhenMintAssignsDifferentAmounts(): void
    {
        // Wallet prepared 3 blank outputs (amounts unknown ahead of time, all 1);
        // the mint returns only 2 signatures with its own amounts 4 and 2.
        $pending = $this->pendingData([1, 1, 1]);
        $quote = $this->quoteWithChange([
            $this->changeSignature(0, 4),
            $this->changeSignature(1, 2),
        ]);

        $proofs = $this->recoverMeltChange($pending, $quote);

        $this->assertCount(2, $proofs);
        $this->assertSame([4, 2], array_map(fn($p) => $p->amount, $proofs));

        // Secrets must be the deterministic NUT-13 secrets for counters 0 and 1
        foreach ($proofs as $i => $proof) {
            $expected = $this->wallet->generateDeterministicSecret(self::KEYSET, $i);
            $this->assertSame($expected['secret'], $proof->secret);
            $this->assertSame(self::KEYSET, $proof->id);

            // Unblinded C must verify as k * hash_to_curve(secret)
            $Y = Crypto::hashToCurve($proof->secret);
            $kY = Secp256k1::scalarMult($this->mintKeys[$proof->amount], $Y);
            $this->assertSame(
                bin2hex(Secp256k1::compressPoint($kY)),
                $proof->C,
                "change proof $i does not verify against the mint key"
            );
        }
    }

    public function testUsesCounterStartOffset(): void
    {
        $pending = $this->pendingData([1, 1], 5);

        $blinded = $this->wallet->createDeterministicBlindedMessage(self::KEYSET, 5);
        $B = Secp256k1::decompressPoint(hex2bin($blinded['B_']));
        $C_ = Secp256k1::scalarMult($this->mintKeys[8], $B);
        $quote = $this->quoteWithChange([[
            'id' => self::KEYSET,
            'amount' => 8,
            'C_' => bin2hex(Secp256k1::compressPoint($C_)),
        ]]);

        $proofs = $this->recoverMeltChange($pending, $quote);
        $this->assertCount(1, $proofs);
        $this->assertSame(
            $this->wallet->generateDeterministicSecret(self::KEYSET, 5)['secret'],
            $proofs[0]->secret
        );
    }

    /**
     * NUT-05/NUT-23 only define `change` on the POST melt response. A mint that omits it
     * from the GET quote response must not cost us the fee-reserve refund, so recovery
     * asks the mint via NUT-09 whether it signed our blank outputs.
     */
    public function testAsksTheMintViaRestoreWhenQuoteHasNoChange(): void
    {
        $signature = $this->changeSignature(1, 4);
        $blinded = $this->wallet->createDeterministicBlindedMessage(self::KEYSET, 1);
        $this->useRestoreResponse([
            'outputs' => [['amount' => 0, 'id' => self::KEYSET, 'B_' => $blinded['B_']]],
            'signatures' => [$signature],
        ]);

        foreach ([null, []] as $change) {
            $proofs = $this->recoverMeltChange(
                $this->pendingData([0, 0]),
                $this->quoteWithChange($change)
            );
            $this->assertCount(1, $proofs);
            $this->assertSame(4, $proofs[0]->amount);
            $this->assertSame(
                $this->wallet->generateDeterministicSecret(self::KEYSET, 1)['secret'],
                $proofs[0]->secret
            );
        }
    }

    /** A mint that signed nothing proves the refund really was zero. */
    public function testReturnsNothingWhenRestoreFindsNoSignatures(): void
    {
        $this->useRestoreResponse(['outputs' => [], 'signatures' => []]);

        $this->assertSame(
            [],
            $this->recoverMeltChange($this->pendingData([0, 0]), $this->quoteWithChange(null))
        );
    }

    /** Swap the wallet's mint client for one that answers /v1/restore from a fixture. */
    private function useRestoreResponse(array $response): void
    {
        $client = new class ($response) extends \Cashu\MintClient {
            public function __construct(private array $response)
            {
                parent::__construct('https://mint.example');
            }

            public function post(string $path, array $data, ?int $timeout = null): array
            {
                if ($path !== 'restore') {
                    throw new CashuException("Unexpected request: $path");
                }
                return $this->response;
            }
        };
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($this->wallet, $client);
    }

    public function testReturnsNothingWithoutCounterJournalData(): void
    {
        $quote = $this->quoteWithChange([$this->changeSignature(0, 2)]);

        $this->assertSame([], $this->recoverMeltChange([], $quote));
        $this->assertSame([], $this->recoverMeltChange(
            ['keyset_id' => self::KEYSET, 'amounts' => [1]], // counter_start missing
            $quote
        ));
    }

    public function testRejectsMoreSignaturesThanPreparedOutputs(): void
    {
        $pending = $this->pendingData([1]);
        $quote = $this->quoteWithChange([
            $this->changeSignature(0, 1),
            $this->changeSignature(1, 2),
        ]);

        $this->expectException(CashuException::class);
        $this->expectExceptionMessageMatches('/more melt change signatures/');
        $this->recoverMeltChange($pending, $quote);
    }

    public function testRejectsChangeFromForeignKeyset(): void
    {
        $pending = $this->pendingData([1, 1]);
        $sig = $this->changeSignature(0, 2);
        $sig['id'] = '00ad268c4d1f5826';

        $this->expectException(CashuException::class);
        $this->expectExceptionMessageMatches('/does not match the prepared outputs/');
        $this->recoverMeltChange($pending, $this->quoteWithChange([$sig]));
    }

    public function testRejectsNonPositiveChangeAmounts(): void
    {
        $pending = $this->pendingData([1, 1]);
        $sig = $this->changeSignature(0, 2);
        $sig['amount'] = 0;

        $this->expectException(CashuException::class);
        $this->recoverMeltChange($pending, $this->quoteWithChange([$sig]));
    }

    public function testMeltQuoteFromArrayAndStateHelpers(): void
    {
        $quote = MeltQuote::fromArray([
            'quote' => 'abc',
            'amount' => 21,
            'fee_reserve' => 2,
            'state' => 'pending',
            'expiry' => 1234,
            'payment_preimage' => 'feed',
        ]);

        $this->assertSame('abc', $quote->quote);
        $this->assertSame(21, $quote->amount);
        $this->assertSame(2, $quote->feeReserve);
        $this->assertTrue($quote->isPending());
        $this->assertFalse($quote->isPaid());
        $this->assertNull($quote->change);

        $paid = MeltQuote::fromArray(['quote' => 'x', 'amount' => 1, 'state' => 'PAID']);
        $this->assertTrue($paid->isPaid());
    }

    public function testMintQuoteFromArrayAndStateHelpers(): void
    {
        $quote = MintQuote::fromArray([
            'quote' => 'q',
            'request' => 'lnbc1...',
            'amount' => 10,
            'state' => 'ISSUED',
        ]);

        $this->assertTrue($quote->isIssued());
        $this->assertFalse($quote->isPaid());
        $this->assertSame('lnbc1...', $quote->request);

        $defaults = MintQuote::fromArray(['quote' => 'q2', 'request' => 'lnbc2...']);
        $this->assertFalse($defaults->isPaid());
        $this->assertFalse($defaults->isIssued());
        $this->assertSame(0, $defaults->amount);
    }

    /**
     * NUT-04/NUT-23: `state` is deprecated; `amount_paid`/`amount_issued`
     * are authoritative when present.
     */
    public function testMintQuoteAmountAccounting(): void
    {
        // Paid but not yet issued — mintable.
        $paid = MintQuote::fromArray([
            'quote' => 'q', 'request' => 'lnbc1...', 'amount' => 10,
            'amount_paid' => 10, 'amount_issued' => 0,
        ]);
        $this->assertTrue($paid->isPaid());
        $this->assertFalse($paid->isIssued());
        $this->assertSame(10, $paid->mintableAmount());

        // Fully issued.
        $issued = MintQuote::fromArray([
            'quote' => 'q', 'request' => 'lnbc1...', 'amount' => 10,
            'amount_paid' => 10, 'amount_issued' => 10,
        ]);
        $this->assertFalse($issued->isPaid());
        $this->assertTrue($issued->isIssued());
        $this->assertSame(0, $issued->mintableAmount());

        // Unpaid, no state field at all (post-deprecation mint).
        $unpaid = MintQuote::fromArray([
            'quote' => 'q', 'request' => 'lnbc1...', 'amount' => 10,
            'amount_paid' => 0, 'amount_issued' => 0,
        ]);
        $this->assertFalse($unpaid->isPaid());
        $this->assertFalse($unpaid->isIssued());

        // Amount fields override a stale deprecated state.
        $conflict = MintQuote::fromArray([
            'quote' => 'q', 'request' => 'lnbc1...', 'amount' => 10,
            'state' => 'UNPAID', 'amount_paid' => 10, 'amount_issued' => 0,
        ]);
        $this->assertTrue($conflict->isPaid());
    }
}
