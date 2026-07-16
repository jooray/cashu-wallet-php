<?php
/**
 * CashuWallet - A PHP implementation of the Cashu protocol
 *
 * Single-file library for interacting with Cashu mints.
 * Supports minting, melting, swapping, sending, and receiving Cashu tokens.
 *
 * Requirements:
 * - PHP 8.0+
 * - ext-gmp OR ext-bcmath (for big integer math; GMP preferred for performance)
 * - ext-curl (for HTTP)
 * - ext-json (standard)
 *
 * @see https://github.com/cashubtc/nuts - Cashu NUT specifications
 */

declare(strict_types=1);

namespace Cashu;

// ============================================================================
// EXCEPTIONS
// ============================================================================

/**
 * Base exception for all Cashu errors
 */
class CashuException extends \Exception {}

/**
 * Protocol error from the mint
 */
class CashuProtocolException extends CashuException
{
    // Standardized mint error codes (nuts/error_codes.md). getCode() returns
    // the mint-provided code, or 0 when the mint sent none.
    public const PROOF_VERIFICATION_FAILED = 10001;
    public const PROOFS_ALREADY_SPENT = 11001;
    public const PROOFS_PENDING = 11002;
    public const OUTPUTS_ALREADY_SIGNED = 11003;
    public const TRANSACTION_UNBALANCED = 11005;
    public const KEYSET_UNKNOWN = 12001;
    public const KEYSET_INACTIVE = 12002;
    public const KEYSET_EXPIRED = 12003;
    public const QUOTE_NOT_PAID = 20001;
    public const QUOTE_ALREADY_ISSUED = 20002;
    public const QUOTE_PENDING = 20005;
    public const QUOTE_EXPIRED = 20007;
    public const MINT_SIGNATURE_INVALID = 20008;
    public const MINT_PUBKEY_REQUIRED = 20009;

    public function __construct(string $message, ?int $code = null)
    {
        parent::__construct($message, $code ?? 0);
    }
}

/**
 * Insufficient balance error
 */
class InsufficientBalanceException extends CashuException {}

// ============================================================================
// BIG INTEGER ABSTRACTION (GMP with BCMath fallback)
// ============================================================================

/**
 * Big integer abstraction supporting both GMP and BCMath
 *
 * Provides a unified interface for arbitrary-precision arithmetic,
 * automatically selecting GMP when available or falling back to BCMath.
 */
class BigInt
{
    private static bool $initialized = false;
    private static bool $useGmp = true;

    /** @var \GMP|string Internal value (GMP object or decimal string for BCMath) */
    private \GMP|string $value;

    private function __construct(\GMP|string $value)
    {
        $this->value = $value;
    }

    /**
     * Initialize the BigInt system, detecting available extensions
     *
     * @throws CashuException if neither GMP nor BCMath is available
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$useGmp = extension_loaded('gmp');
        if (!self::$useGmp && !extension_loaded('bcmath')) {
            throw new CashuException(
                'Either ext-gmp or ext-bcmath is required for Cashu operations'
            );
        }

        self::$initialized = true;
    }

    /**
     * Check if GMP is being used (vs BCMath)
     */
    public static function isUsingGmp(): bool
    {
        self::init();
        return self::$useGmp;
    }

    // ========================================================================
    // FACTORY METHODS
    // ========================================================================

    /**
     * Create BigInt from hexadecimal string
     */
    public static function fromHex(string $hex): self
    {
        self::init();
        $hex = ltrim($hex, '0') ?: '0';

        if (self::$useGmp) {
            return new self(gmp_init($hex, 16));
        }

        // BCMath: convert hex to decimal
        $dec = '0';
        $hex = strtolower($hex);
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $digit = strpos('0123456789abcdef', $hex[$i]);
            $dec = bcadd(bcmul($dec, '16'), (string)$digit);
        }
        return new self($dec);
    }

    /**
     * Create BigInt from decimal string or integer
     */
    public static function fromDec(string|int $dec): self
    {
        self::init();
        $dec = (string)$dec;

        if (self::$useGmp) {
            return new self(gmp_init($dec, 10));
        }

        return new self($dec);
    }

    /**
     * Create BigInt representing zero
     */
    public static function zero(): self
    {
        return self::fromDec('0');
    }

    /**
     * Create BigInt representing one
     */
    public static function one(): self
    {
        return self::fromDec('1');
    }

    // ========================================================================
    // ARITHMETIC OPERATIONS
    // ========================================================================

    /**
     * Add two BigInts
     */
    public function add(BigInt $other): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_add($this->value, $other->value));
        }
        return new self(bcadd($this->value, $other->value));
    }

    /**
     * Subtract another BigInt from this one
     */
    public function sub(BigInt $other): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_sub($this->value, $other->value));
        }
        return new self(bcsub($this->value, $other->value));
    }

    /**
     * Multiply two BigInts
     */
    public function mul(BigInt $other): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_mul($this->value, $other->value));
        }
        return new self(bcmul($this->value, $other->value));
    }

    /**
     * Compute modulo
     */
    public function mod(BigInt $m): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_mod($this->value, $m->value));
        }

        // BCMath mod that handles negative numbers correctly
        $result = bcmod($this->value, $m->value);
        if (bccomp($result, '0') < 0) {
            $result = bcadd($result, $m->value);
        }
        return new self($result);
    }

    /**
     * Raise to an integer power
     */
    public function pow(int $exp): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_pow($this->value, $exp));
        }
        return new self(bcpow($this->value, (string)$exp));
    }

    /**
     * Modular exponentiation: (this ^ exp) mod m
     */
    public function powMod(BigInt $exp, BigInt $m): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_powm($this->value, $exp->value, $m->value));
        }

        // BCMath modular exponentiation using square-and-multiply
        return self::bcPowMod($this->value, $exp->value, $m->value);
    }

    /**
     * BCMath implementation of modular exponentiation
     */
    private static function bcPowMod(string $base, string $exp, string $mod): self
    {
        $result = '1';
        $base = bcmod($base, $mod);

        while (bccomp($exp, '0') > 0) {
            // If exp is odd, multiply result by base
            if (bcmod($exp, '2') === '1') {
                $result = bcmod(bcmul($result, $base), $mod);
            }
            $exp = bcdiv($exp, '2', 0);
            $base = bcmod(bcmul($base, $base), $mod);
        }

        return new self($result);
    }

    /**
     * Integer division
     */
    public function div(BigInt $other): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_div_q($this->value, $other->value));
        }
        return new self(bcdiv($this->value, $other->value, 0));
    }

    /**
     * Negate the value
     */
    public function neg(): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_neg($this->value));
        }
        return new self(bcmul($this->value, '-1'));
    }

    /**
     * Right shift by n bits (equivalent to div by 2^n)
     */
    public function shiftRight(int $bits): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_div_q($this->value, gmp_pow(2, $bits)));
        }
        return new self(bcdiv($this->value, bcpow('2', (string)$bits), 0));
    }

    // ========================================================================
    // COMPARISON OPERATIONS
    // ========================================================================

    /**
     * Compare with another BigInt
     * Returns: -1 if this < other, 0 if equal, 1 if this > other
     */
    public function cmp(BigInt $other): int
    {
        if (self::$useGmp) {
            return gmp_cmp($this->value, $other->value);
        }
        return bccomp($this->value, $other->value);
    }

    /**
     * Check if value is zero
     */
    public function isZero(): bool
    {
        return $this->cmp(self::zero()) === 0;
    }

    /**
     * Check if value is odd
     */
    public function isOdd(): bool
    {
        if (self::$useGmp) {
            return gmp_cmp(gmp_and($this->value, 1), 1) === 0;
        }
        // For BCMath, check last digit
        $lastDigit = (int)substr($this->value, -1);
        return $lastDigit % 2 === 1;
    }

    /**
     * Check if value is negative
     */
    public function isNegative(): bool
    {
        return $this->cmp(self::zero()) < 0;
    }

    // ========================================================================
    // CONVERSION METHODS
    // ========================================================================

    /**
     * Convert to hexadecimal string
     *
     * @param int $padLength Pad to this many characters (0 for no padding)
     */
    public function toHex(int $padLength = 0): string
    {
        if (self::$useGmp) {
            $hex = gmp_strval($this->value, 16);
        } else {
            // BCMath: convert decimal to hex
            $hex = '';
            $val = $this->value;

            if (bccomp($val, '0') === 0) {
                $hex = '0';
            } else {
                $isNegative = bccomp($val, '0') < 0;
                if ($isNegative) {
                    $val = bcmul($val, '-1');
                }

                while (bccomp($val, '0') > 0) {
                    $remainder = bcmod($val, '16');
                    $hex = '0123456789abcdef'[(int)$remainder] . $hex;
                    $val = bcdiv($val, '16', 0);
                }

                if ($isNegative) {
                    $hex = '-' . $hex;
                }
            }
        }

        if ($padLength > 0) {
            $hex = str_pad($hex, $padLength, '0', STR_PAD_LEFT);
        }

        return $hex;
    }

    /**
     * Convert to decimal string
     */
    public function toDec(): string
    {
        if (self::$useGmp) {
            return gmp_strval($this->value, 10);
        }
        return $this->value;
    }

    // ========================================================================
    // SPECIAL OPERATIONS
    // ========================================================================

    /**
     * Compute modular inverse: find x where (this * x) mod m = 1
     *
     * @throws CashuException if inverse doesn't exist
     */
    public function modInverse(BigInt $m): BigInt
    {
        if (self::$useGmp) {
            $result = gmp_gcdext($this->value, $m->value);
            if (gmp_cmp($result['g'], 1) !== 0) {
                throw new CashuException('Modular inverse does not exist');
            }
            return new self(gmp_mod(gmp_add($result['s'], $m->value), $m->value));
        }

        // BCMath: Extended Euclidean algorithm
        $result = self::gcdExtBcmath($this->value, $m->value);
        if ($result['g'] !== '1') {
            throw new CashuException('Modular inverse does not exist');
        }

        $inv = bcmod(bcadd($result['s'], $m->value), $m->value);
        return new self($inv);
    }

    /**
     * Extended GCD using BCMath
     * Returns ['g' => gcd, 's' => coefficient s, 't' => coefficient t]
     * Such that: gcd = a*s + b*t
     */
    private static function gcdExtBcmath(string $a, string $b): array
    {
        $old_r = $a;
        $r = $b;
        $old_s = '1';
        $s = '0';
        $old_t = '0';
        $t = '1';

        while (bccomp($r, '0') !== 0) {
            $q = bcdiv($old_r, $r, 0);

            $temp = $r;
            $r = bcsub($old_r, bcmul($q, $r));
            $old_r = $temp;

            $temp = $s;
            $s = bcsub($old_s, bcmul($q, $s));
            $old_s = $temp;

            $temp = $t;
            $t = bcsub($old_t, bcmul($q, $t));
            $old_t = $temp;
        }

        return ['g' => $old_r, 's' => $old_s, 't' => $old_t];
    }

    /**
     * Bitwise AND with another BigInt
     */
    public function bitAnd(BigInt $other): BigInt
    {
        if (self::$useGmp) {
            return new self(gmp_and($this->value, $other->value));
        }

        // BCMath: implement bitwise AND via binary conversion
        // For our use case (AND with small values), simplified approach
        $a = $this->value;
        $b = $other->value;

        // Convert to binary, perform AND, convert back
        $binA = self::decToBin($a);
        $binB = self::decToBin($b);

        // Pad to same length
        $maxLen = max(strlen($binA), strlen($binB));
        $binA = str_pad($binA, $maxLen, '0', STR_PAD_LEFT);
        $binB = str_pad($binB, $maxLen, '0', STR_PAD_LEFT);

        $result = '';
        for ($i = 0; $i < $maxLen; $i++) {
            $result .= ($binA[$i] === '1' && $binB[$i] === '1') ? '1' : '0';
        }

        return new self(self::binToDec($result));
    }

    /**
     * Convert decimal string to binary string (BCMath helper)
     */
    private static function decToBin(string $dec): string
    {
        if (bccomp($dec, '0') === 0) {
            return '0';
        }

        $bin = '';
        while (bccomp($dec, '0') > 0) {
            $bin = bcmod($dec, '2') . $bin;
            $dec = bcdiv($dec, '2', 0);
        }

        return $bin ?: '0';
    }

    /**
     * Convert binary string to decimal string (BCMath helper)
     */
    private static function binToDec(string $bin): string
    {
        $dec = '0';
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcmul($dec, '2');
            if ($bin[$i] === '1') {
                $dec = bcadd($dec, '1');
            }
        }
        return $dec;
    }

    /**
     * Get the raw internal value (for debugging/testing)
     */
    public function getRawValue(): \GMP|string
    {
        return $this->value;
    }
}

// ============================================================================
// SECP256K1 ELLIPTIC CURVE OPERATIONS
// ============================================================================

/**
 * Secp256k1 elliptic curve operations
 *
 * Implementation of secp256k1 curve operations using BigInt abstraction.
 * Supports both GMP and BCMath backends.
 * y^2 = x^3 + 7 (mod p)
 */
class Secp256k1
{
    // secp256k1 curve parameters (hex)
    public const P = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';
    public const N = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
    public const GX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    public const GY = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';
    public const A = '0';
    public const B = '7';

    private static ?BigInt $p = null;
    private static ?BigInt $n = null;
    private static ?array $G = null; // [BigInt, BigInt]

    /**
     * Initialize curve parameters
     */
    private static function init(): void
    {
        if (self::$p === null) {
            BigInt::init(); // Ensure BigInt system is initialized
            self::$p = BigInt::fromHex(self::P);
            self::$n = BigInt::fromHex(self::N);
            self::$G = [
                BigInt::fromHex(self::GX),
                BigInt::fromHex(self::GY)
            ];
        }
    }

    /**
     * Get the generator point G
     *
     * @return array [BigInt, BigInt]
     */
    public static function getGenerator(): array
    {
        self::init();
        return self::$G;
    }

    /**
     * Get curve order n
     */
    public static function getOrder(): BigInt
    {
        self::init();
        return self::$n;
    }

    /**
     * Get field prime p
     */
    public static function getPrime(): BigInt
    {
        self::init();
        return self::$p;
    }

    /**
     * Modular inverse using BigInt
     */
    public static function modInverse(BigInt $a, BigInt $m): BigInt
    {
        return $a->modInverse($m);
    }

    /**
     * Point addition on the curve
     * Returns null for point at infinity
     *
     * @param array|null $p1 [BigInt, BigInt] or null
     * @param array|null $p2 [BigInt, BigInt] or null
     * @return array|null [BigInt, BigInt] or null
     */
    public static function pointAdd(?array $p1, ?array $p2): ?array
    {
        self::init();

        if ($p1 === null) return $p2;
        if ($p2 === null) return $p1;

        [$x1, $y1] = $p1;
        [$x2, $y2] = $p2;

        $p = self::$p;

        // Check if points are inverses (result is point at infinity)
        if ($x1->cmp($x2) === 0 && $y1->add($y2)->mod($p)->isZero()) {
            return null;
        }

        // Calculate slope
        if ($x1->cmp($x2) === 0 && $y1->cmp($y2) === 0) {
            // Point doubling: slope = (3 * x1^2) / (2 * y1)
            $three = BigInt::fromDec(3);
            $two = BigInt::fromDec(2);
            $num = $three->mul($x1->pow(2))->mod($p);
            $den = $two->mul($y1)->mod($p);
        } else {
            // Point addition: slope = (y2 - y1) / (x2 - x1)
            $num = $y2->sub($y1)->mod($p);
            $den = $x2->sub($x1)->mod($p);
        }

        $slope = $num->mul(self::modInverse($den, $p))->mod($p);

        // Calculate new point
        // x3 = slope^2 - x1 - x2
        $x3 = $slope->pow(2)->sub($x1)->sub($x2)->mod($p);
        // y3 = slope * (x1 - x3) - y1
        $y3 = $slope->mul($x1->sub($x3))->sub($y1)->mod($p);

        return [$x3, $y3];
    }

    /**
     * Scalar multiplication using double-and-add
     *
     * @param BigInt $k Scalar multiplier
     * @param array|null $point [BigInt, BigInt] or null
     * @return array|null [BigInt, BigInt] or null
     */
    public static function scalarMult(BigInt $k, ?array $point): ?array
    {
        self::init();

        if ($point === null) return null;

        // Reduce k mod n
        $k = $k->mod(self::$n);
        if ($k->isZero()) return null;

        $result = null;
        $addend = $point;
        $zero = BigInt::zero();

        while ($k->cmp($zero) > 0) {
            if ($k->isOdd()) {
                $result = self::pointAdd($result, $addend);
            }
            $addend = self::pointAdd($addend, $addend);
            $k = $k->shiftRight(1);
        }

        return $result;
    }

    /**
     * Point subtraction
     */
    public static function pointSub(?array $p1, ?array $p2): ?array
    {
        if ($p2 === null) return $p1;
        return self::pointAdd($p1, self::pointNegate($p2));
    }

    /**
     * Negate a point
     */
    public static function pointNegate(?array $point): ?array
    {
        if ($point === null) return null;
        self::init();
        return [$point[0], $point[1]->neg()->mod(self::$p)];
    }

    /**
     * Compress a point to 33 bytes
     *
     * @param array $point [BigInt, BigInt]
     */
    public static function compressPoint(array $point): string
    {
        $x = $point[0]->toHex(64);

        // Prefix: 02 for even y, 03 for odd y
        $prefix = $point[1]->isOdd() ? '03' : '02';

        return hex2bin($prefix . $x);
    }

    /**
     * Decompress a point from 33 bytes
     *
     * @return array [BigInt, BigInt]
     */
    public static function decompressPoint(string $compressed): array
    {
        self::init();

        if (strlen($compressed) !== 33) {
            throw new CashuException('Invalid compressed point length');
        }

        $prefix = ord($compressed[0]);
        if ($prefix !== 0x02 && $prefix !== 0x03) {
            throw new CashuException('Invalid point prefix');
        }

        $x = BigInt::fromHex(bin2hex(substr($compressed, 1)));
        $p = self::$p;

        // y^2 = x^3 + 7 (mod p)
        $three = BigInt::fromDec(3);
        $seven = BigInt::fromDec(7);
        $y2 = $x->powMod($three, $p)->add($seven)->mod($p);

        // Calculate square root using Tonelli-Shanks (simplified for p ≡ 3 mod 4)
        // For secp256k1, p ≡ 3 mod 4, so y = y2^((p+1)/4) mod p
        $one = BigInt::one();
        $four = BigInt::fromDec(4);
        $exp = $p->add($one)->div($four);
        $y = $y2->powMod($exp, $p);

        // Verify the square root
        $two = BigInt::fromDec(2);
        if ($y->powMod($two, $p)->cmp($y2) !== 0) {
            throw new CashuException('Point not on curve');
        }

        // Select correct y based on prefix
        $yIsOdd = $y->isOdd();
        $needOdd = $prefix === 0x03;

        if ($yIsOdd !== $needOdd) {
            $y = $y->neg()->mod($p);
        }

        return [$x, $y];
    }

    /**
     * Check if a point is on the curve
     *
     * @param array $point [BigInt, BigInt]
     */
    public static function isOnCurve(array $point): bool
    {
        self::init();
        [$x, $y] = $point;
        $p = self::$p;

        // y^2 = x^3 + 7 (mod p)
        $two = BigInt::fromDec(2);
        $three = BigInt::fromDec(3);
        $seven = BigInt::fromDec(7);

        $left = $y->powMod($two, $p);
        $right = $x->powMod($three, $p)->add($seven)->mod($p);

        return $left->cmp($right) === 0;
    }

    /**
     * Generate a random scalar (private key)
     */
    public static function randomScalar(): BigInt
    {
        self::init();
        $zero = BigInt::zero();

        do {
            $bytes = random_bytes(32);
            $scalar = BigInt::fromHex(bin2hex($bytes));
        } while ($scalar->cmp($zero) === 0 || $scalar->cmp(self::$n) >= 0);

        return $scalar;
    }

    /**
     * Convert scalar to 32-byte hex string
     */
    public static function scalarToHex(BigInt $scalar): string
    {
        return $scalar->toHex(64);
    }

    /**
     * Convert hex string to scalar
     */
    public static function hexToScalar(string $hex): BigInt
    {
        return BigInt::fromHex($hex);
    }

    // ========================================================================
    // BIP340 SCHNORR SIGNATURES (used by NUT-20)
    // ========================================================================

    /** BIP340 tagged hash: SHA256(SHA256(tag) || SHA256(tag) || data). */
    public static function taggedHash(string $tag, string $data): string
    {
        $tagHash = hash('sha256', $tag, true);
        return hash('sha256', $tagHash . $tagHash . $data, true);
    }

    /**
     * BIP340 Schnorr signature over a 32-byte message hash.
     *
     * Deterministic: uses all-zero auxiliary randomness, which is the BIP340
     * reference behaviour for aux = 0x00…00 and safe for our use (the message
     * already commits to a unique quote id and fresh blinded outputs).
     *
     * @param string $privkeyHex 32-byte private key (hex)
     * @param string $msg32 32-byte message hash (raw bytes)
     * @return string 64-byte signature (hex)
     */
    public static function schnorrSign(string $privkeyHex, string $msg32): string
    {
        self::init();
        if (strlen($msg32) !== 32) {
            throw new CashuException('BIP340 message must be 32 bytes');
        }

        $n = self::getOrder();
        $G = self::getGenerator();

        $d = BigInt::fromHex($privkeyHex)->mod($n);
        if ($d->isZero()) {
            throw new CashuException('Invalid private key');
        }

        $P = self::scalarMult($d, $G);
        if ($P[1]->isOdd()) {
            $d = $n->sub($d);
        }
        $dBytes = hex2bin(str_pad($d->toHex(), 64, '0', STR_PAD_LEFT));
        $pxBytes = hex2bin($P[0]->toHex(64));

        $aux = str_repeat("\x00", 32);
        $t = $dBytes ^ self::taggedHash('BIP0340/aux', $aux);
        $rand = self::taggedHash('BIP0340/nonce', $t . $pxBytes . $msg32);

        $k = BigInt::fromHex(bin2hex($rand))->mod($n);
        if ($k->isZero()) {
            throw new CashuException('BIP340 nonce derivation failed');
        }
        $R = self::scalarMult($k, $G);
        if ($R[1]->isOdd()) {
            $k = $n->sub($k);
        }
        $rxBytes = hex2bin($R[0]->toHex(64));

        $e = BigInt::fromHex(bin2hex(self::taggedHash('BIP0340/challenge', $rxBytes . $pxBytes . $msg32)))->mod($n);
        $s = $k->add($e->mul($d))->mod($n);

        $sig = bin2hex($rxBytes) . str_pad($s->toHex(), 64, '0', STR_PAD_LEFT);

        if (!self::schnorrVerify(bin2hex($pxBytes), $msg32, $sig)) {
            throw new CashuException('BIP340 signature self-check failed');
        }
        return $sig;
    }

