<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\Crypto;
use Cashu\DLEQWallet;
use Cashu\Proof;
use Cashu\Secp256k1;
use PHPUnit\Framework\TestCase;

/**
 * NUT-12 DLEQ verification against the official cashubtc/nuts test vectors
 * (tests/fixtures/nut12-dleq.json).
 */
final class DleqTest extends TestCase
{
    public function testHashEMatchesOfficialVector(): void
    {
        $vector = cashu_fixture('nut12-dleq')['hash_e'];
        $points = array_map(
            fn($hex) => Secp256k1::decompressPoint(hex2bin($hex)),
            $vector['points']
        );
        $this->assertSame($vector['digest'], Crypto::hashE(...$points));
    }

    public function testBlindSignatureDleqVerifies(): void
    {
        $v = cashu_fixture('nut12-dleq')['blind_signature'];
        $this->assertTrue(Crypto::verifyDleq($v['e'], $v['s'], $v['A'], $v['B_'], $v['C_']));
    }

    public function testDeterministicNonceVectorVerifies(): void
    {
        $v = cashu_fixture('nut12-dleq')['deterministic_nonce'];
        $this->assertTrue(Crypto::verifyDleq($v['e'], $v['s'], $v['A'], $v['B_'], $v['C_']));
    }

    public function testTamperedChallengeIsRejected(): void
    {
        $v = cashu_fixture('nut12-dleq')['blind_signature'];
        $tampered = substr($v['e'], 0, -1) . ($v['e'][-1] === '0' ? '1' : '0');
        $this->assertFalse(Crypto::verifyDleq($tampered, $v['s'], $v['A'], $v['B_'], $v['C_']));
    }

    public function testWrongMintKeyIsRejected(): void
    {
        $v = cashu_fixture('nut12-dleq')['blind_signature'];
        $wrongA = cashu_fixture('nut12-dleq')['deterministic_nonce']['A'];
        $this->assertFalse(Crypto::verifyDleq($v['e'], $v['s'], $wrongA, $v['B_'], $v['C_']));
    }

    public function testMalformedInputsAreRejectedNotFatal(): void
    {
        $v = cashu_fixture('nut12-dleq')['blind_signature'];
        $this->assertFalse(Crypto::verifyDleq('zz', $v['s'], $v['A'], $v['B_'], $v['C_']));
        $this->assertFalse(Crypto::verifyDleq($v['e'], $v['s'], '02abcd', $v['B_'], $v['C_']));
    }

    public function testProofDleqVerifies(): void
    {
        $v = cashu_fixture('nut12-dleq')['proof'];
        $proof = new Proof(
            $v['id'],
            $v['amount'],
            $v['secret'],
            $v['C'],
            new DLEQWallet($v['dleq']['e'], $v['dleq']['s'], $v['dleq']['r'])
        );
        $this->assertTrue(Crypto::verifyProofDleq($proof, $v['A']));
    }

    public function testProofDleqRejectsTamperedSecret(): void
    {
        $v = cashu_fixture('nut12-dleq')['proof'];
        $proof = new Proof(
            $v['id'],
            $v['amount'],
            'deadbeef' . substr($v['secret'], 8),
            $v['C'],
            new DLEQWallet($v['dleq']['e'], $v['dleq']['s'], $v['dleq']['r'])
        );
        $this->assertFalse(Crypto::verifyProofDleq($proof, $v['A']));
    }

    public function testProofWithoutBlindingFactorIsNotVerifiable(): void
    {
        $v = cashu_fixture('nut12-dleq')['proof'];
        $proof = new Proof(
            $v['id'],
            $v['amount'],
            $v['secret'],
            $v['C'],
            new DLEQWallet($v['dleq']['e'], $v['dleq']['s'], null)
        );
        $this->assertFalse(Crypto::verifyProofDleq($proof, $v['A']));
    }
}
