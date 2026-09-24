<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\CashuException;
use Cashu\Keyset;
use Cashu\MintClient;
use Cashu\Proof;
use Cashu\TokenSerializer;
use Cashu\Wallet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NUT-02 keyset ID V2 ("01" version byte) and NUT-13 HMAC-SHA256 derivation
 * against the official cashubtc/nuts test vectors
 * (tests/fixtures/nut02-keyset-ids-v2.json, nut13-derivation-v2.json).
 */
final class KeysetV2Test extends TestCase
{
    private const V2_ID = '015ba18a8adcd02e715a58358eb618da4a4b3791151a4bee5e968bb88406ccf76a';

    public static function v2KeysetVectors(): array
    {
        $out = [];
        foreach (cashu_fixture('nut02-keyset-ids-v2')['vectors'] as $v) {
            $out['keyset ' . substr($v['id'], 0, 18) . '… (' . count($v['keys']) . ' keys)'] = [$v];
        }
        return $out;
    }

    #[DataProvider('v2KeysetVectors')]
    public function testDeriveKeysetIdV2MatchesOfficialVectors(array $vector): void
    {
        $this->assertSame(
            $vector['id'],
            Keyset::deriveKeysetIdV2(
                $vector['keys'],
                $vector['unit'],
                $vector['input_fee_ppk'],
                $vector['final_expiry']
            )
        );
    }

    #[DataProvider('v2KeysetVectors')]
    public function testDeriveExpectedIdDispatchesToV2(array $vector): void
    {
        $keyset = new Keyset(
            $vector['id'],
            $vector['unit'],
            $vector['keys'],
            true,
            $vector['input_fee_ppk'],
            $vector['final_expiry']
        );
        $this->assertSame($vector['id'], $keyset->deriveExpectedId());
    }

    public function testDeriveExpectedIdDetectsTampering(): void
    {
        $vector = cashu_fixture('nut02-keyset-ids-v2')['vectors'][0];
        // A mint lying about its fee must produce a different (mismatching) ID.
        $keyset = new Keyset($vector['id'], $vector['unit'], $vector['keys'], true, 0, $vector['final_expiry']);
        $this->assertNotSame($vector['id'], $keyset->deriveExpectedId());
    }

    public function testHmacSecretDerivationMatchesOfficialVectors(): void
    {
        $fixture = cashu_fixture('nut13-derivation-v2');

        $wallet = new Wallet('https://example.com', 'sat');
        $wallet->initFromMnemonic($fixture['mnemonic']);

        foreach ($fixture['secrets'] as $counter => $expectedSecret) {
            $derived = $wallet->generateDeterministicSecret($fixture['keyset_id'], $counter);
            $this->assertSame($expectedSecret, $derived['secret'], "secret_$counter");
            $this->assertSame(
                $fixture['blinding_factors'][$counter],
                str_pad($derived['r']->toHex(), 64, '0', STR_PAD_LEFT),
                "r_$counter"
            );
        }
    }

    public function testV1DerivationIsUnchangedByVersionDispatch(): void
    {
        // The v1 fixture must still derive through the BIP32 path.
        $fixture = cashu_fixture('nut13-derivation');
        $wallet = new Wallet('https://example.com', 'sat');
        $wallet->initFromMnemonic($fixture['mnemonic']);
        $derived = $wallet->generateDeterministicSecret($fixture['keyset_id'], 0);
        $this->assertSame($fixture['secrets'][0], $derived['secret']);
    }

    public function testKeysetIdToIntRejectsV2Ids(): void
    {
        $wallet = new Wallet('https://example.com', 'sat');
        $this->expectException(CashuException::class);
        $wallet->keysetIdToInt(self::V2_ID);
    }

    public function testKeysetIdToIntRejectsGarbage(): void
    {
        $wallet = new Wallet('https://example.com', 'sat');
        $this->expectException(CashuException::class);
        $wallet->keysetIdToInt('definitely-not-a-keyset');
    }

    public function testUnknownHexVersionByteThrowsInsteadOfDerivingGarbage(): void
    {
        $wallet = new Wallet('https://example.com', 'sat');
        $wallet->initFromMnemonic(cashu_fixture('nut13-derivation-v2')['mnemonic']);
        $this->expectException(CashuException::class);
        $wallet->generateDeterministicSecret('02' . str_repeat('ab', 32), 0);
    }

