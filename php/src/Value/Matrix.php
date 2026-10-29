<?php

declare(strict_types=1);

namespace FancyFormula\Value;

/**
 * A rectangular block of scalar cells: a range read, an array literal, or a
 * computed array. Rows are 0-based here; everything a person or a trace sees
 * is 1-based (INDEX numbering, counting a header row).
 *
 * `isRef` keeps "this came from a REFERENCE" apart from "this is an array
 * literal or a computed array", because aggregates treat the two differently
 * (HYPOTHESIS H-12: SUM skips text inside a range but coerces a literal).
 *
 * @phpstan-type scalar float|string|bool|Blank|ErrorValue
 */
final class Matrix
{
    /**
     * @param  list<list<float|string|bool|Blank|ErrorValue>>  $rows  rectangular, at least 1x1
     * @param  string|null  $sheet  the sheet of a cell-bound range, for absolute addresses
     * @param  int|null  $row0  1-based sheet row of the top-left cell, when cell-bound
     * @param  int|null  $col0  1-based sheet column of the top-left cell, when cell-bound
     * @param  bool  $header  the first row is a header row (declared on the input)
     */
    public function __construct(
        public readonly array $rows,
        public readonly bool $isRef = false,
        public readonly ?string $sheet = null,
        public readonly ?int $row0 = null,
        public readonly ?int $col0 = null,
        public readonly bool $header = false,
    ) {}

    public function height(): int
    {
        return count($this->rows);
    }

    public function width(): int
    {
        return count($this->rows[0] ?? []);
    }

    /** @return float|string|bool|Blank|ErrorValue */
    public function at(int $r, int $c): mixed
    {
        return $this->rows[$r][$c] ?? Blank::get();
    }

    /** @return float|string|bool|Blank|ErrorValue */
    public function first(): mixed
    {
        return $this->rows[0][0] ?? Blank::get();
    }

    /**
     * Every cell, row-major.
     *
     * @return list<float|string|bool|Blank|ErrorValue>
     */
    public function cells(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            foreach ($row as $cell) {
                $out[] = $cell;
            }
        }

        return $out;
    }

    /** The absolute address of a 0-based cell, or null when not cell-bound. */
    public function address(int $r, int $c): ?string
    {
        if ($this->sheet === null || $this->row0 === null || $this->col0 === null) {
            return null;
        }

        return self::quoteSheet($this->sheet).'!'.self::columnName($this->col0 + $c).($this->row0 + $r);
    }

    /** A sub-block that keeps the reference origin, so a trace can still name cells. */
    public function slice(int $r, int $c, int $height, int $width): self
    {
        $rows = [];
        for ($i = 0; $i < $height; $i++) {
            $row = [];
            for ($j = 0; $j < $width; $j++) {
                $row[] = $this->at($r + $i, $c + $j);
            }
            $rows[] = $row;
        }

        return new self(
            $rows,
            $this->isRef,
            $this->sheet,
            $this->row0 === null ? null : $this->row0 + $r,
            $this->col0 === null ? null : $this->col0 + $c,
            $this->header && $r === 0,
        );
    }

    /** @param float|string|bool|Blank|ErrorValue $value */
    public static function scalar(mixed $value): self
    {
        return new self([[$value]]);
    }

    public static function columnName(int $col): string
    {
        $name = '';
        while ($col > 0) {
            $m = ($col - 1) % 26;
            $name = chr(65 + $m).$name;
            $col = intdiv($col - 1, 26);
        }

        return $name;
    }

    public static function quoteSheet(string $sheet): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sheet) === 1
            ? $sheet
            : "'".str_replace("'", "''", $sheet)."'";
    }
}
