<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\BigInt;
use Cashu\CashuException;
use Cashu\Crypto;
use Cashu\Keyset;
use Cashu\MintClient;
use Cashu\Proof;
use Cashu\ProofState;
use Cashu\Secp256k1;
use Cashu\Wallet;
use PHPUnit\Framework\TestCase;

final class WalletOperationSafetyTest extends TestCase
{
    private const KEYSET = '009a1f293253e41e';
    private const MINT = 'https://mint.example';
    private const G = '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    private string $db;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/cashu-operations-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->db . $suffix);
        }
    }

    private function wallet(callable $post, ?callable $get = null): Wallet
    {
        $wallet = new Wallet(self::MINT, 'sat', $this->db);
        $mnemonic = cashu_fixture('nut13-derivation')['mnemonic'];
        if ($wallet->getStorage()->getSeedFingerprint() === null) {
            $wallet->initializeNewFromMnemonic($mnemonic);
        } else {
            $wallet->initFromMnemonic($mnemonic);
        }
        $keys = array_fill_keys([1, 2, 4, 8, 16, 32, 64, 128], self::G);
        (new \ReflectionProperty(Wallet::class, 'keys'))->setValue($wallet, [self::KEYSET => $keys]);
        (new \ReflectionProperty(Wallet::class, 'keysets'))->setValue($wallet,
            [new Keyset(self::KEYSET, 'sat', $keys)]);
        $client = $this->createStub(MintClient::class);
        $client->method('post')->willReturnCallback($post);
        $client->method('get')->willReturnCallback($get ?? static function (): array {
            throw new CashuException('Mock mint unavailable');
        });
        (new \ReflectionProperty(Wallet::class, 'client'))->setValue($wallet, $client);
        return $wallet;
    }

    /** Synthetic mint uses private key 1 for each denomination. */
    private function signature(array $output, bool $dleq = false): array
    {
        $signature = ['id' => $output['id'], 'amount' => $output['amount'], 'C_' => $output['B_']];
        if ($dleq) {
            $B = Secp256k1::decompressPoint(hex2bin($output['B_']));
            $two = BigInt::fromHex('02');
            $e = Crypto::hashE(Secp256k1::scalarMult($two, Secp256k1::getGenerator()),
                Secp256k1::scalarMult($two, $B), Secp256k1::getGenerator(), $B);
            $s = BigInt::fromHex($e)->add($two)->mod(Secp256k1::getOrder());
            $signature['dleq'] = ['e' => $e, 's' => Secp256k1::scalarToHex($s)];
        }
        return $signature;
    }

    public function testMintPlanMatchesActualOutputsAfterSwapAndRestart(): void
    {
        $requests = [];
        $post = function ($path, $data) use (&$requests): array {
            if ($path === 'swap') {
                return ['signatures' => array_map(fn($o) => $this->signature($o), $data['outputs'])];
            }
            $requests[] = $data['outputs'];
            throw new CashuException('Response lost');
        };
        $wallet = $this->wallet($post);
        $wallet->swap([new Proof(self::KEYSET, 1, 'input', self::G)], [1]);
        $this->assertSame(0, $wallet->getCounter(self::KEYSET), 'exercise stale diagnostic cache');
        foreach ([$wallet, $this->wallet($post)] as $instance) {
            try {
                $instance->mint('q', 3);
                $this->fail('Lost response accepted');
            } catch (CashuException $e) {
                $plan = $instance->getStorage()->getPendingOperationById('mint:q')['data'];
                $this->assertSame(1, $plan['counter_start']);
                $this->assertSame(3, $instance->getStorage()->getCounter(self::KEYSET));
            }
        }
        $this->assertSame($requests[0], $requests[1]);
        foreach ($requests[0] as $i => $output) {
            $this->assertSame($wallet->createDeterministicBlindedMessage(self::KEYSET, 1 + $i)['B_'], $output['B_']);
        }
    }

    public function testMintRejectsValidDleqWithWrongAmountOrKeyset(): void
    {
        foreach (['amount', 'id'] as $field) {
            $wallet = $this->wallet(function ($path, $data) use ($field): array {
                $output = $data['outputs'][0];
                $output[$field] = $field === 'amount' ? 1 : '00ad268c4d1f5826';
                $signature = $this->signature($output, true);
                $this->assertTrue(Crypto::verifyDleq($signature['dleq']['e'], $signature['dleq']['s'],
                    self::G, $output['B_'], $signature['C_']));
                return ['signatures' => [$signature]];
            });
            try {
                $wallet->mint($field, 8);
                $this->fail('Wrong response metadata accepted');
            } catch (CashuException $e) {
                $this->assertSame(0, $wallet->getBalance());
                $this->assertNotNull($wallet->getStorage()->getPendingOperationById('mint:' . $field));
            }
        }
    }

    public function testIssuedMintRestoresExactPlanAfterResponseLossAndRestart(): void
    {
        $submitted = [];
        $wallet = $this->wallet(function ($path, $data) use (&$submitted): array {
            $submitted = $data['outputs'];
            throw new CashuException('Response lost');
        });
        try { $wallet->mint('q', 3); } catch (CashuException $e) {}
        $plan = $wallet->getStorage()->getPendingOperationById('mint:q')['data'];
        $wallet = $this->wallet(function ($path, $data) use ($submitted): array {
            $this->assertSame($submitted, $data['outputs']);
            if ($path === 'mint/bolt11') {
                throw new CashuException('Already issued; replay cache expired');
            }
            $this->assertSame('restore', $path);
            return ['outputs' => $data['outputs'],
                'signatures' => array_map(fn($o) => $this->signature($o, true), $data['outputs'])];
        }, fn() => ['quote' => 'q', 'request' => 'lnbc-test', 'state' => 'ISSUED']);
        $proofs = $wallet->mint('q', 3);
        $this->assertSame(3, $wallet->getBalance());
        $this->assertNotNull($proofs[0]->dleq);
        $this->assertNull($wallet->getStorage()->getPendingOperationById('mint:q'));
        $wallet->getStorage()->preparePendingSpend('swap:next', 'swap', $proofs, self::KEYSET, [1, 2]);
        $wallet->getStorage()->finalizePendingMint('q', $plan, $proofs);
        $this->assertCount(2, $wallet->mint('q', 3), 'completed retry uses local receipt, not a new request');
        $this->assertSame(0, $wallet->getBalance());
    }

    public function testMintPlansAreImmutableAcrossWorkersAndQuotes(): void
    {
        $wallet = $this->wallet(fn() => []);
        $a = $wallet->getStorage();
        $b = $this->wallet(fn() => [])->getStorage();
        $first = $a->preparePendingSpend('mint:a', 'mint', [], self::KEYSET, [1, 2]);
        $second = $b->preparePendingSpend('mint:b', 'mint', [], self::KEYSET, [4]);
        $retry = $b->preparePendingSpend('mint:a', 'mint', [], 'different-active-keyset', [8]);
        $this->assertSame($first, $retry);
        $this->assertSame(2, $second['counter_start']);
        $this->assertSame(3, $a->getCounter(self::KEYSET));
    }

    public function testMintCompletionRollsBackProofsJournalAndQuoteKeyTogether(): void
    {
        $wallet = $this->wallet(fn() => []);
        $storage = $wallet->getStorage();
        $plan = $storage->preparePendingSpend('mint:q', 'mint', [], self::KEYSET, [1, 2]);
        $storage->storeMintQuoteKey('q', 0, self::G);
        $storage->getPdo()->exec("CREATE TRIGGER fail_completion BEFORE DELETE ON cashu_pending_operations
            BEGIN SELECT RAISE(ABORT, 'injected completion failure'); END");
        try {
            $storage->finalizePendingMint('q', $plan, [new Proof(self::KEYSET, 1, 'out', self::G)]);
            $this->fail('Injected failure missed');
        } catch (\PDOException $e) {
            $this->assertSame(0, $storage->getBalance());
            $this->assertNotNull($storage->getPendingOperationById('mint:q'));
            $this->assertNotNull($storage->getMintQuoteKey('q'));
        }
    }

    public function testSwapRecoveryRetainsJournalOnDuplicateOrInvalidDleq(): void
    {
        foreach (['duplicate', 'dleq', 'partial', 'amount', 'id'] as $fault) {
            $wallet = $this->wallet(function ($path, $data) use ($fault): array {
                if ($path === 'checkstate') {
                    return ['states' => array_map(fn($Y) => ['Y' => $Y, 'state' => 'SPENT'], $data['Ys'])];
                }
                $outputs = $data['outputs'];
                if ($fault === 'duplicate') { $outputs[1] = $outputs[0]; }
                if ($fault === 'partial') { array_pop($outputs); }
                $signatures = array_map(fn($o) => $this->signature($o, true), $outputs);
                if ($fault === 'dleq') { $signatures[0]['dleq']['e'] = str_repeat('00', 32); }
                if ($fault === 'amount') { $signatures[0]['amount'] = 1; }
                if ($fault === 'id') { $signatures[0]['id'] = '00ad268c4d1f5826'; }
                return ['outputs' => $outputs, 'signatures' => $signatures];
            });
            $storage = $wallet->getStorage();
            $id = 'swap:' . $fault;
            $storage->preparePendingSpend($id, 'swap', [new Proof(self::KEYSET, 8, $fault, self::G)], self::KEYSET, [4, 4]);
            $wallet->recoverPendingSwaps();
            $this->assertNotNull($storage->getPendingOperationById($id), $fault);
            $this->assertSame(ProofState::PENDING, $storage->getProofsStatesBySecrets([$fault])[$fault]);
            $this->assertSame(0, $storage->getBalance());
        }
    }
}
