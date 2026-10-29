<?php

declare(strict_types=1);

namespace FancyFormula\Eval;

use FancyFormula\Formula;
use FancyFormula\Value\Blank;
use FancyFormula\Value\Matrix;
use FancyFormula\Value\Values;

/**
 * Everything a request binds: inputs (by name or by cell), formulas (by name
 * or by cell), and `names` aliasing addresses. A cell that nothing binds reads
 * as empty.
 *
 * Names resolve case-insensitively, as Sheets named ranges do, with an exact
 * match preferred; sheet names compare case-insensitively too.
 */
final class Workbook
{
    /** A new Google Sheet's grid. */
    public const int GRID_ROWS = 1000;

    public const int GRID_COLUMNS = 26;

    /** @var array<string, Input> */
    public array $inputs = [];

    /** @var array<string, Formula> */
    public array $formulas = [];

    /** @var array<string, Address|null> formula binding => its cell, when cell-bound */
    public array $formulaAt = [];

    /** @var array<string, Address> */
    public array $names = [];

    /** @var array<string, array<int, array<int, mixed>>> sheetKey => row => col => scalar */
    private array $inputCells = [];

    /** @var array<string, array<int, array<int, string>>> sheetKey => row => col => formula binding */
    private array $formulaCells = [];

    /** @var array<string, string> sheetKey => sheet name as first written */
    private array $sheetNames = [];

    /** @var array<string, array{0: string, 1: string}> exact name => [kind, binding] */
    private array $exact = [];

    /** @var array<string, list<array{0: string, 1: string}>> upper name => candidates */
    private array $folded = [];

    /** @var array<string, mixed> formula binding => computed value */
    public array $results = [];

    /** @var list<array{0: string, 1: string}> [target, message] FORMULA_INPUT_INVALID */
    public array $problems = [];

    /**
     * @param  array<string, Input>  $inputs
     * @param  array<string, Formula>  $formulas
     * @param  array<string, string>  $names
     */
    public function __construct(array $inputs, array $formulas, array $names)
    {
        $this->inputs = $inputs;
        $this->formulas = $formulas;

        foreach ($inputs as $binding => $input) {
            $binding = (string) $binding;
            if ($input->address === null) {
                $this->index($binding, 'input', $binding);

                continue;
            }
            $this->place($binding, $input);
        }
        foreach ($formulas as $binding => $formula) {
            $binding = (string) $binding;
            $address = Address::parse($binding);
            $this->formulaAt[$binding] = $address;
            if ($address === null) {
                if (isset($this->exact[$binding])) {
                    $this->problems[] = [$binding, "{$binding} is bound twice: as an input and as a formula."];
                }
                $this->index($binding, 'formula', $binding);

                continue;
            }
            if (! $address->isCell()) {
                $this->problems[] = [$binding, "{$binding} is a range; in P1 a formula binds to a name or to one cell."];

                continue;
            }
            $key = $address->sheetKey();
            $this->sheetNames[$key] ??= $address->sheet;
            if (array_key_exists($address->r1, $this->inputCells[$key] ?? []) && array_key_exists($address->c1, $this->inputCells[$key][$address->r1])) {
                $this->problems[] = [$binding, "{$binding} is bound by an input and by a formula."];
            }
            $this->formulaCells[$key][$address->r1][$address->c1] = $binding;
        }
        foreach ($names as $name => $target) {
            $name = (string) $name;
            $address = is_string($target) ? Address::parse($target) : null;
            if ($address === null) {
                $this->problems[] = [$name, "The name {$name} must alias a sheet-qualified cell or range."];

                continue;
            }
            if (isset($this->exact[$name])) {
                $this->problems[] = [$name, "{$name} is declared as a name and as an input or formula."];

                continue;
            }
            $this->names[$name] = $address;
            $this->index($name, 'name', $name);
        }
    }

    private function index(string $name, string $kind, string $binding): void
    {
        $this->exact[$name] = [$kind, $binding];
        $this->folded[strtoupper($name)][] = [$kind, $binding];
    }

    /** Write a cell-bound input into the grid; overlapping inputs must agree. */
    private function place(string $binding, Input $input): void
    {
        $address = $input->address;
        $key = $address->sheetKey();
        $this->sheetNames[$key] ??= $address->sheet;
        $cells = [];
        if ($input->matrix !== null) {
            foreach ($input->matrix->rows as $r => $row) {
                foreach ($row as $c => $value) {
                    $cells[] = [$address->r1 + $r, $address->c1 + $c, $value];
                }
            }
        } elseif ($input->present) {
            $cells[] = [$address->r1, $address->c1, $input->value];
        }
        foreach ($cells as [$r, $c, $value]) {
            if (isset($this->inputCells[$key][$r]) && array_key_exists($c, $this->inputCells[$key][$r])) {
                $prior = $this->inputCells[$key][$r][$c];
                if (! self::identical($prior, $value)) {
                    $this->problems[] = [$binding, sprintf('%s overlaps another input at %s and disagrees with it.', $binding, Matrix::quoteSheet($address->sheet).'!'.Matrix::columnName($c).$r)];
                }

                continue;
            }
            $this->inputCells[$key][$r][$c] = $value;
        }
    }