    /**
     * BIP340 Schnorr verification.
     *
     * @param string $pubkey x-only (64 hex) or compressed (66 hex) public key
     * @param string $msg32 32-byte message hash (raw bytes)
     * @param string $sigHex 64-byte signature (hex)
     */
    public static function schnorrVerify(string $pubkey, string $msg32, string $sigHex): bool
    {
        self::init();
        if (strlen($msg32) !== 32 || strlen($sigHex) !== 128 || !ctype_xdigit($sigHex)) {
            return false;
        }
        $pubkeyX = strlen($pubkey) === 66 ? substr($pubkey, 2) : $pubkey;
        if (strlen($pubkeyX) !== 64 || !ctype_xdigit($pubkeyX)) {
            return false;
        }

        try {
            // lift_x: point with the given x and even y
            $P = self::decompressPoint(hex2bin('02' . $pubkeyX));

            $n = self::getOrder();
            $p = self::getPrime();
            $r = BigInt::fromHex(substr($sigHex, 0, 64));
            $s = BigInt::fromHex(substr($sigHex, 64, 64));
            if ($r->cmp($p) >= 0 || $s->cmp($n) >= 0) {
                return false;
            }

            $e = BigInt::fromHex(bin2hex(self::taggedHash(
                'BIP0340/challenge',
                hex2bin(substr($sigHex, 0, 64)) . hex2bin($pubkeyX) . $msg32
            )))->mod($n);

            // R = s*G - e*P
            $R = self::pointSub(
                self::scalarMult($s, self::getGenerator()),
                self::scalarMult($e, $P)
            );
            if ($R === null || $R[1]->isOdd()) {
                return false;
            }
            return $R[0]->cmp($r) === 0;
        } catch (\Throwable $error) {
            return false;
        }
    }
}

// ============================================================================
// BDHKE CRYPTOGRAPHY
// ============================================================================

/**
 * Blind Diffie-Hellman Key Exchange implementation
 */
class Crypto
{
    private const DOMAIN_SEPARATOR = 'Secp256k1_HashToCurve_Cashu_';

    /**
     * Hash a message to a point on the curve
     *
     * Uses the try-and-increment method with domain separation.
     */
    public static function hashToCurve(string $message): array
    {
        $domainSeparator = self::DOMAIN_SEPARATOR;
        $msgHash = hash('sha256', $domainSeparator . $message, true);

        for ($counter = 0; $counter < 65536; $counter++) {
            $counterBytes = pack('V', $counter); // Little-endian 4 bytes
            $hash = hash('sha256', $msgHash . $counterBytes, true);

            try {
                // Try with 02 prefix (even y)
                $compressed = "\x02" . $hash;
                $point = Secp256k1::decompressPoint($compressed);
                if (Secp256k1::isOnCurve($point)) {
                    return $point;
                }
            } catch (\Exception $e) {
                // Point not on curve, continue
            }
        }

        throw new CashuException('Failed to hash to curve');
    }

    /**
     * Generate a random secret (32 bytes, hex encoded)
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate a random blinding factor
     */
    public static function generateBlindingFactor(): BigInt
    {
        return Secp256k1::randomScalar();
    }

    /**
     * Create a blinded message
     *
     * B_ = Y + r*G where Y = hash_to_curve(secret)
     *
     * @return array ['B_' => string, 'r' => BigInt, 'Y' => array]
     */
    public static function createBlindedMessage(string $secret): array
    {
        $Y = self::hashToCurve($secret);
        $r = self::generateBlindingFactor();
        $G = Secp256k1::getGenerator();

        // B_ = Y + r*G
        $rG = Secp256k1::scalarMult($r, $G);
        $B_ = Secp256k1::pointAdd($Y, $rG);

        return [
            'B_' => bin2hex(Secp256k1::compressPoint($B_)),
            'r' => $r,
            'Y' => $Y
        ];
    }

    /**
     * Unblind a signature
     *
     * C = C_ - r*A
     *
     * @param string $C_ Hex-encoded blinded signature point
     * @param BigInt $r Blinding factor
     * @param string $A Hex-encoded mint public key point
     * @return string Hex-encoded unblinded signature
     */
    public static function unblindSignature(string $C_, BigInt $r, string $A): string
    {
        $C_point = Secp256k1::decompressPoint(hex2bin($C_));
        $A_point = Secp256k1::decompressPoint(hex2bin($A));

        // C = C_ - r*A
        $rA = Secp256k1::scalarMult($r, $A_point);
        $C = Secp256k1::pointSub($C_point, $rA);

        return bin2hex(Secp256k1::compressPoint($C));
    }

    /**
     * Compute Y = hash_to_curve(secret)
     */
    public static function computeY(string $secret): string
    {
        $Y = self::hashToCurve($secret);
        return bin2hex(Secp256k1::compressPoint($Y));
    }

    /**
     * NUT-12 hash_e: SHA256 over the concatenated lowercase hex strings of the
     * uncompressed (65-byte) serializations of the given points.
     *
     * @param array ...$points Points as [BigInt $x, BigInt $y]
     * @return string 64-char hex digest
     */
    public static function hashE(array ...$points): string
    {
        $concat = '';
        foreach ($points as $point) {
            $concat .= '04' . $point[0]->toHex(64) . $point[1]->toHex(64);
        }
        return hash('sha256', $concat);
    }

    /**
     * Verify a NUT-12 DLEQ proof: R1 = s*G - e*A, R2 = s*B' - e*C',
     * check e == hash_e(R1, R2, A, C').
     *
     * @param string $e   challenge (hex scalar)
     * @param string $s   response (hex scalar)
     * @param string $A   mint public key for the amount (33-byte compressed hex)
     * @param string $B_  blinded message (compressed hex)
     * @param string $C_  blind signature (compressed hex)
     */
    public static function verifyDleq(string $e, string $s, string $A, string $B_, string $C_): bool
    {
        try {
            $eScalar = BigInt::fromHex($e);
            $sScalar = BigInt::fromHex($s);
            $Apoint = Secp256k1::decompressPoint(hex2bin($A));
            $Bpoint = Secp256k1::decompressPoint(hex2bin($B_));
            $Cpoint = Secp256k1::decompressPoint(hex2bin($C_));

            $G = Secp256k1::getGenerator();
            $R1 = Secp256k1::pointSub(
                Secp256k1::scalarMult($sScalar, $G),
                Secp256k1::scalarMult($eScalar, $Apoint)
            );
            $R2 = Secp256k1::pointSub(
                Secp256k1::scalarMult($sScalar, $Bpoint),
                Secp256k1::scalarMult($eScalar, $Cpoint)
            );
            if ($R1 === null || $R2 === null) {
                return false;
            }

            return hash_equals(self::hashE($R1, $R2, $Apoint, $Cpoint), strtolower($e));
        } catch (\Throwable $error) {
            return false;
        }
    }

    /**
     * Verify the DLEQ proof on a received Proof (NUT-12 "Carol" flow):
     * reconstruct B' = Y + r*G and C' = C + r*A from the revealed blinding
     * factor, then run the standard verification.
     */
    public static function verifyProofDleq(Proof $proof, string $A): bool
    {
        if ($proof->dleq === null || $proof->dleq->r === null || $proof->dleq->r === '') {
            return false;
        }
        try {
            $r = BigInt::fromHex($proof->dleq->r);
            $Apoint = Secp256k1::decompressPoint(hex2bin($A));
            $Cpoint = Secp256k1::decompressPoint(hex2bin($proof->C));
            $G = Secp256k1::getGenerator();

            $B_ = Secp256k1::pointAdd(self::hashToCurve($proof->secret), Secp256k1::scalarMult($r, $G));
            $C_ = Secp256k1::pointAdd($Cpoint, Secp256k1::scalarMult($r, $Apoint));
            if ($B_ === null || $C_ === null) {
                return false;
            }

            return self::verifyDleq(
                $proof->dleq->e,
                $proof->dleq->s,
                $A,
                bin2hex(Secp256k1::compressPoint($B_)),
                bin2hex(Secp256k1::compressPoint($C_))
            );
        } catch (\Throwable $error) {
            return false;
        }
    }
}

// ============================================================================
// BIP-39 MNEMONIC (NUT-13)
// ============================================================================

/**
 * BIP-39 Mnemonic implementation for deterministic wallet backup
 */
class Mnemonic
{
    private static ?array $wordlist = null;
    private static ?array $wordlistFlipped = null;

    /**
     * Load the BIP-39 English wordlist
     */
    private static function loadWordlist(): void
    {
        if (self::$wordlist !== null) {
            return;
        }

        $wordlistPath = __DIR__ . '/bip39-english.txt';
        if (!file_exists($wordlistPath)) {
            throw new CashuException('BIP-39 wordlist not found: ' . $wordlistPath);
        }

        $content = file_get_contents($wordlistPath);
        self::$wordlist = array_map('trim', explode("\n", trim($content)));

        if (count(self::$wordlist) !== 2048) {
            throw new CashuException('Invalid BIP-39 wordlist: expected 2048 words');
        }

        self::$wordlistFlipped = array_flip(self::$wordlist);
    }

    /**
     * Generate a new 12-word mnemonic (128 bits entropy)
     */
    public static function generate(): string
    {
        self::loadWordlist();

        // Generate 128 bits (16 bytes) of entropy
        $entropy = random_bytes(16);

        return self::entropyToMnemonic($entropy);
    }

    /**
     * Convert entropy bytes to mnemonic words
     */
    private static function entropyToMnemonic(string $entropy): string
    {
        self::loadWordlist();

        // Calculate checksum (first 4 bits of SHA256 for 128-bit entropy)
        $hash = hash('sha256', $entropy, true);
        $checksumBits = 4; // entropy_bits / 32 = 128 / 32 = 4

        // Convert entropy to binary string
        $bits = '';
        for ($i = 0; $i < strlen($entropy); $i++) {
            $bits .= str_pad(decbin(ord($entropy[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Append checksum bits
        $checksumByte = ord($hash[0]);
        $checksumStr = str_pad(decbin($checksumByte), 8, '0', STR_PAD_LEFT);
        $bits .= substr($checksumStr, 0, $checksumBits);

        // Split into 11-bit groups and convert to words
        $words = [];
        for ($i = 0; $i < strlen($bits); $i += 11) {
            $index = bindec(substr($bits, $i, 11));
            $words[] = self::$wordlist[$index];
        }

        return implode(' ', $words);
    }

    /**
     * Validate a mnemonic phrase
     */
    public static function validate(string $mnemonic): bool
    {
        self::loadWordlist();

        $words = preg_split('/\s+/', trim($mnemonic));

        // Must be 12, 15, 18, 21, or 24 words
        $wordCount = count($words);
        if (!in_array($wordCount, [12, 15, 18, 21, 24])) {
            return false;
        }

        // All words must be in wordlist
        foreach ($words as $word) {
            if (!isset(self::$wordlistFlipped[$word])) {
                return false;
            }
        }

        // Convert words to bits
        $bits = '';
        foreach ($words as $word) {
            $index = self::$wordlistFlipped[$word];
            $bits .= str_pad(decbin($index), 11, '0', STR_PAD_LEFT);
        }

        // Split into entropy and checksum
        $checksumBits = $wordCount / 3; // CS = ENT / 32, and words = (ENT + CS) / 11
        $entropyBits = strlen($bits) - $checksumBits;

        $entropyStr = substr($bits, 0, $entropyBits);
        $checksumStr = substr($bits, $entropyBits);

        // Convert entropy bits back to bytes
        $entropy = '';
        for ($i = 0; $i < $entropyBits; $i += 8) {
            $entropy .= chr(bindec(substr($entropyStr, $i, 8)));
        }

        // Verify checksum
        $hash = hash('sha256', $entropy, true);
        $expectedChecksum = str_pad(decbin(ord($hash[0])), 8, '0', STR_PAD_LEFT);
        $expectedChecksum = substr($expectedChecksum, 0, $checksumBits);

        return $checksumStr === $expectedChecksum;
    }

    /**
     * Convert mnemonic to seed using PBKDF2
     *
     * @param string $mnemonic The mnemonic phrase
     * @param string $passphrase Optional passphrase (BIP-39 extension)
     * @return string 64-byte seed as raw bytes
     */
    public static function toSeed(string $mnemonic, string $passphrase = ''): string
    {
        // Normalize mnemonic (NFKD normalization, lowercase)
        $mnemonic = mb_strtolower(trim($mnemonic));
        $mnemonic = preg_replace('/\s+/', ' ', $mnemonic);

        // Salt is "mnemonic" + passphrase
        $salt = 'mnemonic' . $passphrase;

        // PBKDF2-HMAC-SHA512, 2048 iterations, 64 bytes output
        return hash_pbkdf2('sha512', $mnemonic, $salt, 2048, 64, true);
    }

    /**
     * Get word at index (for testing)
     */
    public static function getWord(int $index): string
    {
        self::loadWordlist();
        return self::$wordlist[$index] ?? '';
    }
}

// ============================================================================
// BIP-32 HD KEY DERIVATION (NUT-13)
// ============================================================================

/**
 * BIP-32 Hierarchical Deterministic key derivation
 */
class BIP32
{
    private string $privateKey; // 32 bytes raw
    private string $chainCode;  // 32 bytes raw

    private function __construct(string $privateKey, string $chainCode)
    {
        $this->privateKey = $privateKey;
        $this->chainCode = $chainCode;
    }

    /**
     * Create master key from seed
     */
    public static function fromSeed(string $seed): self
    {
        // HMAC-SHA512 with key "Bitcoin seed"
        $I = hash_hmac('sha512', $seed, 'Bitcoin seed', true);

        $privateKey = substr($I, 0, 32);
        $chainCode = substr($I, 32, 32);

        // Verify private key is valid (non-zero and less than curve order)
        $keyInt = BigInt::fromHex(bin2hex($privateKey));
        $n = Secp256k1::getOrder();

        if ($keyInt->isZero() || $keyInt->cmp($n) >= 0) {
            throw new CashuException('Invalid master key derived from seed');
        }

        return new self($privateKey, $chainCode);
    }

    /**
     * Derive child key at path
     *
     * @param string $path Path like "m/129372'/0'/123'/0'/0"
     * @return string 32-byte private key as hex
     */
    public function derivePath(string $path): string
    {
        $parts = explode('/', $path);

        if ($parts[0] !== 'm') {
            throw new CashuException('Invalid derivation path: must start with m');
        }

        $node = $this;

        for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];
            $hardened = str_ends_with($part, "'");

            if ($hardened) {
                $index = (int) substr($part, 0, -1);
            } else {
                $index = (int) $part;
            }

            $node = $node->deriveChild($index, $hardened);
        }

        return bin2hex($node->privateKey);
    }

    /**
     * Derive a single child key
     */
    private function deriveChild(int $index, bool $hardened): self
    {
        if ($hardened) {
            // Hardened child: HMAC-SHA512(chainCode, 0x00 || privateKey || index)
            $index += 0x80000000;
            $data = "\x00" . $this->privateKey . pack('N', $index);
        } else {
            // Normal child: HMAC-SHA512(chainCode, publicKey || index)
            $pubkey = $this->getPublicKey();
            $data = $pubkey . pack('N', $index);
        }

        $I = hash_hmac('sha512', $data, $this->chainCode, true);

        $IL = substr($I, 0, 32);
        $IR = substr($I, 32, 32);

        // child_key = (IL + parent_key) mod n
        $n = Secp256k1::getOrder();
        $ilInt = BigInt::fromHex(bin2hex($IL));
        $parentInt = BigInt::fromHex(bin2hex($this->privateKey));

        $childInt = $ilInt->add($parentInt)->mod($n);

        if ($childInt->isZero() || $ilInt->cmp($n) >= 0) {
            throw new CashuException('Invalid child key derived');
        }

        $childKey = hex2bin($childInt->toHex(64));

        return new self($childKey, $IR);
    }

    /**
     * Get compressed public key (33 bytes)
     */
    private function getPublicKey(): string
    {
        $privInt = BigInt::fromHex(bin2hex($this->privateKey));
        $G = Secp256k1::getGenerator();
        $pubPoint = Secp256k1::scalarMult($privInt, $G);
        return Secp256k1::compressPoint($pubPoint);
    }

    /**
     * Get the private key as hex
     */
    public function getPrivateKeyHex(): string
    {
        return bin2hex($this->privateKey);
    }
}

// ============================================================================
// DATA STRUCTURES
// ============================================================================

/**
 * Unit helper for amount formatting
 *
 * Provides formatting rules for different currency units.
 * Common units (sat, msat, usd, eur, btc) have known formatting.
 * Unknown units default to 0 decimals with code as symbol.
 */
class Unit
{
    /**
     * Known units with their formatting rules
     * @var array<string, array{decimals: int, symbol: string, position: string}>
     */
    private const KNOWN_UNITS = [
        'sat' => ['decimals' => 0, 'symbol' => 'sat', 'position' => 'after'],
        'msat' => ['decimals' => 0, 'symbol' => 'msat', 'position' => 'after'],
        'usd' => ['decimals' => 2, 'symbol' => '$', 'position' => 'before'],
        'eur' => ['decimals' => 2, 'symbol' => "\u{20AC}", 'position' => 'before'], // €
        'btc' => ['decimals' => 8, 'symbol' => "\u{20BF}", 'position' => 'before'], // ₿
    ];

    public readonly string $code;
    public readonly int $decimals;
    public readonly string $symbol;
    public readonly string $position; // 'before' or 'after'

    private function __construct(string $code, int $decimals, string $symbol, string $position)
    {
        $this->code = $code;
        $this->decimals = $decimals;
        $this->symbol = $symbol;
        $this->position = $position;
    }

    /**
     * Create a Unit from a unit code
     */
    public static function fromCode(string $code): self
    {
        $code = strtolower($code);

        if (isset(self::KNOWN_UNITS[$code])) {
            $config = self::KNOWN_UNITS[$code];
            return new self($code, $config['decimals'], $config['symbol'], $config['position']);
        }

        // Unknown unit: 0 decimals, code as symbol, after position
        return new self($code, 0, $code, 'after');
    }

    /**
     * Format an amount in the smallest unit to a display string
     *
     * Examples:
     *   - sat: 100 -> "100 sat"
     *   - usd: 150 -> "$1.50"
     *   - eur: 50 -> "€0.50"
     *   - btc: 100 -> "₿0.00000100"
     *
     * @param int $amount Amount in smallest unit (satoshis, cents, etc.)
     * @return string Formatted amount
     */
    public function format(int $amount): string
    {
        if ($this->decimals === 0) {
            // No decimals: just append/prepend symbol
            if ($this->position === 'before') {
                return $this->symbol . $amount;
            }
            return $amount . ' ' . $this->symbol;
        }

        // Calculate decimal value
        $divisor = (int) pow(10, $this->decimals);
        $whole = intdiv($amount, $divisor);
        $frac = abs($amount % $divisor);

        // Format with proper decimal places
        $formatted = $whole . '.' . str_pad((string)$frac, $this->decimals, '0', STR_PAD_LEFT);

        if ($this->position === 'before') {
            return $this->symbol . $formatted;
        }
        return $formatted . ' ' . $this->symbol;
    }

    /**
     * Get the display name for the unit (uppercase code)
     */
    public function getName(): string
    {
        return strtoupper($this->code);
    }

    /**
     * Parse a display amount string to smallest unit
     *
     * Examples:
     *   - sat: "100" -> 100
     *   - usd: "1.50" -> 150, "0.05" -> 5
     *   - eur: "0.50" -> 50, "2" -> 200
     *   - btc: "0.00000100" -> 100
     *
     * @param string $input User input (e.g., "0.05" for 5 cents)
     * @return int Amount in smallest unit
     * @throws \InvalidArgumentException if input is invalid
     */
    public function parse(string $input): int
    {
        $input = trim($input);

        // Remove currency symbols if present
        $input = str_replace([$this->symbol, ','], ['', ''], $input);
        $input = trim($input);

        if (!is_numeric($input)) {
            throw new \InvalidArgumentException("Invalid amount: '$input'");
        }

        if ($this->decimals === 0) {
            // No decimals: input is already in smallest unit
            $amount = (int) $input;
        } else {
            // Has decimals: multiply by 10^decimals
            $multiplier = (int) pow(10, $this->decimals);
            // Use bcmul for precision, then convert to int
            $amount = (int) round((float) $input * $multiplier);
        }

        return $amount;
    }

    /**
     * Get example amount string for prompts
     *
     * Returns a sensible default amount for the unit:
     *   - sat: "100"
     *   - usd/eur: "1.00"
     *   - btc: "0.0001"
     */
    public function getExampleAmount(): string
    {
        if ($this->decimals === 0) {
            return '100';
        }
        if ($this->decimals <= 2) {
            return '1.00';
        }
        // For BTC and similar high-decimal units
        return '0.0001';
    }
}

/**
 * DLEQ proof for wallet (includes blinding factor)
 */
class DLEQWallet
{
    public function __construct(
        public string $e,
        public string $s,
        public ?string $r = null
    ) {}

    public function toArray(): array
    {
        $data = ['e' => $this->e, 's' => $this->s];
        if ($this->r !== null) {
            $data['r'] = $this->r;
        }
        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self($data['e'], $data['s'], $data['r']);
    }
}

/**
 * A proof (value token)
 */
class Proof
{
    public string $Y;

    public function __construct(
        public string $id,
        public int $amount,
        public string $secret,
        public string $C,
        public ?DLEQWallet $dleq = null,
        public ?string $witness = null
    ) {
        $this->Y = Crypto::computeY($this->secret);
    }

    public function toArray(bool $includeDleq = false): array
    {
        $data = [
            'id' => $this->id,
            'amount' => $this->amount,
            'secret' => $this->secret,
            'C' => $this->C
        ];

        if ($includeDleq && $this->dleq !== null) {
            $data['dleq'] = $this->dleq->toArray();
        }

        if ($this->witness !== null) {
            $data['witness'] = $this->witness;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        $dleq = null;
        if (isset($data['dleq']) && is_array($data['dleq'])) {
            $dleq = DLEQWallet::fromArray($data['dleq']);
        }

        return new self(
            $data['id'],
            $data['amount'],
            $data['secret'],
            $data['C'],
            $dleq,
            $data['witness'] ?? null
        );
    }
}

/**
 * A blinded message to be signed by the mint
 */
class BlindedMessage
{
    public function __construct(
        public int $amount,
        public string $id,
        public string $B_
    ) {}

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'id' => $this->id,
            'B_' => $this->B_
        ];
    }
}

/**
 * A blinded signature from the mint
 */
class BlindedSignature
{
    public function __construct(
        public string $id,
        public int $amount,
        public string $C_,
        public ?array $dleq = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['amount'],
            $data['C_'],
            $data['dleq'] ?? null
        );
    }
}

/**
 * Keyset information
 */
class Keyset
{
    public function __construct(
        public string $id,
        public string $unit,
        public array $keys, // amount => public key (hex)
        public bool $active = true,
        public int $inputFeePpk = 0,
        public ?int $finalExpiry = null
    ) {}

    /**
     * Derive a V1 keyset ID from public keys (NUT-02, deprecated format)
     */
    public static function deriveKeysetId(array $keys): string
    {
        // Sort by amount
        ksort($keys);

        // Concatenate compressed public keys
        $concat = '';
        foreach ($keys as $pubkey) {
            $concat .= hex2bin($pubkey);
        }

        // ID = "00" + first 14 hex chars of SHA256
        return '00' . substr(hash('sha256', $concat), 0, 14);
    }

