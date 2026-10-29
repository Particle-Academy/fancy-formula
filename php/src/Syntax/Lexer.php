<?php

declare(strict_types=1);

namespace FancyFormula\Syntax;

use FancyFormula\FormulaError;

/**
 * Tokens of the US-locale formula grammar (SPEC.md section 3.1 and 3.2).
 *
 * A reference is ONE token: `Sheet!A1`, `'My Sheet'!A1:B2`, `A:A`, `1:1`. That
 * keeps `:` out of the general operator table, so nothing but a reference can
 * be built with it, and a DDE form such as `=cmd|' /C calc'!A0` dies at `|`.
 *
 * Token: ['type' => number|string|error|ref|ident|op|eof, 'value' => mixed, 'at' => byte offset, 'end' => byte offset]
 */
final class Lexer
{
    /** Longest first. */
    private const array OPS = ['<>', '<=', '>=', '+', '-', '*', '/', '^', '&', '%', '=', '<', '>', '(', ')', ',', ';', '{', '}'];

    /** #NULL! and #ERROR! are real Sheets literals: captured, P-0030 / P-0031 (H-16). */
    public const array ERROR_LITERALS = ['#N/A', '#DIV/0!', '#VALUE!', '#REF!', '#NAME?', '#NUM!', '#NULL!', '#ERROR!'];

    private const string CELL = '\$?[A-Za-z]{1,3}\$?[0-9]+';

    private const string COL = '\$?[A-Za-z]{1,3}';

    private const string ROW = '\$?[0-9]+';

    /** @return list<array<string, mixed>> */
    public static function tokenize(string $source): array
    {
        $tokens = [];
        $len = strlen($source);
        $i = 0;
        if ($len > 0 && $source[0] === '=') {
            $i = 1;
        }

        while ($i < $len) {
            $c = $source[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;

                continue;
            }

            if ($c === '"') {
                $tokens[] = self::string($source, $i);
                $i = $tokens[array_key_last($tokens)]['end'];

                continue;
            }

            if ($c === '#') {
                $tokens[] = self::error($source, $i);
                $i = $tokens[array_key_last($tokens)]['end'];

                continue;
            }

            $ref = self::reference($source, $i);
            if ($ref !== null) {
                $tokens[] = $ref;
                $i = $ref['end'];

                continue;
            }

            if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($source[$i + 1]))) {
                if (preg_match('/\G(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?/', $source, $m, 0, $i) !== 1) {
                    throw new FormulaError('Malformed number', $source, $i);
                }
                $tokens[] = ['type' => 'number', 'value' => $m[0], 'at' => $i, 'end' => $i + strlen($m[0])];
                $i += strlen($m[0]);

                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_.]*/', $source, $m, 0, $i);
                $tokens[] = ['type' => 'ident', 'value' => $m[0], 'at' => $i, 'end' => $i + strlen($m[0])];
                $i += strlen($m[0]);