    private static function identical(mixed $a, mixed $b): bool
    {
        if ($a instanceof Blank || $b instanceof Blank) {
            return $a instanceof Blank && $b instanceof Blank;
        }

        return Values::kindRank($a) === Values::kindRank($b) && $a === $b;
    }

    /**
     * What a bare name means: [kind, binding] with kind input, formula or name,
     * or null for an unknown name.
     *
     * @return array{0: string, 1: string}|null
     */
    public function resolve(string $name): ?array
    {
        if (isset($this->exact[$name])) {
            return $this->exact[$name];
        }
        $candidates = $this->folded[strtoupper($name)] ?? [];

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Clamp a reference to concrete corners. An open column or row range
     * (`B:B`, `1:1`) spans the GRID, as in Sheets (captured, L-0016:
     * ROWS(B:B) is 1000): a new sheet's 1000 rows by 26 columns, grown to the
     * populated extent of the bound cells when they reach further.
     *
     * @param  array<string, mixed>  $ref  a ref AST node
     * @return array{0: string, 1: int, 2: int, 3: int, 4: int} [sheetKey, r1, c1, r2, c2]
     */
    public function corners(array $ref, string $sheet): array
    {
        $key = strtoupper($sheet);
        [$r1, $c1, $r2, $c2] = [$ref['r1'], $ref['c1'], $ref['r2'], $ref['c2']];
        if ($r1 === null) {
            $max = self::GRID_ROWS;
            foreach ([$this->inputCells[$key] ?? [], $this->formulaCells[$key] ?? []] as $grid) {
                foreach ($grid as $row => $cols) {
                    foreach (array_keys($cols) as $col) {
                        if ($col >= $c1 && $col <= $c2) {
                            $max = max($max, $row);
                        }
                    }
                }
            }
            [$r1, $r2] = [1, $max];
        }
        if ($c1 === null) {
            $max = self::GRID_COLUMNS;
            foreach ([$this->inputCells[$key] ?? [], $this->formulaCells[$key] ?? []] as $grid) {
                foreach ($grid as $row => $cols) {
                    if ($row >= $r1 && $row <= $r2) {
                        $max = max($max, ...array_keys($cols));
                    }
                }
            }
            [$c1, $c2] = [1, $max];
        }

        return [$key, $r1, $c1, $r2, $c2];
    }

    /**
     * Formula bindings whose cell lies inside the corners.
     *
     * @return list<string>
     */
    public function formulasIn(string $sheetKey, int $r1, int $c1, int $r2, int $c2): array
    {
        $out = [];
        foreach ($this->formulaCells[$sheetKey] ?? [] as $row => $cols) {
            if ($row < $r1 || $row > $r2) {
                continue;
            }
            foreach ($cols as $col => $binding) {
                if ($col >= $c1 && $col <= $c2) {
                    $out[] = $binding;
                }
            }
        }

        return $out;
    }

    /**
     * Read cells into a reference Matrix. Formula cells read their computed
     * value, so they must have been evaluated first (topological order).
     */
    public function read(string $sheetKey, int $r1, int $c1, int $r2, int $c2): Matrix
    {
        $rows = [];
        for ($r = $r1; $r <= $r2; $r++) {
            $row = [];
            for ($c = $c1; $c <= $c2; $c++) {
                if (isset($this->formulaCells[$sheetKey][$r][$c])) {
                    $value = $this->results[$this->formulaCells[$sheetKey][$r][$c]] ?? Blank::get();
                    $row[] = $value instanceof Matrix ? $value->first() : $value;
                } elseif (isset($this->inputCells[$sheetKey][$r]) && array_key_exists($c, $this->inputCells[$sheetKey][$r])) {
                    $row[] = $this->inputCells[$sheetKey][$r][$c];
                } else {
                    $row[] = Blank::get();
                }
            }
            $rows[] = $row;
        }

        return new Matrix($rows, true, $this->sheetNames[$sheetKey] ?? $sheetKey, $r1, $c1);
    }

    /** The input whose cells exactly cover these corners, for header and table metadata. */
    public function inputAt(string $sheetKey, int $r1, int $c1, int $r2, int $c2): ?Input
    {
        foreach ($this->inputs as $input) {
            $a = $input->address;
            if ($a !== null && $a->sheetKey() === $sheetKey && $a->r1 === $r1 && $a->c1 === $c1 && $a->r2 === $r2 && $a->c2 === $c2) {
                return $input;
            }
        }

        return null;
    }

    public function totalInputCells(): int
    {
        $n = 0;
        foreach ($this->inputs as $input) {
            $n += $input->cellCount();
        }

        return $n;
    }
}
