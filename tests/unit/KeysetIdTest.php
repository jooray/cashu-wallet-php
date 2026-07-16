<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\Keyset;
use Cashu\TokenSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NUT-02 keyset ID derivation against the official cashubtc/nuts test vectors
 * (tests/fixtures/nut02-keyset-ids.json, version-1 IDs).
 */
final class KeysetIdTest extends TestCase
{
    public static function keysetVectors(): array
    {
        $out = [];
        foreach (cashu_fixture('nut02-keyset-ids')['vectors'] as $v) {
            $out['keyset ' . $v['id'] . ' (' . count($v['keys']) . ' keys)'] = [$v['id'], $v['keys']];
        }
        return $out;
    }

    #[DataProvider('keysetVectors')]
    public function testDeriveKeysetIdMatchesOfficialVectors(string $expectedId, array $keys): void
    {
        $this->assertSame($expectedId, Keyset::deriveKeysetId($keys));
    }

    #[DataProvider('keysetVectors')]
    public function testDerivationIsIndependentOfKeyOrder(string $expectedId, array $keys): void
    {
        // Shuffle preserving amount => key association
        $amounts = array_keys($keys);
        shuffle($amounts);
        $shuffled = [];
        foreach ($amounts as $amount) {
            $shuffled[$amount] = $keys[$amount];
        }

        $this->assertSame($expectedId, Keyset::deriveKeysetId($shuffled));
    }

    public function testIsHexKeysetId(): void
    {
        $this->assertTrue(TokenSerializer::isHexKeysetId('009a1f293253e41e'));
        $this->assertTrue(TokenSerializer::isHexKeysetId('00456a94ab4e1c46'));
        $this->assertFalse(TokenSerializer::isHexKeysetId('009a1f293253e41'), 'too short');
        $this->assertFalse(TokenSerializer::isHexKeysetId('009a1f293253e41ef'), 'too long');
        $this->assertFalse(TokenSerializer::isHexKeysetId('xyz11f293253e41e'), 'not hex');
        $this->assertFalse(TokenSerializer::isHexKeysetId('yTNjNzWzGr6nvqcRvt2V'), 'legacy base64 id');
    }
}
