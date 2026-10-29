<?php

declare(strict_types=1);

namespace FancyFormula\Eval\Library;

use FancyFormula\Dates;
use FancyFormula\Decimal;
use FancyFormula\Value\Blank;
use FancyFormula\Value\ErrorValue;
use FancyFormula\Value\Matrix;
use FancyFormula\Value\Values;

/**
 * Text (CONCATENATE, CONCAT, LEN, TEXT) and date (DATE, YEAR, MONTH, DAY,
 * EDATE, EOMONTH, TODAY, NOW) functions of slice 1.
 */
trait TextDate
{
    /**
     * CONCATENATE joins every argument; a range argument contributes every
     * cell, row-major (Sheets' documented behaviour; E-0301 is the capture row,
     * filed under H-11). The first error wins (H-20).
     */
    private function concatenate(array $args): string|ErrorValue
    {
        $out = '';
        foreach ($args as $arg) {
            $values = $arg instanceof Matrix ? $arg->cells() : [$arg ?? Blank::get()];
            foreach ($values as $v) {
                $t = Values::toText($v);
                if ($t instanceof ErrorValue) {
                    return $t;
                }
                $out .= $t;
            }
            $this->budget()->text(Values::length($out));
        }

        return $out;
    }

    /** LEN in UTF-16 code units (HYPOTHESIS H-15). */
    private function len(array $a): float|ErrorValue
    {
        $t = Values::toText($this->scalar($a[0] ?? Blank::get()));

        return $t instanceof ErrorValue ? $t : (float) Values::length($t);
    }

    /**
     * TEXT(value, format) for NUMBER and PERCENT format codes. Date, time and
     * other codes arrive in a later slice and are a #VALUE! with a detail that
     * says so, never a guess. Rounding is the decimal-string algorithm (H-05).
     * A format code read from an input is interpreted: it is a closed grammar.
     */
    private function text(array $a): string|ErrorValue
    {
        $value = $this->scalar($a[0] ?? Blank::get());
        $format = Values::toText($this->scalar($a[1] ?? Blank::get()));
        if ($value instanceof ErrorValue) {
            return $value;
        }
        if ($format instanceof ErrorValue) {
            return $format;
        }
        $x = Values::toNumber($value);
        if ($x instanceof ErrorValue) {
            return is_string($value) ? $value : $x;
        }
        $out = self::numberFormat($x, $format);
        if ($out === null) {
            return ErrorValue::value("The format \"{$format}\" is not a number or percent format; other TEXT formats arrive in a later slice.");
        }
        $this->budget()->text(Values::length($out));

        return $out;
    }

    /** Render a number with a number/percent format code, or null when unsupported. */
    public static function numberFormat(float $x, string $format): ?string
    {
        if (strcasecmp($format, 'General') === 0 || $format === '') {
            return Decimal::general($x);
        }
        $sections = self::sections($format);
        if ($sections === null) {
            return null;
        }
        $explicitSign = false;
        if ($x < 0 && count($sections) >= 2) {
            $section = $sections[1];
            $x = -$x;
            $explicitSign = true;
        } elseif ($x == 0.0 && count($sections) >= 3) {
            $section = $sections[2];
        } else {
            $section = $sections[0];
        }

        $parsed = self::parseSection($section);
        if ($parsed === null) {
            return null;
        }
        [$prefix, $intPattern, $fracPattern, $suffix, $percents, $grouping] = $parsed;
        if ($intPattern === '' && $fracPattern === '' && $percents === 0) {
            return $prefix.$suffix;
        }

        $value = abs($x) * (100 ** $percents);
        $decimals = strlen($fracPattern);
        $rounded = Decimal::round($value, $decimals, Decimal::HALF);
        $text = $decimals > 0 ? sprintf('%.'.$decimals.'F', $rounded) : sprintf('%.0F', $rounded);
        [$int, $frac] = array_pad(explode('.', $text, 2), 2, '');

        $minInt = substr_count($intPattern, '0');
        $int = ltrim($int, '0');
        if (strlen($int) < $minInt) {
            $int = str_pad($int, $minInt, '0', STR_PAD_LEFT);
        }
        if ($grouping && $int !== '') {
            $int = strrev(implode(',', str_split(strrev($int), 3)));
        }
        $minFrac = strlen(rtrim($fracPattern, '#'));
        while (strlen($frac) > $minFrac && str_ends_with($frac, '0')) {
            $frac = substr($frac, 0, -1);
        }
        $number = $int.($fracPattern !== '' && ($frac !== '' || str_contains($section, '.')) ? '.'.$frac : '');
        $negative = $x < 0 && ! $explicitSign && $rounded != 0.0;

        return ($negative ? '-' : '').$prefix.$number.$suffix;
    }