    public function testIsHexKeysetIdAcceptsBothVersions(): void
    {
        $this->assertTrue(TokenSerializer::isHexKeysetId('00882760bfa2eb41'));
        $this->assertTrue(TokenSerializer::isHexKeysetId(self::V2_ID));
        $this->assertFalse(TokenSerializer::isHexKeysetId('c2aZ8gPfObGc'));       // legacy base64
        $this->assertFalse(TokenSerializer::isHexKeysetId(substr(self::V2_ID, 0, 64)));
        $this->assertFalse(TokenSerializer::isHexKeysetId(''));
    }

    // ------------------------------------------------------------------
    // NUT-00 short keyset ID resolution (first 8 bytes of a V2 ID)
    // ------------------------------------------------------------------

    private function walletWithKeysets(array $ids): Wallet
    {
        $wallet = new Wallet('https://example.com', 'sat');
        $keysets = array_map(fn($id) => new Keyset($id, 'sat', []), $ids);
        $prop = new \ReflectionProperty(Wallet::class, 'keysets');
        $prop->setValue($wallet, $keysets);
        return $wallet;
    }

    public function testShortKeysetIdResolvesToFullId(): void
    {
        $wallet = $this->walletWithKeysets([self::V2_ID]);
        $proof = new Proof(substr(self::V2_ID, 0, 16), 8, 'secret', str_repeat('02', 33));
        $wallet->resolveShortKeysetIds([$proof]);
        $this->assertSame(self::V2_ID, $proof->id);
    }

    public function testShortKeysetIdWithoutMatchThrows(): void
    {
        $wallet = $this->walletWithKeysets([self::V2_ID]);
        $proof = new Proof('01deadbeefdeadbe', 8, 'secret', str_repeat('02', 33));
        $this->expectException(CashuException::class);
        $wallet->resolveShortKeysetIds([$proof]);
    }

    public function testAmbiguousShortKeysetIdThrows(): void
    {
        $prefix = substr(self::V2_ID, 0, 16);
        $wallet = $this->walletWithKeysets([
            $prefix . str_repeat('aa', 25),
            $prefix . str_repeat('bb', 25),
        ]);
        $proof = new Proof($prefix, 8, 'secret', str_repeat('02', 33));
        $this->expectException(CashuException::class);
        $wallet->resolveShortKeysetIds([$proof]);
    }

    public function testFullAndV1IdsPassThroughUnchanged(): void
    {
        $wallet = $this->walletWithKeysets([self::V2_ID]);
        $v1 = new Proof('00882760bfa2eb41', 1, 's', str_repeat('02', 33));
        $full = new Proof(self::V2_ID, 1, 's', str_repeat('02', 33));
        $legacy = new Proof('c2aZ8gPfObGc', 1, 's', str_repeat('02', 33));
        $wallet->resolveShortKeysetIds([$v1, $full, $legacy]);
        $this->assertSame('00882760bfa2eb41', $v1->id);
        $this->assertSame(self::V2_ID, $full->id);
        $this->assertSame('c2aZ8gPfObGc', $legacy->id);
    }

    // ------------------------------------------------------------------
    // Inactive keysets: fee metadata and active selection
    // ------------------------------------------------------------------

    public function testInactiveKeysetFeesAreKnownAndActiveKeysetWins(): void
    {
        $inactive = new Keyset('00aaaaaaaaaaaaaa', 'sat', [], false, 200);
        $active = new Keyset('00bbbbbbbbbbbbbb', 'sat', [], true, 100);

        $wallet = new Wallet('https://example.com', 'sat');
        $prop = new \ReflectionProperty(Wallet::class, 'keysets');
        // Active-first ordering is what loadMint() produces.
        $prop->setValue($wallet, [$active, $inactive]);

        $this->assertSame('00bbbbbbbbbbbbbb', $wallet->getActiveKeysetId());
        $this->assertSame(200, $wallet->getInputFeePpk('00aaaaaaaaaaaaaa'));
        $this->assertSame(100, $wallet->getInputFeePpk('00bbbbbbbbbbbbbb'));

        // Fees for proofs from a rotated-out keyset must still be counted.
        $oldProof = new Proof('00aaaaaaaaaaaaaa', 64, 's', str_repeat('02', 33));
        $this->assertSame(1, $wallet->calculateFee([$oldProof])); // ceil(200/1000)
    }

    // ------------------------------------------------------------------
    // Keyset ID versions the wallet cannot derive (L-12)
    // ------------------------------------------------------------------

