<?php

declare(strict_types=1);

namespace Cashu\Tests;

use Cashu\Bech32;
use Cashu\CashuException;
use Cashu\PaymentRequest;
use Cashu\Transport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NUT-26 `creqB` payment requests (TLV + Bech32m).
 *
 * The vectors are cdk's (crates/cashu/src/nuts/nut26/encoding.rs, which also carries
 * the NUT-26 spec example); cashu-ts produces the same bytes. Encoding must match them
 * byte for byte, and decoding then re-encoding must reproduce them.
 */
final class PaymentRequestCreqBTest extends TestCase
{
    /** name => [request as NUT-18 JSON, expected creqB, whether the JSON states `s`] */
    public static function cdkVectors(): array
    {
        return [
            'nut26 example' => ['{"i":"demo123","a":1000,"u":"sat","s":true,"m":["https://mint.example.com"],"d":"Coffee payment"}', 'CREQB1QYQQWER9D4HNZV3NQGQQSQQQQQQQQQQRAQPSQQGQQSQQZQG9QQVXSAR5WPEN5TE0D45KUAPWV4UXZMTSD3JJUCM0D5RQQRJRDANXVET9YPCXZ7TDV4H8GXHR3TQ', true],
            'http post' => ['{"i":"http_test","a":250,"u":"sat","m":["https://mint.example.com"],"t":[{"t":"post","a":"https://api.example.com/v1/payment","g":[["custom","value1","value2"]]}]}', 'CREQB1QYQQJ6R5W3C97AR9WD6QYQQGQQQQQQQQQQQ05QCQQYQQ2QQCDP68GURN8GHJ7MTFDE6ZUETCV9KHQMR99E3K7MG8QPQSZQQPQYPQQGNGW368QUE69UHKZURF9EJHSCTDWPKX2TNRDAKJ7A339ACXZ7TDV4H8GQCQZ5RXXATNW3HK6PNKV9K82EF3QEMXZMR4V5EQ9X3SJM', false],
            'nostr' => ['{"i":"f92a51b8","a":100,"u":"sat","m":["https://mint1.example.com","https://mint2.example.com"],"t":[{"t":"nostr","a":"nprofile1qqsqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq8uzqt","g":[["n","17"],["n","9735"]]}]}', 'CREQB1QYQQSE3EXFSN2VTZ8QPQQZQQQQQQQQQQQPJQXQQPQQZSQXTGW368QUE69UHK66TWWSCJUETCV9KHQMR99E3K7MG9QQVKSAR5WPEN5TE0D45KUAPJ9EJHSCTDWPKX2TNRDAKSWQPEQYQQZQQZQQSQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQRQQZSZMSZXYMSXQQ8Q9HQGWFHXV6SCAGZ48', false],
            'relays' => ['{"i":"relay_test","a":100,"u":"sat","m":["https://mint.example.com"],"t":[{"t":"nostr","a":"nprofile1qqsrhuxx8l9ex335q7he0f09aej04zpazpl0ne2cgukyawd24mayt8gprpmhxue69uhhyetvv9unztn90psk6urvv5hxxmmdqyv8wumn8ghj7un9d3shjv3wv4uxzmtsd3jjucm0d5q3samnwvaz7tmjv4kxz7fn9ejhsctdwpkx2tnrdaksxzjpjp"}]}', 'CREQB1QYQQ5UN9D3SHJHM5V4EHGQSQPQQQQQQQQQQQQEQRQQQSQPGQRP58GARSWVAZ7TMDD9H8GTN90PSK6URVV5HXXMMDQUQGZQGQQYQQYQPQ80CVV07TJDRRGPA0J7J7TMNYL2YR6YR7L8J4S3EVF6U64TH6GKWSXQQMQ9EPSAMNWVAZ7TMJV4KXZ7F39EJHSCTDWPKX2TNRDAKSXQQMQ9EPSAMNWVAZ7TMJV4KXZ7FJ9EJHSCTDWPKX2TNRDAKSXQQMQ9EPSAMNWVAZ7TMJV4KXZ7FN9EJHSCTDWPKX2TNRDAKSKRFDAR', false],
            'nut10' => ['{"i":"c9e45d2a","a":500,"u":"sat","m":["https://mint.example.com"],"nut10":{"k":"P2PK","d":"02c3b5bb27e361457c92d93d78dd73d3d53732110b2cfe8b50fbc0abc615e9c331","t":[["timeout","3600"]]}}', 'CREQB1QYQQSCEEV56R2EPJVYPQQZQQQQQQQQQQQ86QXQQPQQZSQXRGW368QUE69UHK66TWWSHX27RPD4CXCEFWVDHK6ZQQTYQSQQGQQGQYYVPJVVEKYDTZVGERWEFNXCCNGDFHVVUNYEPEXDJRWWRYVSMNXEPNVS6NXDENXGCNZVRZXF3KVEFCVG6NQENZVVCXZCNRXCCN2EFEVVENXVGRQQXSWARFD4JK7AT5QSENVVPS2N5FAS', false],
            'minimal' => ['{"i":"7f4a2b39","u":"sat","m":["https://mint.example.com"]}', 'CREQB1QYQQSDMXX3SNYC3N8YPSQQGQQ5QPS6R5W3C8XW309AKKJMN59EJHSCTDWPKX2TNRDAKSYP0LHG', false],
            'msat' => ['{"i":"unit_msat","a":1000,"u":"msat","m":["https://mint.example.com"]}', 'CREQB1QYQQJATWD9697MTNV96QYQQGQQQQQQQQQQP7SQCQQ3KHXCT5Q5QPS6R5W3C8XW309AKKJMN59EJHSCTDWPKX2TNRDAKSYYMU95', false],
            'usd' => ['{"i":"unit_usd","a":500,"u":"usd","m":["https://mint.example.com"]}', 'CREQB1QYQQSATWD9697ATNVSPQQZQQQQQQQQQQQ86QXQQRW4EKGPGQRP58GARSWVAZ7TMDD9H8GTN90PSK6URVV5HXXMMDEPCJYC', false],
            'single true' => ['{"i":"single_use_true","a":100,"u":"sat","s":true,"m":["https://mint.example.com"]}', 'CREQB1QYQQ7UMFDENKCE2LW4EK2HM5WF6K2QSQPQQQQQQQQQQQQEQRQQQSQPQQQYQS2QQCDP68GURN8GHJ7MTFDE6ZUETCV9KHQMR99E3K7MGX0AYM7', true],
            'single false' => ['{"i":"single_use_false","a":100,"u":"sat","s":false,"m":["https://mint.example.com"]}', 'CREQB1QYQPQUMFDENKCE2LW4EK2HMXV9K8XEGZQQYQQQQQQQQQQQRYQVQQZQQYQQQSQPGQRP58GARSWVAZ7TMDD9H8GTN90PSK6URVV5HXXMMDQ40L90', true],
            'description' => ['{"i":"desc_test","a":100,"u":"sat","m":["https://mint.example.com"],"d":"Test payment description"}', 'CREQB1QYQQJER9WD347AR9WD6QYQQGQQQQQQQQQQQXGQCQQYQQ2QQCDP68GURN8GHJ7MTFDE6ZUETCV9KHQMR99E3K7MGXQQV9GETNWSS8QCTED4JKUAPQV3JHXCMJD9C8G6T0DCFLJJRX', false],
            'methods' => ['{"i":"preferred_fee_methods","a":100,"u":"sat","m":["https://mint.example.com"],"mp":true,"sm":[{"mn":"bolt11"},{"mn":"bolt12","mf":5}]}', 'CREQB1QYQP2URJV4NX2UNJV4J97EN9V40K6ET5DPHKGUCZQQYQQQQQQQQQQQRYQVQQZQQ9QQVXSAR5WPEN5TE0D45KUAPWV4UXZMTSD3JJUCM0D5YSQQGPPGQQJQGQQE3X7MR5XYCS5QQ5QYQQVCN0D36RZVSZQQYQQQQQQQQQQQQ9FJ2568', false],
            'multi transports' => ['{"i":"multi_transport","a":500,"u":"sat","m":["https://mint.example.com"],"d":"Payment with multiple transports","t":[{"t":"nostr","a":"nprofile1qqsrhuxx8l9ex335q7he0f09aej04zpazpl0ne2cgukyawd24mayt8g2lcy6q","g":[["n","17"]]},{"t":"post","a":"https://api1.example.com/payment"},{"t":"post","a":"https://api2.example.com/payment","g":[["priority","backup"]]}]}', 'CREQB1QYQQ7MT4D36XJHM5WFSKUUMSDAE8GQSQPQQQQQQQQQQQRAQRQQQSQPGQRP58GARSWVAZ7TMDD9H8GTN90PSK6URVV5HXXMMDQCQZQ5RP09KK2MN5YPMKJARGYPKH2MR5D9CXCEFQW3EXZMNNWPHHYARNQUQZ7QGQQYQQYQPQ80CVV07TJDRRGPA0J7J7TMNYL2YR6YR7L8J4S3EVF6U64TH6GKWSXQQ9Q9HQYVFHQUQZWQGQQYQSYQPQDP68GURN8GHJ7CTSDYCJUETCV9KHQMR99E3K7MF0WPSHJMT9DE6QWQP6QYQQZQGZQQSXSAR5WPEN5TE0V9CXJV3WV4UXZMTSD3JJUCM0D5HHQCTED4JKUAQRQQGQSURJD9HHY6T50YRXYCTRDD6HQTSH7TP', false],
        ];
    }

