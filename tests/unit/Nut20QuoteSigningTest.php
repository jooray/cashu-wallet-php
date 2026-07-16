<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\Secp256k1;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * NUT-20 quote locking and BIP340 Schnorr signatures against the official
 * cashubtc/nuts and BIP340 test vectors
 * (tests/fixtures/nut20-quote-signing.json).
 */
final class Nut20QuoteSigningTest extends TestCase
{
    // ------------------------------------------------------------------
    // BIP340 Schnorr primitives
    // ------------------------------------------------------------------

    public function testSchnorrSignMatchesBip340Vector0(): void
    {
        $v = cashu_fixture('nut20-quote-signing')['bip340']['sign_vector0'];
        $sig = Secp256k1::schnorrSign($v['secret_key'], hex2bin($v['message']));
        $this->assertSame(strtolower($v['signature']), $sig);
        $this->assertTrue(Secp256k1::schnorrVerify($v['pubkey_x'], hex2bin($v['message']), $sig));
    }

    public function testSchnorrVerifyBip340Vector4(): void
    {
        $v = cashu_fixture('nut20-quote-signing')['bip340']['verify_vector4'];
        $this->assertTrue(Secp256k1::schnorrVerify($v['pubkey_x'], hex2bin($v['message']), strtolower($v['signature'])));
    }

    public function testSchnorrVerifyRejectsTampering(): void
    {
        $v = cashu_fixture('nut20-quote-signing')['bip340']['sign_vector0'];
        $sig = strtolower($v['signature']);
        $bad = substr($sig, 0, -1) . ($sig[-1] === '0' ? '1' : '0');
        $this->assertFalse(Secp256k1::schnorrVerify($v['pubkey_x'], hex2bin($v['message']), $bad));
        // Wrong message
        $this->assertFalse(Secp256k1::schnorrVerify($v['pubkey_x'], str_repeat("\x01", 32), $sig));
        // Malformed inputs are rejected, not fatal
        $this->assertFalse(Secp256k1::schnorrVerify('zz', hex2bin($v['message']), $sig));
        $this->assertFalse(Secp256k1::schnorrVerify($v['pubkey_x'], hex2bin($v['message']), 'deadbeef'));
    }

    // ------------------------------------------------------------------
    // NUT-20 deterministic quote locking keys
    // ------------------------------------------------------------------

    public function testDeterministicLockingKeysMatchOfficialVectors(): void
    {
        $fixture = cashu_fixture('nut20-quote-signing');
        $wallet = new Wallet('https://example.com', 'sat');
        $wallet->initFromMnemonic($fixture['mnemonic']);

        foreach ($fixture['locking_pubkeys'] as $counter => $expectedPubkey) {
            $key = $wallet->deriveQuoteLockingKey($counter);
            $this->assertSame($expectedPubkey, $key['pubkey'], "counter $counter");
        }
    }

    public function testRecoverQuoteLockingKeyFindsCounterByPubkey(): void
    {
        $fixture = cashu_fixture('nut20-quote-signing');
        $wallet = new Wallet('https://example.com', 'sat');
        $wallet->initFromMnemonic($fixture['mnemonic']);

        // Counter 3's pubkey must be findable by scanning (seed-restore case).
        $key = $wallet->recoverQuoteLockingKey($fixture['locking_pubkeys'][3]);
        $this->assertNotNull($key);
        $this->assertSame(3, $key['counter']);

        // A foreign pubkey is not ours.
        $this->assertNull($wallet->recoverQuoteLockingKey(
            '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798',
            50
        ));
    }

    // ------------------------------------------------------------------
    // NUT-20 mint request signing
    // ------------------------------------------------------------------

    public function testMintQuoteSignatureMessageMatchesOfficialVector(): void
    {
        $v = cashu_fixture('nut20-quote-signing')['mint_request'];
        $msg = Wallet::buildMintQuoteSignatureMessage($v['quote'], $v['outputs']);
        $this->assertSame($v['msg_to_sign_hex'], bin2hex($msg));
        $this->assertSame($v['msg_sha256'], hash('sha256', $msg));
    }

