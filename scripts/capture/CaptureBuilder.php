<?php

declare(strict_types=1);

namespace FancyFormula\Capture;

use RuntimeException;

/**
 * Builds the capture workbook (written by holy-sheet) and its request.json from
 * the case data in cases-v1.php. Dev tooling, not part of the package: it is
 * kept out of php/src because it needs holy-sheet, which is a dev dependency.
 *
 * The workbook follows plan section 6.2:
 *
 *   Cases!A  case id
 *   Cases!B  the formula, written as a formula with NO cached value
 *   Cases!C  =TYPE(B)
 *   Cases!D  =IFERROR(ERROR.TYPE(B),"")
 *   Cases!E  =LEN(B&"")
 *   Inputs   the cells formulas read, by address
 *   About    what to do with this file
 *
 * Every formula cell is written without a `<v>`, so whatever value the export
 * carries was computed by Google Sheets. scripts/build-formula-cases.py (in
 * fancy-conformance) refuses an export where any formula cell has no value.
 */
final class CaptureBuilder
{
    public const string CASES_SHEET = 'Cases';

    public const string INPUTS_SHEET = 'Inputs';

    public const int FIRST_ROW = 2;

    /** The `Inputs!` references a formula makes: a cell, a rectangle, or an open column range. */
    private const string REF = '/Inputs!(\$?[A-Z]{1,3}\$?[0-9]+(?::\$?[A-Z]{1,3}\$?[0-9]+)?|\$?[A-Z]{1,3}:\$?[A-Z]{1,3})/';

    /** @var array<int, array<int, mixed>> row => col => cell, 1-based */
    private array $grid = [];

    /** @param array{inputs: array<string, list<list<mixed>>>, hypotheses: array<string, string>, cases: list<array<int, mixed>>} $data */
    public function __construct(private readonly array $data)
    {
        foreach ($data['inputs'] as $topLeft => $rows) {
            [$c0, $r0] = self::cell($topLeft);
            foreach ($rows as $dr => $row) {
                foreach ($row as $dc => $value) {
                    $r = $r0 + $dr;
                    $c = $c0 + $dc;
                    if (isset($this->grid[$r]) && array_key_exists($c, $this->grid[$r])) {
                        throw new RuntimeException('Input blocks overlap at '.self::address($c, $r).'.');
                    }
                    $this->grid[$r][$c] = $value;
                }
            }
        }
    }

    /**
     * The cases with ids, rows, functions and engine requests assigned.
     *
     * @return list<array<string, mixed>>
     */
    public function cases(): array
    {
        $counters = [];
        $out = [];
        $row = self::FIRST_ROW;
        foreach ($this->data['cases'] as $case) {
            [$prefix, $suite, $formula, $note, $hypotheses] = $case;
            $primary = $case[5] ?? null;
            $extra = $case[6] ?? [];
            $counters[$prefix] = ($counters[$prefix] ?? 0) + 1;
            $id = sprintf('%s-%04d', $prefix, $counters[$prefix]);

            foreach ($hypotheses as $h) {
                if (! isset($this->data['hypotheses'][$h])) {
                    throw new RuntimeException("{$id} cites unknown hypothesis {$h}.");
                }
            }

            $entry = [
                'id' => $id,
                'row' => $row,
                'suite' => $suite,
                'formula' => $formula,
                'functions' => self::functions($formula),
            ];
            if ($primary !== null) {
                $entry['fn'] = $primary;
            }
            if ($note !== '') {
                $entry['note'] = $note;
            }
            if ($hypotheses !== []) {
                $entry['hypotheses'] = $hypotheses;
            }
            foreach ($extra as $k => $v) {
                $entry[$k] = $v;
            }
            $entry['request'] = [
                'version' => 1,
                'formulas' => ['result' => $formula],
            ];
            $inputs = $this->inputsFor($formula);
            if ($inputs !== []) {
                $entry['request']['inputs'] = $inputs;
            }
            $entry['request']['outputs'] = ['result' => new \stdClass];
            $entry['request']['trace'] = 'none';

            $out[] = $entry;
            $row++;
        }

        return $out;
    }

