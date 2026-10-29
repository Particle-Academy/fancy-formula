<?php

declare(strict_types=1);

namespace FancyFormula\Eval\Library;

use FancyFormula\Eval\Criteria;
use FancyFormula\Value\Blank;
use FancyFormula\Value\ErrorValue;
use FancyFormula\Value\Matrix;
use FancyFormula\Value\Values;

/**
 * VLOOKUP, HLOOKUP, XLOOKUP, INDEX, MATCH and LOOKUP, each leaving a lookup
 * step for the trace (SPEC.md section 10): the key, the range, the match, the
 * approximate-match `bracket`, what was returned, whether the key column is
 * sorted, and how many probes the search made.
 *
 * Approximate match is a BINARY SEARCH, on sorted and unsorted data alike
 * (HYPOTHESIS H-09), so the answer follows Sheets' search even where a linear
 * scan would land elsewhere; unsorted keys add FORMULA_LOOKUP_UNSORTED. Exact
 * match is a linear scan with the engine's own equality: no equality across
 * kinds, text case-insensitive (H-14), numbers at 15 digits (H-03).
 *
 * Rows and columns in a trace are 1-based within the range and COUNT a header
 * row, as INDEX numbers them.
 */
trait Lookup
{
    /**
     * The last position whose value is <= key (ascending) or >= key
     * (descending), by binary search; -1 when there is none.
     *
     * @param  list<mixed>  $vector
     * @return array{0: int, 1: int} [position, probes]
     */
    private static function binarySearch(array $vector, mixed $key, bool $descending): array
    {
        $lo = 0;
        $hi = count($vector) - 1;
        $probes = 0;
        while ($lo <= $hi) {
            // The UPPER middle: captured L-0001 / L-0005 over unsorted keys
            // 30,10,50,20,40 find 10 for a key of 25, which only this split
            // reaches. Order is the raw double (L-0015), not 15 digits.
            $mid = intdiv($lo + $hi + 1, 2);
            $probes++;
            $v = $vector[$mid];
            $c = $v instanceof ErrorValue ? 1 : Values::compareOrder($v, $key);
            if ($descending ? $c >= 0 : $c <= 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return [$hi, $probes];
    }

    /**
     * First (or, reversed, last) position equal to key; wildcards apply to a
     * text key when $wildcards is set.
     *
     * @param  list<mixed>  $vector
     * @return array{0: int, 1: int} [position or -1, probes]
     */
    private static function linearSearch(array $vector, mixed $key, bool $wildcards, bool $reverse = false): array
    {
        $pattern = $wildcards && is_string($key) ? Criteria::wildcard($key) : null;
        $order = $reverse ? array_reverse(array_keys($vector)) : array_keys($vector);
        $probes = 0;
        foreach ($order as $i) {
            $probes++;
            $v = $vector[$i];
            if ($pattern !== null ? (is_string($v) && preg_match($pattern, $v) === 1) : Values::same($v, $key)) {
                return [$i, $probes];
            }
        }

        return [-1, $probes];
    }

    /**
     * Are the values of the key's kind in order? A header row and cells of
     * other kinds are not part of the question.
     *
     * @param  list<mixed>  $vector
     */
    private static function inOrder(array $vector, mixed $key, bool $header, bool $descending): bool
    {
        $kind = Values::kindRank($key);
        $prev = null;
        foreach ($vector as $i => $v) {
            if (($header && $i === 0) || $v instanceof Blank || $v instanceof ErrorValue || Values::kindRank($v) !== $kind) {
                continue;
            }
            if ($prev !== null) {
                $c = Values::compare($prev, $v);
                if ($descending ? $c < 0 : $c > 0) {
                    return false;
                }
            }
            $prev = $v;
        }

        return true;
    }

    /** @return list<mixed> */
    private static function column(Matrix $m, int $c): array
    {
        return array_map(static fn (array $row) => $row[$c] ?? Blank::get(), $m->rows);
    }

    /** @return list<mixed> */
    private static function row(Matrix $m, int $r): array
    {
        return $m->rows[$r] ?? [];
    }

    /** A 1-D vector of a single row or column; null for a 2-D block. */
    private static function vector(Matrix $m): ?array
    {
        if ($m->width() === 1) {
            return self::column($m, 0);
        }
        if ($m->height() === 1) {
            return self::row($m, 0);
        }

        return null;
    }

    /**
     * @param  list<mixed>  $vector
     * @return array<string, mixed>|null
     */
    private static function bracket(array $vector, int $pos): array
    {
        return [
            'from' => ['row' => $pos + 1, 'key' => Values::toJson($vector[$pos])],
            'to' => isset($vector[$pos + 1]) ? ['row' => $pos + 2, 'key' => Values::toJson($vector[$pos + 1])] : null,
        ];
    }

    /** @return list<mixed> */
    private static function jsonRow(array $cells): array
    {
        return array_map(Values::toJson(...), $cells);
    }

    private function unsorted(bool $sorted, bool $binary): void
    {
        if ($binary && ! $sorted) {
            $this->markUnsorted();
        }
    }

    /** A scalar result: a 1x1 reference becomes its cell. */
    private static function cellOf(mixed $v): mixed
    {
        return $v instanceof Matrix && $v->height() === 1 && $v->width() === 1 ? $v->first() : $v;
    }

    // ------------------------------------------------------- VLOOKUP / HLOOKUP

    /** VLOOKUP / HLOOKUP(key, range, index, [is_sorted = TRUE]). */
    private function vlookup(array $a, array $nodes, bool $vertical): mixed
    {
        $key = $this->scalar($a[0] ?? Blank::get());
        if ($key instanceof ErrorValue) {
            return $key;
        }
        $table = $this->asMatrix($a[1]);
        $index = $this->integer($a[2]);
        if ($index instanceof ErrorValue) {
            return $index;
        }
        $approx = $a[3] ?? null;
        $approx = $approx === null ? true : Values::toBool($this->scalar($approx));
        if ($approx instanceof ErrorValue) {
            return $approx;
        }
        $width = $vertical ? $table->width() : $table->height();
        if ($index < 1) {
            return ErrorValue::value('The index must be at least 1.');
        }
        if ($index > $width) {
            return ErrorValue::ref("The index {$index} is past the range's {$width} ".($vertical ? 'columns' : 'rows').'.');
        }

        $keys = $vertical ? self::column($table, 0) : self::row($table, 0);
        $this->budget()->step(count($keys));
        $wildcard = ! $approx && is_string($key) && Criteria::wildcard($key) !== null;
        [$pos, $probes] = $approx ? self::binarySearch($keys, $key, false) : self::linearSearch($keys, $key, true);
        $sorted = self::inOrder($keys, $key, $table->header, false);
        $this->unsorted($sorted, $approx);

        $lookup = ['key' => Values::toExchange($key), 'range' => $this->label($nodes[1]), 'match' => null];
        $mode = $approx ? 'approximate' : ($wildcard ? 'wildcard' : 'exact');
        if ($pos < 0) {
            $this->detail(['mode' => $mode, 'lookup' => $lookup + ['sorted' => $sorted, 'probes' => $probes]]);

            return ErrorValue::na("No match for {$this->describe($key)}.");
        }

        [$r, $c] = $vertical ? [$pos, $index - 1] : [$index - 1, $pos];
        $value = $table->at($r, $c);
        $lookup['match'] = [
            'row' => $r + 1,
            'column' => $c + 1,
            'cell' => $table->address($r, $c),
            'rowValues' => self::jsonRow($vertical ? self::row($table, $pos) : self::column($table, $pos)),
            'header' => $table->header ? self::jsonRow(self::row($table, 0)) : null,
        ];
        if ($approx) {
            $lookup['bracket'] = self::bracket($keys, $pos);
        }
        $lookup['returned'] = ['row' => $r + 1, 'column' => $c + 1, 'value' => Values::toExchange($value)];
        $this->detail(['mode' => $mode, 'lookup' => $lookup + ['sorted' => $sorted, 'probes' => $probes]]);

        return $value;
    }

    // ---------------------------------------------------------------- XLOOKUP

    /**
     * XLOOKUP(key, lookup, return, [if_not_found], [match_mode = 0], [search_mode = 1]).
     * match_mode 0 exact, -1 exact or next smaller, 1 exact or next larger,
     * 2 wildcard; all LINEAR scans (L-0006 is the control for H-09).
     */
    private function xlookup(array $a, array $nodes): mixed
    {
        $key = $this->scalar($a[0] ?? Blank::get());
        if ($key instanceof ErrorValue) {
            return $key;
        }
        $lookupRange = $this->asMatrix($a[1]);
        $returnRange = $this->asMatrix($a[2]);
        $matchMode = $this->integer($a[4] ?? null, 0.0);
        $searchMode = $this->integer($a[5] ?? null, 1.0);
        foreach ([$matchMode, $searchMode] as $mode) {
            if ($mode instanceof ErrorValue) {
                return $mode;
            }
        }
        if (! in_array($matchMode, [-1, 0, 1, 2], true)) {
            return ErrorValue::value("XLOOKUP has no match mode {$matchMode}.");
        }
        if (! in_array($searchMode, [1, -1], true)) {
            return ErrorValue::value("XLOOKUP search mode {$searchMode} arrives in a later slice.");
        }
        $vector = self::vector($lookupRange);
        if ($vector === null) {
            return ErrorValue::value('XLOOKUP needs a single row or column to search.');
        }
        $vertical = $lookupRange->height() !== 1 || $lookupRange->width() === 1;
        $length = count($vector);
        if (($vertical ? $returnRange->height() : $returnRange->width()) !== $length) {
            return ErrorValue::value('XLOOKUP needs the lookup and return ranges the same length.');
        }
        $this->budget()->step($length);

        $reverse = $searchMode === -1;
        [$pos, $probes] = self::linearSearch($vector, $key, $matchMode === 2, $reverse);
        if ($pos < 0 && ($matchMode === -1 || $matchMode === 1)) {
            [$pos, $probes] = self::nearest($vector, $key, $matchMode, $reverse);
        }
        $sorted = self::inOrder($vector, $key, false, false);

        $mode = $reverse ? 'reverse' : match ($matchMode) {
            0 => 'exact', 2 => 'wildcard', default => 'approximate',
        };
        $lookup = ['key' => Values::toExchange($key), 'range' => $this->label($nodes[1]), 'match' => null];
        if ($pos < 0) {
            $this->detail(['mode' => $mode, 'lookup' => $lookup + ['sorted' => $sorted, 'probes' => $probes]]);
            if (array_key_exists(3, $a) && $a[3] !== null) {
                return $a[3];
            }

            return ErrorValue::na("No match for {$this->describe($key)}.");
        }

        $value = $vertical
            ? self::cellOf($returnRange->slice($pos, 0, 1, $returnRange->width()))
            : self::cellOf($returnRange->slice(0, $pos, $returnRange->height(), 1));
        [$r, $c] = $vertical ? [$pos, 0] : [0, $pos];
        $lookup['match'] = [
            'row' => $r + 1,
            'column' => $c + 1,
            'cell' => $lookupRange->address($r, $c),
            'rowValues' => [Values::toJson($vector[$pos])],
            'header' => null,
        ];
        if ($matchMode === -1 || $matchMode === 1) {
            $lookup['bracket'] = self::bracket($vector, $pos);
        }
        $lookup['returned'] = ['row' => $r + 1, 'column' => $c + 1, 'value' => Values::toExchange($value)];
        $this->detail(['mode' => $mode, 'lookup' => $lookup + ['sorted' => $sorted, 'probes' => $probes]]);

        return $value;
    }

    /**
     * The next smaller (-1) or next larger (1) value of the key's kind, by
     * linear scan; the first such in search order wins a tie.
     *
     * @param  list<mixed>  $vector
     * @return array{0: int, 1: int}
     */
    private static function nearest(array $vector, mixed $key, int $mode, bool $reverse): array
    {
        $best = -1;
        $probes = 0;
        $order = $reverse ? array_reverse(array_keys($vector)) : array_keys($vector);
        foreach ($order as $i) {
            $v = $vector[$i];
            if ($v instanceof Blank || $v instanceof ErrorValue || Values::kindRank($v) !== Values::kindRank($key)) {
                continue;
            }
            $probes++;
            $c = Values::compare($v, $key);
            if ($mode === -1 ? $c >= 0 : $c <= 0) {
                continue;
            }
            if ($best < 0) {
                $best = $i;

                continue;
            }
            $vs = Values::compare($v, $vector[$best]);
            if ($mode === -1 ? $vs > 0 : $vs < 0) {
                $best = $i;
            }
        }

        return [$best, $probes];
    }

    // ------------------------------------------------------------------ INDEX

    /**
     * INDEX(range, [row], [column]). A row or column of 0, or an EMPTY
     * argument, means the whole column or row (HYPOTHESIS H-27:
     * `INDEX(r,,1)`). Past the range is #REF! (HYPOTHESIS H-22, MOIC's case F).
     */
    private function index(array $a, array $nodes): mixed
    {
        $m = $this->asMatrix($a[0]);
        $rowArg = $a[1] ?? null;
        $colArg = $a[2] ?? null;
        if ($m->height() === 1 && $m->width() > 1 && count($a) === 2) {
            // INDEX(row_vector, n) counts along the row.
            [$rowArg, $colArg] = [null, $rowArg];
        }
        $row = $this->integer($rowArg);
        $col = $this->integer($colArg);
        foreach ([$row, $col] as $x) {
            if ($x instanceof ErrorValue) {
                return $x;
            }
        }
        $keyValue = $this->scalar($rowArg ?? $colArg ?? Blank::get());
        $lookup = [
            'key' => Values::toExchange($keyValue),
            'range' => $this->label($nodes[0]),
            'match' => null,
            'sorted' => true,
            'probes' => 0,
        ];
        if ($row < 0 || $col < 0) {
            $this->detail(['mode' => 'exact', 'lookup' => $lookup]);

            return ErrorValue::value('INDEX positions cannot be negative.');
        }
        if ($col === 0 && $m->width() === 1) {
            $col = 1;
        }
        if ($row === 0 && $m->height() === 1) {
            $row = 1;
        }
        if ($row > $m->height() || $col > $m->width()) {
            $this->detail(['mode' => 'exact', 'lookup' => $lookup]);

            // #NUM!, not #REF! (captured, E-0235 and MOIC's case F, A-0029).
            return ErrorValue::num(sprintf(
                'INDEX position %d,%d is outside the %dx%d range.',
                $row, $col, $m->height(), $m->width(),
            ));
        }

        $result = match (true) {
            $row === 0 && $col === 0 => $m,
            $row === 0 => $m->slice(0, $col - 1, $m->height(), 1),
            $col === 0 => $m->slice($row - 1, 0, 1, $m->width()),
            default => $m->slice($row - 1, $col - 1, 1, 1),
        };
        $r = max(1, $row);
        $c = max(1, $col);
        $value = self::cellOf($result);
        $lookup['match'] = [
            'row' => $r,
            'column' => $c,
            'cell' => $m->address($r - 1, $c - 1),
            'rowValues' => self::jsonRow(self::row($m, $r - 1)),
            'header' => $m->header ? self::jsonRow(self::row($m, 0)) : null,
        ];
        $lookup['returned'] = ['row' => $r, 'column' => $c, 'value' => Values::toExchange($value)];
        $this->detail(['mode' => 'exact', 'lookup' => $lookup]);

        // A single cell stays a REFERENCE (SUM(INDEX(...)) skips its text, H-12).
        return $result;
    }

    // ------------------------------------------------------------------ MATCH

    /** MATCH(key, range, [type = 1]): 1 ascending binary, 0 exact, -1 descending binary. */
    private function match(array $a, array $nodes): float|ErrorValue
    {
        $key = $this->scalar($a[0] ?? Blank::get());
        if ($key instanceof ErrorValue) {
            return $key;
        }
        $m = $this->asMatrix($a[1]);
        $type = $this->integer($a[2] ?? null, 1.0);
        if ($type instanceof ErrorValue) {
            return $type;
        }
        $vector = self::vector($m);
        if ($vector === null) {
            return ErrorValue::na('MATCH needs a single row or column.');
        }
        $this->budget()->step(count($vector));
        $type = $type > 0 ? 1 : ($type < 0 ? -1 : 0);
        $binary = $type !== 0;
        [$pos, $probes] = $binary ? self::binarySearch($vector, $key, $type === -1) : self::linearSearch($vector, $key, true);
        $sorted = self::inOrder($vector, $key, $m->header, $type === -1);
        $this->unsorted($sorted, $binary);

        $wildcard = $type === 0 && is_string($key) && Criteria::wildcard($key) !== null;
        $mode = $binary ? 'approximate' : ($wildcard ? 'wildcard' : 'exact');
        $lookup = ['key' => Values::toExchange($key), 'range' => $this->label($nodes[1]), 'match' => null];
        if ($pos < 0) {
            $this->detail(['mode' => $mode, 'lookup' => $lookup + ['sorted' => $sorted, 'probes' => $probes]]);

            return ErrorValue::na("No match for {$this->describe($key)}.");
        }
        $vertical = $m->width() === 1;
        [$r, $c] = $vertical ? [$pos, 0] : [0, $pos];
        $lookup['match'] = [
            'row' => $r + 1,
            'column' => $c + 1,
            'cell' => $m->address($r, $c),
            'rowValues' => [Values::toJson($vector[$pos])],
            'header' => null,
        ];
        if ($binary) {
            $lookup['bracket'] = self::bracket($vector, $pos);
        }
        $lookup['returned'] = ['row' => $r + 1, 'column' => $c + 1, 'value' => Values::toExchange((float) ($pos + 1))];
        $this->detail(['mode' => $mode, 'lookup' => $lookup + ['sorted' => $sorted, 'probes' => $probes]]);

        return (float) ($pos + 1);
    }

    // ----------------------------------------------------------------- LOOKUP

    /** LOOKUP(key, search, [result]): approximate, binary (H-09). */
    private function lookup(array $a, array $nodes): mixed
    {
        $key = $this->scalar($a[0] ?? Blank::get());
        if ($key instanceof ErrorValue) {
            return $key;
        }
        $search = $this->asMatrix($a[1]);
        if (isset($a[2])) {
            $vector = self::vector($search);
            $results = self::vector($this->asMatrix($a[2]));
            if ($vector === null || $results === null) {
                return ErrorValue::na('LOOKUP needs single rows or columns.');
            }
        } elseif ($search->height() >= $search->width()) {
            $vector = self::column($search, 0);
            $results = self::column($search, $search->width() - 1);
        } else {
            $vector = self::row($search, 0);
            $results = self::row($search, $search->height() - 1);
        }
        $this->budget()->step(count($vector));
        [$pos, $probes] = self::binarySearch($vector, $key, false);
        $sorted = self::inOrder($vector, $key, $search->header, false);
        $this->unsorted($sorted, true);
        $lookup = ['key' => Values::toExchange($key), 'range' => $this->label($nodes[1]), 'match' => null];
        if ($pos < 0 || ! array_key_exists($pos, $results)) {
            $this->detail(['mode' => 'approximate', 'lookup' => $lookup + ['sorted' => $sorted, 'probes' => $probes]]);

            return ErrorValue::na("No match for {$this->describe($key)}.");
        }
        $value = $results[$pos];
        $lookup['match'] = ['row' => $pos + 1, 'column' => 1, 'cell' => null, 'rowValues' => [Values::toJson($vector[$pos])], 'header' => null];
        $lookup['bracket'] = self::bracket($vector, $pos);
        $lookup['returned'] = ['row' => $pos + 1, 'column' => 1, 'value' => Values::toExchange($value)];
        $this->detail(['mode' => 'approximate', 'lookup' => $lookup + ['sorted' => $sorted, 'probes' => $probes]]);

        return $value;
    }

    private function describe(mixed $key): string
    {
        return is_string($key) ? '"'.$key.'"' : (string) Values::toText($key);
    }
}
