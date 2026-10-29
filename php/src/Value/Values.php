<?php

declare(strict_types=1);

namespace FancyFormula\Value;

use FancyFormula\Dates;
use FancyFormula\Decimal;

/**
 * Coercion (SPEC.md section 5) and the ONE comparator (section 6.4).
 *
 * Every cell of the coercion table is a hypothesis until the Sheets capture
 * lands; each function names the hypothesis it implements so a capture
 * mismatch points straight at the line to change.
 *
 * Internal scalars: float (number), string (text), bool, Blank, ErrorValue.
 */
final class Values
{
    /**
     * Number from a scalar. Text is parsed (H-24), bool is 1/0, empty is 0, an
     * error propagates.
     */
    public static function toNumber(mixed $v): float|ErrorValue
    {
        return match (true) {
            is_float($v) => $v,
            is_int($v) => (float) $v,
            is_bool($v) => $v ? 1.0 : 0.0,
            $v instanceof Blank => 0.0,
            $v instanceof ErrorValue => $v,
            // Empty TEXT reads as 0 in arithmetic (captured, C-0013: =1+"" is 1).
            $v === '' => 0.0,
            is_string($v) => self::parseNumber($v) ?? ErrorValue::value("\"{$v}\" is text, and cannot be read as a number."),
            $v instanceof Matrix => self::toNumber($v->first()),
            default => ErrorValue::value(),
        };
    }

    /**
     * Numeric text (HYPOTHESIS H-24): a plain decimal, an exponent, a trailing
     * `%`, thousands separators in groups of three, a leading `$`, an
     * accounting `(5)`, surrounding spaces, and an ISO or US date. Empty text is
     * NOT a number. Text input values are never handed to the lexer: this is a
     * closed number grammar, nothing more.
     */
    public static function parseNumber(string $text): ?float
    {
        $t = trim($text, " \t\n\r");
        if ($t === '') {
            return null;
        }
        $date = self::parseDateText($t);
        if ($date !== null) {
            return $date;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $t, $m) === 1) {
            $negative = true;
            $t = trim($m[1]);
        }
        if (preg_match('/^([+-]?)\$?((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d*)?|\.\d+)(?:[eE]([+-]?\d+))?(%?)$/', $t, $m) !== 1) {
            return null;
        }
        $number = (float) (str_replace(',', '', $m[2]).($m[3] !== '' ? 'e'.$m[3] : ''));
        if ($m[4] === '%') {
            $number /= 100;
        }
        if ($m[1] === '-') {
            $number = -$number;
        }
        if ($negative) {
            $number = -$number;
        }

