<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\Keyset;
use Cashu\CashuException;
use Cashu\Proof;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * NUT-02 input fee (input_fee_ppk) calculation.
 *
 * Keysets normally come from the mint via loadMint(); tests inject them
 * through reflection so no network is involved.
 */
final class FeeCalculationTest extends TestCase
{
    private const KEYSET_FEE = '00ad268c4d1f5826';   // 100 ppk
    private const KEYSET_FREE = '009a1f293253e41e';  // 0 ppk

    private function walletWithKeysets(): Wallet
    {
        $wallet = new Wallet('https://mint.example');

        $keysets = [
            new Keyset(self::KEYSET_FEE, 'sat', [], true, 100),
            new Keyset(self::KEYSET_FREE, 'sat', [], true, 0),
        ];

        $prop = new \ReflectionProperty(Wallet::class, 'keysets');
        $prop->setValue($wallet, $keysets);

        return $wallet;
    }

    private static function proof(string $keysetId, string $secret): Proof
    {
        return new Proof($keysetId, 1, $secret, '02' . str_repeat('11', 32));
    }

    public function testGetInputFeePpk(): void
    {
        $wallet = $this->walletWithKeysets();
        $this->assertSame(100, $wallet->getInputFeePpk(self::KEYSET_FEE));
        $this->assertSame(0, $wallet->getInputFeePpk(self::KEYSET_FREE));
    }

    /**
     * Guessing a zero fee for an unknown keyset produces an under-funded swap or melt
     * that the mint only rejects after the journal reserved the inputs.
     */
    public function testUnknownKeysetFeeFailsClosed(): void
    {
        $wallet = $this->walletWithKeysets();
        $this->expectException(CashuException::class);
        $wallet->getInputFeePpk('0000000000000000');
    }

    public function testGetInputFeePpkDefaultsToActiveKeyset(): void
    {
        $wallet = $this->walletWithKeysets();
        // First keyset in the list is the active one
        $this->assertSame(100, $wallet->getInputFeePpk());
    }

    public function testCalculateFeeIsCeilOfSummedPpk(): void
    {
        $wallet = $this->walletWithKeysets();

        // NUT-02: fee = ceil(sum(input_fee_ppk) / 1000)
        $one = [self::proof(self::KEYSET_FEE, 's1')];
        $this->assertSame(1, $wallet->calculateFee($one), '100 ppk rounds up to 1');

        $ten = array_map(fn($i) => self::proof(self::KEYSET_FEE, "t$i"), range(1, 10));
        $this->assertSame(1, $wallet->calculateFee($ten), '1000 ppk is exactly 1');

        $eleven = array_map(fn($i) => self::proof(self::KEYSET_FEE, "e$i"), range(1, 11));
        $this->assertSame(2, $wallet->calculateFee($eleven), '1100 ppk rounds up to 2');
    }

    public function testCalculateFeeMixedKeysets(): void
    {
        $wallet = $this->walletWithKeysets();
        $proofs = [
            self::proof(self::KEYSET_FEE, 'a'),
            self::proof(self::KEYSET_FREE, 'b'),
            self::proof(self::KEYSET_FREE, 'c'),
        ];
        $this->assertSame(1, $wallet->calculateFee($proofs));
    }

    public function testCalculateFeeZeroCases(): void
    {
        $wallet = $this->walletWithKeysets();
        $this->assertSame(0, $wallet->calculateFee([]));
        $this->assertSame(0, $wallet->calculateFee([
            self::proof(self::KEYSET_FREE, 'x'),
            self::proof(self::KEYSET_FREE, 'y'),
        ]));
    }
}
