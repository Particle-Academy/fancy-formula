<?php

declare(strict_types=1);

namespace FancyFormula\Eval\Library;

use FancyFormula\Decimal;
use FancyFormula\Eval\Criteria;
use FancyFormula\Eval\Evaluator;
use FancyFormula\Value\Blank;
use FancyFormula\Value\ErrorValue;
use FancyFormula\Value\Matrix;
use FancyFormula\Value\Values;

/**
 * Math and statistical functions of slice 1. Mixed into Evaluator.
 *
 * Aggregates follow HYPOTHESIS H-12: inside a REFERENCE (or an array) they
 * read numbers only, skipping text, booleans and empties; a LITERAL argument
 * is coerced (`SUM(1,"2",TRUE)` is 4). An array literal is treated like a
 * range (C-0042 decides).
 */
trait Math
{
    /**
     * The numbers an aggregate reads, or the first error in argument order (H-20).
     *
     * @param  list<mixed>  $args
     * @return list<float>|ErrorValue
     */
    private function numbers(array $args): array|ErrorValue
    {
        $out = [];
        foreach ($args as $arg) {
            if ($arg instanceof Matrix) {
                $this->budget()->step($arg->height() * $arg->width());
                foreach ($arg->rows as $row) {
                    foreach ($row as $cell) {
                        if ($cell instanceof ErrorValue) {
                            return $cell;
                        }
                        if (is_float($cell)) {
                            $out[] = $cell;
                        }
                    }
                }

                continue;
            }
            $x = Values::toNumber($arg ?? Blank::get());
            if ($x instanceof ErrorValue) {
                return $x;
            }
            $out[] = $x;
        }

        return $out;
    }

    /** @param list<mixed> $args */
    private function aggregate(array $args, string $kind): float|ErrorValue
    {
        $xs = $this->numbers($args);
        if ($xs instanceof ErrorValue) {
            return $xs;
        }

        return self::reduce($xs, $kind);
    }

    /** @param list<float> $xs */
    private static function reduce(array $xs, string $kind): float|ErrorValue
    {
        switch ($kind) {
            case 'sum':
            case 'average':
                $sum = 0.0;
                foreach ($xs as $x) {
                    $sum = Evaluator::arithmetic('+', $sum, $x);
                    if ($sum instanceof ErrorValue) {
                        return $sum;
                    }
                }
                if ($kind === 'sum') {
                    return $sum;
                }

                return $xs === [] ? ErrorValue::div0('AVERAGE of no numbers.') : (Decimal::finite($sum / count($xs)) ?? ErrorValue::num());
            case 'product':
                $p = 1.0;
                foreach ($xs as $x) {
                    $p *= $x;
                }

                return $xs === [] ? 0.0 : (Decimal::finite($p) ?? ErrorValue::num('The result is too large.'));
            case 'min':
                return $xs === [] ? 0.0 : min($xs);
            default:
                return $xs === [] ? 0.0 : max($xs);
        }
    }

    /** COUNT: numbers in references; numbers, numeric text and booleans as literals. Errors are skipped. */
    private function count(array $args): float
    {
        $n = 0;
        foreach ($args as $arg) {
            if ($arg instanceof Matrix) {
                $this->budget()->step($arg->height() * $arg->width());
                foreach ($arg->cells() as $cell) {
                    $n += is_float($cell) ? 1 : 0;
                }

                continue;
            }
            if (is_float($arg) || is_bool($arg) || (is_string($arg) && Values::parseNumber($arg) !== null)) {
                $n++;
            }
        }

        return (float) $n;
    }

    /** COUNTA: every non-empty value; a literal "" counts. */
    private function countA(array $args): float
    {
        $n = 0;
        foreach ($args as $arg) {
            if ($arg instanceof Matrix) {
                $this->budget()->step($arg->height() * $arg->width());
                foreach ($arg->cells() as $cell) {
                    $n += $cell instanceof Blank ? 0 : 1;
                }

                continue;
            }
            $n += $arg === null ? 0 : 1;
        }

        return (float) $n;
    }

