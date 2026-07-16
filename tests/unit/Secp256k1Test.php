<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\BigInt;
use Cashu\CashuException;
use Cashu\Secp256k1;
use PHPUnit\Framework\TestCase;

final class Secp256k1Test extends TestCase
{
    private const G_COMPRESSED = '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    // Well-known value of 2*G on secp256k1
    private const TWO_G_COMPRESSED = '02c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5';

    public function testGeneratorIsOnCurveAndCompressesToKnownValue(): void
    {
        $G = Secp256k1::getGenerator();
        $this->assertTrue(Secp256k1::isOnCurve($G));
        $this->assertSame(self::G_COMPRESSED, bin2hex(Secp256k1::compressPoint($G)));
    }

    public function testScalarMultTwoGMatchesKnownValue(): void
    {
        $twoG = Secp256k1::scalarMult(BigInt::fromDec(2), Secp256k1::getGenerator());
        $this->assertSame(self::TWO_G_COMPRESSED, bin2hex(Secp256k1::compressPoint($twoG)));
    }

    public function testPointAddGPlusGEqualsScalarMultTwoG(): void
    {
        $G = Secp256k1::getGenerator();
        $sum = Secp256k1::pointAdd($G, $G);
        $this->assertSame(self::TWO_G_COMPRESSED, bin2hex(Secp256k1::compressPoint($sum)));
    }

    public function testOrderMinusOneTimesGIsNegatedGenerator(): void
    {
        $nMinusOne = Secp256k1::getOrder()->sub(BigInt::one());
        $point = Secp256k1::scalarMult($nMinusOne, Secp256k1::getGenerator());

        // (n-1)*G = -G: same x coordinate, odd-y prefix
        $this->assertSame(
            '03' . substr(self::G_COMPRESSED, 2),
            bin2hex(Secp256k1::compressPoint($point))
        );
    }

    public function testPointAddWithInfinityReturnsOtherPoint(): void
    {
        $G = Secp256k1::getGenerator();
        $this->assertSame($G, Secp256k1::pointAdd(null, $G));
        $this->assertSame($G, Secp256k1::pointAdd($G, null));
        $this->assertNull(Secp256k1::pointAdd(null, null));
    }

    public function testPointPlusNegationIsInfinity(): void
    {
        $G = Secp256k1::getGenerator();
        $this->assertNull(Secp256k1::pointAdd($G, Secp256k1::pointNegate($G)));
        $this->assertNull(Secp256k1::pointSub($G, $G));
    }

    public function testCompressDecompressRoundtrip(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $k = Secp256k1::randomScalar();
            $point = Secp256k1::scalarMult($k, Secp256k1::getGenerator());
            $compressed = Secp256k1::compressPoint($point);

            $this->assertSame(33, strlen($compressed));
            $decompressed = Secp256k1::decompressPoint($compressed);
            $this->assertSame(0, $point[0]->cmp($decompressed[0]), 'x mismatch');
            $this->assertSame(0, $point[1]->cmp($decompressed[1]), 'y mismatch');
        }
    }

    public function testDecompressRejectsWrongLength(): void
    {
        $this->expectException(CashuException::class);
        Secp256k1::decompressPoint("\x02" . str_repeat("\x11", 16));
    }

    public function testDecompressRejectsInvalidPrefix(): void
    {
        $this->expectException(CashuException::class);
        Secp256k1::decompressPoint("\x04" . str_repeat("\x11", 32));
    }

    public function testDecompressRejectsXNotOnCurve(): void
    {
        // x = 0 has no square root solution for y^2 = 7 mod p
        $this->expectException(CashuException::class);
        Secp256k1::decompressPoint("\x02" . str_repeat("\x00", 32));
    }

    public function testIsOnCurveRejectsBogusPoint(): void
    {
        $this->assertFalse(Secp256k1::isOnCurve([BigInt::fromDec(1), BigInt::fromDec(1)]));
    }

    public function testScalarToHexPadsTo64Chars(): void
    {
        $this->assertSame(str_repeat('0', 63) . '5', Secp256k1::scalarToHex(BigInt::fromDec(5)));
        $roundtrip = Secp256k1::hexToScalar(Secp256k1::scalarToHex(BigInt::fromDec(12345)));
        $this->assertSame('12345', $roundtrip->toDec());
    }

    public function testRandomScalarIsInRange(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $k = Secp256k1::randomScalar();
            $this->assertFalse($k->isZero());
            $this->assertLessThan(0, $k->cmp(Secp256k1::getOrder()));
        }
    }
}