    private const V3_ID = '02aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testIdVersionRank(): void
    {
        $this->assertSame(2, Keyset::idVersionRank(self::V2_ID));
        $this->assertSame(2, Keyset::idVersionRank(strtoupper(self::V2_ID)));
        $this->assertSame(1, Keyset::idVersionRank('009a1f293253e41e'));
        $this->assertSame(1, Keyset::idVersionRank('c2aZ8gPfObGc'));
        $this->assertSame(0, Keyset::idVersionRank(self::V3_ID));
        $this->assertSame(0, Keyset::idVersionRank('02aaaaaaaaaaaaaa'));
        $this->assertSame(0, Keyset::idVersionRank(''));
    }

    public function testActiveSelectionSkipsUnknownVersionsAndPrefersV2(): void
    {
        $wallet = new Wallet('https://example.com', 'sat');
        $prop = new \ReflectionProperty(Wallet::class, 'keysets');

        $prop->setValue($wallet, [
            new Keyset(self::V3_ID, 'sat', [], true),
            new Keyset('00bbbbbbbbbbbbbb', 'sat', [], true),
            new Keyset(self::V2_ID, 'sat', [], true),
            new Keyset('00aaaaaaaaaaaaaa', 'sat', [], false),
        ]);
        $this->assertSame(self::V2_ID, $wallet->getActiveKeysetId());

        $prop->setValue($wallet, [
            new Keyset(self::V3_ID, 'sat', [], true),
            new Keyset(self::V2_ID, 'sat', [], false),
            new Keyset('00bbbbbbbbbbbbbb', 'sat', [], true),
        ]);
        $this->assertSame('00bbbbbbbbbbbbbb', $wallet->getActiveKeysetId(), 'inactive V2 is not a candidate');

        // Only an unusable keyset is active: fail clearly, but keep fee metadata for
        // any proofs of that keyset the wallet already holds.
        $prop->setValue($wallet, [
            new Keyset(self::V3_ID, 'sat', [], true, 300),
            new Keyset('00aaaaaaaaaaaaaa', 'sat', [], false),
        ]);
        $this->assertSame(300, $wallet->getInputFeePpk(self::V3_ID));
        $this->expectException(CashuException::class);
        $this->expectExceptionMessage('no active keyset with a supported ID version');
        $wallet->getActiveKeysetId();
    }

    public function testLoadMintNeverSelectsAnUnknownVersionKeyset(): void
    {
        $wallet = new Wallet('https://mint.example', 'sat');
        $client = $this->createStub(MintClient::class);
        $client->method('get')->willReturnCallback(fn(string $path): array => match ($path) {
            'info' => [],
            'keysets' => ['keysets' => [
                ['id' => self::V3_ID, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 100],
                ['id' => '00bbbbbbbbbbbbbb', 'unit' => 'sat', 'active' => true],
            ]],
            'keys' => ['keysets' => []],
        });
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $client);

        $wallet->loadMint();

        $this->assertSame('00bbbbbbbbbbbbbb', $wallet->getActiveKeysetId());
        $this->assertSame(100, $wallet->getInputFeePpk(self::V3_ID));
    }

    /** One keyset of an unknown ID version must not abort the whole restore (N8). */
    public function testRestoreSkipsKeysetsWithUnknownIdVersion(): void
    {
        $wallet = new Wallet('https://mint.example', 'sat');
        $wallet->initFromMnemonic(cashu_fixture('nut13-derivation')['mnemonic']);
        $gets = [];
        $restored = [];
        $client = $this->createStub(MintClient::class);
        $client->method('get')->willReturnCallback(function (string $path) use (&$gets): array {
            $gets[] = $path;
            return match ($path) {
                'keysets' => ['keysets' => [
                    ['id' => self::V3_ID, 'unit' => 'sat', 'active' => true],
                    ['id' => '009a1f293253e41e', 'unit' => 'sat', 'active' => true],
                ]],
                'keys/009a1f293253e41e' => ['keysets' => [[
                    'id' => '009a1f293253e41e', 'unit' => 'sat',
                    'keys' => ['1' => '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798'],
                ]]],
            };
        });
        $client->method('post')->willReturnCallback(function (string $path, array $data) use (&$restored): array {
            $restored[] = $data['outputs'][0]['id'];
            return ['outputs' => [], 'signatures' => []];
        });
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $client);

        $result = $wallet->restore(2, 1);

        $this->assertFalse($result['incomplete']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([self::V3_ID], $result['skippedKeysets']);
        $this->assertSame(['keysets', 'keys/009a1f293253e41e'], $gets);
        $this->assertSame(['009a1f293253e41e'], $restored);
    }
}
