<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\Mnemonic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BIP-39 against the official Trezor test vectors
 * (tests/fixtures/bip39-vectors.json, passphrase "TREZOR").
 */
final class MnemonicTest extends TestCase
{
    public static function trezorVectors(): array
    {
        $out = [];
        foreach (cashu_fixture('bip39-vectors')['english'] as $i => $v) {
            // [entropy, mnemonic, seed, xprv]
            $out["vector $i (" . (strlen($v[0]) * 4) . " bits)"] = [$v[0], $v[1], $v[2]];
        }
        return $out;
    }

    #[DataProvider('trezorVectors')]
    public function testOfficialMnemonicsValidate(string $entropyHex, string $mnemonic, string $seedHex): void
    {
        $this->assertTrue(Mnemonic::validate($mnemonic));
    }

    #[DataProvider('trezorVectors')]
    public function testSeedDerivationMatchesOfficialVectors(string $entropyHex, string $mnemonic, string $seedHex): void
    {
        $this->assertSame($seedHex, bin2hex(Mnemonic::toSeed($mnemonic, 'TREZOR')));
    }

    public static function trezor128BitVectors(): array
    {
        return array_filter(
            self::trezorVectors(),
            fn(array $v) => strlen($v[0]) === 32 // 16 bytes entropy
        );
    }

    /**
     * entropyToMnemonic is private and only ever called by generate() with
     * 128-bit entropy, so only the 128-bit official vectors apply.
     */
    #[DataProvider('trezor128BitVectors')]
    public function testEntropyToMnemonicMatchesOfficialVectors(string $entropyHex, string $mnemonic, string $seedHex): void
    {
        $method = new \ReflectionMethod(Mnemonic::class, 'entropyToMnemonic');
        $this->assertSame($mnemonic, $method->invoke(null, hex2bin($entropyHex)));
    }

    public function testValidateRejectsWrongWordCount(): void
    {
        $this->assertFalse(Mnemonic::validate('abandon abandon abandon'));
        $this->assertFalse(Mnemonic::validate(implode(' ', array_fill(0, 13, 'abandon'))));
    }

    public function testValidateRejectsUnknownWord(): void
    {
        $this->assertFalse(Mnemonic::validate(
            'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon zzzzzz'
        ));
    }

    public function testValidateRejectsBadChecksum(): void
    {
        // Valid words, invalid checksum (last word changed from "about" to "abandon")
        $this->assertFalse(Mnemonic::validate(
            'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon'
        ));
    }

    public function testValidateAcceptsExtraWhitespace(): void
    {
        $this->assertTrue(Mnemonic::validate(
            "  abandon abandon  abandon abandon abandon abandon abandon abandon abandon abandon abandon\tabout "
        ));
    }

    public function testGenerateProducesValidTwelveWordMnemonics(): void
    {
        $a = Mnemonic::generate();
        $b = Mnemonic::generate();

        $this->assertCount(12, explode(' ', $a));
        $this->assertTrue(Mnemonic::validate($a));
        $this->assertNotSame($a, $b, 'two generated mnemonics should differ');
    }

    public function testPassphraseChangesSeed(): void
    {
        $mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
        $this->assertNotSame(
            bin2hex(Mnemonic::toSeed($mnemonic)),
            bin2hex(Mnemonic::toSeed($mnemonic, 'TREZOR'))
        );
    }

    public function testWordlistBoundaries(): void
    {
        $this->assertSame('abandon', Mnemonic::getWord(0));
        $this->assertSame('zoo', Mnemonic::getWord(2047));
        $this->assertSame('', Mnemonic::getWord(2048));
    }
}
