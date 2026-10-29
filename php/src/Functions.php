<?php

declare(strict_types=1);

namespace FancyFormula;

/**
 * The CLOSED function registry (SPEC.md section 11.1), keyed by upper-case
 * name. Dispatch is a `match` over these literal names in the evaluator: a
 * user string is compared against them and never selects a host method.
 *
 * Slice 1 of P1 is the pricing set below. Every other P1 function is in
 * LATER and reports FORMULA_UNKNOWN_FUNCTION at check(), with a message that
 * says it arrives in a later slice, rather than crashing or guessing.
 */
final class Functions
{
    /**
     * name => [category, minArgs, maxArgs (null = variadic), lazy, parity]
     * `parity` is 'odd' or 'even' for argument lists that come in pairs.
     *
     * @var array<string, array{0: string, 1: int, 2: int|null, 3: bool, 4: string|null}>
     */
    public const array REGISTRY = [
        // Math
        'SUM' => ['math', 1, null, false, null],
        'SUMIF' => ['math', 2, 3, false, null],
        'SUMIFS' => ['math', 3, null, false, 'odd'],
        'SUMPRODUCT' => ['math', 1, null, false, null],
        'PRODUCT' => ['math', 1, null, false, null],
        'ROUND' => ['math', 1, 2, false, null],
        'ROUNDUP' => ['math', 1, 2, false, null],
        'ROUNDDOWN' => ['math', 1, 2, false, null],
        'MROUND' => ['math', 2, 2, false, null],
        'CEILING' => ['math', 1, 2, false, null],
        'FLOOR' => ['math', 1, 2, false, null],
        'INT' => ['math', 1, 1, false, null],
        'TRUNC' => ['math', 1, 2, false, null],
        'ABS' => ['math', 1, 1, false, null],
        'MOD' => ['math', 2, 2, false, null],
        'POWER' => ['math', 2, 2, false, null],
        'SQRT' => ['math', 1, 1, false, null],
        // Statistical
        'MIN' => ['statistical', 1, null, false, null],
        'MAX' => ['statistical', 1, null, false, null],
        'AVERAGE' => ['statistical', 1, null, false, null],
        'COUNT' => ['statistical', 1, null, false, null],
        'COUNTA' => ['statistical', 1, null, false, null],
        'COUNTIF' => ['statistical', 2, 2, false, null],
        'COUNTIFS' => ['statistical', 2, null, false, 'even'],
        'AVERAGEIF' => ['statistical', 2, 3, false, null],
        // Logical (LET is a syntax node, listed for the inventory)
        'IF' => ['logical', 2, 3, true, null],
        'IFS' => ['logical', 2, null, true, 'even'],
        'SWITCH' => ['logical', 3, null, true, null],
        'IFERROR' => ['logical', 1, 2, true, null],
        'IFNA' => ['logical', 2, 2, true, null],
        'AND' => ['logical', 1, null, false, null],
        'OR' => ['logical', 1, null, false, null],
        'NOT' => ['logical', 1, 1, false, null],
        'TRUE' => ['logical', 0, 0, false, null],
        'FALSE' => ['logical', 0, 0, false, null],
        'LET' => ['logical', 3, null, true, 'odd'],
        // Lookup
        'VLOOKUP' => ['lookup', 3, 4, false, null],
        'HLOOKUP' => ['lookup', 3, 4, false, null],
        'XLOOKUP' => ['lookup', 3, 6, false, null],
        'INDEX' => ['lookup', 2, 3, false, null],
        'MATCH' => ['lookup', 2, 3, false, null],
        'LOOKUP' => ['lookup', 2, 3, false, null],
        'CHOOSE' => ['lookup', 2, null, true, null],
        'ROWS' => ['lookup', 1, 1, false, null],
        'COLUMNS' => ['lookup', 1, 1, false, null],
        // Info
        'N' => ['info', 1, 1, false, null],
        'ISBLANK' => ['info', 1, 1, false, null],
        'ISERROR' => ['info', 1, 1, false, null],
        'ISNA' => ['info', 1, 1, false, null],
        'ISNUMBER' => ['info', 1, 1, false, null],
        'ISTEXT' => ['info', 1, 1, false, null],
        'NA' => ['info', 0, 0, false, null],
        // Text
        'CONCATENATE' => ['text', 1, null, false, null],
        'CONCAT' => ['text', 2, 2, false, null],
        'LEN' => ['text', 1, 1, false, null],
        'TEXT' => ['text', 2, 2, false, null],
        // Date
        'DATE' => ['date', 3, 3, false, null],
        'YEAR' => ['date', 1, 1, false, null],
        'MONTH' => ['date', 1, 1, false, null],
        'DAY' => ['date', 1, 1, false, null],
        'EDATE' => ['date', 2, 2, false, null],
        'EOMONTH' => ['date', 2, 2, false, null],
        'TODAY' => ['date', 0, 0, false, null],
        'NOW' => ['date', 0, 0, false, null],
    ];

