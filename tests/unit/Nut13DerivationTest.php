<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\CashuException;
use Cashu\Crypto;
use Cashu\Secp256k1;
use Cashu\Wallet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NUT-13 deterministic secret derivation against the official cashubtc/nuts
 * test vectors (tests/fixtures/nut13-derivation.json). This underpins wallet
 * restore/recovery, so exact matches are critical.
 *
 * Only version-1 (00-prefixed, 8-byte) keyset ID vectors apply; the library
 * does not implement v2 (33-byte) keyset IDs.
 */
final class Nut13DerivationTest extends TestCase
{
    private static function seededWallet(): Wallet
    {
        // No storage: seed-only initialization is the supported path for
        // derivation and restore workflows (spending stays disabled).
        $wallet = new Wallet('https://mint.example');
        $wallet->initFromMnemonic(cashu_fixture('nut13-derivation')['mnemonic']);
        return $wallet;
    }

    public function testKeysetIdToIntMatchesOfficialVector(): void
    {
        $fixture = cashu_fixture('nut13-derivation');
        $wallet = new Wallet('https://mint.example');
        $this->assertSame(
            $fixture['keyset_id_int'],
            $wallet->keysetIdToInt($fixture['keyset_id'])
        );
    }

    public static function counterVectors(): array
    {
        $fixture = cashu_fixture('nut13-derivation');
        $out = [];
        foreach ($fixture['secrets'] as $counter => $secret) {
            $out["counter $counter"] = [$counter, $secret, $fixture['blinding_factors'][$counter]];
        }
        return $out;
    }

    #[DataProvider('counterVectors')]
    public function testDeterministicSecretsMatchOfficialVectors(int $counter, string $expectedSecret, string $expectedR): void
    {
        $fixture = cashu_fixture('nut13-derivation');
        $derived = self::seededWallet()->generateDeterministicSecret($fixture['keyset_id'], $counter);

        $this->assertSame($expectedSecret, $derived['secret']);
        $this->assertSame($expectedR, Secp256k1::scalarToHex($derived['r']));
    }

    #[DataProvider('counterVectors')]
    public function testDeterministicBlindedMessageIsBlindingOfDerivedSecret(int $counter, string $expectedSecret, string $expectedR): void
    {
        $fixture = cashu_fixture('nut13-derivation');
        $blinded = self::seededWallet()->createDeterministicBlindedMessage($fixture['keyset_id'], $counter);

        $this->assertSame($expectedSecret, $blinded['secret']);

        // B_ must equal hash_to_curve(secret) + r*G for the official (secret, r)
        $Y = Crypto::hashToCurve($expectedSecret);
        $rG = Secp256k1::scalarMult($blinded['r'], Secp256k1::getGenerator());
        $expectedB = bin2hex(Secp256k1::compressPoint(Secp256k1::pointAdd($Y, $rG)));
        $this->assertSame($expectedB, $blinded['B_']);
        $this->assertSame($expectedR, Secp256k1::scalarToHex($blinded['r']));
    }

    public function testDerivationIsDeterministicAcrossWalletInstances(): void
    {
        $fixture = cashu_fixture('nut13-derivation');
        $a = self::seededWallet()->generateDeterministicSecret($fixture['keyset_id'], 7);
        $b = self::seededWallet()->generateDeterministicSecret($fixture['keyset_id'], 7);

        $this->assertSame($a['secret'], $b['secret']);
        $this->assertSame(0, $a['r']->cmp($b['r']));
    }

    public function testDifferentCountersAndKeysetsYieldDifferentSecrets(): void
    {
        $fixture = cashu_fixture('nut13-derivation');
        $wallet = self::seededWallet();

        $s0 = $wallet->generateDeterministicSecret($fixture['keyset_id'], 0)['secret'];
        $s1 = $wallet->generateDeterministicSecret($fixture['keyset_id'], 1)['secret'];
        $other = $wallet->generateDeterministicSecret('00ad268c4d1f5826', 0)['secret'];

        $this->assertNotSame($s0, $s1);
        $this->assertNotSame($s0, $other);
    }

    public function testGenerateDeterministicSecretRequiresSeed(): void
    {
        $wallet = new Wallet('https://mint.example');
        $this->expectException(CashuException::class);
        $wallet->generateDeterministicSecret('009a1f293253e41e', 0);
    }
}
