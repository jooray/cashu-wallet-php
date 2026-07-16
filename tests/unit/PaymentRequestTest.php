<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\CashuException;
use Cashu\PaymentRequest;
use Cashu\Transport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NUT-18 payment requests against the official cashubtc/nuts encoded vectors
 * (tests/fixtures/nut18-payment-requests.json).
 */
final class PaymentRequestTest extends TestCase
{
    public static function officialVectors(): array
    {
        $out = [];
        foreach (cashu_fixture('nut18-payment-requests')['vectors'] as $v) {
            $out[$v['name']] = [$v];
        }
        return $out;
    }

    #[DataProvider('officialVectors')]
    public function testParseOfficialEncodedVectors(array $vector): void
    {
        $pr = PaymentRequest::parse($vector['encoded']);

        $this->assertSame($vector['id'], $pr->id);
        $this->assertSame($vector['amount'], $pr->amount);
        $this->assertSame($vector['unit'], $pr->unit);
        $this->assertSame($vector['mints'], $pr->mints);

        if (isset($vector['transport'])) {
            $this->assertNotNull($pr->transport);
            $this->assertSame($vector['transport'], $pr->transport->type);
        }
        if (isset($vector['memo'])) {
            $this->assertSame($vector['memo'], $pr->memo);
        }
    }

    public function testParseRejectsMissingPrefix(): void
    {
        $this->expectException(CashuException::class);
        PaymentRequest::parse('nonsense');
    }

    public function testSerializeParseRoundtrip(): void
    {
        $pr = new PaymentRequest(
            'ab12cd34',
            256,
            'sat',
            ['https://mint.example'],
            'lunch',
            Transport::http('https://shop.example/callback'),
            false
        );

        $serialized = $pr->serialize();
        $this->assertStringStartsWith('creqA', $serialized);

        $parsed = PaymentRequest::parse($serialized);
        $this->assertSame($pr->id, $parsed->id);
        $this->assertSame($pr->amount, $parsed->amount);
        $this->assertSame($pr->unit, $parsed->unit);
        $this->assertSame($pr->mints, $parsed->mints);
        $this->assertSame($pr->memo, $parsed->memo);
        $this->assertFalse($parsed->singleUse);
        $this->assertSame(Transport::TYPE_POST, $parsed->transport->type);
        $this->assertSame('https://shop.example/callback', $parsed->transport->target);
    }

    public function testTransportFactories(): void
    {
        $http = Transport::http('https://a.example');
        $this->assertSame(Transport::TYPE_POST, $http->type);

        $nostr = Transport::nostr('nprofile1xyz');
        $this->assertSame(Transport::TYPE_NOSTR, $nostr->type);
        $this->assertSame('nprofile1xyz', $nostr->target);

        $roundtrip = Transport::fromArray($nostr->toArray());
        $this->assertSame($nostr->type, $roundtrip->type);
        $this->assertSame($nostr->target, $roundtrip->target);
    }

    public function testGenerateIdIsRandomHex(): void
    {
        $a = PaymentRequest::generateId();
        $b = PaymentRequest::generateId();
        $this->assertSame(16, strlen($a));
        $this->assertTrue(ctype_xdigit($a));
        $this->assertNotSame($a, $b);
    }
}