    #[DataProvider('cdkVectors')]
    public function testEncodesLikeCdk(string $json, string $expected, bool $statesSingleUse): void
    {
        $pr = PaymentRequest::fromArray(json_decode($json, true));
        if (!$statesSingleUse) {
            $pr->singleUse = null;
        }
        $this->assertSame($expected, $pr->serializeB());
    }

    #[DataProvider('cdkVectors')]
    public function testDecodesAndReencodes(string $json, string $expected, bool $statesSingleUse): void
    {
        $parsed = PaymentRequest::parse($expected);
        $this->assertSame($expected, $parsed->serializeB());
        $this->assertSame($expected, PaymentRequest::parse(strtolower($expected))->serializeB(),
            'decoders must accept lowercase too');

        $want = json_decode($json, true);
        $this->assertSame($want['i'] ?? '', $parsed->id);
        $this->assertSame($want['a'] ?? null, $parsed->amount);
        $this->assertSame($want['u'] ?? 'sat', $parsed->unit);
        $this->assertSame($want['m'] ?? [], $parsed->mints);
        $this->assertSame($want['d'] ?? null, $parsed->memo);
        $this->assertCount(count($want['t'] ?? []), $parsed->transports);
    }

    public function testFieldsSurviveTheRoundTrip(): void
    {
        $pr = new PaymentRequest('abc', 2100, 'sat', ['https://mint.example'], 'Coffee',
            Transport::http('https://shop.example/receive?store_id=s1'), true);
        $back = PaymentRequest::parse($pr->serializeB());
        $this->assertSame('abc', $back->id);
        $this->assertSame(2100, $back->amount);
        $this->assertSame('sat', $back->unit);
        $this->assertSame(['https://mint.example'], $back->mints);
        $this->assertSame('Coffee', $back->memo);
        $this->assertTrue($back->singleUse);
        $this->assertSame(Transport::TYPE_POST, $back->transport->type);
        $this->assertSame('https://shop.example/receive?store_id=s1', $back->transport->target);
    }

