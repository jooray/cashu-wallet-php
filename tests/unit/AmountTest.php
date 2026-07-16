<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\InsufficientBalanceException;
use Cashu\Proof;
use Cashu\Wallet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    private static function proof(int $amount, string $secret): Proof
    {
        return new Proof('009a1f293253e41e', $amount, $secret, '02' . str_repeat('11', 32));
    }

    public static function splitCases(): array
    {
        return [
            'zero' => [0, []],
            'one' => [1, [1]],
            'power of two' => [64, [64]],
            'all ones' => [63, [1, 2, 4, 8, 16, 32]],
            'mixed' => [2000, [16, 64, 128, 256, 512, 1024]],
            'typical' => [10, [2, 8]],
        ];
    }

    #[DataProvider('splitCases')]
    public function testSplitAmount(int $amount, array $expected): void
    {
        $this->assertSame($expected, Wallet::splitAmount($amount));
    }

    public function testSplitAmountInvariants(): void
    {
        for ($amount = 0; $amount <= 300; $amount++) {
            $parts = Wallet::splitAmount($amount);

            $this->assertSame($amount, array_sum($parts), "sum mismatch for $amount");
            $sorted = $parts;
            sort($sorted);
            $this->assertSame($sorted, $parts, "not ascending for $amount");
            foreach ($parts as $p) {
                $this->assertSame(0, $p & ($p - 1), "$p is not a power of two (amount $amount)");
            }
            $this->assertSame(count(array_unique($parts)), count($parts), "duplicate denominations for $amount");
        }
    }

    public function testSumProofs(): void
    {
        $this->assertSame(0, Wallet::sumProofs([]));
        $this->assertSame(7, Wallet::sumProofs([self::proof(1, 'a'), self::proof(2, 'b'), self::proof(4, 'c')]));
    }

    public function testSelectProofsPicksLargestFirst(): void
    {
        $proofs = [self::proof(1, 'a'), self::proof(8, 'b'), self::proof(2, 'c'), self::proof(4, 'd')];

        // Greedy selection: proofs are taken largest-first until the target is met
        $selected = Wallet::selectProofs($proofs, 9);
        $this->assertSame([8, 4], array_map(fn($p) => $p->amount, $selected));
    }

    public function testSelectProofsExactMatch(): void
    {
        $selected = Wallet::selectProofs([self::proof(4, 'a'), self::proof(2, 'b')], 6);
        $this->assertSame(6, Wallet::sumProofs($selected));
    }

    public function testSelectProofsThrowsOnInsufficientBalance(): void
    {
        $this->expectException(InsufficientBalanceException::class);
        Wallet::selectProofs([self::proof(1, 'a'), self::proof(2, 'b')], 10);
    }
}