    /**
     * Derive a V2 keyset ID (NUT-02): version byte "01" + SHA256 over
     * "amount:pubkey" pairs joined by commas, plus unit / fee / expiry tags.
     */
    public static function deriveKeysetIdV2(
        array $keys,
        string $unit,
        ?int $inputFeePpk = null,
        ?int $finalExpiry = null
    ): string {
        ksort($keys);

        $pairs = [];
        foreach ($keys as $amount => $pubkey) {
            $pairs[] = $amount . ':' . strtolower($pubkey);
        }
        $preimage = implode(',', $pairs);
        $preimage .= '|unit:' . strtolower($unit);
        if ($inputFeePpk !== null && $inputFeePpk !== 0) {
            $preimage .= '|input_fee_ppk:' . $inputFeePpk;
        }
        if ($finalExpiry !== null && $finalExpiry !== 0) {
            $preimage .= '|final_expiry:' . $finalExpiry;
        }

        return '01' . hash('sha256', $preimage);
    }

    /**
     * Derive the expected ID for this keyset using the version encoded in its
     * announced ID. Returns null for legacy (base64) IDs that cannot be verified.
     */
    public function deriveExpectedId(): ?string
    {
        if (empty($this->keys) || !TokenSerializer::isHexKeysetId($this->id)) {
            return null;
        }
        return match (substr($this->id, 0, 2)) {
            '00' => self::deriveKeysetId($this->keys),
            '01' => self::deriveKeysetIdV2($this->keys, $this->unit, $this->inputFeePpk, $this->finalExpiry),
            default => null,
        };
    }
}

/**
 * Mint quote response
 */
class MintQuote
{
    public function __construct(
        public string $quote,
        public string $request,
        public int $amount,
        public string $state,
        public ?int $expiry = null,
        public ?string $unit = null,
        public ?int $amountPaid = null,
        public ?int $amountIssued = null,
        public ?string $pubkey = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['quote'],
            $data['request'],
            $data['amount'] ?? 0,
            $data['state'] ?? '',
            $data['expiry'] ?? null,
            $data['unit'] ?? null,
            isset($data['amount_paid']) ? (int)$data['amount_paid'] : null,
            isset($data['amount_issued']) ? (int)$data['amount_issued'] : null,
            $data['pubkey'] ?? null
        );
    }

    /**
     * NUT-04/NUT-23: `state` is deprecated; when the mint sends
     * `amount_paid`/`amount_issued`, those are authoritative.
     */
    public function isPaid(): bool
    {
        if ($this->amountPaid !== null && $this->amountIssued !== null) {
            return $this->amountPaid > $this->amountIssued;
        }
        return strtoupper($this->state) === 'PAID';
    }

    public function isIssued(): bool
    {
        if ($this->amountPaid !== null && $this->amountIssued !== null) {
            return $this->amountIssued > 0 && $this->amountIssued >= $this->amountPaid;
        }
        return strtoupper($this->state) === 'ISSUED';
    }

    /** Amount that has been paid but not yet issued (mintable now). */
    public function mintableAmount(): ?int
    {
        if ($this->amountPaid === null || $this->amountIssued === null) {
            return null;
        }
        return max(0, $this->amountPaid - $this->amountIssued);
    }
}

/**
 * Melt quote response
 */
class MeltQuote
{
    public function __construct(
        public string $quote,
        public int $amount,
        public int $feeReserve,
        public string $state,
        public ?int $expiry = null,
        public ?string $paymentPreimage = null,
        public ?array $change = null,
        public ?string $unit = null,
        public ?string $request = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['quote'],
            $data['amount'],
            $data['fee_reserve'] ?? 0,
            $data['state'] ?? 'UNPAID',
            $data['expiry'] ?? null,
            $data['payment_preimage'] ?? null,
            $data['change'] ?? null,
            $data['unit'] ?? null,
            $data['request'] ?? null
        );
    }

    public function isPaid(): bool
    {
        return strtoupper($this->state) === 'PAID';
    }

    public function isPending(): bool
    {
        return strtoupper($this->state) === 'PENDING';
    }
}

/**
 * Proof state constants
 */
class ProofState
{
    const UNSPENT = 'UNSPENT';
    const PENDING = 'PENDING';
    const SPENT = 'SPENT';
}

// ============================================================================
// NUT-18 PAYMENT REQUEST
// ============================================================================

/**
 * Transport specification for payment delivery (NUT-18)
 */
class Transport
{
    public const TYPE_POST = 'post';      // HTTP POST
    public const TYPE_NOSTR = 'nostr';    // Nostr NIP-17
    public const TYPE_INBAND = '';        // In-band (no transport)

    public function __construct(
        public string $type,          // 'post', 'nostr', or '' (in-band)
        public string $target,        // URL or npub for nostr
        public array $tags = []       // Optional tags
    ) {}

    public function toArray(): array
    {
        $data = ['t' => $this->type, 'a' => $this->target];
        if (!empty($this->tags)) {
            $data['g'] = $this->tags;
        }
        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['t'] ?? '',
            $data['a'] ?? '',
            $data['g'] ?? []
        );
    }

    /**
     * Create an HTTP POST transport
     */
    public static function http(string $url): self
    {
        return new self(self::TYPE_POST, $url);
    }

    /**
     * Create a Nostr transport
     */
    public static function nostr(string $npub): self
    {
        return new self(self::TYPE_NOSTR, $npub);
    }

    /**
     * Create an in-band transport (no delivery)
     */
    public static function inband(): self
    {
        return new self(self::TYPE_INBAND, '');
    }
}

/**
 * Payment request (NUT-18)
 *
 * Allows receivers to request specific amounts from senders.
 */
class PaymentRequest
{
    public function __construct(
        public string $id,              // Unique request ID
        public int $amount,             // Amount in unit
        public string $unit,            // 'sat', 'usd', etc.
        public array $mints,            // Accepted mint URLs
        public ?string $memo = null,    // Description
        public ?Transport $transport = null,  // How to deliver payment
        public bool $singleUse = true   // Whether request can be used once
    ) {}

    /**
     * Generate a random request ID
     */
    public static function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function toArray(): array
    {
        $data = [
            'i' => $this->id,
            'a' => $this->amount,
            'u' => $this->unit,
            'm' => $this->mints,
        ];

        if ($this->memo !== null) {
            $data['d'] = $this->memo;
        }

        if ($this->transport !== null) {
            $data['t'] = [$this->transport->toArray()];
        }

        if (!$this->singleUse) {
            $data['s'] = false;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        $transport = null;
        if (!empty($data['t']) && is_array($data['t'])) {
            $transport = Transport::fromArray($data['t'][0]);
        }

        return new self(
            $data['i'] ?? '',
            $data['a'] ?? 0,
            $data['u'] ?? 'sat',
            $data['m'] ?? [],
            $data['d'] ?? null,
            $transport,
            $data['s'] ?? true
        );
    }

    /**
     * Serialize to payment request string (cashuR format)
     */
    public function serialize(): string
    {
        $cbor = CBOR::encode($this->toArray());
        $base64 = rtrim(strtr(base64_encode($cbor), '+/', '-_'), '=');
        return 'creqA' . $base64;
    }

    /**
     * Parse a payment request string
     */
    public static function parse(string $prString): self
    {
        if (!str_starts_with($prString, 'creqA')) {
            throw new CashuException('Invalid payment request format. Expected creqA prefix.');
        }

        $base64 = substr($prString, 5);
        $base64 = strtr($base64, '-_', '+/');
        $base64 = str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '=');

        $cbor = base64_decode($base64);
        $data = CBOR::decode($cbor);

        return self::fromArray($data);
    }
}

/**
 * Token container
 */
class Token
{
    public function __construct(
        public string $mint,
        public string $unit,
        public array $proofs,
        public ?string $memo = null
    ) {}

    public function getAmount(): int
    {
        return array_sum(array_map(fn($p) => $p->amount, $this->proofs));
    }

    public function getKeysets(): array
    {
        return array_unique(array_map(fn($p) => $p->id, $this->proofs));
    }
}

// ============================================================================
// CBOR ENCODER/DECODER (Minimal implementation for token serialization)
// ============================================================================

/**
 * Minimal CBOR encoder/decoder for Cashu tokens
 */
class CBOR
{
    // CBOR major types
    private const UNSIGNED_INT = 0;
    private const NEGATIVE_INT = 1;
    private const BYTE_STRING = 2;
    private const TEXT_STRING = 3;
    private const ARRAY = 4;
    private const MAP = 5;

    /**
     * Encode a value to CBOR
     */
    public static function encode($value): string
    {
        if (is_null($value)) {
            return "\xf6"; // null
        }

        if (is_bool($value)) {
            return $value ? "\xf5" : "\xf4"; // true/false
        }

        if (is_int($value)) {
            if ($value >= 0) {
                return self::encodeUnsigned($value);
            } else {
                return self::encodeNegative(-1 - $value);
            }
        }

        if (is_string($value)) {
            // Check if it's binary data (contains non-UTF8 or is marked as bytes)
            if (!mb_check_encoding($value, 'UTF-8') || self::isBinaryString($value)) {
                return self::encodeByteString($value);
            }
            return self::encodeTextString($value);
        }

        if (is_array($value)) {
            if (self::isAssoc($value)) {
                return self::encodeMap($value);
            }
            return self::encodeArray($value);
        }

        if (is_object($value)) {
            return self::encodeMap((array)$value);
        }

        throw new CashuException('Unsupported CBOR type');
    }

    /**
     * Decode CBOR data
     */
    public static function decode(string $data)
    {
        $offset = 0;
        return self::decodeValue($data, $offset);
    }

    private static function encodeUnsigned(int $value): string
    {
        return self::encodeHead(self::UNSIGNED_INT, $value);
    }

    private static function encodeNegative(int $value): string
    {
        return self::encodeHead(self::NEGATIVE_INT, $value);
    }

    private static function encodeByteString(string $value): string
    {
        return self::encodeHead(self::BYTE_STRING, strlen($value)) . $value;
    }

    private static function encodeTextString(string $value): string
    {
        return self::encodeHead(self::TEXT_STRING, strlen($value)) . $value;
    }

    private static function encodeArray(array $value): string
    {
        $result = self::encodeHead(self::ARRAY, count($value));
        foreach ($value as $item) {
            $result .= self::encode($item);
        }
        return $result;
    }

    private static function encodeMap(array $value): string
    {
        $result = self::encodeHead(self::MAP, count($value));
        foreach ($value as $k => $v) {
            $result .= self::encodeTextString((string)$k);
            $result .= self::encode($v);
        }
        return $result;
    }

    private static function encodeHead(int $majorType, int $value): string
    {
        $type = $majorType << 5;

        if ($value < 24) {
            return chr($type | $value);
        } elseif ($value < 256) {
            return chr($type | 24) . chr($value);
        } elseif ($value < 65536) {
            return chr($type | 25) . pack('n', $value);
        } elseif ($value < 4294967296) {
            return chr($type | 26) . pack('N', $value);
        } else {
            return chr($type | 27) . pack('J', $value);
        }
    }

    private static function decodeValue(string $data, int &$offset)
    {
        if ($offset >= strlen($data)) {
            throw new CashuException('CBOR: Unexpected end of data');
        }

        $byte = ord($data[$offset]);
        $majorType = $byte >> 5;
        $additionalInfo = $byte & 0x1f;
        $offset++;

        $value = self::decodeLength($data, $offset, $additionalInfo);

        switch ($majorType) {
            case self::UNSIGNED_INT:
                return $value;

            case self::NEGATIVE_INT:
                return -1 - $value;

            case self::BYTE_STRING:
                $result = substr($data, $offset, $value);
                $offset += $value;
                return $result;

            case self::TEXT_STRING:
                $result = substr($data, $offset, $value);
                $offset += $value;
                return $result;

            case self::ARRAY:
                $result = [];
                for ($i = 0; $i < $value; $i++) {
                    $result[] = self::decodeValue($data, $offset);
                }
                return $result;

            case self::MAP:
                $result = [];
                for ($i = 0; $i < $value; $i++) {
                    $key = self::decodeValue($data, $offset);
                    $result[$key] = self::decodeValue($data, $offset);
                }
                return $result;

            case 7: // Simple values and floats
                switch ($additionalInfo) {
                    case 20: return false;
                    case 21: return true;
                    case 22: return null;
                    case 23: return null; // undefined
                }
                throw new CashuException('CBOR: Unsupported simple value');

            default:
                throw new CashuException('CBOR: Unknown major type');
        }
    }

    private static function decodeLength(string $data, int &$offset, int $additionalInfo): int
    {
        if ($additionalInfo < 24) {
            return $additionalInfo;
        }

        switch ($additionalInfo) {
            case 24:
                $value = ord($data[$offset]);
                $offset += 1;
                return $value;
            case 25:
                $value = unpack('n', substr($data, $offset, 2))[1];
                $offset += 2;
                return $value;
            case 26:
                $value = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                return $value;
            case 27:
                $value = unpack('J', substr($data, $offset, 8))[1];
                $offset += 8;
                return $value;
        }

        throw new CashuException('CBOR: Invalid length encoding');
    }

    private static function isAssoc(array $arr): bool
    {
        if (empty($arr)) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private static function isBinaryString(string $str): bool
    {
        // Check if string contains binary data
        for ($i = 0; $i < strlen($str); $i++) {
            $ord = ord($str[$i]);
            if ($ord < 32 && $ord !== 9 && $ord !== 10 && $ord !== 13) {
                return true;
            }
        }
        return false;
    }
}

// ============================================================================
// TOKEN SERIALIZATION
// ============================================================================

/**
 * Token serialization utilities
 */
class TokenSerializer
{
    /**
     * Serialize proofs to V3 token format (cashuA)
     */
    public static function serializeV3(
        string $mint,
        array $proofs,
        string $unit = 'sat',
        ?string $memo = null,
        bool $includeDleq = false
    ): string {
        $tokenData = [
            'token' => [
                [
                    'mint' => $mint,
                    'proofs' => array_map(fn($p) => $p->toArray($includeDleq), $proofs)
                ]
            ],
            'unit' => $unit
        ];

        if ($memo !== null) {
            $tokenData['memo'] = $memo;
        }

        $json = json_encode($tokenData, JSON_UNESCAPED_SLASHES);
        $base64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return 'cashuA' . $base64;
    }

    /**
     * Serialize proofs to V4 token format (cashuB)
     */
    public static function serializeV4(
        string $mint,
        array $proofs,
        string $unit = 'sat',
        ?string $memo = null,
        bool $includeDleq = false
    ): string {
        // Group proofs by keyset ID
        $proofsByKeyset = [];
        foreach ($proofs as $proof) {
            $keysetId = $proof->id;
            if (!isset($proofsByKeyset[$keysetId])) {
                $proofsByKeyset[$keysetId] = [];
            }
            $proofsByKeyset[$keysetId][] = $proof;
        }

        // Build token structure
        $tokenData = [
            'm' => $mint,
            'u' => $unit,
            't' => []
        ];

        foreach ($proofsByKeyset as $keysetId => $keysetProofs) {
            $proofArray = [];
            foreach ($keysetProofs as $proof) {
                $p = [
                    'a' => $proof->amount,
                    's' => $proof->secret,
                    'c' => hex2bin($proof->C)
                ];

                if ($includeDleq && $proof->dleq !== null) {
                    $p['d'] = [
                        'e' => hex2bin($proof->dleq->e),
                        's' => hex2bin($proof->dleq->s),
                        'r' => hex2bin($proof->dleq->r)
                    ];
                }

                if ($proof->witness !== null) {
                    $p['w'] = $proof->witness;
                }

                $proofArray[] = $p;
            }

            $tokenData['t'][] = [
                'i' => hex2bin($keysetId),
                'p' => $proofArray
            ];
        }

        if ($memo !== null) {
            $tokenData['d'] = $memo;
        }

        $cbor = CBOR::encode($tokenData);
        $base64 = rtrim(strtr(base64_encode($cbor), '+/', '-_'), '=');

        return 'cashuB' . $base64;
    }

    /**
     * Check if keyset ID is in modern hex format: 16 hex chars (V1, version
     * byte "00") or 66 hex chars (V2, version byte "01", NUT-02).
     * V4 tokens only support hex IDs; deprecated base64 IDs need V3 format.
     */
    public static function isHexKeysetId(string $id): bool
    {
        return (strlen($id) === 16 || strlen($id) === 66) && ctype_xdigit($id);
    }

    /**
     * Deserialize a token string
     */
    public static function deserialize(string $tokenString): Token
    {
        if (str_starts_with($tokenString, 'cashuA')) {
            return self::deserializeV3($tokenString);
        } elseif (str_starts_with($tokenString, 'cashuB')) {
            return self::deserializeV4($tokenString);
        }

        throw new CashuException('Unknown token format');
    }

    /**
     * Deserialize V3 token (cashuA)
     */
    private static function deserializeV3(string $tokenString): Token
    {
        $base64 = substr($tokenString, 6);
        $base64 = strtr($base64, '-_', '+/');
        $base64 = str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '=');

        $json = base64_decode($base64);
        $data = json_decode($json, true);

        if (!isset($data['token']) || empty($data['token'])) {
            throw new CashuException('Invalid V3 token: missing token data');
        }

        $firstToken = $data['token'][0];
        $mint = $firstToken['mint'] ?? '';
        $unit = $data['unit'] ?? 'sat';
        $memo = $data['memo'] ?? null;

        $proofs = [];
        foreach ($data['token'] as $tokenPart) {
            foreach ($tokenPart['proofs'] ?? [] as $proofData) {
                $proofs[] = Proof::fromArray($proofData);
            }
        }

        return new Token($mint, $unit, $proofs, $memo);
    }

    /**
     * Deserialize V4 token (cashuB)
     */
    private static function deserializeV4(string $tokenString): Token
    {
        $base64 = substr($tokenString, 6);
        $base64 = strtr($base64, '-_', '+/');
        $base64 = str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '=');

        $cbor = base64_decode($base64);
        $data = CBOR::decode($cbor);

        $mint = $data['m'] ?? '';
        $unit = $data['u'] ?? 'sat';
        $memo = $data['d'] ?? null;

        $proofs = [];
        foreach ($data['t'] ?? [] as $tokenPart) {
            $keysetId = bin2hex($tokenPart['i']);

            foreach ($tokenPart['p'] ?? [] as $p) {
                $dleq = null;
                if (isset($p['d'])) {
                    $dleq = new DLEQWallet(
                        bin2hex($p['d']['e']),
                        bin2hex($p['d']['s']),
                        bin2hex($p['d']['r'])
                    );
                }

                $proofs[] = new Proof(
                    $keysetId,
                    $p['a'],
                    $p['s'],
                    bin2hex($p['c']),
                    $dleq,
                    $p['w'] ?? null
                );
            }
        }

        return new Token($mint, $unit, $proofs, $memo);
    }
}

// ============================================================================
// HTTP CLIENT
// ============================================================================

/**
 * HTTP client for mint API
 */
class MintClient
{
    private string $mintUrl;
    private int $timeout;