    /**
     * SUMPRODUCT: arrays of one shape, multiplied element-wise and summed.
     * Non-numbers count as 0. HYPOTHESIS H-33: arrays of different sizes are
     * #VALUE!, not #N/A padding.
     */
    private function sumProduct(array $args): float|ErrorValue
    {
        $ms = array_map(fn ($a) => $this->asMatrix($a), $args);
        $h = $ms[0]->height();
        $w = $ms[0]->width();
        foreach ($ms as $m) {
            if ($m->height() !== $h || $m->width() !== $w) {
                return ErrorValue::value('SUMPRODUCT needs arrays of the same size.');
            }
        }
        $this->budget()->step($h * $w * count($ms));
        $sum = 0.0;
        for ($r = 0; $r < $h; $r++) {
            for ($c = 0; $c < $w; $c++) {
                $p = 1.0;
                foreach ($ms as $m) {
                    $cell = $m->at($r, $c);
                    if ($cell instanceof ErrorValue) {
                        return $cell;
                    }
                    $p *= is_float($cell) ? $cell : 0.0;
                }
                $sum = Evaluator::arithmetic('+', $sum, $p);
                if ($sum instanceof ErrorValue) {
                    return $sum;
                }
            }
        }

        return $sum;
    }

    // ------------------------------------------------------------- criteria

    /**
     * Rows of $range matching $criteria, 1-based in row-major order.
     *
     * @return list<int>
     */
    private function matching(Matrix $range, Criteria $criteria): array
    {
        $this->budget()->step($range->height() * $range->width());
        $rows = [];
        $i = 0;
        foreach ($range->cells() as $cell) {
            $i++;
            if ($criteria->matches($cell)) {
                $rows[] = $i;
            }
        }

        return $rows;
    }

    /**
     * @param  list<int>  $rows
     * @return array<string, mixed>
     */
    private function criteriaTrace(Criteria $criteria, array $rows): array
    {
        if (count($rows) > 1000) {
            $this->recorder()->truncate();
            $rows = array_slice($rows, 0, 1000);
        }

        return $criteria->trace() + ['matchedRows' => $rows];
    }

    /** The cells of $values at the positions of $shape (Sheets resizes a sum range from its top-left). */
    private static function aligned(Matrix $values, Matrix $shape): Matrix
    {
        return $values->slice(0, 0, $shape->height(), $shape->width());
    }

    /** SUMIF / AVERAGEIF(range, criteria, [values]). */
    private function sumIf(array $a, array $nodes, string $kind): float|ErrorValue
    {
        $range = $this->asMatrix($a[0]);
        $criteria = Criteria::from($this->scalar($a[1] ?? Blank::get()));
        if ($criteria instanceof ErrorValue) {
            return $criteria;
        }
        $values = isset($a[2]) ? self::aligned($this->asMatrix($a[2]), $range) : $range;
        $rows = $this->matching($range, $criteria);
        $this->detail(['criteria' => [$this->criteriaTrace($criteria, $rows)]]);

        return $this->sumRows($values, $rows, $kind);
    }

    /** @param list<int> $rows 1-based row-major positions */
    private function sumRows(Matrix $values, array $rows, string $kind): float|ErrorValue
    {
        $cells = $values->cells();
        $xs = [];
        foreach ($rows as $i) {
            $cell = $cells[$i - 1] ?? Blank::get();
            if ($cell instanceof ErrorValue) {
                return $cell;
            }
            if (is_float($cell)) {
                $xs[] = $cell;
            }
        }

        return self::reduce($xs, $kind);
    }

    /**
     * Pairs of (range, criteria), every range the same shape; the rows that
     * match ALL of them.
     *
     * @param  list<mixed>  $pairs
     * @return array{0: list<int>, 1: Matrix}|ErrorValue
     */
    private function multiCriteria(array $pairs): array|ErrorValue
    {
        $traces = [];
        $matched = null;
        $shape = null;
        for ($k = 0; $k + 1 < count($pairs); $k += 2) {
            $range = $this->asMatrix($pairs[$k]);
            if ($shape !== null && ($range->height() !== $shape->height() || $range->width() !== $shape->width())) {
                return ErrorValue::value('Every criteria range must be the same size.');
            }
            $shape ??= $range;
            $criteria = Criteria::from($this->scalar($pairs[$k + 1] ?? Blank::get()));
            if ($criteria instanceof ErrorValue) {
                return $criteria;
            }
            $rows = $this->matching($range, $criteria);
            $traces[] = $this->criteriaTrace($criteria, $rows);
            $matched = $matched === null ? $rows : array_values(array_intersect($matched, $rows));
        }
        $this->detail(['criteria' => $traces]);

        return [$matched ?? [], $shape];
    }

    private function countIfs(array $a, array $nodes): float|ErrorValue
    {
        $result = $this->multiCriteria($a);
        if ($result instanceof ErrorValue) {
            return $result;
        }

        return (float) count($result[0]);
    }

