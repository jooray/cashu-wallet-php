<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\BigInt;
use Cashu\Crypto;
use Cashu\Secp256k1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NUT-00 BDHKE crypto against the official cashubtc/nuts test vectors
 * (tests/fixtures/nut00-bdhke.json).
 */
final class BdhkeTest extends TestCase
{
    public static function hashToCurveVectors(): array
    {
        $out = [];
        foreach (cashu_fixture('nut00-bdhke')['hash_to_curve'] as $i => $v) {
            $out["vector $i"] = [$v['message'], $v['point']];
        }
        return $out;
    }

    #[DataProvider('hashToCurveVectors')]
    public function testHashToCurveMatchesOfficialVectors(string $messageHex, string $expectedPoint): void
    {
        $point = Crypto::hashToCurve(hex2bin($messageHex));
        $this->assertSame($expectedPoint, bin2hex(Secp256k1::compressPoint($point)));
    }

    #[DataProvider('hashToCurveVectors')]
    public function testComputeYIsCompressedHashToCurve(string $messageHex, string $expectedPoint): void
    {
        $this->assertSame($expectedPoint, Crypto::computeY(hex2bin($messageHex)));
    }

    public static function blindedMessageVectors(): array
    {
        $out = [];
        foreach (cashu_fixture('nut00-bdhke')['blinded_messages'] as $i => $v) {
            $out["vector $i"] = [$v['x'], $v['r'], $v['B_']];
        }
        return $out;
    }

    /**
     * B_ = Y + r*G where Y = hash_to_curve(x). The official vectors give the
     * blinding factor r, so the point arithmetic is verified deterministically.
     * Note: x in the vectors is a hex-encoded byte array (the raw secret bytes).
     */
    #[DataProvider('blindedMessageVectors')]
    public function testBlindedMessageMatchesOfficialVectors(string $xHex, string $rHex, string $expectedB): void
    {
        $Y = Crypto::hashToCurve(hex2bin($xHex));
        $r = BigInt::fromHex($rHex);
        $rG = Secp256k1::scalarMult($r, Secp256k1::getGenerator());
        $B = Secp256k1::pointAdd($Y, $rG);

        $this->assertSame($expectedB, bin2hex(Secp256k1::compressPoint($B)));
    }

    public static function blindSignatureVectors(): array
    {
        $out = [];
        foreach (cashu_fixture('nut00-bdhke')['blind_signatures'] as $i => $v) {
            $out["vector $i"] = [$v['mint_privkey'], $v['B_'], $v['C_']];
        }
        return $out;
    }

    /** C_ = k * B_ where k is the mint private key. */
    #[DataProvider('blindSignatureVectors')]
    public function testBlindSignatureMatchesOfficialVectors(string $kHex, string $bHex, string $expectedC): void
    {
        $k = BigInt::fromHex($kHex);
        $B = Secp256k1::decompressPoint(hex2bin($bHex));
        $C = Secp256k1::scalarMult($k, $B);

        $this->assertSame($expectedC, bin2hex(Secp256k1::compressPoint($C)));
    }

    /**
     * Full BDHKE cycle over official inputs: blind with the vector's (x, r),
     * sign with the vector mint key, unblind, and confirm C == k*hash_to_curve(x).
     */
    public function testUnblindSignatureRecoversMintSignatureOnSecret(): void
    {
        $fixture = cashu_fixture('nut00-bdhke');
        $bm = $fixture['blinded_messages'][0];
        $kHex = $fixture['blind_signatures'][1]['mint_privkey']; // 7f7f...7f

        $k = BigInt::fromHex($kHex);
        $G = Secp256k1::getGenerator();
        $A = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, $G)));

        // Mint signs the blinded message from the official vector
        $B = Secp256k1::decompressPoint(hex2bin($bm['B_']));
        $C_ = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, $B)));

        $C = Crypto::unblindSignature($C_, BigInt::fromHex($bm['r']), $A);

        // C must equal k * hash_to_curve(x): the unblinded signature on the secret
        $Y = Crypto::hashToCurve(hex2bin($bm['x']));
        $expected = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, $Y)));
        $this->assertSame($expected, $C);
    }

    public function testCreateBlindedMessageUnblindsToMintSignature(): void
    {
        $secret = Crypto::generateSecret();
        $blinded = Crypto::createBlindedMessage($secret);

        // Simulate a mint with private key k
        $k = BigInt::fromHex(str_repeat('42', 32));
        $G = Secp256k1::getGenerator();
        $A = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, $G)));

        $B = Secp256k1::decompressPoint(hex2bin($blinded['B_']));
        $C_ = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, $B)));

        $C = Crypto::unblindSignature($C_, $blinded['r'], $A);

        $Y = Crypto::hashToCurve($secret);
        $expected = bin2hex(Secp256k1::compressPoint(Secp256k1::scalarMult($k, $Y)));
        $this->assertSame($expected, $C);
    }

    public function testGenerateSecretIs32RandomBytesHexEncoded(): void
    {
        $a = Crypto::generateSecret();
        $b = Crypto::generateSecret();

        $this->assertSame(64, strlen($a));
        $this->assertTrue(ctype_xdigit($a));
        $this->assertNotSame($a, $b);
    }

    public function testGenerateBlindingFactorIsValidScalar(): void
    {
        $r = Crypto::generateBlindingFactor();
        $this->assertFalse($r->isZero());
        $this->assertLessThan(0, $r->cmp(Secp256k1::getOrder()));
    }
}