    public function __construct(string $mintUrl, int $timeout = 30)
    {
        $this->mintUrl = rtrim($mintUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Make a GET request
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * Make a POST request
     */
    public function post(string $path, array $data, ?int $timeout = null): array
    {
        return $this->request('POST', $path, $data, $timeout);
    }

    /**
     * Make an HTTP request
     */
    private function request(string $method, string $path, ?array $data = null, ?int $timeout = null): array
    {
        $url = $this->mintUrl . '/v1/' . ltrim($path, '/');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout ?? $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // curl handle is auto-closed when it goes out of scope in PHP 8.0+

        if ($error) {
            throw new CashuException("HTTP request failed: $error");
        }

        if ($response === false || $response === '') {
            // Empty body on a completed request is ambiguous — the mint may have processed
            // the operation but a proxy/CDN dropped the body. Treat as an error so callers
            // do NOT delete their recovery journal. See FABLE-CASHU-WALLET-PHP (F3).
            throw new CashuException("HTTP request failed: empty response (HTTP $httpCode)");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMsg = (is_array($decoded) ? ($decoded['detail'] ?? null) : null) ?? "HTTP error $httpCode";
            if (is_array($errorMsg)) {
                $errorMsg = json_encode($errorMsg);
            }
            $errorCode = is_array($decoded) ? ($decoded['code'] ?? null) : null;
            throw new CashuProtocolException($errorMsg, $errorCode);
        }

        // A 2xx that isn't valid JSON is also ambiguous (HTML error page, truncation).
        if ($decoded === null && strtolower(trim($response)) !== 'null') {
            throw new CashuException("Invalid JSON response from mint (HTTP $httpCode)");
        }

        return is_array($decoded) ? $decoded : [];
    }
}

// ============================================================================
// WALLET STORAGE (SQLite persistence)
// ============================================================================

/**
 * SQLite storage for wallet data (proofs, counters, pending operations)
 *
 * Provides persistent storage for:
 * - Proofs with state tracking (UNSPENT, PENDING, SPENT)
 * - Keyset counters for deterministic secret generation (NUT-13)
 * - Pending operations for crash recovery
 */
class WalletStorage
{
    private \PDO $pdo;
    private string $walletId;

    /**
     * Create a wallet storage instance
     *
     * @param string $dbPath Path to SQLite database file
     * @param string $mintUrl Mint URL (used to create wallet ID for multi-wallet support)
     * @param string $unit Currency unit (e.g., 'sat', 'eur') - different units have separate wallets
     */
    public function __construct(string $dbPath, string $mintUrl, string $unit = 'sat', ?string $storageIdentity = null)
    {
        // Ensure directory exists
        $dir = dirname($dbPath);
        if ($dir && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new \PDO("sqlite:$dbPath");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->walletId = self::deriveWalletId($mintUrl, $unit, $storageIdentity);
        $this->initSchema();
    }

    public static function deriveWalletId(
        string $mintUrl,
        string $unit = 'sat',
        ?string $storageIdentity = null
    ): string {
        $identityMaterial = rtrim($mintUrl, '/') . ':' . strtolower($unit);
        if ($storageIdentity !== null && $storageIdentity !== '') {
            $identityMaterial .= ':account:' . $storageIdentity;
        }
        return substr(hash('sha256', $identityMaterial), 0, 16);
    }

    /**
     * Initialize database schema on an external PDO connection
     *
     * Useful when integrating with an existing database that manages
     * its own connection (e.g., CashuPayServer's Database class).
     *
     * @param \PDO $pdo PDO connection to use
     */
    public static function initializeSchema(\PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cashu_proofs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                wallet_id TEXT NOT NULL,
                keyset_id TEXT NOT NULL,
                amount INTEGER NOT NULL,
                secret TEXT NOT NULL,
                C TEXT NOT NULL,
                dleq TEXT,
                state TEXT NOT NULL DEFAULT 'UNSPENT',
                mint_quote_id TEXT,
                created_at INTEGER NOT NULL,
                spent_at INTEGER,
                UNIQUE(wallet_id, secret)
            );

            CREATE TABLE IF NOT EXISTS cashu_counters (
                wallet_id TEXT NOT NULL,
                keyset_id TEXT NOT NULL,
                counter INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY(wallet_id, keyset_id)
            );

            CREATE TABLE IF NOT EXISTS cashu_pending_operations (
                id TEXT PRIMARY KEY,
                wallet_id TEXT NOT NULL,
                type TEXT NOT NULL,
                data TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS cashu_wallet_metadata (
                wallet_id TEXT PRIMARY KEY,
                seed_fingerprint TEXT NOT NULL,
                ready INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS cashu_mint_quotes (
                wallet_id TEXT NOT NULL,
                quote_id TEXT NOT NULL,
                key_counter INTEGER NOT NULL,
                pubkey TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                PRIMARY KEY(wallet_id, quote_id)
            );

            CREATE INDEX IF NOT EXISTS idx_proofs_wallet_state
                ON cashu_proofs(wallet_id, state);
            CREATE INDEX IF NOT EXISTS idx_proofs_secret
                ON cashu_proofs(secret);
        ");
    }

    /**
     * Initialize database schema (instance method)
     */
    private function initSchema(): void
    {
        self::initializeSchema($this->pdo);
    }

    /**
     * Get the wallet ID (hash of mint URL and unit)
     */
    public function getWalletId(): string
    {
        return $this->walletId;
    }

    /**
     * Get the PDO instance for advanced operations
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /** Return whether this account has any state predating seed metadata. */
    public function hasWalletData(): bool
    {
        foreach (['cashu_proofs', 'cashu_counters', 'cashu_pending_operations'] as $table) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM $table WHERE wallet_id = ? LIMIT 1");
            $stmt->execute([$this->walletId]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }

    public function getSeedFingerprint(): ?string
    {
        $stmt = $this->pdo->prepare('SELECT seed_fingerprint FROM cashu_wallet_metadata WHERE wallet_id = ?');
        $stmt->execute([$this->walletId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    public function isSeedReady(): bool
    {
        $stmt = $this->pdo->prepare('SELECT ready FROM cashu_wallet_metadata WHERE wallet_id = ?');
        $stmt->execute([$this->walletId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    /** Bind a seed to this account. Callers must explicitly choose new or legacy adoption. */
    public function bindSeedFingerprint(string $fingerprint, bool $expectEmpty, bool $ready = true): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $existing = $this->getSeedFingerprint();
            if ($existing !== null && !hash_equals($existing, $fingerprint)) {
                throw new CashuException('Storage is already bound to a different wallet seed');
            }
            if ($existing === null && $expectEmpty && $this->hasWalletData()) {
                throw new CashuException('Cannot initialize a new seed on storage containing wallet data');
            }
            if ($existing === null) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO cashu_wallet_metadata (wallet_id, seed_fingerprint, ready, created_at) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$this->walletId, $fingerprint, $ready ? 1 : 0, time()]);
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $this->pdo->exec('ROLLBACK'); } catch (\Throwable $ignored) {}
            throw $e;
        }
    }

    public function markSeedReady(): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cashu_wallet_metadata SET ready = 1 WHERE wallet_id = ? AND seed_fingerprint IS NOT NULL'
        );
        $stmt->execute([$this->walletId]);
        if ($stmt->rowCount() !== 1) {
            throw new CashuException('Cannot mark uninitialized storage ready');
        }
    }

    /**
     * List all wallets in the database with their statistics
     *
     * Static method that can be called without creating a wallet instance.
     * Useful for discovery and debugging when you don't know which mints are stored.
     *
     * @param string $dbPath Path to SQLite database file
     * @return array Array of wallet info arrays, each containing:
     *               - wallet_id: string (hash of mint URL + unit)
     *               - total_proofs: int
     *               - unspent: int (count)
     *               - spent: int (count)
     *               - pending: int (count)
     *               - balance: int (sum of unspent amounts)
     *               - keyset_ids: string[] (unique keyset IDs for this wallet)
     */
    public static function listWallets(string $dbPath): array
    {
        if (!file_exists($dbPath)) {
            return [];
        }

        $pdo = new \PDO("sqlite:$dbPath");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Get wallet statistics
        $stmt = $pdo->query("
            SELECT
                wallet_id,
                COUNT(*) as total_proofs,
                SUM(CASE WHEN state = 'UNSPENT' THEN 1 ELSE 0 END) as unspent,
                SUM(CASE WHEN state = 'SPENT' THEN 1 ELSE 0 END) as spent,
                SUM(CASE WHEN state = 'PENDING' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN state = 'UNSPENT' THEN amount ELSE 0 END) as balance
            FROM cashu_proofs
            GROUP BY wallet_id
            ORDER BY total_proofs DESC
        ");

        $wallets = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get keyset IDs for each wallet
        $keysetStmt = $pdo->query("
            SELECT wallet_id, keyset_id
            FROM cashu_proofs
            GROUP BY wallet_id, keyset_id
        ");

        $keysetsByWallet = [];
        foreach ($keysetStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $keysetsByWallet[$row['wallet_id']][] = $row['keyset_id'];
        }

        // Merge keyset IDs into wallet data
        foreach ($wallets as &$wallet) {
            $wallet['keyset_ids'] = $keysetsByWallet[$wallet['wallet_id']] ?? [];
        }

        return $wallets;
    }

    // ========================================================================
    // PROOF MANAGEMENT
    // ========================================================================

    /**
     * Store proofs in the database
     *
     * @param Proof[] $proofs Array of Proof objects
     * @param string|null $quoteId Optional mint quote ID for tracking
     */
    public function storeProofs(array $proofs, ?string $quoteId = null): void
    {
        if (empty($proofs)) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO cashu_proofs
            (wallet_id, keyset_id, amount, secret, C, dleq, state, mint_quote_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'UNSPENT', ?, ?)
        ");

        // Store all proofs atomically: either every proof of a mint/swap/melt-change is
        // persisted, or none is. A crash mid-loop must not leave a partial set (which would
        // silently lose the un-stored denominations). See FABLE-CASHU-WALLET-PHP (F4).
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $now = time();
            foreach ($proofs as $proof) {
                $dleq = null;
                if ($proof->dleq !== null) {
                    $dleq = json_encode([
                        'e' => $proof->dleq->e,
                        's' => $proof->dleq->s,
                        'r' => $proof->dleq->r
                    ]);
                }

                $stmt->execute([
                    $this->walletId,
                    $proof->id,
                    $proof->amount,
                    $proof->secret,
                    $proof->C,
                    $dleq,
                    $quoteId,
                    $now
                ]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Exception $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get proofs by state
     *
     * @param string $state Proof state ('UNSPENT', 'PENDING', 'SPENT')
     * @return array Array of proof data arrays
     */
    public function getProofs(string $state = ProofState::UNSPENT): array
    {
        $stmt = $this->pdo->prepare("
            SELECT keyset_id, amount, secret, C, dleq, state, mint_quote_id, created_at
            FROM cashu_proofs
            WHERE wallet_id = ? AND state = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$this->walletId, $state]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Update the state of proofs by their secrets
     *
     * @param array $secrets Array of secret strings
     * @param string $state New state ('UNSPENT', 'PENDING', 'SPENT')
     */
    public function updateProofsState(array $secrets, string $state): void
    {
        if (empty($secrets)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($secrets), '?'));
        $params = array_merge(
            [$state, $state === ProofState::SPENT ? time() : null, $this->walletId],
            $secrets
        );

        $stmt = $this->pdo->prepare("
            UPDATE cashu_proofs
            SET state = ?, spent_at = ?
            WHERE wallet_id = ? AND secret IN ($placeholders)
        ");
        $stmt->execute($params);
    }

    /**
     * Get proof states by their secrets
     *
     * @param array $secrets Array of secret strings
     * @return array Map of secret => state for matching proofs
     */
    public function getProofsStatesBySecrets(array $secrets): array
    {
        if (empty($secrets)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($secrets), '?'));
        $stmt = $this->pdo->prepare("
            SELECT secret, state FROM cashu_proofs
            WHERE wallet_id = ? AND secret IN ($placeholders)
        ");
        $stmt->execute(array_merge([$this->walletId], $secrets));

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[$row['secret']] = $row['state'];
        }
        return $result;
    }

    /**
     * Delete proofs by their secrets
     *
     * @param array $secrets Array of secret strings
     */
    public function deleteProofs(array $secrets): void
    {
        if (empty($secrets)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($secrets), '?'));
        $params = array_merge([$this->walletId], $secrets);

        $stmt = $this->pdo->prepare("
            DELETE FROM cashu_proofs
            WHERE wallet_id = ? AND secret IN ($placeholders)
        ");
        $stmt->execute($params);
    }

    // ========================================================================
    // OFFLINE STORAGE HELPERS
    // ========================================================================

    /**
     * Static factory for offline (standalone) storage access
     *
     * Use this when you need to access stored proofs without connecting to a mint.
     * Useful for balance checks, token export, or maintenance when the mint is unreachable.
     *
     * @param string $dbPath Path to SQLite database file
     * @param string $mintUrl Mint URL (used to derive wallet ID)
     * @param string $unit Currency unit (default: 'sat')
     * @return self
     */
    public static function forOffline(string $dbPath, string $mintUrl, string $unit = 'sat'): self
    {
        return new self($dbPath, $mintUrl, $unit);
    }

    /**
     * Get total balance of unspent proofs
     *
     * Calculates balance directly from storage without contacting the mint.
     * Use this for quick balance checks or when the mint is unreachable.
     *
     * @return int Total balance in smallest unit
     */
    public function getBalance(): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM cashu_proofs
            WHERE wallet_id = ? AND state = ?
        ");
        $stmt->execute([$this->walletId, ProofState::UNSPENT]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)$row['total'];
    }

    /**
     * Get proofs as Proof objects
     *
     * Converts stored proof data to Proof objects, including DLEQ if present.
     * Use this when you need Proof objects for token serialization or other operations.
     *
     * @param string $state Proof state filter (default: UNSPENT)
     * @return Proof[] Array of Proof objects
     */
    public function getProofsAsObjects(string $state = ProofState::UNSPENT): array
    {
        $rows = $this->getProofs($state);
        return array_map(function($row) {
            $dleq = null;
            if (!empty($row['dleq'])) {
                $dleqData = json_decode($row['dleq'], true);
                if ($dleqData) {
                    $dleq = new DLEQWallet($dleqData['e'], $dleqData['s'], $dleqData['r'] ?? null);
                }
            }
            return new Proof($row['keyset_id'], (int)$row['amount'], $row['secret'], $row['C'], $dleq);
        }, $rows);
    }

    /** @return Proof[] */
    public function getProofsBySecretsAsObjects(array $secrets): array
    {
        if (empty($secrets)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($secrets), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT keyset_id, amount, secret, C, dleq FROM cashu_proofs
             WHERE wallet_id = ? AND secret IN ($placeholders)"
        );
        $stmt->execute(array_merge([$this->walletId], $secrets));
        $bySecret = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $dleq = null;
            if (!empty($row['dleq'])) {
                $data = json_decode($row['dleq'], true);
                if ($data) {
                    $dleq = new DLEQWallet($data['e'], $data['s'], $data['r'] ?? null);
                }
            }
            $bySecret[$row['secret']] = new Proof(
                $row['keyset_id'],
                (int)$row['amount'],
                $row['secret'],
                $row['C'],
                $dleq
            );
        }
        $proofs = [];
        foreach ($secrets as $secret) {
            if (!isset($bySecret[$secret])) {
                throw new CashuException('Pending operation input proof is missing from storage');
            }
            $proofs[] = $bySecret[$secret];
        }
        return $proofs;
    }

    // ========================================================================
    // COUNTER MANAGEMENT
    // ========================================================================

    /**
     * Get current counter for a keyset
     *
     * @param string $keysetId Keyset ID
     * @return int Current counter value (0 if not set)
     */
    public function getCounter(string $keysetId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT counter FROM cashu_counters
            WHERE wallet_id = ? AND keyset_id = ?
        ");
        $stmt->execute([$this->walletId, $keysetId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? (int)$row['counter'] : 0;
    }

    /**
     * Set counter for a keyset
     *
     * @param string $keysetId Keyset ID
     * @param int $counter New counter value
     */
    public function setCounter(string $keysetId, int $counter): void
    {
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO cashu_counters (wallet_id, keyset_id, counter)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->walletId, $keysetId, $counter]);
    }

    /**
     * Atomically increment counter and return the old value
     *
     * @param string $keysetId Keyset ID
     * @return int The counter value before incrementing
     */
    public function incrementCounter(string $keysetId): int
    {
        // Use BEGIN IMMEDIATE to acquire lock immediately and prevent race conditions
        // when multiple processes try to increment the counter concurrently.
        // We use raw SQL commands throughout since exec('BEGIN IMMEDIATE') doesn't
        // update PDO's internal transaction state.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $current = $this->getCounter($keysetId);
            $this->setCounter($keysetId, $current + 1);
            $this->pdo->exec('COMMIT');
            return $current;
        } catch (\Exception $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Exception $rollbackEx) {
                // Transaction may have already been rolled back or not started
            }
            throw $e;
        }
    }

    /**
     * Get all counters for this wallet
     *
     * @return array Map of keyset_id => counter
     */
    public function getAllCounters(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT keyset_id, counter FROM cashu_counters
            WHERE wallet_id = ?
        ");
        $stmt->execute([$this->walletId]);

        $counters = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $counters[$row['keyset_id']] = (int)$row['counter'];
        }

        return $counters;
    }

    /**
     * Atomically reserve spend inputs, allocate deterministic outputs, and journal
     * the operation before any mint request. Inputs not already in this storage
     * (for example received tokens) are durably imported in PENDING state.
     *
     * @param Proof[] $proofs
     * @param int[] $amounts
     */
    public function preparePendingSpend(
        string $id,
        string $type,
        array $proofs,
        string $keysetId,
        array $amounts,
        array $extraData = []
    ): array {
        $inputSecrets = array_map(fn($proof) => $proof->secret, $proofs);
        if (count($inputSecrets) !== count(array_unique($inputSecrets))) {
            throw new CashuException('Cannot reserve duplicate input proofs');
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $existing = $this->getPendingOperationById($id);
            if ($existing !== null) {
                $data = $existing['data'];
                if ($existing['type'] !== $type || ($data['input_secrets'] ?? []) !== $inputSecrets) {
                    throw new CashuException("Pending operation ID collision: $id");
                }
                $this->pdo->exec('COMMIT');
                return $data;
            }

            $select = $this->pdo->prepare(
                'SELECT state FROM cashu_proofs WHERE wallet_id = ? AND secret = ?'
            );
            $update = $this->pdo->prepare(
                'UPDATE cashu_proofs SET state = ?, spent_at = NULL WHERE wallet_id = ? AND secret = ?'
            );
            $insert = $this->pdo->prepare("\n                INSERT INTO cashu_proofs\n                (wallet_id, keyset_id, amount, secret, C, dleq, state, mint_quote_id, created_at)\n                VALUES (?, ?, ?, ?, ?, ?, 'PENDING', NULL, ?)\n            ");

            foreach ($proofs as $proof) {
                $select->execute([$this->walletId, $proof->secret]);
                $state = $select->fetchColumn();
                if ($state !== false && $state !== ProofState::UNSPENT) {
                    throw new CashuException("Cannot reserve proof: state is $state");
                }
                if ($state === false) {
                    $dleq = $proof->dleq === null ? null : json_encode([
                        'e' => $proof->dleq->e,
                        's' => $proof->dleq->s,
                        'r' => $proof->dleq->r,
                    ]);
                    $insert->execute([
                        $this->walletId, $proof->id, $proof->amount, $proof->secret,
                        $proof->C, $dleq, time(),
                    ]);
                } else {
                    $update->execute([ProofState::PENDING, $this->walletId, $proof->secret]);
                }
            }

            $counterStart = $this->getCounter($keysetId);
            if (!empty($amounts)) {
                $this->setCounter($keysetId, $counterStart + count($amounts));
            }
            $data = array_merge($extraData, [
                'counter_start' => $counterStart,
                'keyset_id' => $keysetId,
                'amounts' => array_values($amounts),
                'input_secrets' => $inputSecrets,
            ]);
            $this->savePendingOperation($id, $type, $data, $extraData['expires_at'] ?? null);
            $this->pdo->exec('COMMIT');
            return $data;
        } catch (\Throwable $e) {
            try { $this->pdo->exec('ROLLBACK'); } catch (\Throwable $ignored) {}
            throw $e;
        }
    }

    /** Atomically persist outputs, transition inputs, and remove the journal. */
    public function finalizePendingSpend(
        string $id,
        array $inputSecrets,
        string $inputState,
        array $outputProofs = []
    ): void {
        $this->pdo->beginTransaction();
        try {
            if (!empty($outputProofs)) {
                $this->storeProofs($outputProofs);
            }
            $this->updateProofsState($inputSecrets, $inputState);
            $this->deletePendingOperation($id);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ========================================================================
    // PENDING OPERATIONS (for crash recovery)
    // ========================================================================

    /**
     * Save a pending operation for crash recovery
     *
     * @param string $id Unique operation ID
     * @param string $type Operation type (e.g., 'mint', 'melt', 'swap')
     * @param array $data Operation data
     * @param int|null $expiresAt Optional expiration timestamp
     */
    public function savePendingOperation(string $id, string $type, array $data, ?int $expiresAt = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO cashu_pending_operations
            (id, wallet_id, type, data, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->scopedOperationId($id),
            $this->walletId,
            $type,
            json_encode($data),
            time(),
            $expiresAt
        ]);
    }

    /**
     * Get a pending operation by its ID
     *
     * @param string $id Operation ID
     * @return array|null Operation data or null if not found
     */
    public function getPendingOperationById(string $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, type, data, created_at, expires_at
            FROM cashu_pending_operations
            WHERE id = ? AND wallet_id = ?
        ");
        $stmt->execute([$this->scopedOperationId($id), $this->walletId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            // Journals created before account-scoped IDs used the public ID directly.
            $stmt->execute([$id, $this->walletId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        if ($row) {
            $row['id'] = $id;
            $row['data'] = json_decode($row['data'], true);
        }
        return $row ?: null;
    }

    /**
     * Get pending operations
     *
     * @param string|null $type Filter by type, or null for all
     * @return array Array of pending operations
     */
    public function getPendingOperations(?string $type = null): array
    {
        if ($type !== null) {
            $stmt = $this->pdo->prepare("
                SELECT id, type, data, created_at, expires_at
                FROM cashu_pending_operations
                WHERE wallet_id = ? AND type = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$this->walletId, $type]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT id, type, data, created_at, expires_at
                FROM cashu_pending_operations
                WHERE wallet_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$this->walletId]);
        }

        $ops = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $row['id'] = $this->unscopedOperationId($row['id']);
            $row['data'] = json_decode($row['data'], true);
            $ops[] = $row;
        }

        return $ops;
    }

    // ========================================================================
    // MINT QUOTE LOCKING KEYS (NUT-20)
    // ========================================================================

    /** Remember which deterministic locking-key counter a mint quote uses. */
    public function storeMintQuoteKey(string $quoteId, int $counter, string $pubkey): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO cashu_mint_quotes (wallet_id, quote_id, key_counter, pubkey, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->walletId, $quoteId, $counter, $pubkey, time()]);
    }

    /** @return ?array{key_counter: int, pubkey: string} */
    public function getMintQuoteKey(string $quoteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT key_counter, pubkey FROM cashu_mint_quotes WHERE wallet_id = ? AND quote_id = ?'
        );
        $stmt->execute([$this->walletId, $quoteId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? ['key_counter' => (int)$row['key_counter'], 'pubkey' => $row['pubkey']] : null;
    }

    public function deleteMintQuoteKey(string $quoteId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM cashu_mint_quotes WHERE wallet_id = ? AND quote_id = ?'
        );
        $stmt->execute([$this->walletId, $quoteId]);
    }

    /** Return secrets reserved by unresolved money-moving operations. */
    public function getReservedInputSecrets(): array
    {
        $reserved = [];
        foreach (['melt', 'swap'] as $type) {
            foreach ($this->getPendingOperations($type) as $operation) {
                foreach (($operation['data']['input_secrets'] ?? []) as $secret) {
                    $reserved[$secret] = true;
                }
            }
        }
        return array_keys($reserved);
    }

    /**
     * Delete a pending operation
     *
     * @param string $id Operation ID
     */
    public function deletePendingOperation(string $id): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM cashu_pending_operations
            WHERE id IN (?, ?) AND wallet_id = ?
        ");
        $stmt->execute([$this->scopedOperationId($id), $id, $this->walletId]);
    }

    private function scopedOperationId(string $id): string
    {
        return $this->walletId . ':' . $id;
    }

    private function unscopedOperationId(string $id): string
    {
        $prefix = $this->walletId . ':';
        return str_starts_with($id, $prefix) ? substr($id, strlen($prefix)) : $id;
    }

    /**
     * Clean expired pending operations
     *
     * Removes pending operations that have passed their expiration time.
     *
     * @return int Number of deleted operations
     */
    public function cleanExpiredPendingOperations(): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM cashu_pending_operations
            WHERE wallet_id = ? AND type NOT IN ('melt', 'swap')
              AND expires_at IS NOT NULL AND expires_at < ?
        ");
        $stmt->execute([$this->walletId, time()]);
        return $stmt->rowCount();
    }

    /**
     * Get proofs by mint quote ID
     *
     * Used for orphaned invoice recovery - finds proofs that were minted
     * for a specific quote but the invoice wasn't marked as settled.
     *
     * @param string $quoteId Mint quote ID
     * @return array Array of proof data arrays
     */
    public function getProofsByQuoteId(string $quoteId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT keyset_id, amount, secret, C, dleq, state, mint_quote_id, created_at
            FROM cashu_proofs
            WHERE wallet_id = ? AND mint_quote_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$this->walletId, $quoteId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Check if currently in a transaction
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit the current transaction
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Roll back the current transaction
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }
}

// ============================================================================
// WALLET
// ============================================================================

/**
 * Cashu wallet for interacting with mints
 */
class Wallet
{
    private string $mintUrl;
    private string $unit;
    private Unit $unitHelper;
    private MintClient $client;
    private ?array $keysets = null;
    private ?array $keys = null;
    private ?array $mintInfo = null;

    // Seed-based deterministic secret generation (NUT-13)
    private ?string $mnemonic = null;
    private ?BIP32 $bip32 = null;
    private ?string $seedBytes = null; // raw BIP39 seed, needed for NUT-13 HMAC (V2 keyset) derivation
    private array $counters = []; // keyset_id => counter

    // SQLite storage
    private ?WalletStorage $storage = null;
    private ?string $dbPath = null;
    private ?string $storageIdentity = null;
    private bool $seedReadyForSpending = false;

    /**
     * Create a new wallet instance
     *
     * @param string $mintUrl URL of the Cashu mint
     * @param string $unit Unit of account (e.g., 'sat', 'usd', 'eur')
     * @param string|null $dbPath Optional path to SQLite database for persistence
     * @param string|null $storageIdentity Stable account identity for isolating wallets
     */
    public function __construct(
        string $mintUrl,
        string $unit = 'sat',
        ?string $dbPath = null,
        ?string $storageIdentity = null
    )
    {
        $this->mintUrl = rtrim($mintUrl, '/');
        $this->unit = $unit;
        $this->unitHelper = Unit::fromCode($unit);
        $this->client = new MintClient($this->mintUrl);

        if ($dbPath !== null) {
            $this->dbPath = $dbPath;
            $this->storageIdentity = $storageIdentity;
            $this->storage = new WalletStorage($dbPath, $this->mintUrl, $this->unit, $storageIdentity);
        }
    }

    /**
     * Get units supported by a mint (static, before wallet creation)
     *
     * Queries the mint's /v1/keysets endpoint to discover available units.
     *
     * @param string $mintUrl The mint URL to query
     * @return array<string, array{keysets: array, activeCount: int, totalCount: int}>
     *               Map of unit code to keyset info
     */
    public static function getSupportedUnits(string $mintUrl): array
    {
        $client = new MintClient(rtrim($mintUrl, '/'));
        $response = $client->get('keysets');

        $units = [];
        foreach ($response['keysets'] ?? [] as $ks) {
            $unit = $ks['unit'] ?? 'sat';
            $isActive = $ks['active'] ?? true;

            if (!isset($units[$unit])) {
                $units[$unit] = [
                    'keysets' => [],
                    'activeCount' => 0,
                    'totalCount' => 0,
                ];
            }

            $units[$unit]['keysets'][] = [
                'id' => $ks['id'],
                'active' => $isActive,
                'input_fee_ppk' => $ks['input_fee_ppk'] ?? 0,
            ];
            $units[$unit]['totalCount']++;

            if ($isActive) {
                $units[$unit]['activeCount']++;
            }
        }

        return $units;
    }

    /**
     * Format an amount using this wallet's unit
     *
     * @param int $amount Amount in smallest unit
     * @return string Formatted amount (e.g., "100 sat", "$1.50")
     */
    public function formatAmount(int $amount): string
    {
        return $this->unitHelper->format($amount);
    }

    /**
     * Format an amount for a specific unit (static helper)
     *
     * @param int $amount Amount in smallest unit
     * @param string $unit Unit code (e.g., 'sat', 'usd')
     * @return string Formatted amount
     */
    public static function formatAmountForUnit(int $amount, string $unit): string
    {
        return Unit::fromCode($unit)->format($amount);
    }

    /**
     * Get the Unit helper for this wallet
     */
    public function getUnitHelper(): Unit
    {
        return $this->unitHelper;
    }

    // ========================================================================
    // STORAGE
    // ========================================================================

    /**
     * Check if wallet has storage configured
     */
    public function hasStorage(): bool
    {
        return $this->storage !== null;
    }

    /**
     * Get the storage instance
     *
     * @return WalletStorage|null Storage instance or null if not configured
     */
    public function getStorage(): ?WalletStorage
    {
        return $this->storage;
    }

    /**
     * Get the database path
     */
    public function getDbPath(): ?string
    {
        return $this->dbPath;
    }

    /**
     * Get total balance of unspent proofs in storage
     *
     * @return int Total balance in smallest unit
     * @throws CashuException if storage is not configured
     */
    public function getBalance(): int
    {
        if (!$this->storage) {
            throw new CashuException('No storage configured');
        }

        $proofs = $this->storage->getProofs(ProofState::UNSPENT);
        return array_sum(array_map(fn($p) => (int)$p['amount'], $proofs));
    }

    /**
     * Get all unspent proofs from storage
     *
     * @return Proof[] Array of Proof objects
     * @throws CashuException if storage is not configured
     */
    public function getStoredProofs(): array
    {
        if (!$this->storage) {
            throw new CashuException('No storage configured');
        }

        $rows = $this->storage->getProofs(ProofState::UNSPENT);

        return array_map(function($row) {
            $dleq = null;
            if (!empty($row['dleq'])) {
                $dleqData = json_decode($row['dleq'], true);
                if ($dleqData) {
                    $dleq = new DLEQWallet(
                        $dleqData['e'],
                        $dleqData['s'],
                        $dleqData['r'] ?? null
                    );
                }
            }

            return new Proof(
                $row['keyset_id'],
                (int)$row['amount'],
                $row['secret'],
                $row['C'],
                $dleq
            );
        }, $rows);
    }

    /**
     * Sync proof states with the mint
     *
     * Checks the state of all UNSPENT proofs with the mint and updates
     * any that have been spent (e.g., by another wallet instance using
     * the same seed).
     *
     * @return array{checked: int, updated: int, errors: int} Statistics
     * @throws CashuException if storage is not configured
     */
    public function syncProofStates(): array
    {
        if (!$this->storage) {
            return ['error' => 'No storage configured', 'checked' => 0, 'updated' => 0, 'errors' => 0];
        }

        $proofs = $this->storage->getProofs(ProofState::UNSPENT);
        if (empty($proofs)) {
            return ['checked' => 0, 'updated' => 0, 'errors' => 0];
        }

        // Build Y values for batch check (NUT-07 /checkstate)
        $Ys = [];
        foreach ($proofs as $proof) {
            $Y = Crypto::hashToCurve($proof['secret']);
            $Ys[] = bin2hex(Secp256k1::compressPoint($Y));
        }

        try {
            // Check with mint
            $response = $this->client->post('checkstate', ['Ys' => $Ys]);

            $updated = 0;
            $toUpdate = [];
            foreach ($response['states'] ?? [] as $i => $state) {
                // Normalize case - mints may return lowercase states
                $mintState = strtoupper($state['state'] ?? ProofState::UNSPENT);
                if ($mintState === ProofState::SPENT && isset($proofs[$i])) {
                    $toUpdate[] = $proofs[$i]['secret'];
                }
            }

            if (!empty($toUpdate)) {
                $this->storage->updateProofsState($toUpdate, ProofState::SPENT);
                $updated = count($toUpdate);
            }

            return [
                'checked' => count($proofs),
                'updated' => $updated,
                'errors' => 0
            ];
        } catch (\Exception $e) {
            return [
                'checked' => count($proofs),
                'updated' => 0,
                'errors' => 1,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Recover pending melt operations
     *
     * Checks all pending melt operations against the mint and updates
     * proof states accordingly:
     * - PAID: Mark input proofs as SPENT, recover change proofs, delete pending op
     * - UNPAID + expired: Mark input proofs as UNSPENT, delete pending op
     * - PENDING or UNPAID + not expired: Keep as-is for next check
     *
     * @return array{
     *   checked: int,
     *   paid: int,
     *   restored: int,
     *   still_pending: int,
     *   change_recovered: int,
     *   errors: array<string, string>
     * }
     */
    public function recoverPendingMelts(): array
    {
        $result = [
            'checked' => 0,
            'paid' => 0,
            'restored' => 0,
            'still_pending' => 0,
            'change_recovered' => 0,
            'errors' => [],
        ];

        // Early return if no storage
        if (!$this->storage) {
            return $result;
        }

        // Get pending melt operations
        $pendingOps = $this->storage->getPendingOperations('melt');
        if (empty($pendingOps)) {
            return $result;
        }

        foreach ($pendingOps as $op) {
            $result['checked']++;
            $pendingId = $op['id'];

            // Extract quoteId from operation ID (format: "melt:<quoteId>")
            if (!str_starts_with($pendingId, 'melt:')) {
                $result['errors'][$pendingId] = 'Invalid pending operation ID format';
                continue;
            }
            $quoteId = substr($pendingId, 5);

            try {
                // Check quote status with mint
                $quote = $this->checkMeltQuote($quoteId);
                $state = strtoupper($quote->state);

                if ($state === 'PAID') {
                    $inputSecrets = $op['data']['input_secrets'] ?? [];
                    $changeProofs = $this->recoverMeltChange($op['data'], $quote);
                    $this->storage->finalizePendingSpend(
                        $pendingId,
                        $inputSecrets,
                        ProofState::SPENT,
                        $changeProofs
                    );
                    $result['change_recovered'] += self::sumProofs($changeProofs);
                    $result['paid']++;

                } elseif ($state === 'PENDING') {
                    // Still in progress - keep for next check
                    $result['still_pending']++;

                } elseif ($state === 'UNPAID') {
                    // Check if quote has expired
                    $now = time();
                    $expired = $quote->expiry !== null && $quote->expiry < $now;

                    if ($expired) {
                        $inputSecrets = $op['data']['input_secrets'] ?? [];
                        $this->storage->finalizePendingSpend(
                            $pendingId,
                            $inputSecrets,
                            ProofState::UNSPENT
                        );
                        $result['restored']++;
                    } else {
                        // Not expired yet - user might still complete payment
                        $result['still_pending']++;
                    }
                } else {
                    // Unknown state - keep for next check
                    $result['still_pending']++;
                }
            } catch (\Exception $e) {
                $result['errors'][$quoteId] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Recover change proofs from a completed melt operation
     *
     * @param array $pendingData Pending operation data with counter_start, keyset_id, amounts
     * @param MeltQuote $quote Quote response containing change signatures
     * @return Proof[] Recovered change proofs
     */
    private function recoverMeltChange(array $pendingData, MeltQuote $quote): array
    {
        // No change signatures in quote response
        if (empty($quote->change)) {
            return [];
        }

        $counterStart = $pendingData['counter_start'] ?? null;
        $keysetId = $pendingData['keyset_id'] ?? null;
        $amounts = $pendingData['amounts'] ?? [];

        // Need counter data to regenerate blinding factors
        if ($counterStart === null || $keysetId === null || empty($amounts)) {
            return [];
        }

        // Rebuild blinding data from stored counter range
        $blindingData = [];
        foreach ($amounts as $i => $amt) {
            $blinded = $this->createDeterministicBlindedMessage($keysetId, $counterStart + $i);
            $blindingData[] = ['secret' => $blinded['secret'], 'r' => $blinded['r'], 'amount' => $amt];
        }

        // Unblind change signatures and store proofs
        $changeProofs = [];

        if (count($quote->change) > count($blindingData)) {
            throw new CashuException('Mint returned more melt change signatures than prepared outputs');
        }

        foreach ($quote->change as $i => $sig) {
            // NUT-08: the mint ignores the amounts on the wallet's change outputs and
            // assigns its own (the decomposed overpaid fees), so only the keyset and
            // output count can be validated — not the amounts.
            if (!isset($blindingData[$i])
                || ($sig['id'] ?? null) !== $keysetId
                || (int)($sig['amount'] ?? 0) <= 0) {
                throw new CashuException('Mint returned melt change that does not match the prepared outputs');
            }

            $pubkey = $this->getPublicKey($sig['id'], $sig['amount']);
            $this->verifyChangeSignatureDleq($sig, $blindingData[$i], $pubkey);
            $C = Crypto::unblindSignature($sig['C_'], $blindingData[$i]['r'], $pubkey);

            $proof = new Proof(
                $sig['id'],
                $sig['amount'],
                $blindingData[$i]['secret'],
                $C
            );

            $changeProofs[] = $proof;
        }

        return $changeProofs;
    }

    /** Recover or release swaps left ambiguous by a crash or timeout. */
    public function recoverPendingSwaps(): array
    {
        $result = ['checked' => 0, 'recovered' => 0, 'released' => 0, 'still_pending' => 0, 'errors' => []];
        if (!$this->storage) {
            return $result;
        }

        foreach ($this->storage->getPendingOperations('swap') as $op) {
            $result['checked']++;
            try {
                $data = $op['data'];
                $secrets = $data['input_secrets'] ?? [];
                $Ys = [];
                foreach ($secrets as $secret) {
                    $Ys[] = bin2hex(Secp256k1::compressPoint(Crypto::hashToCurve($secret)));
                }
                $response = $this->client->post('checkstate', ['Ys' => $Ys]);
                $states = array_map(
                    fn($state) => strtoupper($state['state'] ?? ''),
                    $response['states'] ?? []
                );
                if (count($states) !== count($secrets)) {
                    throw new CashuException('Mint returned incomplete input state response');
                }

                if (!empty($states) && count(array_filter($states, fn($state) => $state === ProofState::SPENT)) === count($states)) {
                    $proofs = $this->recoverPendingOutputs($data);
                    if (count($proofs) !== count($data['amounts'] ?? [])) {
                        $result['still_pending']++;
                        continue;
                    }
                    $this->storage->finalizePendingSpend($op['id'], $secrets, ProofState::SPENT, $proofs);
                    $result['recovered']++;
                } elseif (!empty($states) && count(array_filter($states, fn($state) => $state === ProofState::UNSPENT)) === count($states)) {
                    $proofs = $this->storage->getProofsBySecretsAsObjects($secrets);
                    $this->submitPreparedSwap($op['id'], $proofs, $data);
                    $result['recovered']++;
                } else {
                    $result['still_pending']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][$op['id']] = $e->getMessage();
            }
        }
        return $result;
    }

    /** @return Proof[] */
    private function recoverPendingOutputs(array $data): array
    {
        $outputs = [];
        $blindingByOutput = [];
        foreach (($data['amounts'] ?? []) as $i => $amount) {
            $blinded = $this->createDeterministicBlindedMessage(
                $data['keyset_id'],
                $data['counter_start'] + $i
            );
            $output = ['amount' => $amount, 'id' => $data['keyset_id'], 'B_' => $blinded['B_']];
            $outputs[] = $output;
            $blindingByOutput[$blinded['B_'] . ':' . $amount] = $blinded;
        }
        if (empty($outputs)) {
            return [];
        }

        $response = $this->client->post('restore', ['outputs' => $outputs]);
        $returnedOutputs = $response['outputs'] ?? [];
        $signatures = $response['signatures'] ?? [];
        $proofs = [];
        foreach ($signatures as $i => $signature) {
            $output = $returnedOutputs[$i] ?? null;
            $key = $output === null ? '' : ($output['B_'] ?? '') . ':' . ($output['amount'] ?? '');
            if (!isset($blindingByOutput[$key])) {
                continue;
            }
            $blinded = $blindingByOutput[$key];
            $pubkey = $this->getPublicKey($signature['id'], $signature['amount']);
            $proofs[] = new Proof(
                $signature['id'],
                $signature['amount'],
                $blinded['secret'],
                Crypto::unblindSignature($signature['C_'], $blinded['r'], $pubkey)
            );
        }
        return $proofs;
    }

    /**
     * NUT-02 keyset hygiene: swap unspent proofs off inactive or soon-expiring
     * keysets onto the active keyset, so held balances survive keyset rotation
     * and are never stranded past a keyset's final_expiry.
     *
     * Inputs reserved by in-flight melt/swap recovery are never touched.
     *
     * @param int $expiryWithinSeconds Rotate proofs whose keyset expires within
     *                                 this window (default 30 days)
     * @return array{checked:int, rotated:int, skipped_reserved:int, skipped_dust:int, errors:array}
     */
    public function rotateProofs(int $expiryWithinSeconds = 2592000, int $batchSize = 50): array
    {
        $result = ['checked' => 0, 'rotated' => 0, 'skipped_reserved' => 0, 'skipped_dust' => 0, 'errors' => []];
        if (!$this->storage) {
            return $result;
        }

        $activeId = $this->getActiveKeysetId();
        $rotateFrom = [];
        foreach ($this->keysets as $keyset) {
            if ($keyset->id === $activeId) {
                continue;
            }
            $expiring = $keyset->finalExpiry !== null
                && $keyset->finalExpiry !== 0
                && $keyset->finalExpiry < time() + $expiryWithinSeconds;
            if (!$keyset->active || $expiring) {
                $rotateFrom[$keyset->id] = true;
            }
        }
        if (empty($rotateFrom)) {
            return $result;
        }

        $reserved = array_flip($this->storage->getReservedInputSecrets());
        $candidates = [];
        foreach ($this->storage->getProofsAsObjects(ProofState::UNSPENT) as $proof) {
            if (!isset($rotateFrom[$proof->id])) {
                continue;
            }
            $result['checked']++;
            if (isset($reserved[$proof->secret])) {
                $result['skipped_reserved']++;
                continue;
            }
            $candidates[] = $proof;
        }

        foreach (array_chunk($candidates, $batchSize) as $batch) {
            try {
                $fee = $this->calculateFee($batch);
                $amount = self::sumProofs($batch) - $fee;
                if ($amount <= 0) {
                    $result['skipped_dust'] += count($batch);
                    continue;
                }
                $this->swap($batch, self::splitAmount($amount));
                $result['rotated'] += count($batch);
            } catch (\Throwable $e) {
                $result['errors'][] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * NUT-12: verify a DLEQ proof on a melt-change signature when present.
     * B_ is recomputed from the deterministic blinding data.
     */
    private function verifyChangeSignatureDleq(array $sig, array $blinding, string $pubkey): void
    {
        if (!isset($sig['dleq'])) {
            return;
        }
        $Bpoint = Secp256k1::pointAdd(
            Crypto::hashToCurve($blinding['secret']),
            Secp256k1::scalarMult($blinding['r'], Secp256k1::getGenerator())
        );
        $B_ = bin2hex(Secp256k1::compressPoint($Bpoint));
        if (!Crypto::verifyDleq($sig['dleq']['e'], $sig['dleq']['s'], $pubkey, $B_, $sig['C_'])) {
            throw new CashuException('Mint returned an invalid DLEQ proof for melt change');
        }
    }

    /** Submit an already journaled swap using its exact deterministic outputs. */
    private function submitPreparedSwap(string $pendingId, array $proofs, array $pendingData): array
    {
        $outputs = [];
        $blindingData = [];
        foreach (($pendingData['amounts'] ?? []) as $i => $amount) {
            $blinded = $this->createDeterministicBlindedMessage(
                $pendingData['keyset_id'],
                $pendingData['counter_start'] + $i
            );
            $outputs[] = [
                'amount' => $amount,
                'id' => $pendingData['keyset_id'],
                'B_' => $blinded['B_'],
            ];
            $blindingData[] = $blinded;
        }

        $response = $this->postWithNut19Replay('swap', [
            'inputs' => array_map(fn($proof) => $proof->toArray(), $proofs),
            'outputs' => $outputs,
        ]);
        $signatures = $response['signatures'] ?? [];
        if (count($signatures) !== count($outputs)) {
            throw new CashuException('Mint returned an incomplete swap response; recovery journal retained');
        }

        $newProofs = [];
        foreach ($signatures as $i => $signature) {
            if (($signature['id'] ?? null) !== $pendingData['keyset_id']
                || (int)($signature['amount'] ?? -1) !== (int)$pendingData['amounts'][$i]) {
                throw new CashuException('Mint returned swap signatures that do not match prepared outputs');
            }
            $pubkey = $this->getPublicKey($signature['id'], $signature['amount']);

            $dleq = null;
            if (isset($signature['dleq'])) {
                // NUT-12: when the mint includes a DLEQ proof, wallets MUST verify it.
                if (!Crypto::verifyDleq($signature['dleq']['e'], $signature['dleq']['s'], $pubkey, $outputs[$i]['B_'], $signature['C_'])) {
                    throw new CashuException('Mint returned an invalid DLEQ proof for a swapped signature');
                }
                $dleq = new DLEQWallet(
                    $signature['dleq']['e'],
                    $signature['dleq']['s'],
                    Secp256k1::scalarToHex($blindingData[$i]['r'])
                );
            }

            $newProofs[] = new Proof(
                $signature['id'],
                $signature['amount'],
                $blindingData[$i]['secret'],
                Crypto::unblindSignature($signature['C_'], $blindingData[$i]['r'], $pubkey),
                $dleq
            );
        }
        $this->storage->finalizePendingSpend(
            $pendingId,
            $pendingData['input_secrets'],
            ProofState::SPENT,
            $newProofs
        );
        return $newProofs;
    }

    /**
     * Parse a display amount string to smallest unit
     *
     * @param string $input User input (e.g., "0.05" for 5 cents in EUR)
     * @return int Amount in smallest unit
     */
    public function parseAmount(string $input): int
    {
        return $this->unitHelper->parse($input);
    }

    /**
     * Load mint information and keys
     */
    public function loadMint(): void
    {
        // Load mint info
        try {
            $this->mintInfo = $this->client->get('info');
        } catch (\Exception $e) {
            // Info endpoint might not exist on older mints
            $this->mintInfo = [];
        }

        // Load keysets
        $keysetsResponse = $this->client->get('keysets');
        $this->keysets = [];

        // Keep ALL keysets for the unit: inactive keysets still owe their
        // input_fee_ppk when their proofs are spent (NUT-02), so fee metadata
        // must survive rotations. Active keysets sort first so that
        // getActiveKeysetId() keeps returning a keyset usable for outputs.
        foreach ($keysetsResponse['keysets'] ?? [] as $ks) {
            if (($ks['unit'] ?? 'sat') === $this->unit) {
                $this->keysets[] = new Keyset(
                    $ks['id'],
                    $ks['unit'] ?? 'sat',
                    [],
                    $ks['active'] ?? true,
                    $ks['input_fee_ppk'] ?? 0,
                    $ks['final_expiry'] ?? null
                );
            }
        }
        usort($this->keysets, fn(Keyset $a, Keyset $b) => (int)$b->active <=> (int)$a->active);

        if (empty(array_filter($this->keysets, fn(Keyset $k) => $k->active))) {
            $this->keysets = [];
        }

        if (empty($this->keysets)) {
            // Provide helpful error with available units
            $available = self::getSupportedUnits($this->mintUrl);
            $activeUnits = array_filter($available, fn($info) => $info['activeCount'] > 0);
            if (empty($activeUnits)) {
                throw new CashuException("No active keysets found on this mint");
            }
            $unitList = implode(', ', array_keys($activeUnits));
            throw new CashuException(
                "No active keysets for unit '{$this->unit}'. Available units: {$unitList}"
            );
        }

        // Load keys for active keysets
        $keysResponse = $this->client->get('keys');
        $this->keys = [];

        foreach ($keysResponse['keysets'] ?? [] as $ks) {
            if (($ks['unit'] ?? 'sat') === $this->unit) {
                $keys = [];
                foreach ($ks['keys'] ?? [] as $amount => $pubkey) {
                    // Skip amounts exceeding PHP_INT_MAX (impractically large)
                    // Use string length check to avoid float conversion warning
                    $amountStr = (string)$amount;
                    $maxStr = (string)PHP_INT_MAX;
                    if (strlen($amountStr) < strlen($maxStr) ||
                        (strlen($amountStr) === strlen($maxStr) && $amountStr <= $maxStr)) {
                        $keys[(int)$amount] = $pubkey;
                    }
                }
                $this->keys[$ks['id']] = $keys;

                // Update keyset with keys
                foreach ($this->keysets as $keyset) {
                    if ($keyset->id === $ks['id']) {
                        $keyset->keys = $keys;
                    }
                }
            }
        }

        // NUT-02: verify announced keyset IDs against the keys. Enforced only
        // for V2 ("01") IDs, where the derivation is unambiguous and the ID
        // commits to unit, input_fee_ppk and final_expiry (a mint cannot
        // misreport fees without changing the ID). Many production mints carry
        // V1 keysets created under older derivation rules whose IDs no longer
        // re-derive — for those the ID is just a stable identifier.
        foreach ($this->keysets as $keyset) {
            if (strtolower(substr($keyset->id, 0, 2)) !== '01') {
                continue;
            }
            $expected = $keyset->deriveExpectedId();
            if ($expected !== null && !hash_equals(strtolower($expected), strtolower($keyset->id))) {
                throw new CashuException(
                    "Mint announced keyset ID {$keyset->id} but its keys derive to {$expected}"
                );
            }
        }
    }

    /**
     * Ensure keys for a (possibly inactive/rotated) keyset are loaded.
     * Fetches GET /keys/{id} on demand and verifies the ID (NUT-02).
     */
    private function ensureKeysetKeys(string $keysetId): void
    {
        if (isset($this->keys[$keysetId])) {
            return;
        }
        $response = $this->client->get('keys/' . urlencode($keysetId));
        foreach ($response['keysets'] ?? [] as $ks) {
            if (($ks['id'] ?? null) !== $keysetId) {
                continue;
            }
            $keys = [];
            foreach ($ks['keys'] ?? [] as $amount => $pubkey) {
                $keys[(int)$amount] = $pubkey;
            }
            foreach ($this->keysets as $keyset) {
                if ($keyset->id === $keysetId) {
                    $keyset->keys = $keys;
                    // Enforce ID verification only for V2 IDs (see loadMint()).
                    if (strtolower(substr($keysetId, 0, 2)) === '01') {
                        $expected = $keyset->deriveExpectedId();
                        if ($expected !== null && !hash_equals(strtolower($expected), strtolower($keysetId))) {
                            throw new CashuException(
                                "Mint announced keyset ID {$keysetId} but its keys derive to {$expected}"
                            );
                        }
                    }
                }
            }
            $this->keys[$keysetId] = $keys;
            return;
        }
        throw new CashuException("Mint has no keys for keyset $keysetId");
    }

    /**
     * Get the active keyset ID
     */
    public function getActiveKeysetId(): string
    {
        foreach ($this->keysets as $keyset) {
            if ($keyset->active) {
                return $keyset->id;
            }
        }
        throw new CashuException('Mint not loaded. Call loadMint() first.');
    }

    /**
     * Get public key for amount. Lazily fetches keys for keysets that are no
     * longer in the active /keys response (e.g. after a keyset rotation).
     */
    public function getPublicKey(string $keysetId, int $amount): string
    {
        if (!isset($this->keys[$keysetId][$amount])) {
            $this->ensureKeysetKeys($keysetId);
        }
        if (!isset($this->keys[$keysetId][$amount])) {
            throw new CashuException("No public key for amount $amount in keyset $keysetId");
        }
        return $this->keys[$keysetId][$amount];
    }

    /**
     * Get input fee PPK (parts per thousand) for a keyset
     */
    public function getInputFeePpk(?string $keysetId = null): int
    {
        $keysetId = $keysetId ?? $this->getActiveKeysetId();

        foreach ($this->keysets as $keyset) {
            if ($keyset->id === $keysetId) {
                return $keyset->inputFeePpk;
            }
        }

        return 0;
    }

    /**
     * Calculate input fees for a set of proofs
     *
     * Fee = ceil(sum(inputFeePpk for each input) / 1000)
     *
     * @param Proof[] $proofs
     */
    public function calculateFee(array $proofs): int
    {
        if (empty($proofs)) {
            return 0;
        }

        $totalFeePpk = 0;
        foreach ($proofs as $proof) {
            $totalFeePpk += $this->getInputFeePpk($proof->id);
        }

        return (int) ceil($totalFeePpk / 1000);
    }

    // ========================================================================
    // MINTING (Lightning -> Tokens)
    // ========================================================================

    /**
     * Request a mint quote (get Lightning invoice to pay)
     */
    public function requestMintQuote(int $amount): MintQuote
    {
        if ($amount <= 0) {
            throw new CashuException('Amount must be greater than 0');
        }

        $body = [
            'amount' => $amount,
            'unit' => $this->unit
        ];

        // NUT-20: lock the quote to a deterministic pubkey so knowing the quote
        // id alone is not enough to mint. Only when the mint supports it.
        $quoteKey = null;
        if ($this->storage && $this->bip32 && $this->mintSupportsNut20()) {
            $counter = $this->storage->incrementCounter(self::NUT20_COUNTER_ID);
            $quoteKey = $this->deriveQuoteLockingKey($counter);
            $body['pubkey'] = $quoteKey['pubkey'];
        }

        $response = $this->client->post('mint/quote/bolt11', $body);
        $quote = MintQuote::fromArray($response);

        if ($quoteKey !== null) {
            $this->storage->storeMintQuoteKey($quote->quote, $quoteKey['counter'], $quoteKey['pubkey']);
        }

        return $quote;
    }

    /** Sentinel counter id (not a keyset) for NUT-20 quote locking keys. */
    private const NUT20_COUNTER_ID = '_nut20_quote_keys';

    /**
     * NUT-19: POST a money-moving request, retrying once with the
     * byte-identical body on a network-level failure. Mints supporting cached
     * responses return the original result for a replayed request, so a
     * timed-out-but-processed request is recovered instead of left ambiguous.
     * Protocol errors (4xx/5xx with a body) are never retried.
     */
    private function postWithNut19Replay(string $path, array $body, ?int $timeout = null): array
    {
        try {
            return $this->client->post($path, $body, $timeout);
        } catch (CashuProtocolException $e) {
            throw $e;
        } catch (CashuException $e) {
            if (!isset($this->mintInfo['nuts']['19'])) {
                throw $e;
            }
            return $this->client->post($path, $body, $timeout);
        }
    }

    private function mintSupportsNut20(): bool
    {
        return (bool)($this->mintInfo['nuts']['20']['supported'] ?? false);
    }

    /**
     * NUT-20 deterministic quote locking key: m/129373'/20'/0'/0'/{counter}
     *
     * @return array{counter: int, privkey: string, pubkey: string}
     */
    public function deriveQuoteLockingKey(int $counter): array
    {
        if ($this->bip32 === null) {
            throw new CashuException('Wallet not initialized with seed');
        }
        if ($counter < 0) {
            throw new CashuException('Counter must be non-negative');
        }
        $privkey = $this->bip32->derivePath("m/129373'/20'/0'/0'/{$counter}");
        $pubkey = bin2hex(Secp256k1::compressPoint(
            Secp256k1::scalarMult(BigInt::fromHex($privkey), Secp256k1::getGenerator())
        ));
        return ['counter' => $counter, 'privkey' => $privkey, 'pubkey' => $pubkey];
    }

    /**
     * NUT-20 message aggregation:
     * "Cashu_MintQuoteSig_v1" || len32(quote) || quote
     *   || per output: len32(amount) || amount || len32(B_) || B_
     * with amounts as canonical minimal big-endian bytes (0 => empty).
     */
    public static function buildMintQuoteSignatureMessage(string $quoteId, array $outputs): string
    {
        $msg = 'Cashu_MintQuoteSig_v1';
        $msg .= pack('N', strlen($quoteId)) . $quoteId;
        foreach ($outputs as $output) {
            $amount = (int)$output['amount'];
            $amountHex = dechex($amount);
            $amountBytes = $amount === 0 ? '' : hex2bin(strlen($amountHex) % 2 ? '0' . $amountHex : $amountHex);
            $B = hex2bin($output['B_']);
            $msg .= pack('N', strlen($amountBytes)) . $amountBytes;
            $msg .= pack('N', strlen($B)) . $B;
        }
        return $msg;
    }

    /** BIP340-sign a mint request for a NUT-20 locked quote. */
    public function signMintQuoteRequest(string $privkey, string $quoteId, array $outputs): string
    {
        $msg = self::buildMintQuoteSignatureMessage($quoteId, $outputs);
        return Secp256k1::schnorrSign($privkey, hash('sha256', $msg, true));
    }

    /**
     * Pre-hardening NUT-20 message (verified by mints released before the
     * May 2026 spec change, e.g. nutshell <= 0.20.x): the UTF-8 quote id
     * concatenated with the B_ hex strings, no length prefixes.
     */
    public static function buildMintQuoteSignatureMessageLegacy(string $quoteId, array $outputs): string
    {
        $msg = $quoteId;
        foreach ($outputs as $output) {
            $msg .= $output['B_'];
        }
        return $msg;
    }

    /** BIP340-sign a mint request using the legacy pre-hardening message. */
    public function signMintQuoteRequestLegacy(string $privkey, string $quoteId, array $outputs): string
    {
        $msg = self::buildMintQuoteSignatureMessageLegacy($quoteId, $outputs);
        return Secp256k1::schnorrSign($privkey, hash('sha256', $msg, true));
    }

    private static function isMintSignatureError(CashuProtocolException $e): bool
    {
        return in_array($e->getCode(), [
            CashuProtocolException::MINT_SIGNATURE_INVALID,
            CashuProtocolException::MINT_PUBKEY_REQUIRED,
        ], true);
    }

    /**
     * Submit a mint request, handling NUT-20 locked quotes: sign with the
     * stored deterministic key, recover the key by counter scan when the
     * local record is missing (seed restore), and fall back to the legacy
     * pre-hardening signature message for older mints.
     */
    private function submitMintRequest(string $quoteId, array $outputs): array
    {
        $request = [
            'quote' => $quoteId,
            'outputs' => $outputs
        ];

        $key = null;
        $record = $this->storage?->getMintQuoteKey($quoteId);
        if ($record !== null && $this->bip32 !== null) {
            $key = $this->deriveQuoteLockingKey($record['key_counter']);
            $request['signature'] = $this->signMintQuoteRequest($key['privkey'], $quoteId, $outputs);
        }

        try {
            return $this->postWithNut19Replay('mint/bolt11', $request);
        } catch (CashuProtocolException $e) {
            if (!self::isMintSignatureError($e)) {
                throw $e;
            }

            if ($key === null) {
                // Locked quote without a local key record (e.g. after seed
                // restore): recover the deterministic key by counter scan.
                $quotePubkey = $this->checkMintQuote($quoteId)->pubkey;
                $key = $quotePubkey ? $this->recoverQuoteLockingKey($quotePubkey) : null;
                if ($key === null) {
                    throw $e;
                }
                $this->storage?->storeMintQuoteKey($quoteId, $key['counter'], $key['pubkey']);
                $request['signature'] = $this->signMintQuoteRequest($key['privkey'], $quoteId, $outputs);
                try {
                    return $this->postWithNut19Replay('mint/bolt11', $request);
                } catch (CashuProtocolException $retryError) {
                    if (!self::isMintSignatureError($retryError)) {
                        throw $retryError;
                    }
                }
            }

            // The mint rejected the current-format signature. Mints released
            // before the NUT-20 message hardening (e.g. nutshell <= 0.20.x)
            // verify the legacy message instead — retry once with it.
            $request['signature'] = $this->signMintQuoteRequestLegacy($key['privkey'], $quoteId, $outputs);
            return $this->postWithNut19Replay('mint/bolt11', $request);
        }
    }

    /**
     * Find the locking key for a quote whose local record is missing (e.g.
     * after a seed restore): scan deterministic counters for the quote pubkey.
     */
    public function recoverQuoteLockingKey(string $quotePubkey, int $scanLimit = 200): ?array
    {
        if ($this->bip32 === null) {
            return null;
        }
        $known = $this->storage ? $this->storage->getCounter(self::NUT20_COUNTER_ID) : 0;
        $max = max($known + 20, $scanLimit);
        for ($counter = 0; $counter < $max; $counter++) {
            $key = $this->deriveQuoteLockingKey($counter);
            if (hash_equals(strtolower($quotePubkey), $key['pubkey'])) {
                if ($this->storage && $known <= $counter) {
                    // Never lower the counter; bump past the recovered index.
                    $this->storage->setCounter(self::NUT20_COUNTER_ID, $counter + 1);
                }
                return $key;
            }
        }
        return null;
    }

    /**
     * Check the status of a mint quote
     */
    public function checkMintQuote(string $quoteId): MintQuote
    {
        $response = $this->client->get("mint/quote/bolt11/$quoteId");
        return MintQuote::fromArray($response);
    }

    /**
     * Mint tokens after quote is paid
     *
     * @return Proof[]
     * @throws CashuException if wallet is not in a safe state for minting
     */
    public function mint(string $quoteId, int $amount): array
    {
        $keysetId = $this->getActiveKeysetId();
        $amounts = self::splitAmount($amount);

        $this->requireSeed();
        $this->requireSafeState();

        // Check for pending operation (retry case)
        $pendingId = "mint:$quoteId";
        $pending = $this->storage ? $this->storage->getPendingOperationById($pendingId) : null;

        $outputs = [];
        $blindingData = [];

        if ($pending) {
            // RETRY: rebuild blinding data from stored counter range
            $counterStart = $pending['data']['counter_start'];
            $pendingKeysetId = $pending['data']['keyset_id'];
            $amounts = $pending['data']['amounts'];
            foreach ($amounts as $i => $amt) {
                $blinded = $this->createDeterministicBlindedMessage($pendingKeysetId, $counterStart + $i);
                $outputs[] = ['amount' => $amt, 'id' => $pendingKeysetId, 'B_' => $blinded['B_']];
                $blindingData[] = ['secret' => $blinded['secret'], 'r' => $blinded['r'], 'amount' => $amt];
            }
            $keysetId = $pendingKeysetId;
        } else {
            // FIRST ATTEMPT: increment counters and record pending op
            $counterStart = $this->getCounter($keysetId);
            foreach ($amounts as $amt) {
                $counter = $this->nextCounter($keysetId);
                $blinded = $this->createDeterministicBlindedMessage($keysetId, $counter);
                $outputs[] = ['amount' => $amt, 'id' => $keysetId, 'B_' => $blinded['B_']];
                $blindingData[] = ['secret' => $blinded['secret'], 'r' => $blinded['r'], 'amount' => $amt];
            }
            // Record pending operation before network call
            if ($this->storage) {
                $this->storage->savePendingOperation($pendingId, 'mint', [
                    'counter_start' => $counterStart,
                    'keyset_id' => $keysetId,
                    'amounts' => $amounts,
                ]);
            }
        }

        // Request signatures from mint (handles NUT-20 locked quotes,
        // including key recovery and the legacy-message fallback).
        $response = $this->submitMintRequest($quoteId, $outputs);

        // Guard: the mint must return exactly one signature per output. A short/empty set
        // means an incomplete response — do NOT proceed to store proofs and delete the
        // recovery journal, or the quote's funds would be lost. See FABLE-CASHU-WALLET-PHP (F3).
        $signatures = $response['signatures'] ?? [];
        if (count($signatures) !== count($outputs)) {
            throw new CashuException(
                'Mint returned ' . count($signatures) . ' signatures for ' . count($outputs)
                . ' outputs; keeping recovery journal for retry'
            );
        }

        // Unblind signatures to create proofs
        $proofs = [];
        foreach ($signatures as $i => $sig) {
            $pubkey = $this->getPublicKey($sig['id'], $sig['amount']);
            $C = Crypto::unblindSignature($sig['C_'], $blindingData[$i]['r'], $pubkey);

            $dleq = null;
            if (isset($sig['dleq'])) {
                // NUT-12: when the mint includes a DLEQ proof, wallets MUST verify it.
                if (!Crypto::verifyDleq($sig['dleq']['e'], $sig['dleq']['s'], $pubkey, $outputs[$i]['B_'], $sig['C_'])) {
                    throw new CashuException('Mint returned an invalid DLEQ proof for a minted signature');
                }
                $dleq = new DLEQWallet(
                    $sig['dleq']['e'],
                    $sig['dleq']['s'],
                    Secp256k1::scalarToHex($blindingData[$i]['r'])
                );
            }

            $proofs[] = new Proof(
                $sig['id'],
                $sig['amount'],
                $blindingData[$i]['secret'],
                $C,
                $dleq
            );
        }

        // Store proofs and clean up pending operation
        if ($this->storage) {
            $this->storage->storeProofs($proofs, $quoteId);
            $this->storage->deletePendingOperation($pendingId);
            $this->storage->deleteMintQuoteKey($quoteId);
        }

        return $proofs;
    }

    // ========================================================================
    // MELTING (Tokens -> Lightning)
    // ========================================================================

    /**
     * Request a melt quote (get fee estimate for paying invoice)
     */
    public function requestMeltQuote(string $invoice): MeltQuote
    {
        $response = $this->client->post('melt/quote/bolt11', [
            'request' => $invoice,
            'unit' => $this->unit
        ]);

        return MeltQuote::fromArray($response);
    }

    /**
     * Check the status of a melt quote
     */
    public function checkMeltQuote(string $quoteId): MeltQuote
    {
        $response = $this->client->get("melt/quote/bolt11/$quoteId");
        return MeltQuote::fromArray($response);
    }

    /**
     * Melt tokens to pay Lightning invoice
     *
     * @param Proof[] $proofs
     * @return array{paid: bool, preimage: ?string, change: Proof[]}
     */
    public function melt(string $quoteId, array $proofs): array
    {
        if (!$this->storage) {
            throw new CashuException('Melt requires persistent storage for input reservation and recovery');
        }
        $inputSecrets = array_map(fn($p) => $p->secret, $proofs);

        $keysetId = $this->getActiveKeysetId();
        $proofsSum = self::sumProofs($proofs);

        // Get quote to know the amount
        $quote = $this->checkMeltQuote($quoteId);
        $totalNeeded = $quote->amount + $quote->feeReserve;

        // Calculate change amount
        $changeAmount = $proofsSum - $totalNeeded;

        $pendingId = "melt:$quoteId";
        $pending = $this->storage ? $this->storage->getPendingOperationById($pendingId) : null;

        // Create change outputs if needed
        $outputs = [];
        $blindingData = [];

        if ($changeAmount > 0) {
            $this->requireSeed();
            $this->requireSafeState();

            $changeAmounts = $pending ? $pending['data']['amounts'] : self::splitAmount($changeAmount);
        }

        $pendingData = $pending
            ? $pending['data']
            : $this->storage->preparePendingSpend(
                $pendingId,
                'melt',
                $proofs,
                $keysetId,
                $changeAmounts ?? [],
                ['quote_expiry' => $quote->expiry, 'expires_at' => $quote->expiry]
            );
        if (($pendingData['input_secrets'] ?? []) !== $inputSecrets) {
            throw new CashuException('Pending melt inputs do not match supplied proofs');
        }
        $keysetId = $pendingData['keyset_id'];
        $counterStart = $pendingData['counter_start'];
        $changeAmounts = $pendingData['amounts'];
        foreach ($changeAmounts as $i => $amt) {
            $blinded = $this->createDeterministicBlindedMessage($keysetId, $counterStart + $i);
            $outputs[] = ['amount' => $amt, 'id' => $keysetId, 'B_' => $blinded['B_']];
            $blindingData[] = ['secret' => $blinded['secret'], 'r' => $blinded['r'], 'amount' => $amt];
        }

        // Send the melt once. Unlike mint/swap, a Lightning melt can complete
        // before its HTTP response reaches us, and some Nutshell deployments
        // advertise NUT-19 without an operational response cache. Blindly
        // replaying then tries to insert the same spent Y values again and may
        // return a raw database uniqueness error even though payment succeeded.
        // On any ambiguous/protocol failure, reconcile through the authoritative
        // melt-quote state before deciding whether to surface the error.
        $request = [
            'quote' => $quoteId,
            'inputs' => array_map(fn($p) => $p->toArray(), $proofs),
            'outputs' => $outputs
        ];
        try {
            // Lightning settlement can take well over the default 30s.
            $response = $this->client->post('melt/bolt11', $request, 120);
        } catch (CashuException $meltError) {
            try {
                $reconciled = $this->checkMeltQuote($quoteId);
                if ($reconciled->isPaid() || $reconciled->isPending()) {
                    $response = [
                        'state' => $reconciled->state,
                        'payment_preimage' => $reconciled->paymentPreimage,
                        'change' => $reconciled->change ?? [],
                    ];
                } else {
                    throw $meltError;
                }
            } catch (CashuException $quoteError) {
                // Preserve the original operation error when reconciliation
                // itself fails or confirms the quote is not paid/pending.
                throw $meltError;
            }
        }

        // Process change
        $changeProofs = [];
        $changeSignatures = $response['change'] ?? [];
        if (count($changeSignatures) > count($blindingData)) {
            throw new CashuException('Mint returned more melt change signatures than prepared outputs');
        }
        foreach ($changeSignatures as $i => $sig) {
            // NUT-08: the mint ignores the amounts on the wallet's change outputs and
            // assigns its own (the decomposed overpaid fees), so only the keyset and
            // output count can be validated — not the amounts.
            if (!isset($blindingData[$i])
                || ($sig['id'] ?? null) !== $keysetId
                || (int)($sig['amount'] ?? 0) <= 0) {
                throw new CashuException('Mint returned melt change that does not match the prepared outputs');
            }
            $pubkey = $this->getPublicKey($sig['id'], $sig['amount']);
            $this->verifyChangeSignatureDleq($sig, $blindingData[$i], $pubkey);
            $C = Crypto::unblindSignature($sig['C_'], $blindingData[$i]['r'], $pubkey);

            $changeProofs[] = new Proof(
                $sig['id'],
                $sig['amount'],
                $blindingData[$i]['secret'],
                $C
            );
        }

        // Check payment state - mints may return lowercase
        $paymentState = strtoupper($response['state'] ?? '');
        $isPaid = $paymentState === 'PAID';
        $isPending = $paymentState === 'PENDING';

        if ($isPaid) {
            $this->storage->finalizePendingSpend(
                $pendingId,
                $inputSecrets,
                ProofState::SPENT,
                $changeProofs
            );
        } else {
            // Ambiguous and unpaid responses remain reserved until quote recovery decides.
            $changeProofs = [];
        }

        return [
            'paid' => $isPaid,
            'pending' => $isPending,
            'preimage' => $response['payment_preimage'] ?? null,
            'change' => $changeProofs
        ];
    }

    // ========================================================================
    // SWAPPING
    // ========================================================================

    /**
     * Swap proofs for new proofs with specified amounts
     *
     * @param Proof[] $proofs Input proofs
     * @param int[] $amounts Desired output amounts
     * @return Proof[]
     */
    public function swap(array $proofs, array $amounts): array
    {
        $keysetId = $this->getActiveKeysetId();
        $inputSum = self::sumProofs($proofs);
        $fee = $this->calculateFee($proofs);
        $outputSum = array_sum($amounts);

        if ($inputSum - $fee !== $outputSum) {
            throw new CashuException("Swap amount mismatch: input=$inputSum - fee=$fee != output=$outputSum");
        }

        // Create blinded messages
        $this->requireSeed();
        $this->requireSafeState();

        if (!$this->storage) {
            throw new CashuException('Swap requires persistent storage for input reservation and recovery');
        }

        $inputSecrets = array_map(fn($p) => $p->secret, $proofs);
        $pendingId = 'swap:' . hash('sha256', implode("\0", $inputSecrets));
        $pending = $this->storage->getPendingOperationById($pendingId);
        $pendingData = $pending
            ? $pending['data']
            : $this->storage->preparePendingSpend(
                $pendingId,
                'swap',
                $proofs,
                $keysetId,
                $amounts
            );
        if (($pendingData['amounts'] ?? []) !== array_values($amounts)) {
            throw new CashuException('Pending swap outputs do not match requested amounts');
        }

        return $this->submitPreparedSwap($pendingId, $proofs, $pendingData);
    }

    /**
     * Split proofs to send a specific amount
     *
     * @param Proof[] $proofs
     * @return array{send: Proof[], keep: Proof[], fee: int}
     */
    public function split(array $proofs, int $amount): array
    {
        $total = self::sumProofs($proofs);
        $fee = $this->calculateFee($proofs);

        if ($amount > $total - $fee) {
            throw new InsufficientBalanceException("Cannot split $amount from $total (fee: $fee, available: " . ($total - $fee) . ")");
        }

        if ($amount === $total - $fee) {
            // No keep proofs needed, just swap for fresh proofs of the send amount
            $sendAmounts = self::splitAmount($amount);
            $newProofs = $this->swap($proofs, $sendAmounts);
            return ['send' => $newProofs, 'keep' => [], 'fee' => $fee];
        }

        $keepAmount = $total - $amount - $fee;
        $sendAmounts = self::splitAmount($amount);
        $keepAmounts = self::splitAmount($keepAmount);

        $allAmounts = array_merge($sendAmounts, $keepAmounts);
        $newProofs = $this->swap($proofs, $allAmounts);

        // Separate into send and keep
        $sendProofs = [];
        $keepProofs = [];
        $sendNeeded = $amount;

        foreach ($newProofs as $proof) {
            if ($sendNeeded > 0 && in_array($proof->amount, $sendAmounts)) {
                $sendProofs[] = $proof;
                $sendNeeded -= $proof->amount;
                // Remove from sendAmounts
                $key = array_search($proof->amount, $sendAmounts);
                if ($key !== false) {
                    unset($sendAmounts[$key]);
                    $sendAmounts = array_values($sendAmounts);
                }
            } else {
                $keepProofs[] = $proof;
            }
        }

        return ['send' => $sendProofs, 'keep' => $keepProofs, 'fee' => $fee];
    }

    // ========================================================================
    // TOKEN OPERATIONS
    // ========================================================================

    /**
     * Serialize proofs to a token string
     *
     * @param Proof[] $proofs
     */
    public function serializeToken(
        array $proofs,
        string $format = 'v4',
        ?string $memo = null,
        bool $includeDleq = false
    ): string {
        // V4 tokens only support modern hex keyset IDs (16 hex chars)
        // If any proof has a deprecated base64 keyset ID, fall back to V3
        if ($format === 'v4') {
            foreach ($proofs as $proof) {
                if (!TokenSerializer::isHexKeysetId($proof->id)) {
                    $format = 'v3';
                    break;
                }
            }
        }

        if ($format === 'v4') {
            return TokenSerializer::serializeV4($this->mintUrl, $proofs, $this->unit, $memo, $includeDleq);
        } else {
            return TokenSerializer::serializeV3($this->mintUrl, $proofs, $this->unit, $memo, $includeDleq);
        }
    }

    /**
     * Resolve NUT-00 short keyset IDs (first 8 bytes of a V2 ID, allowed in V4
     * tokens) to the full 33-byte IDs known from this mint. Wallets MUST
     * support both representations and MUST fail on ambiguity.
     */
    public function resolveShortKeysetIds(array $proofs): void
    {
        foreach ($proofs as $proof) {
            $id = strtolower($proof->id);
            // Full V1 (00..., 16 hex), full V2 (66 hex) and legacy base64 IDs pass through.
            if (strlen($id) !== 16 || !ctype_xdigit($id) || str_starts_with($id, '00')) {
                continue;
            }
            $matches = [];
            foreach ($this->keysets as $keyset) {
                if (str_starts_with(strtolower($keyset->id), $id)) {
                    $matches[strtolower($keyset->id)] = true;
                }
            }
            $matches = array_keys($matches);
            if (count($matches) === 1) {
                $proof->id = $matches[0];
            } elseif (count($matches) > 1) {
                throw new CashuException("Short keyset ID {$proof->id} is ambiguous on this mint");
            } else {
                throw new CashuException("Short keyset ID {$proof->id} does not match any keyset on this mint");
            }
        }
    }

    /**
     * Deserialize a token string
     */
    public function deserializeToken(string $tokenString): Token
    {
        return TokenSerializer::deserialize($tokenString);
    }

    /**
     * Receive a token (swap for new proofs)
     *
     * @return Proof[]
     */
    public function receive(string $tokenString): array
    {
        $token = $this->deserializeToken($tokenString);

        // Verify token is from this mint
        if (rtrim($token->mint, '/') !== $this->mintUrl) {
            throw new CashuException('Token is from a different mint');
        }

        $this->resolveShortKeysetIds($token->proofs);

        // NUT-12: if a received proof carries a full DLEQ (with blinding factor),
        // wallets MUST verify it — it proves the mint actually signed this proof.
        foreach ($token->proofs as $proof) {
            if ($proof->dleq !== null && $proof->dleq->r !== null && $proof->dleq->r !== '') {
                $A = $this->getPublicKey($proof->id, $proof->amount);
                if (!Crypto::verifyProofDleq($proof, $A)) {
                    throw new CashuException('Received token contains an invalid DLEQ proof');
                }
            }
        }

        // Calculate fee and output amount
        $inputAmount = $token->getAmount();
        $fee = $this->calculateFee($token->proofs);
        $outputAmount = $inputAmount - $fee;

        if ($outputAmount <= 0) {
            throw new CashuException("Token amount ($inputAmount) is less than or equal to fee ($fee)");
        }

        // Split output amount into powers of 2
        $amounts = self::splitAmount($outputAmount);
        return $this->swap($token->proofs, $amounts);
    }

    /**
     * Receive a token without swapping (offline/trust mode)
     *
     * WARNING: Does not swap proofs with the mint. The sender could double-spend
     * by redeeming the same proofs elsewhere before you do. Use only when:
     * - You trust the sender
     * - You don't care about double-spend risk
     * - The mint is unreachable and you need to store the token for later
     *
     * The proofs are stored directly in local storage without verification.
     * You should swap them later when the mint is reachable.
     *
     * @param string $tokenString The cashuA/cashuB token string
     * @return Proof[] The stored proofs
     * @throws CashuException if token is from a different mint or storage is not configured
     */
    public function receiveOffline(string $tokenString): array
    {
        $token = $this->deserializeToken($tokenString);

        // Verify token is from this mint
        if (rtrim($token->mint, '/') !== $this->mintUrl) {
            throw new CashuException('Token is from a different mint');
        }

        // Best-effort short-ID resolution: this path must keep working when the
        // mint is unreachable (no keysets loaded), so unresolved short IDs are
        // stored as-is and get resolved by the eventual online swap.
        if (!empty($this->keysets)) {
            $this->resolveShortKeysetIds($token->proofs);
        }

        // Store proofs directly without swap
        if ($this->storage) {
            $this->storage->storeProofs($token->proofs);
        } else {
            throw new CashuException('No storage configured - cannot store offline tokens');
        }

        return $token->proofs;
    }

    // ========================================================================
    // PROOF STATE
    // ========================================================================

    /**
     * Check the state of proofs
     *
     * @param Proof[] $proofs
     * @return array Array of state objects
     */
    public function checkProofState(array $proofs): array
    {
        $Ys = array_map(fn($p) => $p->Y, $proofs);

        $response = $this->client->post('checkstate', ['Ys' => $Ys]);

        return $response['states'] ?? [];
    }

    // ========================================================================
    // UTILITY METHODS
    // ========================================================================

    /**
     * Select proofs to meet a target amount
     *
     * @param Proof[] $proofs
     * @return Proof[]
     */
    public static function selectProofs(array $proofs, int $amount): array
    {
        // Sort by amount descending
        usort($proofs, fn($a, $b) => $b->amount - $a->amount);

        $selected = [];
        $sum = 0;

        foreach ($proofs as $proof) {
            if ($sum >= $amount) break;
            $selected[] = $proof;
            $sum += $proof->amount;
        }

        if ($sum < $amount) {
            throw new InsufficientBalanceException("Insufficient balance: have $sum, need $amount");
        }

        return $selected;
    }

    /**
     * Sum the amounts of proofs
     *
     * @param Proof[] $proofs
     */
    public static function sumProofs(array $proofs): int
    {
        return array_sum(array_map(fn($p) => $p->amount, $proofs));
    }

    /**
     * Split an amount into powers of 2
     *
     * @return int[]
     */
    public static function splitAmount(int $amount): array
    {
        if ($amount === 0) return [];

        $amounts = [];
        $remaining = $amount;

        while ($remaining > 0) {
            // Find highest power of 2 <= remaining
            $power = 1;
            while ($power * 2 <= $remaining) {
                $power *= 2;
            }
            $amounts[] = $power;
            $remaining -= $power;
        }

        // Sort ascending
        sort($amounts);

        return $amounts;
    }

    /**
     * Get mint URL
     */
    public function getMintUrl(): string
    {
        return $this->mintUrl;
    }

    /**
     * Get unit
     */
    public function getUnit(): string
    {
        return $this->unit;
    }

    /**
     * Get mint info
     */
    public function getMintInfo(): ?array
    {
        return $this->mintInfo;
    }

    /**
     * Get keysets
     *
     * @return Keyset[]
     */
    public function getKeysets(): array
    {
        return $this->keysets ?? [];
    }

    // ========================================================================
    // SEED-BASED DETERMINISTIC SECRETS (NUT-13)
    // ========================================================================

    /**
     * Initialize wallet from a mnemonic phrase
     *
     * IMPORTANT: If the database has been lost or corrupted, call restore() after
     * initFromMnemonic() before performing any mint/swap/melt operations. A fresh
     * database will have counters at 0, which can cause secret reuse if the wallet
     * was previously used with higher counter values.
     */
    public function initFromMnemonic(string $mnemonic, string $passphrase = ''): void
    {
        $this->initializeSeed($mnemonic, $passphrase, 'existing');
    }

    /** Initialize storage for a seed guaranteed never to have been used before. */
    public function initializeNewFromMnemonic(string $mnemonic, string $passphrase = ''): void
    {
        $this->initializeSeed($mnemonic, $passphrase, 'new');
    }

    /** Bind pre-fingerprint storage after the application verifies it belongs to this seed. */
    public function adoptSeedForExistingStorage(string $mnemonic, string $passphrase = ''): void
    {
        $this->initializeSeed($mnemonic, $passphrase, 'adopt');
    }

    /** Bind a fresh account for restore; spending stays disabled until restore() completes. */
    public function initializeForRestore(string $mnemonic, string $passphrase = ''): void
    {
        $this->initializeSeed($mnemonic, $passphrase, 'restore');
    }

    public static function calculateSeedFingerprint(string $mnemonic, string $passphrase = ''): string
    {
        if (!Mnemonic::validate($mnemonic)) {
            throw new CashuException('Invalid mnemonic phrase');
        }
        $seed = Mnemonic::toSeed($mnemonic, $passphrase);
        return hash('sha256', "cashu-wallet-php seed fingerprint\0" . $seed);
    }

    private function initializeSeed(string $mnemonic, string $passphrase, string $mode): void
    {
        if (!Mnemonic::validate($mnemonic)) {
            throw new CashuException('Invalid mnemonic phrase');
        }

        $seed = Mnemonic::toSeed($mnemonic, $passphrase);
        $fingerprint = self::calculateSeedFingerprint($mnemonic, $passphrase);

        if (!$this->storage) {
            // Keep legacy seed-only restore workflows available, but never allow
            // them to generate new counters or spend.
        } else {
            $existing = $this->storage->getSeedFingerprint();
            if ($mode === 'existing') {
                if ($existing === null) {
                    throw new CashuException(
                        'Storage has no seed fingerprint. Use initializeNewFromMnemonic(), ' .
                        'adoptSeedForExistingStorage(), or initializeForRestore() explicitly.'
                    );
                }
                if (!hash_equals($existing, $fingerprint)) {
                    throw new CashuException('Storage is bound to a different wallet seed');
                }
            } elseif ($mode === 'new' || $mode === 'restore') {
                if ($existing !== null) {
                    throw new CashuException('Storage account is already initialized');
                }
                $this->storage->bindSeedFingerprint($fingerprint, true, $mode === 'new');
            } elseif ($mode === 'adopt') {
                $this->storage->bindSeedFingerprint($fingerprint, false);
            }
        }

        $this->mnemonic = $mnemonic;
        $this->bip32 = BIP32::fromSeed($seed);
        $this->seedBytes = $seed;
        $this->seedReadyForSpending = $this->storage?->isSeedReady() ?? false;

        // Load counters from storage if available
        if ($this->storage) {
            $this->counters = $this->storage->getAllCounters();
        } else {
            $this->counters = [];
        }
    }

    /**
     * Generate a new mnemonic and initialize the wallet
     *
     * @return string The generated mnemonic (user should back this up!)
     */
    /**
     * Generate a new mnemonic and initialize the wallet
     *
     * IMPORTANT: This method requires storage to be configured. Without storage,
     * counters would be lost on restart, leading to counter reuse and potential
     * token loss. Initialize with a database path:
     *
     *   $wallet = new Wallet($mintUrl, 'sat', './wallet.db');
     *   $wallet->loadMint();
     *   $seed = $wallet->generateMnemonic();
     *   // IMPORTANT: Back up $seed securely!
     *
     * @return string The generated mnemonic (user should back this up!)
     * @throws CashuException if storage is not configured
     */
    public function generateMnemonic(): string
    {
        if (!$this->dbPath) {
            throw new CashuException(
                'Cannot generate mnemonic without storage configured. ' .
                'Provide dbPath in constructor to persist counters: ' .
                'new Wallet($mintUrl, $unit, $dbPath). ' .
                'Without storage, counters would be lost on restart, ' .
                'leading to counter reuse and potential token loss.'
            );
        }

        $mnemonic = Mnemonic::generate();
        $this->initializeNewFromMnemonic($mnemonic);
        return $mnemonic;
    }

    /**
     * Check if wallet has a seed initialized
     */
    public function hasSeed(): bool
    {
        return $this->bip32 !== null;
    }

    /**
     * Check if wallet requires recovery before use
     *
     * A wallet requires recovery when:
     * - It has a seed initialized (for deterministic secret generation)
     * - It does NOT have storage enabled (for counter persistence)
     *
     * In this state, the wallet cannot safely generate new tokens because
     * counters would be lost on restart, leading to counter reuse and
     * potential token loss.
     *
     * To resolve, either:
     * 1. Call restore() to recover existing proofs and set correct counters
     * 2. Reinitialize with storage: new Wallet($mint, $unit, $dbPath)
     *
     * @return bool True if wallet is in recovery mode (has seed, no storage)
     */
    public function requiresRecovery(): bool
    {
        return $this->hasSeed() && (!$this->hasStorage() || !$this->seedReadyForSpending);
    }

    /**
     * Require seed to be initialized, throw if not
     *
     * @throws CashuException if no seed is set
     */
    private function requireSeed(): void
    {
        if (!$this->hasSeed()) {
            throw new CashuException(
                'Wallet seed not initialized. Call initFromMnemonic() or generateMnemonic() first. ' .
                'A seed is required to generate recoverable tokens.'
            );
        }
    }

    /**
     * Require wallet to be in a safe state for token operations
     *
     * Throws if wallet has a seed but no storage. In this state, counters
     * would be lost on restart, leading to counter reuse and token loss.
     *
     * @throws CashuException if wallet requires recovery
     */
    private function requireSafeState(): void
    {
        if ($this->requiresRecovery()) {
            throw new CashuException(
                'Wallet initialized with seed but without storage. ' .
                'The seed is not ready for spending. Complete restore() when recovering an ' .
                'existing seed, or initialize a new seed with persistent storage.'
            );
        }
    }

    /**
     * Get the current mnemonic (if initialized)
     */
    public function getMnemonic(): ?string
    {
        return $this->mnemonic;
    }

    /**
     * Redact sensitive fields from var_dump/print_r output
     */
    public function __debugInfo(): array
    {
        return [
            'mintUrl' => $this->mintUrl,
            'unit' => $this->unit,
            'hasSeed' => $this->hasSeed(),
            'mnemonic' => $this->mnemonic ? '[REDACTED]' : null,
            'bip32' => $this->bip32 ? '[REDACTED]' : null,
            'counters' => $this->counters,
            'activeKeysetId' => $this->activeKeysetId ?? null,
        ];
    }

    /**
     * Prevent serialization of wallet objects containing secret key material
     */
    public function __serialize(): array
    {
        throw new CashuException('Wallet objects must not be serialized - they contain secret key material');
    }

    /**
     * Convert keyset ID (hex) to integer for derivation path
     * Uses modulo 2^31-1 as per NUT-13
     *
     * The keyset ID is interpreted as a big-endian integer and
     * reduced modulo 2^31-1 to fit in the BIP-32 path.
     */
    public function keysetIdToInt(string $keysetId): int
    {
        if (strlen($keysetId) === 16 && ctype_xdigit($keysetId)) {
            // V1 hex format (version byte "00", 16 hex chars) - decode from hex
            $decoded = hex2bin($keysetId);
        } elseif (strlen($keysetId) === 12 && base64_decode($keysetId, true) !== false) {
            // Deprecated pre-V1 base64 format (12 chars) - decode from base64
            $decoded = base64_decode($keysetId, true);
        } else {
            // V2 ("01"...) and unknown formats must NEVER reach the BIP32 path:
            // hex chars are valid base64, so a lenient base64_decode would
            // silently produce garbage derivation instead of an error.
            throw new CashuException(
                "Keyset ID '$keysetId' has no BIP32 integer representation (NUT-13 legacy derivation is for V1 keysets only)"
            );
        }

        // Convert bytes to hex, then to BigInt
        $hex = bin2hex($decoded);
        $bigInt = BigInt::fromHex($hex);
        $modulus = BigInt::fromDec('2147483647'); // 2^31 - 1

        $result = $bigInt->mod($modulus);
        return (int) $result->toDec();
    }

    /**
     * Generate deterministic secret and blinding factor for a keyset/counter (NUT-13)
     *
     * V2 keysets (ID version byte "01"): HMAC-SHA256 KDF —
     *   message = "Cashu_KDF_HMAC_SHA256" || keyset_id_bytes || counter_u64_be
     *   secret = hex(HMAC(seed, message || 0x00)); r = HMAC(seed, message || 0x01) mod N
     *
     * V1 keysets (version byte "00", deprecated) and pre-V1 base64 IDs: BIP32 —
     *   m/129372'/0'/{keyset_id_int}'/{counter}'/0 → secret
     *   m/129372'/0'/{keyset_id_int}'/{counter}'/1 → blinding factor (r)
     *
     * @return array ['secret' => hex, 'r' => BigInt]
     */
    public function generateDeterministicSecret(string $keysetId, int $counter): array
    {
        if ($this->bip32 === null) {
            throw new CashuException('Wallet not initialized with seed');
        }

        if (strlen($keysetId) === 66 && ctype_xdigit($keysetId)) {
            if (strtolower(substr($keysetId, 0, 2)) !== '01') {
                throw new CashuException("Unsupported keyset ID version: $keysetId");
            }
            return $this->generateDeterministicSecretHmac($keysetId, $counter);
        }

        $keysetInt = $this->keysetIdToInt($keysetId);

        // Derive secret: m/129372'/0'/{keyset}'/counter'/0
        $secretPath = "m/129372'/0'/{$keysetInt}'/{$counter}'/0";
        $secret = $this->bip32->derivePath($secretPath);

        // Derive blinding factor: m/129372'/0'/{keyset}'/counter'/1
        $rPath = "m/129372'/0'/{$keysetInt}'/{$counter}'/1";
        $rHex = $this->bip32->derivePath($rPath);
        $r = BigInt::fromHex($rHex);

        // Reduce r modulo curve order
        $n = Secp256k1::getOrder();
        $r = $r->mod($n);

        return ['secret' => $secret, 'r' => $r];
    }

    /**
     * NUT-13 HMAC-SHA256 KDF for V2 ("01") keysets.
     *
     * @return array ['secret' => hex, 'r' => BigInt]
     */
    private function generateDeterministicSecretHmac(string $keysetId, int $counter): array
    {
        if ($this->seedBytes === null) {
            throw new CashuException('Wallet not initialized with seed');
        }
        if ($counter < 0) {
            throw new CashuException('Counter must be non-negative');
        }

        $message = 'Cashu_KDF_HMAC_SHA256' . hex2bin($keysetId) . pack('J', $counter);

        $secretDigest = hash_hmac('sha256', $message . "\x00", $this->seedBytes, true);
        $rDigest = hash_hmac('sha256', $message . "\x01", $this->seedBytes, true);

        $r = BigInt::fromHex(bin2hex($rDigest))->mod(Secp256k1::getOrder());
        if ($r->toDec() === '0') {
            // Astronomically unlikely; spec says reject rather than use r = 0.
            throw new CashuException('Derived invalid blinding scalar r == 0');
        }

        return ['secret' => bin2hex($secretDigest), 'r' => $r];
    }

    /**
     * Create a blinded message using deterministic secret
     *
     * @return array ['B_' => hex, 'r' => BigInt, 'secret' => hex]
     */
    public function createDeterministicBlindedMessage(string $keysetId, int $counter): array
    {
        $derived = $this->generateDeterministicSecret($keysetId, $counter);
        $secret = $derived['secret'];
        $r = $derived['r'];

        // Hash secret to curve point Y
        $Y = Crypto::hashToCurve($secret);
        $G = Secp256k1::getGenerator();

        // B_ = Y + r*G
        $rG = Secp256k1::scalarMult($r, $G);
        $B_ = Secp256k1::pointAdd($Y, $rG);

        return [
            'B_' => bin2hex(Secp256k1::compressPoint($B_)),
            'r' => $r,
            'secret' => $secret
        ];
    }

    /**
     * Get current counter for a keyset
     */
    public function getCounter(string $keysetId): int
    {
        return $this->counters[$keysetId] ?? 0;
    }

    /**
     * Set counter for a keyset
     */
    public function setCounter(string $keysetId, int $counter): void
    {
        $this->counters[$keysetId] = $counter;
    }

    /**
     * Increment and return the counter for a keyset
     */
    private function nextCounter(string $keysetId): int
    {
        if ($this->storage) {
            // Use storage for atomic counter increment (persisted)
            $counter = $this->storage->incrementCounter($keysetId);
            // Keep in-memory cache in sync
            $this->counters[$keysetId] = $counter + 1;
            return $counter;
        }

        // Fallback to in-memory (existing behavior for recovery mode)
        $counter = $this->getCounter($keysetId);
        $this->counters[$keysetId] = $counter + 1;
        return $counter;
    }

    /**
     * Get all keyset counters
     */
    public function getCounters(): array
    {
        return $this->counters;
    }

    /**
     * Set all counters at once (useful for restore)
     */
    public function setCounters(array $counters): void
    {
        $this->counters = $counters;
    }

    // ========================================================================
    // WALLET RESTORE (NUT-09)
    // ========================================================================

    /**
     * Restore tokens for a keyset from a counter range
     *
     * Generates blinded messages for the counter range and checks
     * which ones the mint has signatures for.
     *
     * @param string $keysetId Keyset ID
     * @param int $fromCounter Starting counter (inclusive)
     * @param int $toCounter Ending counter (exclusive)
     * @return Proof[] Recovered proofs
     */
    public function restoreTokensForRange(string $keysetId, int $fromCounter, int $toCounter): array
    {
        if (!$this->hasSeed()) {
            throw new CashuException('Cannot restore: wallet not initialized with seed');
        }

        if (!isset($this->keys[$keysetId])) {
            throw new CashuException("Unknown keyset: $keysetId");
        }

        $outputs = [];
        $blindingData = [];

        // Generate blinded messages for the counter range
        for ($counter = $fromCounter; $counter < $toCounter; $counter++) {
            $blinded = $this->createDeterministicBlindedMessage($keysetId, $counter);

            // We need outputs for each possible amount in the keyset
            // NUT-09 requires sending all possible amounts for each counter
            foreach (array_keys($this->keys[$keysetId]) as $amount) {
                $outputs[] = [
                    'amount' => $amount,
                    'id' => $keysetId,
                    'B_' => $blinded['B_']
                ];

                $blindingData[] = [
                    'secret' => $blinded['secret'],
                    'r' => $blinded['r'],
                    'amount' => $amount,
                    'counter' => $counter
                ];
            }
        }

        if (empty($outputs)) {
            return [];
        }

        // POST to /v1/restore
        $response = $this->client->post('restore', ['outputs' => $outputs]);

        // Process returned signatures
        $proofs = [];
        $returnedOutputs = $response['outputs'] ?? [];
        $returnedSignatures = $response['signatures'] ?? [];

        // Match returned signatures to our blinding data
        for ($i = 0; $i < count($returnedSignatures); $i++) {
            $sig = $returnedSignatures[$i];
            $output = $returnedOutputs[$i] ?? null;

            if ($sig === null || !isset($sig['C_'])) {
                continue;
            }

            // Find matching blinding data by B_
            $matchingData = null;
            foreach ($blindingData as $data) {
                // Create the blinded message again to match
                $blinded = $this->createDeterministicBlindedMessage($keysetId, $data['counter']);
                if ($output && $output['B_'] === $blinded['B_'] && $output['amount'] === $data['amount']) {
                    $matchingData = $data;
                    $matchingData['r'] = $blinded['r'];
                    break;
                }
            }

            if ($matchingData === null) {
                continue;
            }

            // Unblind the signature
            $pubkey = $this->getPublicKey($sig['id'], $sig['amount']);
            $C = Crypto::unblindSignature($sig['C_'], $matchingData['r'], $pubkey);

            $proofs[] = new Proof(
                $sig['id'],
                $sig['amount'],
                $matchingData['secret'],
                $C
            );
        }

        return $proofs;
    }

    /**
     * Restore tokens using a simpler approach - one output per counter
     * This is the approach used by most implementations
     */
    public function restoreBatch(string $keysetId, int $fromCounter, int $batchSize): array
    {
        if (!$this->hasSeed()) {
            throw new CashuException('Cannot restore: wallet not initialized with seed');
        }

        $outputs = [];
        $blindingData = [];

        // Generate one blinded message per counter with amount=1
        // The mint will return the actual amount in the signature
        for ($counter = $fromCounter; $counter < $fromCounter + $batchSize; $counter++) {
            $blinded = $this->createDeterministicBlindedMessage($keysetId, $counter);

            $outputs[] = [
                'amount' => 1, // Placeholder amount
                'id' => $keysetId,
                'B_' => $blinded['B_']
            ];

            $blindingData[$blinded['B_']] = [
                'secret' => $blinded['secret'],
                'r' => $blinded['r'],
                'counter' => $counter
            ];
        }

        if (empty($outputs)) {
            return [];
        }

        // POST to /v1/restore
        $response = $this->client->post('restore', ['outputs' => $outputs]);

        // Process returned signatures
        $proofs = [];
        $returnedOutputs = $response['outputs'] ?? [];
        $returnedSignatures = $response['signatures'] ?? [];

        for ($i = 0; $i < count($returnedSignatures); $i++) {
            $sig = $returnedSignatures[$i];
            $output = $returnedOutputs[$i] ?? null;

            if ($sig === null || !isset($sig['C_']) || $output === null) {
                continue;
            }

            $B_ = $output['B_'];
            if (!isset($blindingData[$B_])) {
                continue;
            }

            $data = $blindingData[$B_];

            // Unblind the signature
            $pubkey = $this->getPublicKey($sig['id'], $sig['amount']);
            $C = Crypto::unblindSignature($sig['C_'], $data['r'], $pubkey);

            $proofs[] = new Proof(
                $sig['id'],
                $sig['amount'],
                $data['secret'],
                $C
            );
        }

        return $proofs;
    }

    /**
     * Full wallet restore - scan all keysets
     *
     * Restores proofs from the mint by scanning all keysets for secrets derived
     * from this wallet's seed. By default, restores ALL units from the mint.
     *
     * WARNING: Setting $allUnits to false is dangerous and can cause PROOF REUSE.
     * Melt operations (Lightning withdrawals) return fee reserve change in sats,
     * regardless of the original token's unit. For example, melting EUR tokens
     * returns leftover fees as sat proofs. If you only restore EUR, those sat
     * proofs are missed, and their counter values may be reused when you later
     * mint sats - generating duplicate secrets and losing funds.
     *
     * Always restore all units unless you are certain no cross-unit operations
     * (like melt) have ever been performed with this seed.
     *
     * @param int $batchSize Number of counters to check per batch
     * @param int $emptyBatches Stop after this many consecutive empty batches
     * @param callable|null $progressCallback Called with (keysetId, counter, proofsFound, unit)
     * @param bool $allUnits Restore ALL units from the mint. Default true.
     *                       WARNING: Setting to false risks proof reuse - see above.
     * @return array ['proofs' => Proof[], 'counters' => array, 'byUnit' => array]
     *               'byUnit' contains ['unit' => ['proofs' => [], 'counters' => []]]
     */
    public function restore(
        // Wider gap tolerance by default: failed operations burn counters, leaving gaps.
        // A small window (was 25*3=75) could stop before reaching later funds. See
        // FABLE-CASHU-WALLET-PHP (F8).
        int $batchSize = 100,
        int $emptyBatches = 5,
        ?callable $progressCallback = null,
        bool $allUnits = true
    ): array {
        if (!$this->hasSeed()) {
            throw new CashuException('Cannot restore: wallet not initialized with seed');
        }

        $allProofs = [];
        $finalCounters = [];
        $byUnit = [];

        // Get all keysets from the mint
        $keysetsResponse = $this->client->get('keysets');

        // Group keysets by unit
        $keysetsByUnit = [];
        foreach ($keysetsResponse['keysets'] ?? [] as $ks) {
            $unit = $ks['unit'] ?? 'sat';

            // If not restoring all units, skip units that don't match
            if (!$allUnits && $unit !== $this->unit) {
                continue;
            }

            if (!isset($keysetsByUnit[$unit])) {
                $keysetsByUnit[$unit] = [];
            }
            $keysetsByUnit[$unit][] = $ks;
        }

        // Process each unit
        foreach ($keysetsByUnit as $unit => $keysets) {
            $unitProofs = [];
            $unitCounters = [];

            // Load keys for each keyset in this unit
            foreach ($keysets as $ks) {
                $keysetId = $ks['id'];

                // Load keys for this keyset if not already loaded
                if (!isset($this->keys[$keysetId])) {
                    try {
                        // URL-encode keyset ID in case it contains special characters like /
                        $keysResponse = $this->client->get('keys/' . urlencode($keysetId));
                        foreach ($keysResponse['keysets'] ?? [] as $keysetData) {
                            if ($keysetData['id'] === $keysetId) {
                                $keys = [];
                                foreach ($keysetData['keys'] ?? [] as $amount => $pubkey) {
                                    $amountStr = (string)$amount;
                                    $maxStr = (string)PHP_INT_MAX;
                                    if (strlen($amountStr) < strlen($maxStr) ||
                                        (strlen($amountStr) === strlen($maxStr) && $amountStr <= $maxStr)) {
                                        $keys[(int)$amount] = $pubkey;
                                    }
                                }
                                $this->keys[$keysetId] = $keys;
                            }
                        }
                    } catch (CashuProtocolException $e) {
                        // Skip this keyset - keys not available (old/deprecated keyset)
                        continue;
                    }
                }

                // Scan this keyset
                $counter = 0;
                $emptyCount = 0;
                $keysetProofs = [];

                while ($emptyCount < $emptyBatches) {
                    $proofs = $this->restoreBatch($keysetId, $counter, $batchSize);

                    if ($progressCallback) {
                        $progressCallback($keysetId, $counter, count($proofs), $unit);
                    }

                    if (empty($proofs)) {
                        $emptyCount++;
                    } else {
                        $emptyCount = 0;
                        $keysetProofs = array_merge($keysetProofs, $proofs);
                    }

                    $counter += $batchSize;
                }

                if (!empty($keysetProofs)) {
                    $unitProofs = array_merge($unitProofs, $keysetProofs);
                    $allProofs = array_merge($allProofs, $keysetProofs);
                    // Set counter to the last found + 1
                    $maxCounter = $counter - ($emptyBatches * $batchSize);
                    $unitCounters[$keysetId] = $maxCounter + count($keysetProofs);
                    $finalCounters[$keysetId] = $unitCounters[$keysetId];
                }
            }

            // Store results for this unit
            if (!empty($unitProofs)) {
                // Check proof states at mint before storing (NUT-07)
                // This ensures we don't store spent proofs as UNSPENT
                $unspentProofs = $unitProofs;
                $spentProofs = [];

                try {
                    // Build Y values for batch check
                    $Ys = [];
                    foreach ($unitProofs as $proof) {
                        $Y = Crypto::hashToCurve($proof->secret);
                        $Ys[] = bin2hex(Secp256k1::compressPoint($Y));
                    }

                    // Check states at mint
                    $response = $this->client->post('checkstate', ['Ys' => $Ys]);

                    // Separate into unspent and spent
                    $unspentProofs = [];
                    $spentProofs = [];
                    foreach ($response['states'] ?? [] as $i => $state) {
                        // Normalize case - mints may return lowercase states
                        $mintState = strtoupper($state['state'] ?? ProofState::UNSPENT);
                        if (isset($unitProofs[$i])) {
                            if ($mintState === ProofState::SPENT) {
                                $spentProofs[] = $unitProofs[$i];
                            } else {
                                $unspentProofs[] = $unitProofs[$i];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // If checkstate fails, store all as UNSPENT (will be synced later)
                    // This maintains backwards compatibility
                }

                $byUnit[$unit] = [
                    'proofs' => $unitProofs,  // Return all found proofs
                    'counters' => $unitCounters,
                    'unspent' => $unspentProofs,
                    'spent' => $spentProofs,
                ];

                // Store proofs and counters for this unit
                if ($this->dbPath !== null) {
                    // Create storage for this unit (may be different from wallet's primary unit)
                    $unitStorage = new WalletStorage(
                        $this->dbPath,
                        $this->mintUrl,
                        $unit,
                        $this->storageIdentity
                    );
                    $fingerprint = $this->storage?->getSeedFingerprint();
                    if ($fingerprint !== null && $unitStorage->getSeedFingerprint() === null) {
                        $unitStorage->bindSeedFingerprint($fingerprint, true, false);
                    }

                    // Store unspent proofs as UNSPENT
                    if (!empty($unspentProofs)) {
                        $unitStorage->storeProofs($unspentProofs);
                    }

                    // Store spent proofs as SPENT (for record-keeping)
                    if (!empty($spentProofs)) {
                        $unitStorage->storeProofs($spentProofs);
                        $spentSecrets = array_map(fn($p) => $p->secret, $spentProofs);
                        $unitStorage->updateProofsState($spentSecrets, ProofState::SPENT);
                    }

                    foreach ($unitCounters as $keysetId => $counterVal) {
                        // Never lower a persisted counter: if this wallet is (or was) in
                        // active use, a restore that computed a smaller value must not cause
                        // secret reuse. See FABLE-CASHU-WALLET-PHP (F10).
                        $existing = $unitStorage->getCounter($keysetId);
                        $unitStorage->setCounter($keysetId, max($existing, $counterVal));
                    }
                }
            }
        }

        // Update internal counters for this wallet's unit
        foreach ($finalCounters as $keysetId => $counter) {
            $this->counters[$keysetId] = $counter;
        }
        if ($this->storage) {
            $this->storage->markSeedReady();
            foreach (array_keys($byUnit) as $unit) {
                $unitStorage = new WalletStorage(
                    $this->dbPath,
                    $this->mintUrl,
                    $unit,
                    $this->storageIdentity
                );
                if ($unitStorage->getSeedFingerprint() !== null) {
                    $unitStorage->markSeedReady();
                }
            }
            $this->seedReadyForSpending = true;
        }

        return [
            'proofs' => $allProofs,
            'counters' => $finalCounters,
            'byUnit' => $byUnit,
        ];
    }

    // ========================================================================
    // PAYMENT REQUESTS (NUT-18)
    // ========================================================================

    /**
     * Create a payment request (NUT-18)
     *
     * @param int $amount Amount requested
     * @param string|null $memo Description of the request
     * @param Transport|null $transport How to receive the payment
     * @return PaymentRequest
     */
    public function createPaymentRequest(
        int $amount,
        ?string $memo = null,
        ?Transport $transport = null
    ): PaymentRequest {
        $pr = new PaymentRequest();
        $pr->id = bin2hex(random_bytes(8));
        $pr->amount = $amount;
        $pr->unit = $this->unit;
        $pr->memo = $memo;
        $pr->mints = [$this->mintUrl];
        $pr->transport = $transport;

        return $pr;
    }

    /**
     * Create a payment request with HTTP transport
     *
     * @param int $amount Amount requested
     * @param string $endpoint URL to POST the token to
     * @param string|null $memo Description
     * @return PaymentRequest
     */
    public function createHttpPaymentRequest(
        int $amount,
        string $endpoint,
        ?string $memo = null
    ): PaymentRequest {
        $transport = new Transport();
        $transport->type = 'post';
        $transport->target = $endpoint;
        $transport->tags = [['n', '0']]; // single use

        return $this->createPaymentRequest($amount, $memo, $transport);
    }

    /**
     * Serialize a payment request for display (QR code, etc.)
     *
     * Format: "creq" prefix + CBOR-encoded data + bech32m encoding
     *
     * @param PaymentRequest $pr Payment request
     * @return string Encoded payment request string
     */
    public function serializePaymentRequest(PaymentRequest $pr): string {
        // Build the CBOR-like structure
        // Note: This is a simplified encoding. Full CBOR library recommended for production.
        $data = [
            'i' => $pr->id,
            'a' => $pr->amount,
            'u' => $pr->unit,
        ];

        if ($pr->memo !== null) {
            $data['d'] = $pr->memo;
        }

        if (!empty($pr->mints)) {
            $data['m'] = $pr->mints;
        }

        if ($pr->transport !== null) {
            $t = ['t' => $pr->transport->type, 'a' => $pr->transport->target];
            if (!empty($pr->transport->tags)) {
                $t['g'] = $pr->transport->tags;
            }
            $data['p'] = [$t];
        }

        // For simplicity, use JSON + base64 (full implementation would use CBOR + bech32m)
        $json = json_encode($data);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return 'creqA' . $encoded;
    }

    /**
     * Parse a payment request string
     *
     * @param string $prString Encoded payment request
     * @return PaymentRequest Parsed payment request
     * @throws CashuException If parsing fails
     */
    public function parsePaymentRequest(string $prString): PaymentRequest {
        if (!str_starts_with($prString, 'creqA')) {
            throw new CashuException('Invalid payment request prefix');
        }

        $encoded = substr($prString, 5);
        $encoded = str_pad(strtr($encoded, '-_', '+/'), strlen($encoded) % 4, '=');
        $json = base64_decode($encoded);

        if ($json === false) {
            throw new CashuException('Failed to decode payment request');
        }

        $data = json_decode($json, true);
        if ($data === null) {
            throw new CashuException('Failed to parse payment request JSON');
        }

        $pr = new PaymentRequest();
        $pr->id = $data['i'] ?? bin2hex(random_bytes(8));
        $pr->amount = $data['a'] ?? 0;
        $pr->unit = $data['u'] ?? 'sat';
        $pr->memo = $data['d'] ?? null;
        $pr->mints = $data['m'] ?? [];

        if (isset($data['p'][0])) {
            $t = $data['p'][0];
            $transport = new Transport();
            $transport->type = $t['t'] ?? '';
            $transport->target = $t['a'] ?? '';
            $transport->tags = $t['g'] ?? [];
            $pr->transport = $transport;
        }

        return $pr;
    }

    /**
     * Pay a payment request by sending tokens
     *
     * @param PaymentRequest $pr Payment request to fulfill
     * @param Proof[]|null $proofs Specific proofs to use, or null to auto-select
     * @return array ['token' => string, 'proofs' => Proof[]] The sent token and proofs
     * @throws CashuException If payment fails
     */
    public function payRequest(PaymentRequest $pr, ?array $proofs = null): array {
        if ($proofs === null) {
            // Auto-select proofs
            $proofs = $this->getStoredProofs();
        }

        // Split to exact amount
        $split = $this->split($proofs, $pr->amount);
        $sendProofs = $split['send'];

        // Create token
        $token = $this->createToken($sendProofs, $pr->memo);

        // If HTTP transport, send the token
        if ($pr->transport !== null && $pr->transport->type === 'post') {
            $this->sendTokenViaHttp($token, $pr->transport->target);
        }

        // Mark proofs as spent in storage
        if ($this->hasStorage()) {
            $secrets = array_map(fn($p) => $p->secret, $sendProofs);
            $this->storage->updateProofsState($secrets, ProofState::SPENT);

            // Store keep proofs
            if (!empty($split['keep'])) {
                $this->storage->storeProofs($split['keep']);
            }
        }

        return ['token' => $token, 'proofs' => $sendProofs];
    }

    /**
     * Send a token via HTTP POST
     *
     * @param string $token The cashu token
     * @param string $url Destination URL
     * @throws CashuException If sending fails
     */
    private function sendTokenViaHttp(string $token, string $url): void {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['token' => $token]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // Note: curl_close() not needed since PHP 8.0 - handle auto-closes

        if ($error) {
            throw new CashuException("Failed to send token: $error");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new CashuException("Failed to send token: HTTP $httpCode");
        }
    }

    /**
     * Pay to a Lightning address using stored proofs
     *
     * Combines LNURL-pay resolution with melt operation. Automatically:
     * - Resolves Lightning address to get invoice
     * - Selects proofs from storage
     * - Executes melt and persists proof states
     *
     * @param string $address Lightning address (user@domain)
     * @param int $amountSats Amount in satoshis to pay
     * @param string|null $comment Optional comment (if supported by receiver)
     * @return array{paid: bool, preimage: ?string, amount: int, fee: int, change: Proof[]}
     * @throws CashuException If payment fails
     */
    public function payToLightningAddress(string $address, int $amountSats, ?string $comment = null): array
    {
        if (!$this->storage) {
            throw new CashuException('Storage is required for payToLightningAddress');
        }

        // Get invoice from Lightning address
        $bolt11 = LightningAddress::getInvoice($address, $amountSats, $comment);

        // Request melt quote
        $meltQuote = $this->requestMeltQuote($bolt11);
        $totalNeeded = $meltQuote->amount + $meltQuote->feeReserve;

        // Get proofs from storage and select
        $proofs = $this->getStoredProofs();
        $balance = self::sumProofs($proofs);

        if ($balance < $totalNeeded) {
            throw new InsufficientBalanceException(
                "Insufficient balance. Have: {$balance} sats, Need: {$totalNeeded} sats"
            );
        }

        $selectedProofs = self::selectProofs($proofs, $totalNeeded);

        // Execute melt - automatically persists proof states
        $result = $this->melt($meltQuote->quote, $selectedProofs);

        if (!$result['paid']) {
            throw new CashuException('Lightning payment failed');
        }

        // Calculate actual fee paid
        $changeAmount = self::sumProofs($result['change'] ?? []);
        $actualFee = $meltQuote->feeReserve - $changeAmount;

        return [
            'paid' => true,
            'preimage' => $result['preimage'],
            'amount' => $meltQuote->amount,
            'fee' => $actualFee,
            'change' => $result['change'],
        ];
    }
}

// ============================================================================
// LIGHTNING ADDRESS (LNURL-PAY)
// ============================================================================

/**
 * Lightning Address (LNURL-pay) resolution and invoice generation
 *
 * Handles the LNURL-pay protocol for Lightning addresses (user@domain format).
 * Resolves addresses to payment endpoints and requests invoices.
 *
 * @see https://github.com/lnurl/luds - LNURL specifications
 */
class LightningAddress
{
    /**
     * Validate a Lightning address format
     *
     * Checks if the string matches the user@domain format expected for
     * Lightning addresses.
     *
     * @param string $address Lightning address to validate
     * @return bool True if format is valid
     */
    public static function isValid(string $address): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/', $address);
    }

    /**
     * Resolve Lightning address to LNURL-pay metadata
     *
     * Fetches the LNURL-pay endpoint and returns payment parameters including
     * min/max amounts, callback URL, and comment support.
     *
     * @param string $address Lightning address (user@domain)
     * @return array|null LNURL metadata or null if resolution fails
     *   - callback: string - URL to request invoice from
     *   - minSendable: int - Minimum amount in millisatoshis
     *   - maxSendable: int - Maximum amount in millisatoshis
     *   - commentAllowed: int - Max comment length (0 = no comments)
     *   - metadata: string - Service metadata
     *   - tag: string - LNURL tag (usually 'payRequest')
     */
    public static function resolve(string $address): ?array
    {
        if (!self::isValid($address)) {
            return null;
        }

        [$username, $domain] = explode('@', $address, 2);

        // Construct LNURL-pay well-known URL
        $url = "https://{$domain}/.well-known/lnurlp/{$username}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        $data = json_decode($response, true);

        // Validate LNURL-pay response
        if (!isset($data['callback']) || !isset($data['minSendable']) || !isset($data['maxSendable'])) {
            return null;
        }

        return [
            'callback' => $data['callback'],
            'minSendable' => (int)$data['minSendable'],
            'maxSendable' => (int)$data['maxSendable'],
            'metadata' => $data['metadata'] ?? '',
            'commentAllowed' => (int)($data['commentAllowed'] ?? 0),
            'tag' => $data['tag'] ?? 'payRequest',
        ];
    }

    /**
     * Get a BOLT11 invoice from a Lightning address
     *
     * Resolves the address and requests an invoice for the specified amount.
     *
     * @param string $address Lightning address (user@domain)
     * @param int $amountSats Amount in satoshis
     * @param string|null $comment Optional payment comment
     * @return string BOLT11 invoice
     * @throws CashuException If resolution or invoice request fails
     */
    public static function getInvoice(string $address, int $amountSats, ?string $comment = null): string
    {
        $metadata = self::resolve($address);
        if ($metadata === null) {
            throw new CashuException("Failed to resolve Lightning address: {$address}");
        }

        $amountMsats = $amountSats * 1000;

        // Check amount limits
        if ($amountMsats < $metadata['minSendable']) {
            throw new CashuException(
                "Amount too low. Minimum: " . ($metadata['minSendable'] / 1000) . " sats"
            );
        }
        if ($amountMsats > $metadata['maxSendable']) {
            throw new CashuException(
                "Amount too high. Maximum: " . ($metadata['maxSendable'] / 1000) . " sats"
            );
        }

        // Build callback URL
        $callbackUrl = $metadata['callback'];
        $separator = (strpos($callbackUrl, '?') !== false) ? '&' : '?';
        $callbackUrl .= $separator . 'amount=' . $amountMsats;

        // Add comment if allowed
        if ($comment && $metadata['commentAllowed'] > 0) {
            $comment = substr($comment, 0, $metadata['commentAllowed']);
            $callbackUrl .= '&comment=' . urlencode($comment);
        }

        // Request invoice
        $ch = curl_init($callbackUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($response)) {
            throw new CashuException("Failed to get invoice from Lightning address");
        }

        $data = json_decode($response, true);

        if (!isset($data['pr'])) {
            $error = $data['reason'] ?? $data['message'] ?? 'Unknown error';
            throw new CashuException("Lightning address error: {$error}");
        }

        return $data['pr'];
    }
}
