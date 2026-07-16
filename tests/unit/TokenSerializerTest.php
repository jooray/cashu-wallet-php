<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\CashuException;
use Cashu\CBOR;
use Cashu\DLEQWallet;
use Cashu\Proof;
use Cashu\Token;
use Cashu\TokenSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Token serialization (V3 "cashuA" and V4 "cashuB") against the official
 * cashubtc/nuts NUT-00 test vectors (tests/fixtures/nut00-token-v[34].json).
 */
final class TokenSerializerTest extends TestCase
{
    /**
     * @param Proof[] $expected
     * @param Proof[] $actual
     */
    private function assertSameProofs(array $expected, array $actual): void
    {
        $this->assertCount(count($expected), $actual);
        foreach ($expected as $i => $p) {
            $this->assertSame($p->id, $actual[$i]->id);
            $this->assertSame($p->amount, $actual[$i]->amount);
            $this->assertSame($p->secret, $actual[$i]->secret);
            $this->assertSame($p->C, $actual[$i]->C);
        }
    }

    // ------------------------------------------------------------------ V3

    public function testDeserializeOfficialV3Token(): void
    {
        $fixture = cashu_fixture('nut00-token-v3');
        $vector = $fixture['valid'][0];

        $token = TokenSerializer::deserialize($vector['serialized']);

        $expected = $vector['decoded'];
        $this->assertSame($expected['token'][0]['mint'], $token->mint);
        $this->assertSame($expected['unit'], $token->unit);
        $this->assertSame($expected['memo'], $token->memo);

        $expectedProofs = $expected['token'][0]['proofs'];
        $this->assertCount(count($expectedProofs), $token->proofs);
        foreach ($expectedProofs as $i => $p) {
            $this->assertSame($p['id'], $token->proofs[$i]->id);
            $this->assertSame($p['amount'], $token->proofs[$i]->amount);
            $this->assertSame($p['secret'], $token->proofs[$i]->secret);
            $this->assertSame($p['C'], $token->proofs[$i]->C);
        }

        $this->assertSame(10, $token->getAmount());
        $this->assertSame(['009a1f293253e41e'], $token->getKeysets());
    }

    public static function invalidV3Vectors(): array
    {
        $out = [];
        foreach (cashu_fixture('nut00-token-v3')['invalid'] as $v) {
            $out[$v['reason']] = [$v['serialized']];
        }
        return $out;
    }

    #[DataProvider('invalidV3Vectors')]
    public function testOfficialInvalidV3TokensAreRejected(string $serialized): void
    {
        $this->expectException(CashuException::class);
        TokenSerializer::deserialize($serialized);
    }

    public function testV3PaddingVariantsBothDeserialize(): void
    {
        $fixture = cashu_fixture('nut00-token-v3');
        [$padded, $unpadded] = $fixture['padding_variants'];

        $a = TokenSerializer::deserialize($padded['serialized']);
        $b = TokenSerializer::deserialize($unpadded['serialized']);

        $this->assertSame('Thank you very much.', $a->memo);
        $this->assertSame('Thank you very much.', $b->memo);
        $this->assertSame($a->proofs[0]->secret, $b->proofs[0]->secret);
        $this->assertSame(10, $a->getAmount());
        $this->assertSame(10, $b->getAmount());
    }

    public function testSerializeV3OfficialTokenRoundtripsToSameData(): void
    {
        $fixture = cashu_fixture('nut00-token-v3');
        $original = TokenSerializer::deserialize($fixture['valid'][0]['serialized']);

        $serialized = TokenSerializer::serializeV3(
            $original->mint,
            $original->proofs,
            $original->unit,
            $original->memo
        );

        $this->assertStringStartsWith('cashuA', $serialized);
        $this->assertMatchesRegularExpression('/^cashuA[A-Za-z0-9_-]+$/', $serialized, 'must be url-safe base64 without padding');

        $roundtrip = TokenSerializer::deserialize($serialized);
        $this->assertSame($original->mint, $roundtrip->mint);
        $this->assertSame($original->unit, $roundtrip->unit);
        $this->assertSame($original->memo, $roundtrip->memo);
        $this->assertSameProofs($original->proofs, $roundtrip->proofs);
    }

    // ------------------------------------------------------------------ V4

    public static function validV4Vectors(): array
    {
        $out = [];
        foreach (cashu_fixture('nut00-token-v4')['valid'] as $i => $v) {
            $out[$i === 0 ? 'single keyset' : 'multiple keysets'] = [$v['serialized'], $v['decoded']];
        }
        return $out;
    }

    #[DataProvider('validV4Vectors')]
    public function testDeserializeOfficialV4Tokens(string $serialized, array $expected): void
    {
        $token = TokenSerializer::deserialize($serialized);

        $this->assertSame($expected['mint'], $token->mint);
        $this->assertSame($expected['unit'], $token->unit);
        $this->assertSame($expected['memo'], $token->memo);

        $this->assertCount(count($expected['proofs']), $token->proofs);
        foreach ($expected['proofs'] as $i => $p) {
            $this->assertSame($p['id'], $token->proofs[$i]->id);
            $this->assertSame($p['amount'], $token->proofs[$i]->amount);
            $this->assertSame($p['secret'], $token->proofs[$i]->secret);
            $this->assertSame($p['C'], $token->proofs[$i]->C);
        }
    }