    private function sumIfs(array $a, array $nodes): float|ErrorValue
    {
        $values = $this->asMatrix($a[0]);
        $result = $this->multiCriteria(array_slice($a, 1));
        if ($result instanceof ErrorValue) {
            return $result;
        }
        [$rows, $shape] = $result;
        if ($values->height() !== $shape->height() || $values->width() !== $shape->width()) {
            return ErrorValue::value('SUMIFS needs its sum range the same size as the criteria ranges.');
        }

        return $this->sumRows($values, $rows, 'sum');
    }

    // --------------------------------------------------------------- rounding

    /** ROUND, ROUNDUP, ROUNDDOWN, TRUNC: the decimal-string algorithm (H-05). */
    private function rounding(array $a, string $mode): float|ErrorValue
    {
        $x = $this->number($a[0]);
        if ($x instanceof ErrorValue) {
            return $x;
        }
        $places = $this->integer($a[1] ?? null);
        if ($places instanceof ErrorValue) {
            return $places;
        }

        return Decimal::round($x, max(-308, min(308, $places)), $mode);
    }

    /** MROUND(n, m): n/m rounded half away from zero, times m; mixed signs are #NUM!. */
    private function mround(array $a): float|ErrorValue
    {
        $n = $this->number($a[0]);
        $m = $n instanceof ErrorValue ? $n : $this->number($a[1]);
        if ($n instanceof ErrorValue || $m instanceof ErrorValue) {
            return $n instanceof ErrorValue ? $n : $m;
        }
        if ($m == 0.0 || $n == 0.0) {
            return 0.0;
        }
        if (($n > 0) !== ($m > 0)) {
            return ErrorValue::num('MROUND needs a number and a multiple of the same sign.');
        }
        $q = Decimal::round($n / $m, 0, Decimal::HALF);

        return Decimal::snap($q * $m);
    }

    /**
     * CEILING / FLOOR(value, [factor = 1]). A negative value with a positive
     * factor rounds toward +infinity / -infinity; a positive value with a
     * negative factor is #NUM! (HYPOTHESIS H-30). The quotient is snapped to 15
     * digits first, so FLOOR(1.58, 0.1) is 1.5.
     */
    private function ceilingFloor(array $a, bool $ceiling): float|ErrorValue
    {
        $x = $this->number($a[0]);
        $f = $x instanceof ErrorValue ? $x : $this->number($a[1] ?? null, 1.0);
        if ($x instanceof ErrorValue || $f instanceof ErrorValue) {
            return $x instanceof ErrorValue ? $x : $f;
        }
        if ($f == 0.0 || $x == 0.0) {
            return 0.0;
        }
        if ($x > 0 && $f < 0) {
            return ErrorValue::num(($ceiling ? 'CEILING' : 'FLOOR').' of a positive number needs a positive factor.');
        }
        $q = Decimal::snap($x / $f);
        $q = $ceiling ? ceil($q) : floor($q);

        return Decimal::snap($q * $f);
    }

    /** INT: down to the integer below, on the 15-digit value. */
    private function int(array $a): float|ErrorValue
    {
        $x = $this->number($a[0]);
        if ($x instanceof ErrorValue) {
            return $x;
        }

        return Decimal::round($x, 0, $x < 0 ? Decimal::UP : Decimal::DOWN);
    }

    private function unaryMath(array $a, callable $f): float|ErrorValue
    {
        $x = $this->number($a[0]);
        if ($x instanceof ErrorValue) {
            return $x;
        }
        $r = $f($x);
        if ($r instanceof ErrorValue) {
            return $r;
        }

        return Decimal::finite($r) ?? ErrorValue::num();
    }

    private function binaryMath(array $a, callable $f): float|ErrorValue
    {
        $x = $this->number($a[0]);
        if ($x instanceof ErrorValue) {
            return $x;
        }
        $y = $this->number($a[1]);
        if ($y instanceof ErrorValue) {
            return $y;
        }

        return $f($x, $y);
    }

    /**
     * MOD takes the sign of the DIVISOR (HYPOTHESIS H-29): a - b * floor(a / b).
     * Never fmod(), which takes the dividend's sign and gives MOD(1, 0.1) as
     * 0.0999...
     */
    private function mod(array $a): float|ErrorValue
    {
        return $this->binaryMath($a, static function (float $x, float $y): float|ErrorValue {
            if ($y == 0.0) {
                return ErrorValue::div0('MOD by zero.');
            }

            // The quotient is taken at 15 digits and a remainder that is zero
            // at 15 digits is 0 (captured, F-0015: MOD(0.3, 0.1) is 0).
            $q = floor(Decimal::snap($x / $y));
            $product = $y * $q;
            if (Values::compareNumbers($x, $product) === 0) {
                return 0.0;
            }

            return Decimal::finite($x - $product) ?? ErrorValue::num();
        });
    }
}
