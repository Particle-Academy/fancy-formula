<?php

declare(strict_types=1);

namespace FancyFormula;

/**
 * The floating-point policy of SPEC.md section 6, in one place.
 *
 * No native rounding or formatting built-in decides a spreadsheet answer here.
 * PHP's round() changed edge behaviour in 8.4, and fmod() takes the sign of the
 * dividend where MOD takes the divisor's; both are exactly the kind of local
 * idiom that makes three ports disagree. Instead every rounding goes through
 * ONE algorithm:
 *
 *   1. render the double to 15 significant digits with sprintf('%.14e'), which
 *      is correctly rounded (and so agrees with JS toExponential(14) and
 *      Python format(x, '.14e') bit for bit);
 *   2. round that DECIMAL STRING, half away from zero.
 *
 * That is why ROUND(1.005, 2) is 1.01 here (HYPOTHESIS H-05) while native
 * rounding of the double 1.00499999999999989... gives 1.
 */
final class Decimal
{
    public const string HALF = 'half';      // half away from zero (ROUND)

    public const string UP = 'up';          // away from zero (ROUNDUP)

    public const string DOWN = 'down';      // toward zero (ROUNDDOWN, TRUNC)

    /**
     * The 15 significant digits of $x and its decimal exponent, so that
     * |x| ~= 0.D1D2...D15 * 10^(exponent + 1). Zero is [0, '000000000000000', 0].
     *
     * @return array{0: int, 1: string, 2: int} [sign (-1, 0, 1), 15 digits, exponent]
     */
    public static function digits(float $x): array
    {
        if ($x == 0.0) {
            return [0, str_repeat('0', 15), 0];
        }
        $s = sprintf('%.14e', abs($x));
        // "d.dddddddddddddde+XX"
        $mantissa = $s[0].substr($s, 2, 14);
        $exponent = (int) substr($s, strpos($s, 'e') + 1);

        return [$x < 0 ? -1 : 1, $mantissa, $exponent];
    }

    /** $x snapped to 15 significant digits: the value Sheets displays and compares. */
    public static function snap(float $x): float
    {
        if ($x == 0.0 || ! is_finite($x)) {
            return $x == 0.0 ? 0.0 : $x;
        }

        return (float) sprintf('%.14e', $x);
    }

    /**
     * Round to $places decimal places (negative rounds left of the point) on the
     * 15-significant-digit decimal string.
     */
    public static function round(float $x, int $places, string $mode = self::HALF): float
    {
        [$sign, $digits, $exponent] = self::digits($x);
        if ($sign === 0) {
            return 0.0;
        }
        // Digits before the decimal point, in the 15-digit form.
        $intDigits = $exponent + 1;
        $keep = $intDigits + $places;
        if ($keep >= 15) {
            return self::snap($x);
        }

        if ($keep < 0) {
            $kept = '0';
            $rest = str_repeat('0', -$keep).$digits;
        } else {
            $kept = $keep === 0 ? '0' : substr($digits, 0, $keep);
            $rest = substr($digits, $keep);
        }

        $bump = match ($mode) {
            self::HALF => $rest !== '' && $rest[0] >= '5',
            self::UP => trim($rest, '0') !== '',
            default => false,
        };
        if ($bump) {
            $kept = self::increment($kept);
        }

        $value = (float) ($kept.'e'.(-$places));

        return $value == 0.0 ? 0.0 : $sign * $value;
    }

    /** Add one to a string of decimal digits. */
    private static function increment(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }
            $digits[$i] = '0';
            $i--;
        }

        return '1'.$digits;
    }

    /**
     * The 15-significant-digit general form (HYPOTHESIS H-23): what `&` and the
     * text functions produce from a number, and every result's `display`.
     *
     * 1/3 is "0.333333333333333". Decimal notation from 1E-9 to below 1E+15;
     * scientific ("1.23456789012346E+17", "1E-10") outside it.
     */
    public static function general(float $x): string
    {
        [$sign, $digits, $exponent] = self::digits($x);
        if ($sign === 0) {
            return '0';
        }
        $significant = rtrim($digits, '0');
        $minus = $sign < 0 ? '-' : '';

        // Large numbers stay in decimal notation: the double's exact integer
        // digits TRUNCATED to 15 and zero-padded (captured: C-0019,
        // 123456789012345678 is "123456789012345000", not ...346000; C-0020,
        // 1E+20 is "100000000000000000000"). Only small numbers go scientific.
        if ($exponent >= 15) {
            $exact = sprintf('%.0f', abs($x));

            return ($sign < 0 ? '-' : '').str_pad(substr($exact, 0, 15), strlen($exact), '0');
        }
        if ($exponent < -9) {
            $mantissa = $significant[0].(strlen($significant) > 1 ? '.'.substr($significant, 1) : '');
            $e = sprintf('%s%02d', $exponent < 0 ? '-' : '+', abs($exponent));

            return $minus.$mantissa.'E'.$e;
        }

        if ($exponent < 0) {
            return $minus.'0.'.str_repeat('0', -$exponent - 1).$significant;
        }

        $intPart = substr(str_pad($significant, $exponent + 1, '0'), 0, $exponent + 1);
        $fraction = substr($significant, $exponent + 1);

        return $minus.$intPart.($fraction !== '' ? '.'.$fraction : '');
    }

    /**
     * A finite number, or null when the operation produced NaN or infinity
     * (which never surface: SPEC.md 6.2). -0 becomes 0.
     */
    public static function finite(float $x): ?float
    {
        if (! is_finite($x)) {
            return null;
        }

        return $x == 0.0 ? 0.0 : $x;
    }
}