    /** Functions whose rows trace as lookup steps. */
    public const array LOOKUPS = ['VLOOKUP', 'HLOOKUP', 'XLOOKUP', 'INDEX', 'MATCH', 'LOOKUP'];

    /** Functions whose rows trace criteria. */
    public const array CRITERIA = ['SUMIF', 'SUMIFS', 'COUNTIF', 'COUNTIFS', 'AVERAGEIF'];

    /** Need a host clock (FORMULA_NEEDS_CLOCK without one). */
    public const array CLOCK = ['NOW', 'TODAY'];

    /** Need a host seed (FORMULA_NEEDS_SEED without one). */
    public const array SEED = ['RAND', 'RANDBETWEEN', 'RANDARRAY'];

    public const array VOLATILE = ['NOW', 'RAND', 'RANDARRAY', 'RANDBETWEEN', 'TODAY'];

    /** Refused unless a host registers a provider (none can be registered yet). */
    public const array EXTERNAL = [
        'DETECTLANGUAGE', 'GOOGLEFINANCE', 'GOOGLETRANSLATE', 'IMAGE', 'IMPORTDATA',
        'IMPORTFEED', 'IMPORTHTML', 'IMPORTRANGE', 'IMPORTXML',
    ];

    /** FORMULA_DYNAMIC_REFERENCE by default (D9). */
    public const array DYNAMIC = ['INDIRECT', 'OFFSET'];

    /**
     * The rest of P1 (plan section 5): known names that arrive in a later
     * slice. check() says so instead of calling them unknown-and-made-up.
     */
    public const array LATER = [
        'ADD', 'MINUS', 'MULTIPLY', 'DIVIDE', 'POW', 'UMINUS', 'UPLUS', 'UNARY_PERCENT',
        'EQ', 'NE', 'GT', 'GTE', 'LT', 'LTE', 'ISBETWEEN',
        'CEILING.MATH', 'FLOOR.MATH', 'SIGN', 'QUOTIENT',
        'MINIFS', 'MAXIFS', 'AVERAGEIFS', 'COUNTBLANK', 'MEDIAN', 'LARGE', 'SMALL',
        'XOR', 'ISERR', 'ISLOGICAL', 'TYPE', 'ERROR.TYPE',
        'TEXTJOIN', 'LEFT', 'RIGHT', 'MID', 'UPPER', 'LOWER', 'PROPER', 'TRIM', 'SUBSTITUTE',
        'REPT', 'FIND', 'SEARCH', 'EXACT', 'VALUE', 'FIXED', 'DOLLAR',
        'DAYS', 'DATEDIF', 'DATEVALUE', 'WEEKDAY', 'NETWORKDAYS', 'WORKDAY',
        'RAND', 'RANDBETWEEN', 'LAMBDA',
    ];

    public static function known(string $name): bool
    {
        return isset(self::REGISTRY[$name]);
    }

    /**
     * The inventory (SPEC.md section 1, Engine::functions()).
     *
     * @return list<array{name: string, category: string, phase: string, minArgs: int, maxArgs: int|null, volatile: bool, external: bool, returnsArray: bool}>
     */
    public static function inventory(): array
    {
        $out = [];
        foreach (self::REGISTRY as $name => [$category, $min, $max]) {
            $out[] = [
                'name' => $name,
                'category' => $category,
                'phase' => 'P1',
                'minArgs' => $min,
                'maxArgs' => $max,
                'volatile' => in_array($name, self::VOLATILE, true),
                'external' => false,
                'returnsArray' => false,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }
}
