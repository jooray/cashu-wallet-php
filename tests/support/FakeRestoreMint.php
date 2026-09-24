<?php

declare(strict_types=1);

namespace Cashu\Tests\Support;

use Cashu\CashuException;
use Cashu\Crypto;
use Cashu\MintClient;
use Cashu\Secp256k1;
use Cashu\Wallet;

/**
 * In-process mint for NUT-09/NUT-07 recovery tests.
 *
 * Every denomination uses private key 1 (public key G), so the mint's signature on B_
 * is B_ itself and the unblinded C is Y. `signed` is the mint's history of blinded
 * outputs it signed for a seed; `states` answers NUT-07 by Y. Hooks can override or
 * fail any request.
 */
final class FakeRestoreMint extends MintClient
{
    public const G = '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    /** @var array<string, array{amount: int, id: string}> lower-case B_ => signed output */
    public array $signed = [];
    /** @var array<string, string> Y => NUT-07 state */
    public array $states = [];
    /** @var list<array> */
    public array $calls = [];
    /** @var array<string, array> quote id => GET melt quote response */
    public array $meltQuotes = [];
    /** @var callable|null fn(string $path, array $data): ?array — non-null result replaces the answer */
    public $onPost = null;
    /** @var callable|null fn(string $path): ?array */
    public $onGet = null;

    /** @param list<array{id: string, unit?: string, active?: bool}> $keysets */
    public function __construct(public array $keysets)
    {
        parent::__construct('https://mint.example');
    }

    /** @return array<string, string> */
    public static function keys(): array
    {
        $keys = [];
        for ($i = 0; $i < 21; $i++) {
            $keys[(string)(1 << $i)] = self::G;
        }
        return $keys;
    }

    public function get(string $path): array
    {
        $this->calls[] = ['GET', $path];
        if ($this->onGet !== null && ($answer = ($this->onGet)($path)) !== null) {
            return $answer;
        }
        if ($path === 'keysets') {
            return ['keysets' => $this->keysets];
        }
        if (str_starts_with($path, 'keys/')) {
            $id = urldecode(substr($path, 5));
            foreach ($this->keysets as $keyset) {
                if ($keyset['id'] === $id) {
                    return ['keysets' => [['id' => $id, 'unit' => $keyset['unit'] ?? 'sat', 'keys' => self::keys()]]];
                }
            }
            throw new CashuException("Unknown keyset $id");
        }
        if (str_starts_with($path, 'melt/quote/bolt11/')) {
            $quote = substr($path, strlen('melt/quote/bolt11/'));
            if (isset($this->meltQuotes[$quote])) {
                return $this->meltQuotes[$quote];
            }
        }
        throw new CashuException("Unexpected GET $path");
    }

    public function post(string $path, array $data, ?int $timeout = null): array
    {
        $this->calls[] = ['POST', $path, count($data['outputs'] ?? $data['Ys'] ?? [])];
        if ($this->onPost !== null && ($answer = ($this->onPost)($path, $data)) !== null) {
            return $answer;
        }
        return match ($path) {
            'restore' => $this->restore($data['outputs']),
            'checkstate' => $this->checkstate($data['Ys']),
            default => throw new CashuException("Unexpected POST $path"),
        };
    }

    public function restore(array $outputs): array
    {
        $returned = [];
        $signatures = [];
        foreach ($outputs as $output) {
            $key = strtolower($output['B_']);
            if (!isset($this->signed[$key])) {
                continue;
            }
            $signed = $this->signed[$key];
            $returned[] = ['amount' => $signed['amount'], 'id' => $signed['id'], 'B_' => $output['B_']];
            $signatures[] = ['id' => $signed['id'], 'amount' => $signed['amount'], 'C_' => $output['B_']];
        }
        return ['outputs' => $returned, 'signatures' => $signatures];
    }

    public function checkstate(array $Ys): array
    {
        return ['states' => array_map(
            fn($Y) => ['Y' => $Y, 'state' => $this->states[$Y] ?? 'UNSPENT', 'witness' => null],
            $Ys
        )];
    }

    /** Record that this mint signed the seed's output at $counter; returns its secret. */
    public function sign(Wallet $seed, string $keysetId, int $counter, int $amount, string $state = 'UNSPENT'): string
    {
        $blinded = $seed->createDeterministicBlindedMessage($keysetId, $counter);
        $this->signed[strtolower($blinded['B_'])] = ['amount' => $amount, 'id' => $keysetId];
        $this->states[self::y($blinded['secret'])] = $state;
        return $blinded['secret'];
    }

    public static function y(string $secret): string
    {
        return bin2hex(Secp256k1::compressPoint(Crypto::hashToCurve($secret)));
    }

    /** Number of restore outputs requested so far. */
    public function restoredOutputs(): int
    {
        $n = 0;
        foreach ($this->calls as $call) {
            if ($call[0] === 'POST' && $call[1] === 'restore') {
                $n += $call[2];
            }
        }
        return $n;
    }
}