    public function testCreqAStillParses(): void
    {
        $pr = new PaymentRequest('abc', 21, 'sat', ['https://mint.example']);
        $this->assertSame(21, PaymentRequest::parse($pr->serialize())->amount);
    }

    public function testUnknownTagsAreIgnored(): void
    {
        $pr = PaymentRequest::parse('CREQB1QYQQSDMXX3SNYC3N8YPSQQGQQ5QPS6R5W3C8XW309AKKJMN59EJHSCTDWPKX2TNRDAKSYP0LHG');
        // Append an unknown tag 0x7f to the TLV body and re-wrap it.
        [, $bytes] = Bech32::decode('creqb1qyqqsdmxx3snyc3n8ypsqqgqq5qps6r5w3c8xw309akkjmn59ejhsctdwpkx2tnrdaksyp0lhg', Bech32::BECH32M);
        $withUnknown = Bech32::encode('creqb', $bytes . "\x7f\x00\x02hi", Bech32::BECH32M);
        $this->assertSame($pr->id, PaymentRequest::parse($withUnknown)->id);
    }

    public static function malformed(): array
    {
        $minimal = 'creqb1qyqqsdmxx3snyc3n8ypsqqgqq5qps6r5w3c8xw309akkjmn59ejhsctdwpkx2tnrdaksyp0lhg';
        return [
            'bad checksum' => [substr($minimal, 0, -1) . 'q'],
            'mixed case' => ['CREQB1' . substr($minimal, 6)],
            'bech32 not bech32m' => [Bech32::encode('creqb', "\x01\x00\x01x", Bech32::BECH32)],
            'truncated field' => [Bech32::encode('creqb', "\x01\x00\x09abc", Bech32::BECH32M)],
            'repeated id' => [Bech32::encode('creqb', "\x01\x00\x01a\x01\x00\x01b", Bech32::BECH32M)],
            'u64 of the wrong size' => [Bech32::encode('creqb', "\x02\x00\x02\x00\x01", Bech32::BECH32M)],
            'not a request at all' => ['lnbc1nonsense'],
        ];
    }

    #[DataProvider('malformed')]
    public function testMalformedInputIsRefused(string $input): void
    {
        $this->expectException(CashuException::class);
        PaymentRequest::parse($input);
    }
}
