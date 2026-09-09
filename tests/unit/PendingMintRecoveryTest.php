<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\CashuException;
use Cashu\MintClient;
use Cashu\ProofState;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * recoverPendingMints(): a mint whose response was lost leaves proofs the mint already
 * signed and we never received. Nothing used to look for them, so the journal sat in the
 * database forever and the money stayed unavailable.
 */
final class PendingMintRecoveryTest extends TestCase
{
    private const MINT = 'https://mint.example';
    private string $db;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/cashu-mintrec-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->db . $suffix);
        }
    }

    /** A wallet whose mint answers from the given callbacks. */
    private function wallet(callable $get, ?callable $post = null): Wallet
    {
        $wallet = new Wallet(self::MINT, 'sat', $this->db, 'recovery');
        $wallet->initializeNewFromMnemonic(cashu_fixture('nut13-derivation')['mnemonic']);

        $client = new class ($get, $post) extends MintClient {
            /** @var callable */
            private $onGet;
            /** @var callable|null */
            private $onPost;

            public function __construct(callable $onGet, ?callable $onPost)
            {
                parent::__construct(PendingMintRecoveryTest::mintUrl());
                $this->onGet = $onGet;
                $this->onPost = $onPost;
            }

            public function get(string $path): array
            {
                return ($this->onGet)($path);
            }

            public function post(string $path, array $data, ?int $timeout = null): array
            {
                if ($this->onPost === null) {
                    throw new CashuException("Unexpected POST: $path");
                }
                return ($this->onPost)($path, $data);
            }
        };
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $client);

        return $wallet;
    }

    public static function mintUrl(): string
    {
        return self::MINT;
    }

    /** Journal a mint operation the way mint() would before contacting the mint. */
    private function journalMint(Wallet $wallet, string $quoteId, array $amounts): array
    {
        return $wallet->getStorage()->preparePendingSpend(
            "mint:$quoteId",
            'mint',
            [],
            '009a1f293253e41e',
            $amounts
        );
    }

    private function quoteResponse(string $quoteId, string $state, ?int $expiry, int $amount = 8): array
    {
        return [
            'quote' => $quoteId,
            'request' => 'lnbc1',
            'amount' => $amount,
            'state' => $state,
            'expiry' => $expiry,
        ];
    }

    /** A quote that expired without ever being paid owns nothing; retire the journal. */
    public function testRetiresAJournalWhoseQuoteExpiredUnpaid(): void
    {
        $wallet = $this->wallet(
            fn(string $path) => $this->quoteResponse('q-expired', 'UNPAID', time() - 86400)
        );
        $this->journalMint($wallet, 'q-expired', [4, 4]);

        $result = $wallet->recoverPendingMints();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['retired']);
        $this->assertSame(0, $result['recovered']);
        $this->assertNull($wallet->getStorage()->getPendingOperationById('mint:q-expired'));
    }

    /** A quote still payable must keep its journal: the customer may yet pay. */
    public function testKeepsAJournalWhoseQuoteIsStillPayable(): void
    {
        $wallet = $this->wallet(
            fn(string $path) => $this->quoteResponse('q-open', 'UNPAID', time() + 3600)
        );
        $this->journalMint($wallet, 'q-open', [4, 4]);

        $result = $wallet->recoverPendingMints();

        $this->assertSame(1, $result['still_pending']);
        $this->assertSame(0, $result['retired']);
        $this->assertNotNull($wallet->getStorage()->getPendingOperationById('mint:q-open'));
    }

    /** An unreachable mint is not evidence of anything; never drop the journal. */
    public function testKeepsTheJournalWhenTheMintCannotBeReached(): void
    {
        $wallet = $this->wallet(function (string $path): array {
            throw new CashuException('HTTP request failed: Could not resolve host');
        });
        $this->journalMint($wallet, 'q-unreachable', [4]);

        $result = $wallet->recoverPendingMints();

        $this->assertSame(1, $result['still_pending']);
        $this->assertNotEmpty($result['errors']);
        $this->assertNotNull($wallet->getStorage()->getPendingOperationById('mint:q-unreachable'));
    }

    /** Proofs already stored for this quote mean only the journal is stale. */
    public function testRetiresAJournalWhoseProofsWereAlreadyStored(): void
    {
        $wallet = $this->wallet(function (string $path): array {
            throw new CashuException('the quote should not be fetched at all');
        });
        $plan = $this->journalMint($wallet, 'q-done', [4]);

        $wallet->getStorage()->storeProofs(
            [new \Cashu\Proof('009a1f293253e41e', 4, 'already-minted', '02' . str_repeat('11', 32))],
            'q-done'
        );

        $result = $wallet->recoverPendingMints();

        $this->assertSame(1, $result['retired']);
        $this->assertNull($wallet->getStorage()->getPendingOperationById('mint:q-done'));
    }

    /** An incomplete NUT-09 restore must not finalize a partial set. */
    public function testKeepsTheJournalWhenRestoreIsIncomplete(): void
    {
        $wallet = $this->wallet(
            fn(string $path) => $this->quoteResponse('q-issued', 'ISSUED', time() - 10),
            fn(string $path, array $data) => $path === 'restore'
                ? ['outputs' => [], 'signatures' => []]   // mint returns nothing
                : throw new CashuException("Unexpected POST: $path")
        );
        $this->journalMint($wallet, 'q-issued', [4, 4]);

        $result = $wallet->recoverPendingMints();

        $this->assertSame(1, $result['still_pending']);
        $this->assertSame(0, $result['recovered']);
        $this->assertNotNull(
            $wallet->getStorage()->getPendingOperationById('mint:q-issued'),
            'a journal that still owns money must survive an incomplete restore'
        );
    }
}