        return is_finite($number) ? $number + 0.0 : null;
    }

    /** `YYYY-MM-DD` or `M/D/YYYY` (en_US) as a serial; two-digit years wait on H-26. */
    public static function parseDateText(string $t): ?float
    {
        $iso = Dates::parseIso($t, false);
        if ($iso !== null) {
            return $iso;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $t, $m) === 1) {
            $y = (int) $m[3];
            $mo = (int) $m[1];
            $d = (int) $m[2];
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= Dates::daysInMonth($y, $mo)) {
                return (float) Dates::serial($y, $mo, $d);
            }
        }

        return null;
    }

    /** Text from a scalar: numbers in the 15-digit general form (H-23). */
    public static function toText(mixed $v): string|ErrorValue
    {
        return match (true) {
            is_string($v) => $v,
            is_float($v), is_int($v) => Decimal::general((float) $v),
            is_bool($v) => $v ? 'TRUE' : 'FALSE',
            $v instanceof Blank => '',
            $v instanceof ErrorValue => $v,
            $v instanceof Matrix => self::toText($v->first()),
            default => ErrorValue::value(),
        };
    }

    /**
     * Boolean from a scalar. Text is TRUE only for "TRUE"/"FALSE" in any case
     * (HYPOTHESIS H-18); every other text, "0" included, is #VALUE!. That last
     * point is where PHP's own truthiness would quietly answer FALSE.
     */
    public static function toBool(mixed $v): bool|ErrorValue
    {
        if (is_string($v)) {
            $upper = strtoupper($v);
            if ($upper === 'TRUE') {
                return true;
            }
            if ($upper === 'FALSE') {
                return false;
            }

            return ErrorValue::value("\"{$v}\" is text, and cannot be read as TRUE or FALSE.");
        }

        return match (true) {
            is_bool($v) => $v,
            is_float($v), is_int($v) => $v != 0,
            $v instanceof Blank => false,
            $v instanceof ErrorValue => $v,
            $v instanceof Matrix => self::toBool($v->first()),
            default => ErrorValue::value(),
        };
    }

    /**
     * THE number comparator (SPEC.md 6.4): both sides at 15 significant digits.
     * HYPOTHESIS H-03: 0.1+0.2 = 0.3 is TRUE.
     */
    public static function compareNumbers(float $a, float $b): int
    {
        return Decimal::snap($a) <=> Decimal::snap($b);
    }

    /**
     * ORDER for an approximate (binary-search) lookup compares the raw doubles:
     * captured, L-0015, VLOOKUP(99.99999999999999, ...) lands on the row BELOW
     * the 100 breakpoint. Exact equality still uses compareNumbers (F-0027).
     */
    public static function compareOrder(mixed $a, mixed $b): int
    {
        if (is_float($a) && is_float($b)) {
            return $a <=> $b;
        }

        return self::compare($a, $b);
    }

    /**
     * Text order and equality: case-insensitive, including non-ASCII letters
     * (HYPOTHESIS H-14), then code-point order.
     */
    public static function compareText(string $a, string $b): int
    {
        return strcmp(self::fold($a), self::fold($b)) <=> 0;
    }

    public static function fold(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * Order two non-error scalars for `<`, `=`, lookups and criteria.
     * HYPOTHESIS H-13: number < text < bool across kinds. HYPOTHESIS H-19: an
     * empty value is 0 against a number, "" against text, FALSE against a bool.
     */
    public static function compare(mixed $a, mixed $b): int
    {
        if ($a instanceof Blank && $b instanceof Blank) {
            return 0;
        }
        if ($a instanceof Blank) {
            $a = self::blankLike($b);
        }
        if ($b instanceof Blank) {
            $b = self::blankLike($a);
        }
        $ka = self::kindRank($a);
        $kb = self::kindRank($b);
        if ($ka !== $kb) {
            return $ka <=> $kb;
        }

        return match ($ka) {
            0 => self::compareNumbers((float) $a, (float) $b),
            1 => self::compareText($a, $b),
            default => ((int) $a) <=> ((int) $b),
        };
    }

    /**
     * Engine equality with NO equality across kinds: the number 1 never equals
     * the text "1". Used for exact lookups and for constraint membership.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof Blank || $b instanceof Blank || $a instanceof ErrorValue || $b instanceof ErrorValue) {
            return false;
        }
        if (self::kindRank($a) !== self::kindRank($b)) {
            return false;
        }

        return self::compare($a, $b) === 0;
    }

    private static function blankLike(mixed $other): float|string|bool
    {
        return match (true) {
            is_string($other) => '',
            is_bool($other) => false,
            default => 0.0,
        };
    }

    /** 0 number, 1 text, 2 bool. */
    public static function kindRank(mixed $v): int
    {
        return match (true) {
            is_string($v) => 1,
            is_bool($v) => 2,
            default => 0,
        };
    }

    /** UTF-16 code units (HYPOTHESIS H-15): an emoji outside the BMP is 2. */
    public static function length(string $text): int
    {
        $n = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($text[$i]);
            if (($byte & 0xC0) !== 0x80) {
                $n += $byte >= 0xF0 ? 2 : 1;
            }
        }

        return $n;
    }

    /**
     * The exchange cell form of an internal scalar (SPEC.md section 4).
     *
     * @return array<string, mixed>
     */
    public static function toExchange(mixed $v): array
    {
        return match (true) {
            is_float($v), is_int($v) => ['t' => 'number', 'v' => self::jsonNumber((float) $v)],
            is_string($v) => ['t' => 'string', 'v' => $v],
            is_bool($v) => ['t' => 'bool', 'v' => $v],
            $v instanceof ErrorValue => $v->detail === '' ? ['t' => 'error', 'v' => $v->code] : ['t' => 'error', 'v' => $v->code, 'detail' => $v->detail],
            $v instanceof Matrix => ['t' => 'array', 'rows' => array_map(static fn (array $row): array => array_map(self::toExchange(...), $row), $v->rows)],
            default => ['t' => 'empty'],
        };
    }

    /** A plain JSON scalar for a trace's rowValues / bracket keys. */
    public static function toJson(mixed $v): mixed
    {
        return match (true) {
            is_float($v), is_int($v) => self::jsonNumber((float) $v),
            is_string($v), is_bool($v) => $v,
            $v instanceof ErrorValue => $v->code,
            default => null,
        };
    }

    /** -0 is 0; an integral double is emitted as a JSON integer, as JS would. */
    public static function jsonNumber(float $v): int|float
    {
        if ($v == 0.0) {
            return 0;
        }
        if ($v === floor($v) && abs($v) < 9.007199254740992E15) {
            return (int) $v;
        }

        return $v;
    }
}
