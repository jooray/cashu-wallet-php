<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\CashuException;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * Wallet seed lifecycle and safety rails (no network, no mint).
 */
final class WalletSeedTest extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const OTHER_MNEMONIC = 'legal winner thank year wave sausage worth useful legal winner thank yellow';

    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/cashu-phpunit-seed-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->dbPath . $suffix);
        }
    }

    public function testSeedFingerprintIsDeterministicAndScoped(): void
    {
        $a = Wallet::calculateSeedFingerprint(self::MNEMONIC);
        $b = Wallet::calculateSeedFingerprint(self::MNEMONIC);

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
        $this->assertNotSame($a, Wallet::calculateSeedFingerprint(self::OTHER_MNEMONIC));
        $this->assertNotSame($a, Wallet::calculateSeedFingerprint(self::MNEMONIC, 'passphrase'));
    }

    public function testSeedFingerprintRejectsInvalidMnemonic(): void
    {
        $this->expectException(CashuException::class);
        Wallet::calculateSeedFingerprint('definitely not a mnemonic');
    }

    public function testInitFromMnemonicRejectsInvalidMnemonic(): void
    {
        $wallet = new Wallet('https://mint.example');
        $this->expectException(CashuException::class);
        $wallet->initFromMnemonic('one two three');
    }

    public function testSeedWithoutStorageRequiresRecovery(): void
    {
        $wallet = new Wallet('https://mint.example');
        $this->assertFalse($wallet->hasSeed());
        $this->assertFalse($wallet->hasStorage());

        $wallet->initFromMnemonic(self::MNEMONIC);

        $this->assertTrue($wallet->hasSeed());
        $this->assertSame(self::MNEMONIC, $wallet->getMnemonic());
        $this->assertTrue($wallet->requiresRecovery(), 'seed without storage must not be spendable');
    }

    public function testNewSeedWithStorageIsReadyForSpending(): void
    {
        $wallet = new Wallet('https://mint.example', 'sat', $this->dbPath);
        $wallet->initializeNewFromMnemonic(self::MNEMONIC);

        $this->assertFalse($wallet->requiresRecovery());

        // Reopen with the plain init path: fingerprint already bound
        $reopened = new Wallet('https://mint.example', 'sat', $this->dbPath);
        $reopened->initFromMnemonic(self::MNEMONIC);
        $this->assertFalse($reopened->requiresRecovery());
    }

    public function testRestoreInitializationBlocksSpendingUntilComplete(): void
    {
        $wallet = new Wallet('https://mint.example', 'sat', $this->dbPath);
        $wallet->initializeForRestore(self::MNEMONIC);
        $this->assertTrue($wallet->requiresRecovery(), 'restore-mode seed must not spend before restore completes');

        // Simulate restore completion
        $wallet->getStorage()->markSeedReady();
        $reopened = new Wallet('https://mint.example', 'sat', $this->dbPath);
        $reopened->initFromMnemonic(self::MNEMONIC);
        $this->assertFalse($reopened->requiresRecovery());
    }

    public function testStorageBoundToDifferentSeedIsRejected(): void
    {
        $wallet = new Wallet('https://mint.example', 'sat', $this->dbPath);
        $wallet->initializeNewFromMnemonic(self::MNEMONIC);

        $intruder = new Wallet('https://mint.example', 'sat', $this->dbPath);
        $this->expectException(CashuException::class);
        $intruder->initFromMnemonic(self::OTHER_MNEMONIC);
    }

    public function testGenerateMnemonicRequiresStorage(): void
    {
        $wallet = new Wallet('https://mint.example');
        $this->expectException(CashuException::class);
        $wallet->generateMnemonic();
    }

    public function testGenerateMnemonicWithStorage(): void
    {
        $wallet = new Wallet('https://mint.example', 'sat', $this->dbPath);
        $mnemonic = $wallet->generateMnemonic();

        $this->assertCount(12, explode(' ', $mnemonic));
        $this->assertTrue($wallet->hasSeed());
        $this->assertFalse($wallet->requiresRecovery());
    }

    public function testWalletRefusesSerialization(): void
    {
        $wallet = new Wallet('https://mint.example');
        $wallet->initFromMnemonic(self::MNEMONIC);

        $this->expectException(CashuException::class);
        serialize($wallet);
    }

    public function testDebugOutputRedactsSecrets(): void
    {
        $wallet = new Wallet('https://mint.example');
        $wallet->initFromMnemonic(self::MNEMONIC);

        $dump = print_r($wallet, true);
        $this->assertStringNotContainsString('abandon', $dump, 'mnemonic must never appear in debug output');
        $this->assertStringContainsString('[REDACTED]', $dump);
    }

    public function testCountersAreSharedWithStorage(): void
    {
        $wallet = new Wallet('https://mint.example', 'sat', $this->dbPath);
        $wallet->initializeNewFromMnemonic(self::MNEMONIC);

        $wallet->setCounter('009a1f293253e41e', 9);
        $this->assertSame(9, $wallet->getCounter('009a1f293253e41e'));
        $this->assertSame(0, $wallet->getCounter('unknown-keyset'));

        $wallet->setCounters(['00ad268c4d1f5826' => 3]);
        $this->assertSame(['00ad268c4d1f5826' => 3], $wallet->getCounters());
    }

    public function testBasicAccessors(): void
    {
        $wallet = new Wallet('https://mint.example/', 'SAT', $this->dbPath);

        $this->assertSame('https://mint.example', $wallet->getMintUrl(), 'trailing slash is normalized');
        $this->assertSame('SAT', $wallet->getUnit());
        $this->assertSame($this->dbPath, $wallet->getDbPath());
        $this->assertTrue($wallet->hasStorage());
        $this->assertNotNull($wallet->getStorage());

        $bare = new Wallet('https://mint.example');
        $this->assertFalse($bare->hasStorage());
        $this->assertNull($bare->getStorage());
        $this->assertNull($bare->getDbPath());
    }

    public function testGetBalanceRequiresStorage(): void
    {
        $bare = new Wallet('https://mint.example');
        $this->expectException(CashuException::class);
        $bare->getBalance();
    }
}
