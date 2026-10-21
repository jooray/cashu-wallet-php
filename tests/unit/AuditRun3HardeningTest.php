<?php

declare(strict_types=1);

namespace Cashu\Tests;

require_once dirname(__DIR__) . '/support/FakeRestoreMint.php';

use Cashu\CashuException;
use Cashu\CashuProtocolException;
use Cashu\CBOR;
use Cashu\ConnectGuard;
use Cashu\DLEQWallet;
use Cashu\LightningAddress;
use Cashu\MintClient;
use Cashu\Proof;
use Cashu\Tests\Support\FakeRestoreMint;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

/**
 * Library fixes from the 2026-10 audit reconciliation: connect guard (N1/N2), receive
 * ordering and duplicates (C1), mint quote checks (Obs 2), CBOR limits (N8) and the
 * smaller mint-input hardening.
 */
final class AuditRun3HardeningTest extends TestCase
{
    private const MNEMONIC = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
    private const KS = '009a1f293253e41e';
    private const MINT = 'https://mint.example';

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $docroot = '';

    private string $db;
    private FakeRestoreMint $mint;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        self::$docroot = sys_get_temp_dir() . '/cashu-guard-' . bin2hex(random_bytes(6));
        mkdir(self::$docroot);
        file_put_contents(self::$docroot . '/router.php', <<<'PHP'
<?php
if (str_ends_with($_SERVER['REQUEST_URI'], '/v1/redirect')) {
    header('Location: /v1/target', true, 302);
    exit;
}
header('Content-Type: application/json');
echo json_encode(['uri' => $_SERVER['REQUEST_URI'], 'host' => $_SERVER['HTTP_HOST'] ?? '']);
PHP);
        $socket = @stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return;
        }
        self::$port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$docroot . '/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        ) ?: null;
        for ($i = 0; $i < 50 && self::$server !== null; $i++) {
            $probe = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);
                return;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        @unlink(self::$docroot . '/router.php');
        @rmdir(self::$docroot);
    }

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/cashu-run3-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->mint = new FakeRestoreMint([['id' => self::KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => 0]]);
    }

    protected function tearDown(): void
    {
        ConnectGuard::set(null);
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->db . $suffix);
        }
    }

    private function wallet(string $unit = 'sat'): Wallet
    {
        $wallet = new Wallet(self::MINT, $unit, $this->db, 'store-1');
        $wallet->initializeNewFromMnemonic(self::MNEMONIC);
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $this->mint);
        $wallet->loadMint();
        return $wallet;
    }

    /** @return Proof[] */
    private static function foreignProofs(array $amounts): array
    {
        return array_map(
            fn($amount) => new Proof(self::KS, $amount, bin2hex(random_bytes(32)), FakeRestoreMint::G),
            $amounts
        );
    }

    private function postsTo(string $path): int
    {
        return count(array_filter($this->mint->calls, fn($call) => $call[0] === 'POST' && $call[1] === $path));
    }

    // ------------------------------------------------------------ N1 / N2: ConnectGuard

    public function testConnectGuardOptions(): void
    {
        $this->assertSame([], ConnectGuard::curlOptions('https://mint.example/v1/info', 'mint'));

        $seen = [];
        ConnectGuard::set(function (string $url, string $purpose) use (&$seen) {
            $seen[] = [$url, $purpose];
            return ['mint.example:443:203.0.113.5'];
        });
        $this->assertSame(
            [CURLOPT_RESOLVE => ['mint.example:443:203.0.113.5']],
            ConnectGuard::curlOptions('https://mint.example/v1/info', 'mint')
        );
        $this->assertSame([['https://mint.example/v1/info', 'mint']], $seen);

        ConnectGuard::set(fn() => []);
        $this->assertSame([], ConnectGuard::curlOptions('http://x.onion/', 'lnurl'));

        ConnectGuard::set(function () {
            throw new \RuntimeException('private address 10.0.0.1');
        });
        try {
            ConnectGuard::curlOptions('https://mint.example/v1/info', 'mint');
            $this->fail('refusal not raised');
        } catch (CashuException $e) {
            $this->assertSame('Connection refused: private address 10.0.0.1', $e->getMessage());
        }

        ConnectGuard::set(null);
        $this->assertSame([], ConnectGuard::curlOptions('https://mint.example/v1/info', 'mint'));
    }

    public function testMintClientIsRefusedBeforeConnecting(): void
    {
        $seen = [];
        ConnectGuard::set(function (string $url, string $purpose) use (&$seen) {
            $seen[] = [$url, $purpose];
            throw new \RuntimeException('not a public address');
        });
        // Port 9 on loopback: if the guard were skipped this would fail differently.
        $client = new MintClient('http://127.0.0.1:9/base', 2);
        try {
            $client->get('info');
            $this->fail('refused mint contacted');
        } catch (CashuException $e) {
            $this->assertSame('Connection refused: not a public address', $e->getMessage());
        }
        $this->assertSame([['http://127.0.0.1:9/base/v1/info', 'mint']], $seen);
    }

    public function testMintClientConnectsToThePinnedAddress(): void
    {
        if (self::$server === null) {
            $this->markTestSkipped('Could not start the PHP built-in web server');
        }
        $host = 'pinned-mint.invalid';
        ConnectGuard::set(fn(string $url, string $purpose) => ["{$host}:" . self::$port . ':127.0.0.1']);

        $answer = (new MintClient("http://{$host}:" . self::$port, 5))->get('keysets');
        $this->assertSame('/v1/keysets', $answer['uri']);
        $this->assertSame("{$host}:" . self::$port, $answer['host']);
    }

    public function testMintClientDoesNotFollowRedirects(): void
    {
        if (self::$server === null) {
            $this->markTestSkipped('Could not start the PHP built-in web server');
        }
        $client = new MintClient('http://127.0.0.1:' . self::$port, 5);
        try {
            $client->get('redirect');
            $this->fail('redirect followed');
        } catch (CashuException $e) {
            $this->assertStringContainsString('HTTP 302', $e->getMessage());
        }
    }

    public function testLnurlFetchGoesThroughTheGuard(): void
    {
        $seen = [];
        ConnectGuard::set(function (string $url, string $purpose) use (&$seen) {
            $seen[] = [$url, $purpose];
            throw new \RuntimeException('refused');
        });
        $this->assertNull(LightningAddress::resolve('alice@lnurl.invalid'));
        $this->assertSame([['https://lnurl.invalid/.well-known/lnurlp/alice', 'lnurl']], $seen);
    }

    // ------------------------------------------------------------ C1: receive

    public function testDuplicateProofsAreRefusedBeforeAnyMintCall(): void
    {
        $wallet = $this->wallet();
        $proof = self::foreignProofs([4])[0];
        $copy = new Proof($proof->id, $proof->amount, $proof->secret, $proof->C);
        $this->mint->calls = [];

        try {
            $wallet->receive($wallet->serializeToken([$proof, $copy], 'v3'));
            $this->fail('duplicate proofs accepted');
        } catch (CashuException $e) {
            $this->assertStringContainsString('more than once', $e->getMessage());
        }
        $this->assertSame(0, $this->postsTo('checkstate'));
        $this->assertSame(0, $this->postsTo('swap'));
        $this->assertSame(0, $wallet->getStorage()->getCounter(self::KS));
    }

    public function testSpentTokenIsRefusedBeforeDleqVerification(): void
    {
        $wallet = $this->wallet();
        $proofs = self::foreignProofs([4, 2]);
        // A full (r-carrying) DLEQ that does not verify: if DLEQ ran first, this would
        // be the error instead of the spent-state refusal.
        $proofs[0]->dleq = new DLEQWallet(str_repeat('11', 32), str_repeat('22', 32), str_repeat('33', 32));
        $this->mint->setState([$proofs[1]->secret], 'SPENT');

        try {
            $wallet->receive($wallet->serializeToken($proofs, 'v3', null, true));
            $this->fail('spent token accepted');
        } catch (CashuProtocolException $e) {
            $this->assertSame(CashuProtocolException::PROOFS_ALREADY_SPENT, $e->getCode());
        }

        // Unspent: the invalid DLEQ is still refused, and nothing is swapped.
        $this->mint->states = [];
        try {
            $wallet->receive($wallet->serializeToken($proofs, 'v3', null, true));
            $this->fail('invalid DLEQ accepted');
        } catch (CashuException $e) {
            $this->assertStringContainsString('DLEQ', $e->getMessage());
        }
        $this->assertSame(0, $this->postsTo('swap'));
    }

    public function testDistinctProofsAreStillReceived(): void
    {
        $wallet = $this->wallet();
        $this->mint->onPost = fn(string $path, array $data) => $path === 'swap'
            ? $this->mint->signOutputs($data['outputs']) : null;
        $received = $wallet->receive($wallet->serializeToken(self::foreignProofs([4, 4, 1]), 'v3'));
        $this->assertSame(9, Wallet::sumProofs($received));
    }

    // ------------------------------------------------------------ Obs 2: mint quote

    public function testSatQuoteNeedsAmountAndMatchingInvoice(): void
    {
        $wallet = $this->wallet();
        foreach ([
            'missing amount' => ['quote' => 'q', 'request' => 'lnbc1u1x', 'state' => 'UNPAID'],
            'zero amount' => ['quote' => 'q', 'request' => 'lnbc1u1x', 'amount' => 0, 'state' => 'UNPAID'],
            'amountless invoice' => ['quote' => 'q', 'request' => 'lnbc1x', 'amount' => 100, 'state' => 'UNPAID'],
            'not an invoice' => ['quote' => 'q', 'request' => 'pay me', 'amount' => 100, 'state' => 'UNPAID'],
            'wrong invoice amount' => ['quote' => 'q', 'request' => 'lightning:lnbc2u1x', 'amount' => 100, 'state' => 'UNPAID'],
        ] as $case => $answer) {
            $this->mint->onPost = fn(string $path) => $path === 'mint/quote/bolt11' ? $answer : null;
            try {
                $wallet->requestMintQuote(100);
                $this->fail("$case accepted");
            } catch (CashuException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->mint->onPost = fn(string $path) => $path === 'mint/quote/bolt11'
            ? ['quote' => 'q', 'request' => 'LIGHTNING:lnbc1u1x', 'amount' => 100, 'state' => 'UNPAID'] : null;
        $this->assertSame('q', $wallet->requestMintQuote(100)->quote);
    }

    public function testNonSatQuoteNeedsAnInvoiceWithAnAmount(): void
    {
        $this->mint = new FakeRestoreMint([['id' => self::KS, 'unit' => 'usd', 'active' => true, 'input_fee_ppk' => 0]]);
        $wallet = $this->wallet('usd');
        foreach ([
            ['quote' => 'q', 'request' => 'lnbc1x', 'amount' => 250, 'unit' => 'usd', 'state' => 'UNPAID'],
            ['quote' => 'q', 'request' => 'garbage', 'amount' => 250, 'unit' => 'usd', 'state' => 'UNPAID'],
            ['quote' => 'q', 'request' => 'lnbc20u1x', 'unit' => 'usd', 'state' => 'UNPAID'],
        ] as $i => $answer) {
            $this->mint->onPost = fn(string $path) => $path === 'mint/quote/bolt11' ? $answer : null;
            try {
                $wallet->requestMintQuote(250);
                $this->fail("usd quote $i accepted");
            } catch (CashuException $e) {
                $this->addToAssertionCount(1);
            }
        }

        // 2000 sat for $2.50: the sat amount is not comparable to cents, only present.
        $this->mint->onPost = fn(string $path) => $path === 'mint/quote/bolt11'
            ? ['quote' => 'q', 'request' => 'lnbc20u1x', 'amount' => 250, 'unit' => 'usd', 'state' => 'UNPAID'] : null;
        $this->assertSame('q', $wallet->requestMintQuote(250)->quote);
    }

    // ------------------------------------------------------------ N8: CBOR

    public function testCborMapLimits(): void
    {
        $map = [];
        for ($i = 0; $i < 64; $i++) {
            $map["k$i"] = $i;
        }
        $this->assertSame($map, CBOR::decode(CBOR::encode($map)));

        $map['k64'] = 64;
        try {
            CBOR::decode(CBOR::encode($map));
            $this->fail('65-entry map accepted');
        } catch (CashuException $e) {
            $this->assertStringContainsString('too many entries', $e->getMessage());
        }

        // {1: "a"} and {[]: 1}
        foreach (["\xa1\x01\x61a", "\xa1\x80\x01"] as $bytes) {
            try {
                CBOR::decode($bytes);
                $this->fail('non-string map key accepted');
            } catch (CashuException $e) {
                $this->assertStringContainsString('keys must be strings', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------ hardening

    public function testHexParsingIsBounded(): void
    {
        // A DLEQ scalar is at most 32 bytes; the cap keeps a huge value out of the
        // quadratic BCMath conversion (MINIMAX 4.4 / N1).
        $this->assertInstanceOf(\Cashu\BigInt::class, \Cashu\BigInt::fromHex('1'));
        try {
            \Cashu\BigInt::fromHex(str_repeat('a', 200));
            $this->fail('200 hex characters accepted');
        } catch (CashuException $e) {
            $this->assertStringContainsString('too long', $e->getMessage());
        }
    }

    public function testInvalidInputFeeIsRefused(): void
    {
        foreach ([-1, '100', 1.5] as $fee) {
            $this->tearDown();
            $this->mint = new FakeRestoreMint([['id' => self::KS, 'unit' => 'sat', 'active' => true, 'input_fee_ppk' => $fee]]);
            try {
                $this->wallet();
                $this->fail('input_fee_ppk ' . var_export($fee, true) . ' accepted');
            } catch (CashuException $e) {
                $this->assertStringContainsString('input fee', $e->getMessage());
            }
        }
    }

    public function testQuoteIdsAreEncodedIntoThePath(): void
    {
        $wallet = $this->wallet();
        $this->mint->onGet = function (string $path) {
            if ($path === 'mint/quote/bolt11/..%2F..%2Fkeys%3Fx') {
                return ['quote' => '../../keys?x', 'request' => 'lnbc1u1x', 'amount' => 100, 'state' => 'UNPAID'];
            }
            if ($path === 'melt/quote/bolt11/a%2Fb') {
                return ['quote' => 'a/b', 'amount' => 1, 'fee_reserve' => 0, 'state' => 'UNPAID'];
            }
            return null;
        };
        $this->assertSame('../../keys?x', $wallet->checkMintQuote('../../keys?x')->quote);
        $this->assertSame('a/b', $wallet->checkMeltQuote('a/b')->quote);
    }

    public function testPreparedSwapNeedsIntegerSignatureAmounts(): void
    {
        $wallet = $this->wallet();
        $this->mint->onPost = function (string $path, array $data) {
            if ($path !== 'swap') {
                return null;
            }
            $answer = $this->mint->signOutputs($data['outputs']);
            foreach ($answer['signatures'] as &$signature) {
                $signature['amount'] = (string)$signature['amount'];
            }
            return $answer;
        };
        try {
            $wallet->receive($wallet->serializeToken(self::foreignProofs([4]), 'v3'));
            $this->fail('string signature amount accepted');
        } catch (CashuException $e) {
            $this->assertStringContainsString('do not match prepared outputs', $e->getMessage());
        }
    }
}
