<?php

declare(strict_types=1);

namespace FancyFormula\Eval;

use FancyFormula\Dates;
use FancyFormula\Value\Blank;
use FancyFormula\Value\ErrorValue;
use FancyFormula\Value\Matrix;

/**
 * One input declaration, converted (SPEC.md section 8.1). Input values are
 * DATA: a text value is never handed to the lexer, here or anywhere.
 */
final class Input
{
    /** Declared type: number, percent, money, text, boolean, date, datetime, range, table. */
    public string $type;

    public ?string $currency = null;

    /** The input was supplied (`value`, `rows` or `table` present). */
    public bool $present = false;

    /** A scalar input's value; Blank when absent. */
    public mixed $value;

    /** A range or table input's cells; null when absent. */
    public ?Matrix $matrix = null;

    public bool $header = false;

    /** @var list<string>|null the column types cells were converted with */
    public ?array $columns = null;

    /** @var array<string, mixed>|null output format for a number read straight from this input */
    public ?array $format = null;

    /**
     * Range cells that did not fit their column's type: [row (1-based, counting a header), column, raw value, type].
     *
     * @var list<array{0: int, 1: int, 2: mixed, 3: string}>
     */
    public array $mismatches = [];

    public bool $formulaMetadataIgnored = false;

    public ?Address $address = null;

    /** @var array<string, mixed> */
    public array $constraints = [];

    /** @var list<string> structural problems: FORMULA_INPUT_INVALID diagnostics */
    public array $problems = [];

    /** @var list<string> FORMULA_PERCENT_SUSPICIOUS messages */
    public array $suspicious = [];

    /** @param array<string, mixed> $decl */
    public function __construct(public readonly string $binding, public readonly array $decl)
    {
        $this->value = Blank::get();
        $this->type = is_string($decl['type'] ?? null) ? $decl['type'] : '';
        $this->currency = is_string($decl['currency'] ?? null) ? $decl['currency'] : null;
        $this->constraints = is_array($decl['constraints'] ?? null) ? $decl['constraints'] : [];
        $this->address = Address::parse($binding);

        match ($this->type) {
            'range' => $this->readRange(),
            'table' => $this->readTable(),
            'number', 'percent', 'money', 'text', 'boolean', 'date', 'datetime' => $this->readScalar(),
            default => $this->problems[] = "{$binding} has no known input type.",
        };
    }

    public function isRange(): bool
    {
        return $this->type === 'range' || $this->type === 'table';
    }

    /** Cells this input occupies, for maxInputCells. */
    public function cellCount(): int
    {
        if ($this->matrix !== null) {
            return $this->matrix->height() * $this->matrix->width();
        }

        return $this->present ? 1 : 0;
    }