    public function testMintRequestSignatureMatchesOfficialVector(): void
    {
        $v = cashu_fixture('nut20-quote-signing')['mint_request'];
        $wallet = new Wallet('https://example.com', 'sat');
        $sig = $wallet->signMintQuoteRequest($v['secret_key'], $v['quote'], $v['outputs']);
        // cdk produced the vector with all-zero aux randomness, same as we do.
        $this->assertSame($v['signature'], $sig);
        $this->assertTrue(Secp256k1::schnorrVerify(
            $v['pubkey'],
            hash('sha256', Wallet::buildMintQuoteSignatureMessage($v['quote'], $v['outputs']), true),
            $sig
        ));
    }

    public function testAmountLengthPrefixIsCanonicalMinimal(): void
    {
        // 0 => empty, 1 => 0x01, 256 => 0x0100 per spec.
        $msgZero = Wallet::buildMintQuoteSignatureMessage('q', [['amount' => 0, 'B_' => '02' . str_repeat('00', 32)]]);
        $msgOne = Wallet::buildMintQuoteSignatureMessage('q', [['amount' => 1, 'B_' => '02' . str_repeat('00', 32)]]);
        $msg256 = Wallet::buildMintQuoteSignatureMessage('q', [['amount' => 256, 'B_' => '02' . str_repeat('00', 32)]]);

        $prefixLen = strlen('Cashu_MintQuoteSig_v1') + 4 + 1; // tag + len32(quote) + quote
        $this->assertSame("\x00\x00\x00\x00", substr($msgZero, $prefixLen, 4));
        $this->assertSame("\x00\x00\x00\x01\x01", substr($msgOne, $prefixLen, 5));
        $this->assertSame("\x00\x00\x00\x02\x01\x00", substr($msg256, $prefixLen, 6));
    }

    public function testLegacyMintQuoteSignatureMessage(): void
    {
        // Mints released before the May 2026 hardening (nutshell <= 0.20.x)
        // verify sha256 over quote_id || B_ hex strings, no length prefixes.
        $v = cashu_fixture('nut20-quote-signing')['mint_request'];
        $legacy = Wallet::buildMintQuoteSignatureMessageLegacy($v['quote'], $v['outputs']);
        $this->assertSame($v['quote'] . $v['outputs'][0]['B_'] . $v['outputs'][1]['B_'], $legacy);
        $this->assertNotSame($v['msg_sha256'], hash('sha256', $legacy));

        $wallet = new Wallet('https://example.com', 'sat');
        $sig = $wallet->signMintQuoteRequestLegacy($v['secret_key'], $v['quote'], $v['outputs']);
        $this->assertTrue(Secp256k1::schnorrVerify($v['pubkey'], hash('sha256', $legacy, true), $sig));
        $this->assertNotSame($v['signature'], $sig);
    }

    // ------------------------------------------------------------------
    // Quote key storage
    // ------------------------------------------------------------------

    public function testMintQuoteKeyStorageRoundTrip(): void
    {
        $db = tempnam(sys_get_temp_dir(), 'cashu-nut20-') . '.db';
        try {
            $wallet = new Wallet('https://example.com', 'sat', $db, 'acct1');
            $wallet->initializeNewFromMnemonic(cashu_fixture('nut20-quote-signing')['mnemonic']);
            $storage = $wallet->getStorage();

            $this->assertNull($storage->getMintQuoteKey('q1'));
            $storage->storeMintQuoteKey('q1', 7, '02abc');
            $this->assertSame(['key_counter' => 7, 'pubkey' => '02abc'], $storage->getMintQuoteKey('q1'));
            $storage->deleteMintQuoteKey('q1');
            $this->assertNull($storage->getMintQuoteKey('q1'));
        } finally {
            @unlink($db);
        }
    }
}
