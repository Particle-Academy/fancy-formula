<?php

declare(strict_types=1);

namespace FancyFormula;

/**
 * Date serials (SPEC.md section 7): days since 1899-12-30, with NO 1900
 * leap-year bug (HYPOTHESIS H-06), by Howard Hinnant's integer civil-calendar
 * algorithms. No date library and no time zone database: the same integer
 * arithmetic runs in every port.
 */
final class Dates
{
    /** days_from_civil(1899, 12, 30): the serial epoch. */
    private const int EPOCH = -25569;

    public static function serial(int $y, int $m, int $d): int
    {
        return self::daysFromCivil($y, $m, $d) - self::EPOCH;
    }

    /** @return array{0: int, 1: int, 2: int} [year, month, day] */
    public static function civil(int $serial): array
    {
        return self::civilFromDays($serial + self::EPOCH);
    }

    /** Days since 1970-01-01 of a proleptic Gregorian date. */
    public static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function civilFromDays(int $z): array
    {
        $z += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp + ($mp < 10 ? 3 : -9);

        return [$y + ($m <= 2 ? 1 : 0), $m, $d];
    }

    /**
     * DATE(y, m, d) with month and day overflow rolling forward or back, as a
     * serial. The 0..1899 year rule and the #NUM! bounds are the caller's.
     */
    public static function fromParts(int $y, int $m, int $d): int
    {
        // Normalise the month into 1..12, carrying whole years.
        $y += intdiv($m - 1 - (($m - 1) % 12 + 12) % 12, 12);
        $m = (($m - 1) % 12 + 12) % 12 + 1;

        return self::serial($y, $m, 1) + $d - 1;
    }

    public static function isLeap(int $y): bool
    {
        return ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
    }

    public static function daysInMonth(int $y, int $m): int
    {
        return match ($m) {
            2 => self::isLeap($y) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    /**
     * A real calendar date `YYYY-MM-DD`, or `YYYY-MM-DDTHH:MM:SS` when $time is
     * true, as a serial; null when it is not one.
     */
    public static function parseIso(string $text, bool $time): ?float
    {
        $pattern = $time
            ? '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z?$/'
            : '/^(\d{4})-(\d{2})-(\d{2})$/';
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > self::daysInMonth($y, $mo)) {
            return null;
        }
        $serial = (float) self::serial($y, $mo, $d);
        if (! $time) {
            return $serial;
        }
        [$h, $mi, $s] = [(int) $m[4], (int) $m[5], (int) $m[6]];
        if ($h > 23 || $mi > 59 || $s > 59) {
            return null;
        }

        return $serial + ($h * 3600 + $mi * 60 + $s) / 86400;
    }

    /** `YYYY-MM-DD` of a serial's day. */
    public static function iso(float $serial): string
    {
        [$y, $m, $d] = self::civil((int) floor($serial));

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** `YYYY-MM-DDTHH:MM:SS` of a serial, to the nearest second. */
    public static function isoDateTime(float $serial): string
    {
        $seconds = (int) Decimal::round($serial * 86400, 0);
        $days = intdiv($seconds - (($seconds % 86400) + 86400) % 86400, 86400);
        $rest = $seconds - $days * 86400;
        [$y, $m, $d] = self::civil($days);

        return sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $y, $m, $d, intdiv($rest, 3600), intdiv($rest % 3600, 60), $rest % 60);
    }
}