    private function readScalar(): void
    {
        $this->format = self::formatFor($this->type, $this->currency);
        if (! array_key_exists('value', $this->decl)) {
            return;
        }
        $this->present = true;
        $raw = $this->decl['value'];
        $converted = self::convert($this->type, $raw);
        if ($converted === null) {
            $this->problems[] = sprintf('%s is declared %s, and %s does not fit that type.', $this->binding, $this->type, json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->value = $converted;
        if ($this->type === 'percent' && (is_int($raw) || is_float($raw)) && $raw > 1) {
            $this->suspicious[] = "{$this->binding} is a percent declared as {$raw}; did you mean ".($raw / 100).' or "'.$raw.'%"?';
        }
        if ($this->address !== null && ! $this->address->isCell()) {
            $this->problems[] = "{$this->binding} is a range address, and a {$this->type} input binds one cell.";
        }
    }

    private function readRange(): void
    {
        $this->header = ($this->decl['header'] ?? false) === true;
        $top = is_array($this->decl['columns'] ?? null) ? array_values($this->decl['columns']) : null;
        $declared = is_array($this->constraints['columns'] ?? null) ? array_values($this->constraints['columns']) : null;
        $this->columns = $top ?? $declared;
        if ($top !== null && $declared !== null && $top !== $declared) {
            $this->problems[] = "{$this->binding} declares `columns` and `constraints.columns` differently; they must be equal.";
        }

        if (! array_key_exists('rows', $this->decl)) {
            return;
        }
        $rows = $this->decl['rows'];
        if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
            $this->problems[] = "{$this->binding} needs `rows`: a non-empty list of rows.";

            return;
        }
        $width = null;
        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row) || $row === []) {
                $this->problems[] = "{$this->binding} has a row that is not a non-empty list.";

                return;
            }
            $width ??= count($row);
            if (count($row) !== $width) {
                $this->problems[] = "{$this->binding} is not rectangular: every row must have {$width} cells.";

                return;
            }
        }
        $this->present = true;
        $this->matrix = $this->cells($rows);
    }

    /**
     * An exchange `table` item: spans flatten into the anchor cell, covered
     * slots are empty, a cell's `formula` metadata is IGNORED (and recorded).
     */
    private function readTable(): void
    {
        $declared = is_array($this->constraints['columns'] ?? null) ? array_values($this->constraints['columns']) : null;
        $this->columns = $declared;
        if (! array_key_exists('table', $this->decl)) {
            return;
        }
        $table = $this->decl['table'];
        $rows = is_array($table) ? ($table['rows'] ?? null) : null;
        if (! is_array($rows) || $rows === []) {
            $this->problems[] = "{$this->binding} is a table input without `rows`.";

            return;
        }
        $headerRows = is_int($table['headerRows'] ?? null) ? $table['headerRows'] : 0;
        $this->header = $headerRows > 0;

        $grid = [];
        $covered = [];
        $width = null;
        foreach (array_values($rows) as $r => $row) {
            $cells = is_array($row) ? ($row['cells'] ?? null) : null;
            if (! is_array($cells) || $cells === []) {
                $this->problems[] = "{$this->binding} has a table row without cells.";

                return;
            }
            $c = 0;
            foreach ($cells as $cell) {
                while (isset($covered[$r][$c])) {
                    $grid[$r][$c] = null;
                    $c++;
                }
                if (! is_array($cell)) {
                    $this->problems[] = "{$this->binding} has a table cell that is not an object.";

                    return;
                }
                if (array_key_exists('formula', $cell)) {
                    $this->formulaMetadataIgnored = true;
                }
                $grid[$r][$c] = $cell;
                $colSpan = is_int($cell['colSpan'] ?? null) ? max(1, $cell['colSpan']) : 1;
                $rowSpan = is_int($cell['rowSpan'] ?? null) ? max(1, $cell['rowSpan']) : 1;
                for ($dr = 0; $dr < $rowSpan; $dr++) {
                    for ($dc = 0; $dc < $colSpan; $dc++) {
                        if ($dr > 0 || $dc > 0) {
                            $covered[$r + $dr][$c + $dc] = true;
                        }
                    }
                }
                $c++;
            }
            while (isset($covered[$r][$c])) {
                $grid[$r][$c] = null;
                $c++;
            }
            $width ??= $c;
            if ($c !== $width) {
                $this->problems[] = "{$this->binding} does not lay out to a rectangle at row ".($r + 1).'.';

                return;
            }
        }
        if ($covered !== [] && max(array_keys($covered)) >= count($rows)) {
            $this->problems[] = "{$this->binding} has a span reaching below its last row.";

            return;
        }
        $plain = [];
        foreach ($grid as $r => $row) {
            ksort($row);
            $plain[] = array_values(array_map(static fn ($cell) => $cell === null ? null : self::exchangeCell($cell), $row));
        }
        $this->present = true;
        $this->matrix = $this->cells($plain);
    }

    /** An exchange cell as the range-cell vocabulary's `{t, v}` (metadata dropped). */
    private static function exchangeCell(array $cell): array
    {
        $out = ['t' => $cell['t'] ?? 'empty'];
        if (array_key_exists('v', $cell)) {
            $out['v'] = $cell['v'];
        }

        return $out;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function cells(array $rows): Matrix
    {
        if ($this->address !== null && $this->type === 'range'
            && ($this->address->height() !== count($rows) || $this->address->width() !== count($rows[0]))) {
            $this->problems[] = sprintf(
                '%s covers %dx%d cells, and its rows are %dx%d.',
                $this->binding, $this->address->height(), $this->address->width(), count($rows), count($rows[0]),
            );
        }

        $out = [];
        foreach ($rows as $r => $row) {
            $converted = [];
            foreach ($row as $c => $raw) {
                if ($r === 0 && $this->header) {
                    $converted[] = self::plainCell($raw) ?? Blank::get();

                    continue;
                }
                $type = $this->columns[$c] ?? 'any';
                $value = $this->convertCell($raw, $type);
                if ($value === null) {
                    $this->mismatches[] = [$r + 1, $c + 1, $raw, $type];
                    $value = self::plainCell($raw) ?? ErrorValue::value('This cell does not fit its column type.');
                }
                $converted[] = $value;
            }
            $out[] = $converted;
        }

        return new Matrix(
            $out,
            true,
            $this->address?->sheet,
            $this->address?->r1,
            $this->address?->c1,
            $this->header,
        );
    }

    /** A cell taken at face value, without a column type: strings are text. */
    private static function plainCell(mixed $raw): mixed
    {
        return match (true) {
            $raw === null => Blank::get(),
            is_bool($raw), is_string($raw) => $raw,
            is_int($raw), is_float($raw) => (float) $raw,
            is_array($raw) && isset($raw['t']) => self::fromExchange($raw),
            is_array($raw) && isset($raw['type']) => self::convert((string) $raw['type'], $raw['value'] ?? null),
            default => null,
        };
    }

    /** A range cell converted to its column type; null when it does not fit. */
    private function convertCell(mixed $raw, string $type): mixed
    {
        if ($raw === null) {
            return Blank::get();
        }
        if (is_array($raw)) {
            $value = isset($raw['t']) ? self::fromExchange($raw) : self::convert((string) ($raw['type'] ?? ''), $raw['value'] ?? null);
            if ($value === null || $value instanceof Blank) {
                return $value;
            }

            return self::fits($value, $raw, $type) ? $value : null;
        }
        if ($type === 'any') {
            return self::plainCell($raw);
        }

        return self::convert($type, $raw);
    }

    /** Does an already-typed cell fit a column type? */
    private static function fits(mixed $value, array $raw, string $type): bool
    {
        $declared = $raw['type'] ?? match ($raw['t'] ?? null) {
            'string' => 'text', 'bool' => 'boolean', 'number' => 'number',
            'date' => 'date', 'datetime' => 'datetime', default => 'any',
        };

        return match ($type) {
            'any' => true,
            'number', 'percent', 'money' => is_float($value) && in_array($declared, ['number', 'percent', 'money'], true),
            'date', 'datetime' => is_float($value),
            'text' => is_string($value),
            'boolean' => is_bool($value),
            default => false,
        };
    }

    /** An exchange cell `{t, v}` as an internal scalar; null when malformed. */
    public static function fromExchange(array $cell): mixed
    {
        $v = $cell['v'] ?? null;

        return match ($cell['t'] ?? null) {
            'empty' => Blank::get(),
            'string' => is_string($v) ? $v : null,
            'number' => is_int($v) || is_float($v) ? (float) $v : null,
            'bool' => is_bool($v) ? $v : null,
            'date' => is_string($v) ? Dates::parseIso($v, false) : null,
            'datetime' => is_string($v) ? Dates::parseIso($v, true) : null,
            'error' => is_string($v) && in_array($v, ErrorValue::CODES, true) ? new ErrorValue($v) : null,
            default => null,
        };
    }

    /**
     * A value of a declared scalar type (SPEC.md 8.1), or null when it does not
     * fit. Text is returned as given: it is data, never parsed.
     */
    public static function convert(string $type, mixed $raw): mixed
    {
        $decimal = static fn (mixed $v): ?float => match (true) {
            is_int($v), is_float($v) => (float) $v,
            is_string($v) && preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $v) === 1 => (float) $v,
            default => null,
        };

        return match ($type) {
            'number', 'money' => $decimal($raw),
            'percent' => is_string($raw) && preg_match('/^(-?[0-9]+(?:\.[0-9]+)?)%$/', $raw, $m) === 1
                ? (float) $m[1] / 100
                : (is_int($raw) || is_float($raw) ? (float) $raw : null),
            'text' => is_string($raw) ? $raw : null,
            'boolean' => is_bool($raw) ? $raw : null,
            'date' => is_string($raw) ? Dates::parseIso($raw, false) : null,
            'datetime' => is_string($raw) ? Dates::parseIso($raw, true) : null,
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    public static function formatFor(string $type, ?string $currency): ?array
    {
        return match ($type) {
            'percent' => ['kind' => 'percent'],
            'money' => $currency !== null ? ['kind' => 'currency', 'currency' => $currency] : null,
            'date' => ['kind' => 'date'],
            'datetime' => ['kind' => 'datetime'],
            default => null,
        };
    }
}
