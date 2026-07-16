<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\Unit;
use Cashu\Wallet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitFormatTest extends TestCase
{
    public static function formatCases(): array
    {
        return [
            'sat' => ['sat', 100, '100 sat'],
            'msat' => ['msat', 2500, '2500 msat'],
            'usd cents' => ['usd', 150, '$1.50'],
            'usd sub-dollar' => ['usd', 5, '$0.05'],
            'eur' => ['eur', 50, "\u{20AC}0.50"],
            'btc' => ['btc', 100, "\u{20BF}0.00000100"],
            'unknown unit' => ['chf', 7, '7 chf'],
            'uppercase code' => ['USD', 150, '$1.50'],
        ];
    }

    #[DataProvider('formatCases')]
    public function testFormat(string $code, int $amount, string $expected): void
    {
        $this->assertSame($expected, Unit::fromCode($code)->format($amount));
        $this->assertSame($expected, Wallet::formatAmountForUnit($amount, $code));
    }

    public static function parseCases(): array
    {
        return [
            'sat integer' => ['sat', '100', 100],
            'usd decimal' => ['usd', '1.50', 150],
            'usd cents' => ['usd', '0.05', 5],
            'usd whole' => ['usd', '2', 200],
            'usd with symbol' => ['usd', '$1.50', 150],
            'usd thousands separator' => ['usd', '1,000.25', 100025],
            'eur' => ['eur', '0.50', 50],
            'btc' => ['btc', '0.00000100', 100],
        ];
    }

    #[DataProvider('parseCases')]
    public function testParse(string $code, string $input, int $expected): void
    {
        $this->assertSame($expected, Unit::fromCode($code)->parse($input));
    }

    public function testParseRejectsNonNumericInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Unit::fromCode('usd')->parse('lots');
    }

    public function testFormatParseRoundtrip(): void
    {
        foreach (['sat', 'usd', 'eur', 'btc'] as $code) {
            $unit = Unit::fromCode($code);
            foreach ([1, 5, 99, 100, 12345] as $amount) {
                $this->assertSame($amount, $unit->parse($unit->format($amount)), "$code $amount");
            }
        }
    }

    public function testUnitMetadata(): void
    {
        $usd = Unit::fromCode('usd');
        $this->assertSame('usd', $usd->code);
        $this->assertSame(2, $usd->decimals);
        $this->assertSame('USD', $usd->getName());
        $this->assertSame('1.00', $usd->getExampleAmount());

        $sat = Unit::fromCode('sat');
        $this->assertSame(0, $sat->decimals);
        $this->assertSame('100', $sat->getExampleAmount());

        $btc = Unit::fromCode('btc');
        $this->assertSame('0.0001', $btc->getExampleAmount());

        $unknown = Unit::fromCode('xyz');
        $this->assertSame(0, $unknown->decimals);
        $this->assertSame('xyz', $unknown->symbol);
    }

    public function testWalletAmountHelpers(): void
    {
        $wallet = new Wallet('https://mint.example', 'eur');
        $this->assertSame("\u{20AC}1.50", $wallet->formatAmount(150));
        $this->assertSame(150, $wallet->parseAmount('1.50'));
        $this->assertSame('eur', $wallet->getUnitHelper()->code);
    }
}