    /** @return list<string>|null */
    private static function sections(string $format): ?array
    {
        $sections = [''];
        $len = strlen($format);
        for ($i = 0; $i < $len; $i++) {
            $ch = $format[$i];
            if ($ch === '"') {
                $end = strpos($format, '"', $i + 1);
                if ($end === false) {
                    return null;
                }
                $sections[count($sections) - 1] .= substr($format, $i, $end - $i + 1);
                $i = $end;
            } elseif ($ch === '\\' && $i + 1 < $len) {
                $sections[count($sections) - 1] .= substr($format, $i, 2);
                $i++;
            } elseif ($ch === ';') {
                $sections[] = '';
            } else {
                $sections[count($sections) - 1] .= $ch;
            }
        }

        return count($sections) > 4 ? null : $sections;
    }

    /**
     * [prefix, integer placeholders, fraction placeholders, suffix, percent
     * count, grouping]; null for a code outside number and percent formats.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: int, 5: bool}|null
     */
    private static function parseSection(string $section): ?array
    {
        $prefix = '';
        $suffix = '';
        $int = '';
        $frac = '';
        $percents = 0;
        $grouping = false;
        $inFraction = false;
        $seenDigit = false;
        $len = strlen($section);
        for ($i = 0; $i < $len; $i++) {
            $ch = $section[$i];
            $literal = null;
            if ($ch === '"') {
                $end = strpos($section, '"', $i + 1);
                $literal = substr($section, $i + 1, $end - $i - 1);
                $i = $end;
            } elseif ($ch === '\\') {
                $literal = $section[++$i] ?? '';
            } elseif ($ch === '0' || $ch === '#') {
                if ($inFraction) {
                    $frac .= $ch;
                } else {
                    $int .= $ch;
                }
                $seenDigit = true;

                continue;
            } elseif ($ch === '.') {
                if ($inFraction) {
                    return null;
                }
                $inFraction = true;
                $seenDigit = true;

                continue;
            } elseif ($ch === ',') {
                if ($seenDigit && ! $inFraction) {
                    $grouping = true;
                }

                continue;
            } elseif ($ch === '%') {
                $percents++;
                $literal = '%';
            } elseif (ctype_alpha($ch) || $ch === '@' || $ch === '?' || $ch === '*' || $ch === '_' || $ch === '[') {
                // Dates, times, text, padding and conditions: a later slice.
                return null;
            } else {
                $literal = $ch;
            }
            if ($seenDigit) {
                $suffix .= $literal;
            } else {
                $prefix .= $literal;
            }
        }

        return [$prefix, $int, $frac, $suffix, $percents, $grouping];
    }

    // ------------------------------------------------------------------ dates

    /**
     * DATE(y, m, d): arguments truncated; a year in 0..1899 has 1900 added; a
     * year below 0 or from 10000 is #NUM! (HYPOTHESIS H-08); month and day
     * overflow roll over; no 1900 leap-year bug (HYPOTHESIS H-06).
     */
    private function date(array $a): float|ErrorValue
    {
        $parts = [];
        foreach ([0, 1, 2] as $k) {
            $x = $this->integer($a[$k] ?? null);
            if ($x instanceof ErrorValue) {
                return $x;
            }
            $parts[] = $x;
        }
        [$y, $m, $d] = $parts;
        if ($y < 0 || $y >= 10000) {
            return ErrorValue::num("DATE year {$y} is outside 0..9999.");
        }
        if ($y < 1900) {
            $y += 1900;
        }
        $serial = Dates::fromParts($y, $m, $d);
        if ($serial < 0) {
            return ErrorValue::num('DATE is before 1899-12-30.');
        }

        return (float) $serial;
    }

    /** YEAR / MONTH / DAY of a serial. A negative serial is #NUM! (HYPOTHESIS H-07). */
    private function datePart(array $a, int $part): float|ErrorValue
    {
        $x = $this->number($a[0]);
        if ($x instanceof ErrorValue) {
            return $x;
        }
        if ($x < 0) {
            return ErrorValue::num('A date serial cannot be negative.');
        }

        return (float) Dates::civil((int) floor($x))[$part];
    }

    /** EDATE / EOMONTH(start, months): the day clamps to the target month's end. */
    private function edate(array $a, bool $endOfMonth): float|ErrorValue
    {
        $start = $this->number($a[0]);
        if ($start instanceof ErrorValue) {
            return $start;
        }
        $months = $this->integer($a[1]);
        if ($months instanceof ErrorValue) {
            return $months;
        }
        if ($start < 0) {
            return ErrorValue::num('A date serial cannot be negative.');
        }
        [$y, $m, $d] = Dates::civil((int) floor($start));
        $index = $y * 12 + ($m - 1) + $months;
        $y = intdiv($index - (($index % 12) + 12) % 12, 12);
        $m = (($index % 12) + 12) % 12 + 1;
        $last = Dates::daysInMonth($y, $m);
        $serial = Dates::serial($y, $m, $endOfMonth ? $last : min($d, $last));
        if ($serial < 0) {
            return ErrorValue::num('The date is before 1899-12-30.');
        }

        return (float) $serial;
    }

    /** TODAY / NOW from the HOST clock; check() refuses them without one. */
    private function today(bool $withTime): float|ErrorValue
    {
        $serial = $this->clockSerial();
        if ($serial === null) {
            return ErrorValue::value('No host clock.');
        }
        $this->recorder()->clock = $this->clockText();

        return $withTime ? $serial : floor($serial);
    }
}