    /** @return array<string, mixed> the holy-sheet schema for the workbook */
    public function workbook(): array
    {
        $rows = [];
        foreach ($this->cases() as $case) {
            $n = $case['row'];
            $rows[] = [
                ['value' => $case['id']],
                $case['formula'],
                "=TYPE(B{$n})",
                "=IFERROR(ERROR.TYPE(B{$n}),\"\")",
                "=LEN(B{$n}&\"\")",
            ];
        }

        $maxRow = max(array_keys($this->grid));
        $maxCol = 1;
        foreach ($this->grid as $cells) {
            $maxCol = max($maxCol, max(array_keys($cells)));
        }
        $inputRows = [];
        for ($r = 1; $r <= $maxRow; $r++) {
            $line = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $line[] = self::sheetCell($this->grid[$r][$c] ?? null);
            }
            $inputRows[] = $line;
        }

        return ['sheets' => [
            [
                'name' => self::CASES_SHEET,
                'columns' => [
                    ['header' => 'id'],
                    ['header' => 'formula'],
                    ['header' => 'type'],
                    ['header' => 'error_type'],
                    ['header' => 'length'],
                ],
                'rows' => $rows,
            ],
            ['name' => self::INPUTS_SHEET, 'rows' => $inputRows],
            ['name' => 'About', 'rows' => array_map(static fn (string $s): array => [['value' => $s]], [
                'fancy-formula capture workbook v1. Every formula on Cases has NO cached value: Google Sheets computes them all.',
                'Upload to Google Drive, open as a Google Sheet, set File > Settings: Locale United States, Time zone (GMT+00:00) UTC.',
                'Let it recalculate, then File > Download > Microsoft Excel (.xlsx). Do not edit any cell.',
                'Record provenance.json beside the export (date, capturer, account type, locale, time zone, export route, sha256 of both files).',
                'scripts/build-formula-cases.py in fancy-conformance refuses an export it cannot trust.',
            ])],
        ]];
    }

    /**
     * request.json, minus the workbook digest (the caller adds it once the file exists).
     *
     * @return array<string, mixed>
     */
    public function request(string $version): array
    {
        $cases = $this->cases();
        $hypotheses = [];
        foreach ($this->data['hypotheses'] as $h => $claim) {
            $hypotheses[$h] = ['claim' => $claim, 'rows' => []];
        }
        foreach ($cases as $case) {
            foreach ($case['hypotheses'] ?? [] as $h) {
                $hypotheses[$h]['rows'][] = $case['id'];
            }
        }

        return [
            '$comment' => 'Generated by scripts/build-capture-workbook.php from scripts/capture/cases-'.$version.'.php. Do not edit: regenerate.',
            'capture' => $version,
            'workbook' => "capture-{$version}.xlsx",
            'expect' => ['locale' => 'en_US', 'timeZone' => 'UTC'],
            'layout' => [
                'sheet' => self::CASES_SHEET,
                'firstRow' => self::FIRST_ROW,
                'columns' => ['id' => 'A', 'formula' => 'B', 'type' => 'C', 'errorType' => 'D', 'length' => 'E'],
            ],
            'canaries' => [
                ['id' => 'K-0001', 'formula' => '=1+1', 'accept' => [['t' => 'number', 'v' => 2]]],
                ['id' => 'K-0002', 'formula' => '=0.1+0.2', 'accept' => [['t' => 'number', 'v' => 0.30000000000000004], ['t' => 'number', 'v' => 0.3]]],
            ],
            'hypotheses' => $hypotheses,
            'cases' => $cases,
        ];
    }

    /** @return list<string> functions a formula calls, upper-cased, sorted, unique; string literals ignored */
    public static function functions(string $formula): array
    {
        $code = preg_replace('/"(?:[^"]|"")*"/', '""', $formula) ?? '';
        preg_match_all('/([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)*)\s*\(/', $code, $m);
        $names = array_values(array_unique(array_map('strtoupper', $m[1])));
        sort($names);

        return $names;
    }

    /**
     * Cell-bound inputs for every Inputs! reference a formula makes. Overlapping
     * references merge into their bounding rectangle, so no cell is bound twice.
     *
     * @return array<string, array<string, mixed>>
     */
    private function inputsFor(string $formula): array
    {
        $code = preg_replace('/"(?:[^"]|"")*"/', '""', $formula) ?? '';
        preg_match_all(self::REF, $code, $m);
        $rects = [];
        foreach ($m[1] as $ref) {
            $ref = str_replace('$', '', $ref);
            if (preg_match('/^([A-Z]{1,3}):([A-Z]{1,3})$/', $ref, $open)) {
                $c1 = self::column($open[1]);
                $c2 = self::column($open[2]);
                $rows = [];
                foreach ($this->grid as $r => $cells) {
                    foreach ($cells as $c => $v) {
                        if ($c >= $c1 && $c <= $c2 && $v !== null) {
                            $rows[] = $r;
                        }
                    }
                }
                $rects[] = [$c1, $rows === [] ? 1 : min($rows), $c2, $rows === [] ? 1 : max($rows)];

                continue;
            }
            $parts = explode(':', $ref);
            [$c1, $r1] = self::cell($parts[0]);
            [$c2, $r2] = self::cell($parts[1] ?? $parts[0]);
            $rects[] = [min($c1, $c2), min($r1, $r2), max($c1, $c2), max($r1, $r2)];
        }

        // Merge until no two rectangles overlap.
        $merged = true;
        while ($merged) {
            $merged = false;
            foreach ($rects as $i => $a) {
                foreach ($rects as $j => $b) {
                    if ($i < $j && $a[0] <= $b[2] && $b[0] <= $a[2] && $a[1] <= $b[3] && $b[1] <= $a[3]) {
                        $rects[$i] = [min($a[0], $b[0]), min($a[1], $b[1]), max($a[2], $b[2]), max($a[3], $b[3])];
                        unset($rects[$j]);
                        $rects = array_values($rects);
                        $merged = true;

                        continue 3;
                    }
                }
            }
        }

        usort($rects, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $inputs = [];
        foreach ($rects as [$c1, $r1, $c2, $r2]) {
            if ($c1 === $c2 && $r1 === $r2) {
                $value = $this->grid[$r1][$c1] ?? null;
                $inputs[self::INPUTS_SHEET.'!'.self::address($c1, $r1)] = $value === null
                    ? ['type' => 'range', 'rows' => [[null]]]
                    : self::scalarInput($value);

                continue;
            }
            $rows = [];
            for ($r = $r1; $r <= $r2; $r++) {
                $line = [];
                for ($c = $c1; $c <= $c2; $c++) {
                    $line[] = self::rangeCell($this->grid[$r][$c] ?? null);
                }
                $rows[] = $line;
            }
            $inputs[self::INPUTS_SHEET.'!'.self::address($c1, $r1).':'.self::address($c2, $r2)] = ['type' => 'range', 'rows' => $rows];
        }

        return $inputs;
    }

    /** @return array<string, mixed> */
    private static function scalarInput(mixed $value): array
    {
        return match (true) {
            is_array($value) => ['type' => 'text', 'value' => $value['text']],
            is_bool($value) => ['type' => 'boolean', 'value' => $value],
            is_int($value), is_float($value) => ['type' => 'number', 'value' => $value],
            default => throw new RuntimeException('Unsupported input cell '.var_export($value, true)),
        };
    }

    private static function rangeCell(mixed $value): mixed
    {
        return is_array($value) ? $value['text'] : $value;
    }

    private static function sheetCell(mixed $value): mixed
    {
        // Text goes in as an EXPLICIT string cell: a bare "=1+1" would be
        // promoted to a formula by holy-sheet, which is the injection the
        // security rows exist to test.
        return is_array($value) ? ['value' => $value['text']] : $value;
    }

    /** @return array{0: int, 1: int} [column, row], 1-based */
    public static function cell(string $address): array
    {
        if (! preg_match('/^\$?([A-Z]{1,3})\$?([0-9]+)$/', $address, $m)) {
            throw new RuntimeException("Not a cell address: {$address}");
        }

        return [self::column($m[1]), (int) $m[2]];
    }

    public static function column(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n;
    }

    public static function address(int $column, int $row): string
    {
        $letters = '';
        while ($column > 0) {
            $rem = ($column - 1) % 26;
            $letters = chr(65 + $rem).$letters;
            $column = intdiv($column - 1, 26);
        }

        return $letters.$row;
    }
}
