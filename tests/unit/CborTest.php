<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\CashuException;
use Cashu\CBOR;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CborTest extends TestCase
{
    public static function integerBoundaries(): array
    {
        // Covers every CBOR head width: immediate, 1-, 2-, 4- and 8-byte lengths
        $values = [0, 1, 23, 24, 255, 256, 65535, 65536, 4294967295, 4294967296, PHP_INT_MAX,
                   -1, -24, -25, -256, -257, -65536, -65537];
        return array_combine(array_map('strval', $values), array_map(fn($v) => [$v], $values));
    }

    #[DataProvider('integerBoundaries')]
    public function testIntegerRoundtrip(int $value): void
    {
        $this->assertSame($value, CBOR::decode(CBOR::encode($value)));
    }

    public function testCanonicalHeadEncodings(): void
    {
        // RFC 8949 examples
        $this->assertSame("\x00", CBOR::encode(0));
        $this->assertSame("\x17", CBOR::encode(23));
        $this->assertSame("\x18\x18", CBOR::encode(24));
        $this->assertSame("\x19\x01\x00", CBOR::encode(256));
        $this->assertSame("\x20", CBOR::encode(-1));
        $this->assertSame("\xf4", CBOR::encode(false));
        $this->assertSame("\xf5", CBOR::encode(true));
        $this->assertSame("\xf6", CBOR::encode(null));
        $this->assertSame("\x64\x74\x65\x78\x74", CBOR::encode('text'));
    }

    public function testTextStringRoundtrip(): void
    {
        foreach (['', 'a', 'hello world', 'ünïcödé ✓', str_repeat('x', 300)] as $s) {
            $this->assertSame($s, CBOR::decode(CBOR::encode($s)));
        }
    }

    public function testBinaryStringEncodesAsByteStringAndRoundtrips(): void
    {
        $binary = "\x00\x01\x02\xff\xfe";
        $encoded = CBOR::encode($binary);
        $this->assertSame(2, ord($encoded[0]) >> 5, 'must use byte-string major type');
        $this->assertSame($binary, CBOR::decode($encoded));
    }

    public function testArrayAndMapRoundtrip(): void
    {
        $value = [
            'list' => [1, 2, 3, 'four'],
            'nested' => ['a' => ['b' => ['c' => true]]],
            'nothing' => null,
            'flag' => false,
        ];
        $this->assertSame($value, CBOR::decode(CBOR::encode($value)));
    }

    public function testEmptyArrayRoundtrip(): void
    {
        $this->assertSame([], CBOR::decode(CBOR::encode([])));
    }

    public function testListEncodesAsCborArray(): void
    {
        $encoded = CBOR::encode([1, 2, 3]);
        $this->assertSame(4, ord($encoded[0]) >> 5, 'sequential array must use array major type');

        $encoded = CBOR::encode(['k' => 'v']);
        $this->assertSame(5, ord($encoded[0]) >> 5, 'assoc array must use map major type');
    }

    public function testDecodeRejectsTruncatedInput(): void
    {
        $this->expectException(CashuException::class);
        CBOR::decode('');
    }

    public function testDecodeRejectsTruncatedMap(): void
    {
        // Map header claiming 4 entries with no content
        $this->expectException(CashuException::class);
        CBOR::decode("\xa4");
    }

    public function testDecodeUndefinedAsNull(): void
    {
        // 0xf7 (undefined) appears in official NUT-18 vectors; decoded as null
        $this->assertNull(CBOR::decode("\xf7"));
        $this->assertNull(CBOR::decode("\xf6"));
    }
}