    public function testSerializeV4OfficialTokenRoundtripsToSameData(): void
    {
        $fixture = cashu_fixture('nut00-token-v4');
        foreach ($fixture['valid'] as $vector) {
            $original = TokenSerializer::deserialize($vector['serialized']);

            $serialized = TokenSerializer::serializeV4(
                $original->mint,
                $original->proofs,
                $original->unit,
                $original->memo
            );
            $this->assertStringStartsWith('cashuB', $serialized);

            $roundtrip = TokenSerializer::deserialize($serialized);
            $this->assertSame($original->mint, $roundtrip->mint);
            $this->assertSame($original->unit, $roundtrip->unit);
            $this->assertSame($original->memo, $roundtrip->memo);
            $this->assertSameProofs($original->proofs, $roundtrip->proofs);
        }
    }

    public function testOfficialRawBinaryV4TokenDecodes(): void
    {
        $raw = hex2bin(cashu_fixture('nut00-token-v4')['raw_binary_hex']);

        // Raw binary framing is "craw" + "B" version byte, then CBOR
        $this->assertSame('crawB', substr($raw, 0, 5));

        $data = CBOR::decode(substr($raw, 5));
        $expected = cashu_fixture('nut00-token-v4')['valid'][0]['decoded'];

        $this->assertSame($expected['mint'], $data['m']);
        $this->assertSame($expected['unit'], $data['u']);
        $this->assertSame($expected['memo'], $data['d']);
        $this->assertSame($expected['proofs'][0]['id'], bin2hex($data['t'][0]['i']));
        $this->assertSame($expected['proofs'][0]['amount'], $data['t'][0]['p'][0]['a']);
        $this->assertSame($expected['proofs'][0]['secret'], $data['t'][0]['p'][0]['s']);
        $this->assertSame($expected['proofs'][0]['C'], bin2hex($data['t'][0]['p'][0]['c']));
    }

    public function testV4RoundtripPreservesDleqAndWitness(): void
    {
        $proof = new Proof(
            '00ad268c4d1f5826',
            8,
            '9a6dbb847bd232ba76db0df197216b29d3b8cc14553cd27827fc1cc942fedb4e',
            '038618543ffb6b8695df4ad4babcde92a34a96bdcd97dcee0d7ccf98d472126792',
            new DLEQWallet(str_repeat('11', 32), str_repeat('22', 32), str_repeat('33', 32)),
            '{"signatures":["deadbeef"]}'
        );

        $serialized = TokenSerializer::serializeV4('https://mint.example', [$proof], 'sat', null, true);
        $roundtrip = TokenSerializer::deserialize($serialized);

        $this->assertCount(1, $roundtrip->proofs);
        $rt = $roundtrip->proofs[0];
        $this->assertSame($proof->secret, $rt->secret);
        $this->assertNotNull($rt->dleq);
        $this->assertSame($proof->dleq->e, $rt->dleq->e);
        $this->assertSame($proof->dleq->s, $rt->dleq->s);
        $this->assertSame($proof->dleq->r, $rt->dleq->r);
        $this->assertSame($proof->witness, $rt->witness);
    }

    public function testSerializeV4GroupsProofsByKeyset(): void
    {
        $fixture = cashu_fixture('nut00-token-v4');
        $multi = TokenSerializer::deserialize($fixture['valid'][1]['serialized']);
        $this->assertSame(['00ffd48b8f5ecf80', '00ad268c4d1f5826'], array_values($multi->getKeysets()));

        // Re-serialize and confirm grouping survives
        $roundtrip = TokenSerializer::deserialize(
            TokenSerializer::serializeV4($multi->mint, $multi->proofs, $multi->unit)
        );
        $this->assertSame(4, $roundtrip->getAmount());
        $this->assertCount(2, $roundtrip->getKeysets());
    }

    // ------------------------------------------------------- error handling

    public function testDeserializeRejectsUnknownFormat(): void
    {
        $this->expectException(CashuException::class);
        TokenSerializer::deserialize('garbage-token');
    }

    public function testDeserializeRejectsEmptyString(): void
    {
        $this->expectException(CashuException::class);
        TokenSerializer::deserialize('');
    }

    public function testDeserializeRejectsGarbageV3Payload(): void
    {
        $this->expectException(CashuException::class);
        TokenSerializer::deserialize('cashuA!!!not-base64!!!');
    }

    public function testDeserializeRejectsTruncatedV4Cbor(): void
    {
        // Valid base64 that decodes to a truncated CBOR document
        $this->expectException(CashuException::class);
        TokenSerializer::deserialize('cashuB' . rtrim(strtr(base64_encode("\xa4\x61"), '+/', '-_'), '='));
    }

    // ----------------------------------------------------------- Proof data

    public function testProofArrayRoundtripWithDleqAndWitness(): void
    {
        $data = [
            'id' => '009a1f293253e41e',
            'amount' => 2,
            'secret' => '407915bc212be61a77e3e6d2aeb4c727980bda51cd06a6afc29e2861768a7837',
            'C' => '02bc9097997d81afb2cc7346b5e4345a9346bd2a506eb7958598a72f0cf85163ea',
            'dleq' => ['e' => str_repeat('aa', 32), 's' => str_repeat('bb', 32), 'r' => str_repeat('cc', 32)],
            'witness' => 'wit',
        ];

        $proof = Proof::fromArray($data);
        $this->assertSame($data['dleq'], $proof->toArray(true)['dleq']);
        $this->assertArrayNotHasKey('dleq', $proof->toArray(), 'DLEQ must be omitted by default');
        $this->assertSame('wit', $proof->toArray()['witness']);

        // Y is computed from the secret on construction (NUT-00 hash_to_curve)
        $this->assertSame(\Cashu\Crypto::computeY($data['secret']), $proof->Y);
    }
}
