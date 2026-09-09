<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\BigInt;
use Cashu\CashuException;
use Cashu\Crypto;
use Cashu\Keyset;
use Cashu\LightningAddress;
use Cashu\MeltQuote;
use Cashu\MintClient;
use Cashu\Mnemonic;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Secp256k1;
use Cashu\TokenSerializer;
use Cashu\Unit;
use Cashu\Wallet;
use Cashu\WalletStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regressions for the fund-safety and hostile-input defects reported in the
 * FABLE-51 and GPT-ASTRA audits.
 */
final class AuditRemediationTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/cashu-audit-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->db . $suffix);
        }
    }

    // --- NUT-08 blank outputs (CWL-H1 / CW-06) -----------------------------

    /**
     * The mint decomposes the real overpayment into powers of two and signs at most as
     * many outputs as we supplied, dropping the rest. One blank output per bit of the
     * largest possible change is therefore the minimum that never loses a denomination.
     */
    public function testBlankOutputCountCoversEveryDenominationOfTheChange(): void
    {
        $this->assertSame(0, Wallet::blankOutputCount(0));
        $this->assertSame(0, Wallet::blankOutputCount(-1));
        $this->assertSame(1, Wallet::blankOutputCount(1));
        $this->assertSame(2, Wallet::blankOutputCount(2));
        $this->assertSame(2, Wallet::blankOutputCount(3));
        $this->assertSame(8, Wallet::blankOutputCount(255));
        $this->assertSame(9, Wallet::blankOutputCount(256));

        // Any value up to maxChange must be representable in that many outputs.
        foreach ([1, 7, 10, 63, 1000, 65535] as $maxChange) {
            $n = Wallet::blankOutputCount($maxChange);
            $worstCase = count(Wallet::splitAmount($maxChange));
            $this->assertGreaterThanOrEqual($worstCase, $n, "maxChange=$maxChange");
        }
    }

    // --- Hostile token input (CWL-H5 / CW-22, CW-23) -----------------------

    /**
     * @see hostileTokens
     */
    #[DataProvider('hostileTokens')]
    public function testMalformedTokensRaiseCashuException(string $token, string $why): void
    {
        $this->expectException(CashuException::class);
        TokenSerializer::deserialize($token);
        $this->fail($why);
    }

    public static function hostileTokens(): array
    {
        $b64 = fn(string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return [
            'V4 array header claiming a huge length' => ['cashuB' . $b64("\x9b\xff\xff\xff\xff\xff\xff\xff\xff"), 'must not decode as empty'],
            'V4 truncated string' => ['cashuB' . $b64("\x65\x41"), 'declared 5 bytes, supplied 1'],
            'V4 trailing data' => ['cashuB' . $b64("\x01\x02"), 'two top-level items'],
            'V4 non-map' => ['cashuB' . $b64("\x01"), 'not a token map'],
            'V3 missing proof fields' => ['cashuA' . $b64('{"token":[{"mint":"https://m","proofs":[{}]}]}'), 'proof without id/secret/C'],
            'V3 negative amount' => ['cashuA' . $b64('{"token":[{"mint":"https://m","proofs":[{"id":"009a1f293253e41e","amount":-5,"secret":"s","C":"02' . str_repeat('11', 32) . '"}]}]}'), 'negative amount'],
            'V3 string amount' => ['cashuA' . $b64('{"token":[{"mint":"https://m","proofs":[{"id":"009a1f293253e41e","amount":"7","secret":"s","C":"02' . str_repeat('11', 32) . '"}]}]}'), 'amount must be an int'],
            'V3 two mints' => ['cashuA' . $b64('{"token":[{"mint":"https://a","proofs":[{"id":"009a1f293253e41e","amount":1,"secret":"s1","C":"02' . str_repeat('11', 32) . '"}]},{"mint":"https://b","proofs":[{"id":"009a1f293253e41e","amount":1,"secret":"s2","C":"02' . str_repeat('22', 32) . '"}]}]}'), 'multi-mint must not be flattened'],
            'not base64url' => ['cashuB!!!!', 'invalid encoding'],
            'unknown prefix' => ['cashuZabcd', 'unknown format'],
        ];
    }

    /** A V4 token with a DLEQ whose blinding factor is missing must still serialize. */
    public function testSerializeV4SkipsDleqWithoutBlindingFactor(): void
    {
        $proof = new Proof(
            '009a1f293253e41e',
            2,
            'secret',
            '02' . str_repeat('11', 32),
            new \Cashu\DLEQWallet(str_repeat('aa', 32), str_repeat('bb', 32), null)
        );
        $token = TokenSerializer::serializeV4('https://mint.example', [$proof], 'sat', null, true);
        $decoded = TokenSerializer::deserialize($token);
        $this->assertCount(1, $decoded->proofs);
        $this->assertNull($decoded->proofs[0]->dleq);
    }

    // --- Proof lifecycle (CWL-M7 / CPS2-C1) --------------------------------

    public function testUnknownProofStatesAreRejected(): void
    {
        $storage = new WalletStorage($this->db, 'https://mint.example', 'sat', 'acct');
        $this->expectException(CashuException::class);
        $storage->updateProofsState(['some-secret'], 'BANANA');
    }

    /** EXPORTED and UNKNOWN exist precisely so they are never selectable. */
    public function testExportedAndUnknownProofsAreNotSpendable(): void
    {
        $storage = new WalletStorage($this->db, 'https://mint.example', 'sat', 'acct');
        $proofs = [
            new Proof('009a1f293253e41e', 4, 'keep-me', '02' . str_repeat('11', 32)),
            new Proof('009a1f293253e41e', 8, 'exported', '02' . str_repeat('22', 32)),
            new Proof('009a1f293253e41e', 16, 'unknown', '02' . str_repeat('33', 32)),
        ];
        $storage->storeProofs($proofs);
        $storage->updateProofsState(['exported'], ProofState::EXPORTED);
        $storage->updateProofsState(['unknown'], ProofState::UNKNOWN);

        $this->assertSame(4, $storage->getBalance());
        $this->assertSame(['keep-me'], array_column($storage->getProofs(), 'secret'));
    }

    // --- Storage hardening (CWL-M4 / CW-32, CWL-M6 / CW-39) ----------------

    public function testDatabaseIsNotGroupOrWorldReadable(): void
    {
        new WalletStorage($this->db, 'https://mint.example', 'sat', 'acct');
        $this->assertSame(0, fileperms($this->db) & 0077, 'proofs must not be readable by other local users');
    }

    public function testCounterIncrementWorksInsideACallerTransaction(): void
    {
        $storage = new WalletStorage($this->db, 'https://mint.example', 'sat', 'acct');
        $storage->getPdo()->beginTransaction();
        $this->assertSame(0, $storage->incrementCounter('009a1f293253e41e'));
        $storage->getPdo()->commit();
        $this->assertSame(1, $storage->getCounter('009a1f293253e41e'));
    }

    /** A stale read-modify-write used to overwrite a concurrent allocation. */
    public function testRaiseCounterNeverLowersAPersistedValue(): void
    {
        $storage = new WalletStorage($this->db, 'https://mint.example', 'sat', 'acct');
        $storage->setCounter('009a1f293253e41e', 101);
        $this->assertSame(101, $storage->raiseCounter('009a1f293253e41e', 100));
        $this->assertSame(150, $storage->raiseCounter('009a1f293253e41e', 150));
    }

    public function testWitnessSurvivesAStorageRoundTrip(): void
    {
        $storage = new WalletStorage($this->db, 'https://mint.example', 'sat', 'acct');
        $witness = '{"signatures":["' . str_repeat('ab', 64) . '"]}';
        $storage->storeProofs([
            new Proof('009a1f293253e41e', 2, 'locked', '02' . str_repeat('11', 32), null, $witness),
        ]);
        $reloaded = $storage->getProofsBySecretsAsObjects(['locked']);
        $this->assertSame($witness, $reloaded[0]->witness);
    }

    // --- Quote responses (CW-08, CW-27) ------------------------------------

    /** A melt quote without `state` is unknown, not UNPAID: UNPAID releases inputs. */
    public function testMeltQuoteWithoutStateIsNotTreatedAsUnpaid(): void
    {
        $quote = MeltQuote::fromArray(['quote' => 'q1', 'amount' => 10, 'fee_reserve' => 2]);
        $this->assertFalse($quote->isUnpaid());
        $this->assertFalse($quote->isPaid());
        $this->assertFalse($quote->isPending());
        $this->assertSame(ProofState::UNKNOWN, $quote->state);
    }

    public function testMalformedQuoteResponsesRaiseCashuException(): void
    {
        $this->expectException(CashuException::class);
        MeltQuote::fromArray(['detail' => 'server error']);
    }

    // --- BIP-39 (CWL-H9 / CW-18) -------------------------------------------

    public function testPassphraseNormalizationMakesSeedsPortable(): void
    {
        $mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
        $composed = Mnemonic::toSeed($mnemonic, "\u{00e9}");
        $decomposed = Mnemonic::toSeed($mnemonic, "e\u{0301}");
        $this->assertSame(bin2hex($composed), bin2hex($decomposed));
    }

    public function testValidateAcceptsWhatToSeedAccepts(): void
    {
        $mnemonic = 'ABANDON abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon ABOUT';
        $this->assertTrue(Mnemonic::validate($mnemonic));
    }

    // --- BOLT11 amount (CWL-H7 / CW-15) ------------------------------------

    /**
     * @see bolt11Amounts
     */
    #[DataProvider('bolt11Amounts')]
    public function testBolt11AmountIsDecodedInSatoshis(string $invoice, int $expected): void
    {
        $this->assertSame($expected, \Cashu\Bolt11::amountSats($invoice));
    }

    public static function bolt11Amounts(): array
    {
        return [
            ['lnbc1500n1pwxyz', 150],
            ['lnbc10u1pwxyz', 1000],
            ['lnbc1m1pwxyz', 100000],
            ['lnbc11pwxyz', 100000000],
            ['lnbc2500u1pwxyz', 250000],
            ['lntb20u1pwxyz', 2000],
            ['lnbcrt500n1pwxyz', 50],
            ['lnbc1pwxyz', 0],              // amountless
            ['lnbc1p1pwxyz', 1],            // 1 pico-BTC rounds up to 1 sat
            ['not-a-bolt11-invoice', 0],
        ];
    }

    public function testOnlyHttpsLnurlCallbacksAreAccepted(): void
    {
        $this->assertTrue(LightningAddress::isSafeCallbackUrl('https://example.com/cb'));
        $this->assertTrue(LightningAddress::isSafeCallbackUrl('http://abc.onion/cb'));
        $this->assertFalse(LightningAddress::isSafeCallbackUrl('http://127.0.0.1:12345/internal'));
        $this->assertFalse(LightningAddress::isSafeCallbackUrl('file:///etc/passwd'));
        $this->assertFalse(LightningAddress::isSafeCallbackUrl('gopher://example.com/'));
        $this->assertFalse(LightningAddress::isSafeCallbackUrl('https://user:pw@example.com/'));
    }

    // --- Mint URL policy (CWL-M3 / CW-19, CWL-M12) -------------------------

    public function testNonHttpMintUrlsAreRefused(): void
    {
        foreach (['file:///etc/passwd', 'gopher://x/', 'https://user:pw@m.example/', 'not a url'] as $url) {
            try {
                new Wallet($url);
                $this->fail("Expected rejection of $url");
            } catch (CashuException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMintUrlsAreCanonicalized(): void
    {
        $this->assertSame(
            'https://mint.example/foo/bar',
            MintClient::canonicalizeMintUrl('https://Mint.Example:443/foo//bar/')
        );
    }

    // --- NUT-02 large denominations (CW-17) --------------------------------

    /**
     * Official V2 vectors include the 2^63 denomination, which does not fit a signed
     * PHP int. Verifying the announced ID against the truncated map rejected a valid
     * keyset outright.
     */
    public function testKeysetIdVerifiesAgainstDenominationsBeyondPhpIntMax(): void
    {
        $keys = [
            '1' => '02' . str_repeat('ab', 32),
            '9223372036854775808' => '03' . str_repeat('cd', 32),
        ];
        $id = Keyset::deriveKeysetIdV2($keys, 'sat', 0, null);

        $keyset = new Keyset($id, 'sat', [1 => $keys['1']], true, 0, null);
        $keyset->rawKeys = $keys;
        $this->assertSame($id, $keyset->deriveExpectedId());

        $truncated = new Keyset($id, 'sat', [1 => $keys['1']], true, 0, null);
        $this->assertNotSame($id, $truncated->deriveExpectedId());
    }

    public function testKeysSortNumericallyBeyondPhpIntMax(): void
    {
        $sorted = array_keys(Keyset::sortKeysByAmount([
            '9223372036854775808' => 'a', '2' => 'b', '10' => 'c', '1' => 'd',
        ]));
        $this->assertSame(['1', '2', '10', '9223372036854775808'], array_map('strval', $sorted));
    }

    // --- Numeric handling (CWL-M10 / CW-38) --------------------------------

    public function testAmountParsingIsExactAndRejectsUnsupportedPrecision(): void
    {
        $this->assertSame(150, Unit::fromCode('usd')->parse('1.50'));
        $this->assertSame(123456789012345678, Unit::fromCode('usd')->parse('1234567890123456.78'));
        $this->assertSame(1, Unit::fromCode('sat')->parse('1.0'));

        foreach ([['sat', '1.9'], ['usd', '-5'], ['sat', '1e5'], ['usd', '0.005']] as [$unit, $value]) {
            try {
                Unit::fromCode($unit)->parse($value);
                $this->fail("Expected rejection of $unit $value");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSplitAmountRejectsNegatives(): void
    {
        $this->expectException(CashuException::class);
        Wallet::splitAmount(-3);
    }

    // --- Crypto input validation (CWL-M5 / CW-26) --------------------------

    public function testNonCanonicalPointEncodingIsRejected(): void
    {
        // x = p + 1 used to be silently reduced modulo p.
        $p = gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16);
        $x = str_pad(gmp_strval(gmp_add($p, 1), 16), 64, '0', STR_PAD_LEFT);
        $this->expectException(CashuException::class);
        Secp256k1::decompressPoint(hex2bin('02' . $x));
    }

    public function testInvalidHexIsRejected(): void
    {
        $this->expectException(CashuException::class);
        BigInt::fromHex('zz');
    }

    public function testSchnorrVerifyRejectsAnInvalidSec1Prefix(): void
    {
        $secretHex = str_pad(dechex(12345), 64, '0', STR_PAD_LEFT);
        $point = Secp256k1::scalarMult(BigInt::fromHex($secretHex), Secp256k1::getGenerator());
        $xOnly = substr(bin2hex(Secp256k1::compressPoint($point)), 2);
        $msg = hash('sha256', 'message', true);
        $sig = Secp256k1::schnorrSign($secretHex, $msg);

        $this->assertTrue(Secp256k1::schnorrVerify($xOnly, $msg, $sig));
        $this->assertFalse(
            Secp256k1::schnorrVerify('ff' . $xOnly, $msg, $sig),
            'only 02/03 are valid compressed-key prefixes'
        );
    }

    /**
     * (int) casting made "m/garbage", "m/4294967296" and "m/-1" all derive m/0, so a
     * NUT-13 counter beyond 2^31 collided with a low one.
     */
    public function testDerivationPathsAreValidated(): void
    {
        $bip32 = \Cashu\BIP32::fromSeed(str_repeat("\x01", 32));
        foreach (['m/garbage', 'm/4294967296', 'm/-1', 'm/'] as $path) {
            try {
                $bip32->derivePath($path);
                $this->fail("Expected rejection of $path");
            } catch (CashuException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- BCMath backend (CW-25) --------------------------------------------

    /**
     * The BCMath fallback compares exact integer strings; without explicit scale a host
     * application's bcscale(2) turned "3" into "3.00" and broke every curve operation.
     */
    public function testBcmathBackendIsIndependentOfGlobalScale(): void
    {
        $expected = bin2hex(Secp256k1::compressPoint(Crypto::hashToCurve('test_message')));

        // Run in a child process: swapping the backend means rebuilding the cached curve
        // constants, and leaving BCMath BigInts behind would corrupt later tests.
        $script = implode(' ', [
            'bcscale(2);',
            "require getenv('CASHU_LIB');",
            "(new ReflectionProperty(Cashu\\BigInt::class, 'useGmp'))->setValue(null, false);",
            '$out = [',
            'Cashu\\BigInt::fromDec(3)->add(Cashu\\BigInt::fromDec(0))->toDec(),',
            'Cashu\\BigInt::fromDec(3)->isOdd() ? "odd" : "even",',
            'Cashu\\BigInt::fromDec(3)->modInverse(Cashu\\BigInt::fromDec(7))->toDec(),',
            "bin2hex(Cashu\\Secp256k1::compressPoint(Cashu\\Crypto::hashToCurve('test_message'))),",
            '];',
            'echo implode("|", $out);',
        ]);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            $descriptors,
            $pipes,
            null,
            ['CASHU_LIB' => dirname(__DIR__, 2) . '/CashuWallet.php'] + getenv()
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame('', trim($err), 'BCMath backend must not emit errors');
        $this->assertSame(['3', 'odd', '5', $expected], explode('|', trim($out)));
    }
}
