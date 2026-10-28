<?php

declare(strict_types=1);

/*
 * The P1 function floor (plan section 5, "P1: what a pricing quote needs"),
 * function name => the fancy-conformance suite its rows land in.
 *
 * Operators themselves (+ - * / ^ & % = <> < > <= >= : and array literals) are
 * not listed: they are exercised by the P- (grammar/precedence) rows. Their
 * NAMED forms (ADD, MINUS, ...) are listed, because Sheets exposes them as
 * functions and P1 claims them.
 *
 * VOLATILE functions are listed with suite null. They cannot be captured --
 * Sheets' clock and random stream are not ours -- so their rows are AUTHORED
 * against the engine's own clock and PRNG specification (SPEC.md section 11.3)
 * and the manifest says so. CaptureWorkbookTest holds every non-null entry to
 * at least three capture rows.
 */

return [
    // Operators, named forms
    'ADD' => 'formula/eval-operators',
    'MINUS' => 'formula/eval-operators',
    'MULTIPLY' => 'formula/eval-operators',
    'DIVIDE' => 'formula/eval-operators',
    'POW' => 'formula/eval-operators',
    'UMINUS' => 'formula/eval-operators',
    'UPLUS' => 'formula/eval-operators',
    'UNARY_PERCENT' => 'formula/eval-operators',
    'EQ' => 'formula/eval-operators',
    'NE' => 'formula/eval-operators',
    'GT' => 'formula/eval-operators',
    'GTE' => 'formula/eval-operators',
    'LT' => 'formula/eval-operators',
    'LTE' => 'formula/eval-operators',
    'ISBETWEEN' => 'formula/eval-operators',
    'CONCAT' => 'formula/eval-operators',

    // Math
    'SUM' => 'formula/eval-math',
    'SUMIF' => 'formula/eval-math',
    'SUMIFS' => 'formula/eval-math',
    'SUMPRODUCT' => 'formula/eval-math',
    'PRODUCT' => 'formula/eval-math',
    'ROUND' => 'formula/eval-math',
    'ROUNDUP' => 'formula/eval-math',
    'ROUNDDOWN' => 'formula/eval-math',
    'MROUND' => 'formula/eval-math',
    'CEILING' => 'formula/eval-math',
    'CEILING.MATH' => 'formula/eval-math',
    'FLOOR' => 'formula/eval-math',
    'FLOOR.MATH' => 'formula/eval-math',
    'INT' => 'formula/eval-math',
    'TRUNC' => 'formula/eval-math',
    'ABS' => 'formula/eval-math',
    'SIGN' => 'formula/eval-math',
    'MOD' => 'formula/eval-math',
    'QUOTIENT' => 'formula/eval-math',
    'POWER' => 'formula/eval-math',
    'SQRT' => 'formula/eval-math',

    // Statistical
    'MIN' => 'formula/eval-stat',
    'MAX' => 'formula/eval-stat',
    'MINIFS' => 'formula/eval-stat',
    'MAXIFS' => 'formula/eval-stat',
    'AVERAGE' => 'formula/eval-stat',
    'AVERAGEIF' => 'formula/eval-stat',
    'AVERAGEIFS' => 'formula/eval-stat',
    'COUNT' => 'formula/eval-stat',
    'COUNTA' => 'formula/eval-stat',
    'COUNTBLANK' => 'formula/eval-stat',
    'COUNTIF' => 'formula/eval-stat',
    'COUNTIFS' => 'formula/eval-stat',
    'MEDIAN' => 'formula/eval-stat',
    'LARGE' => 'formula/eval-stat',
    'SMALL' => 'formula/eval-stat',

    // Logical
    'IF' => 'formula/eval-logical',
    'IFS' => 'formula/eval-logical',
    'SWITCH' => 'formula/eval-logical',
    'IFERROR' => 'formula/eval-logical',
    'IFNA' => 'formula/eval-logical',
    'AND' => 'formula/eval-logical',
    'OR' => 'formula/eval-logical',
    'NOT' => 'formula/eval-logical',
    'XOR' => 'formula/eval-logical',
    'TRUE' => 'formula/eval-logical',
    'FALSE' => 'formula/eval-logical',
    'LET' => 'formula/eval-logical',

    // Lookup
    'VLOOKUP' => 'formula/eval-lookup',
    'HLOOKUP' => 'formula/eval-lookup',
    'XLOOKUP' => 'formula/eval-lookup',
    'INDEX' => 'formula/eval-lookup',
    'MATCH' => 'formula/eval-lookup',
    'LOOKUP' => 'formula/eval-lookup',
    'CHOOSE' => 'formula/eval-lookup',
    'ROWS' => 'formula/eval-lookup',
    'COLUMNS' => 'formula/eval-lookup',

    // Info
    'ISBLANK' => 'formula/eval-info',
    'ISERROR' => 'formula/eval-info',
    'ISERR' => 'formula/eval-info',
    'ISNA' => 'formula/eval-info',
    'ISNUMBER' => 'formula/eval-info',
    'ISTEXT' => 'formula/eval-info',
    'ISLOGICAL' => 'formula/eval-info',
    'NA' => 'formula/eval-info',
    'N' => 'formula/eval-info',
    'TYPE' => 'formula/eval-info',
    'ERROR.TYPE' => 'formula/eval-info',

    // Text
    'CONCATENATE' => 'formula/eval-text',
    'TEXTJOIN' => 'formula/eval-text',
    'LEN' => 'formula/eval-text',
    'LEFT' => 'formula/eval-text',
    'RIGHT' => 'formula/eval-text',
    'MID' => 'formula/eval-text',
    'UPPER' => 'formula/eval-text',
    'LOWER' => 'formula/eval-text',
    'PROPER' => 'formula/eval-text',
    'TRIM' => 'formula/eval-text',
    'SUBSTITUTE' => 'formula/eval-text',
    'REPT' => 'formula/eval-text',
    'FIND' => 'formula/eval-text',
    'SEARCH' => 'formula/eval-text',
    'EXACT' => 'formula/eval-text',
    'VALUE' => 'formula/eval-text',
    'FIXED' => 'formula/eval-text',
    'DOLLAR' => 'formula/eval-text',
    'TEXT' => 'formula/eval-text',

    // Date
    'DATE' => 'formula/eval-date',
    'YEAR' => 'formula/eval-date',
    'MONTH' => 'formula/eval-date',
    'DAY' => 'formula/eval-date',
    'EDATE' => 'formula/eval-date',
    'EOMONTH' => 'formula/eval-date',
    'DAYS' => 'formula/eval-date',
    'DATEDIF' => 'formula/eval-date',
    'DATEVALUE' => 'formula/eval-date',
    'WEEKDAY' => 'formula/eval-date',
    'NETWORKDAYS' => 'formula/eval-date',
    'WORKDAY' => 'formula/eval-date',

    // Volatile: authored, never captured.
    'TODAY' => null,
    'NOW' => null,
    'RAND' => null,
    'RANDBETWEEN' => null,
];