                continue;
            }

            foreach (self::OPS as $op) {
                if (substr_compare($source, $op, $i, strlen($op)) === 0) {
                    $tokens[] = ['type' => 'op', 'value' => $op, 'at' => $i, 'end' => $i + strlen($op)];
                    $i += strlen($op);

                    continue 2;
                }
            }

            $shown = mb_substr(substr($source, $i), 0, 1, 'UTF-8');
            throw new FormulaError("Unexpected '{$shown}'", $source, $i);
        }

        $tokens[] = ['type' => 'eof', 'value' => null, 'at' => $len, 'end' => $len];

        return $tokens;
    }

    /** @return array<string, mixed> */
    private static function string(string $source, int $at): array
    {
        $len = strlen($source);
        $value = '';
        $i = $at + 1;
        while ($i < $len) {
            if ($source[$i] === '"') {
                if ($i + 1 < $len && $source[$i + 1] === '"') {
                    $value .= '"';
                    $i += 2;

                    continue;
                }

                return ['type' => 'string', 'value' => $value, 'at' => $at, 'end' => $i + 1];
            }
            $value .= $source[$i];
            $i++;
        }

        throw new FormulaError('Unterminated string', $source, $at);
    }

    /** @return array<string, mixed> */
    private static function error(string $source, int $at): array
    {
        $rest = strtoupper(substr($source, $at, 8));
        foreach (self::ERROR_LITERALS as $code) {
            if (str_starts_with($rest, $code)) {
                return ['type' => 'error', 'value' => $code, 'at' => $at, 'end' => $at + strlen($code)];
            }
        }

        throw new FormulaError("Unexpected '#'", $source, $at);
    }

    /**
     * A reference at $at, with or without a sheet, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private static function reference(string $source, int $at): ?array
    {
        $sheet = null;
        $i = $at;
        if ($source[$i] === "'") {
            if (preg_match("/\\G'((?:[^']|'')+)'!/", $source, $m, 0, $i) !== 1) {
                throw new FormulaError('Malformed quoted sheet name', $source, $at);
            }
            $sheet = str_replace("''", "'", $m[1]);
            $i += strlen($m[0]);
        } elseif (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)!/', $source, $m, 0, $i) === 1) {
            $sheet = $m[1];
            $i += strlen($m[0]);
        }

        // The end must not run on into an identifier or a call: `LOG10(` is a
        // function, and `A1B` is not a cell.
        $tail = '(?![A-Za-z0-9_.(!])';
        $forms = [
            'range' => '/\G('.self::CELL.'):('.self::CELL.')'.$tail.'/',
            'cols' => '/\G('.self::COL.'):('.self::COL.')'.$tail.'/',
            'rows' => '/\G('.self::ROW.'):('.self::ROW.')'.$tail.'/',
            'cell' => '/\G('.self::CELL.')'.$tail.'/',
        ];
        foreach ($forms as $kind => $pattern) {
            if (preg_match($pattern, $source, $m, 0, $i) !== 1) {
                continue;
            }
            $ref = self::shape($kind, $m[1], $m[2] ?? null);
            if ($ref === null) {
                throw new FormulaError('Malformed reference', $source, $at);
            }
            $ref['sheet'] = $sheet;

            return ['type' => 'ref', 'value' => $ref, 'at' => $at, 'end' => $i + strlen($m[0])];
        }

        if ($sheet !== null) {
            throw new FormulaError('A sheet name must be followed by a cell or range', $source, $i);
        }

        return null;
    }

    /**
     * Coordinates of a reference; null for row 0. Rows and columns are 1-based;
     * an open side is null.
     *
     * @return array<string, mixed>|null
     */
    private static function shape(string $kind, string $a, ?string $b): ?array
    {
        $text = strtoupper($a).($b !== null ? ':'.strtoupper($b) : '');
        $parse = static function (string $part): array {
            preg_match('/^\$?([A-Za-z]*)\$?([0-9]*)$/', $part, $m);
            $col = 0;
            foreach (str_split(strtoupper($m[1])) as $ch) {
                if ($ch !== '') {
                    $col = $col * 26 + (ord($ch) - 64);
                }
            }

            return [$m[2] === '' ? null : (int) $m[2], $col === 0 ? null : $col];
        };
        [$r1, $c1] = $parse($a);
        [$r2, $c2] = $b === null ? [$r1, $c1] : $parse($b);
        if ($r1 === 0 || $r2 === 0) {
            return null;
        }
        if ($kind === 'range' || $kind === 'cols' || $kind === 'rows') {
            // Order the corners: B5:A1 is A1:B5.
            if ($r1 !== null && $r2 !== null && $r1 > $r2) {
                [$r1, $r2] = [$r2, $r1];
            }
            if ($c1 !== null && $c2 !== null && $c1 > $c2) {
                [$c1, $c2] = [$c2, $c1];
            }
        }

        return ['kind' => $kind, 'text' => $text, 'r1' => $r1, 'c1' => $c1, 'r2' => $r2, 'c2' => $c2];
    }
}
