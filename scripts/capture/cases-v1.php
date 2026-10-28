<?php

declare(strict_types=1);

/*
 * Capture workbook v1: the rows Google Sheets will compute for us.
 *
 * APPEND ONLY. A row's id is its position within its prefix, assigned by the
 * builder in declaration order, and ids are cited by SPEC.md, by the schemas
 * and later by fancy-conformance case ids. Inserting a row in the middle
 * renumbers everything after it. CaptureWorkbookTest compares the builder's
 * output with the committed request.json, so a reorder is a red build rather
 * than a silent renumbering.
 *
 * Prefixes:
 *   K  canaries (the generator refuses a capture whose canaries are wrong)
 *   P  grammar and operator precedence
 *   C  coercion (SPEC section 4)
 *   F  floating point (SPEC section 5)
 *   D  dates (SPEC section 6)
 *   L  lookup policy (sorted / unsorted / mixed types / open ranges)
 *   S  security: text that looks like a formula, criteria from inputs
 *   E  one group per P1 function
 *   A  Appendix A, MOIC's pricing seed
 *
 * Every formula must return a SCALAR. A formula returning an array would spill
 * into columns C-E and destroy the TYPE / ERROR.TYPE / LEN probes beside it;
 * CaptureWorkbookTest refuses a bare array literal or a whole-range result.
 *
 * Inputs live on the `Inputs` sheet. A formula reaches them by address
 * (Inputs!C2:D5); the builder binds the same addresses as cell-bound inputs in
 * each row's engine request, so the formula text is IDENTICAL in the capture
 * and in the conformance row.
 *
 * Inputs written through holy-sheet as floats go through number_format(.., 14):
 * keep input numbers to at most 14 decimal places, or the workbook will hold a
 * different value from the request.
 */

/** A text cell. Holy-sheet writes an explicit string as a typed string cell, never a formula. */
$t = static fn (string $s): array => ['text' => $s];

$inputs = [
    // A1:A6 -- a mixed column: number, numeric TEXT, bool, empty, text, number.
    'A1' => [[10], [$t('20')], [true], [null], [$t('abc')], [5]],
    // B1:B5 -- plain numbers 1..5.
    'B1' => [[1], [2], [3], [4], [5]],
    // C1:D5 -- a price-break table WITH a header row (plan section 3.3).
    'C1' => [[$t('min_qty'), $t('unit_price')], [1, 12.5], [50, 11], [100, 9.75], [500, 8.4]],
    // E1:F5 -- an UNSORTED key column.
    'E1' => [[30, $t('c')], [10, $t('a')], [50, $t('e')], [20, $t('b')], [40, $t('d')]],
    // G1:H4 -- text keys with a duplicate and mixed case.
    'G1' => [[$t('apple'), 100], [$t('Banana'), 200], [$t('cherry'), 300], [$t('apple'), 400]],
    // I1:I2 -- holiday serials: 2026-10-12 and 2026-11-11.
    'I1' => [[46307], [46337]],
    // J1:J6 -- text that LOOKS like a formula or a CSV-injection payload.
    'J1' => [[$t('=1+1')], [$t('+1')], [$t('@SUM(A1)')], [$t('-1')], [$t("\tx")], [$t('=HYPERLINK("x")')]],
    // K1:N2 -- a row-oriented table for HLOOKUP.
    'K1' => [[$t('a'), $t('b'), $t('c'), $t('d')], [1, 2, 3, 4]],
    // O1:O2 -- deliberately EMPTY cells.
    'O1' => [[null], [null]],
    // P1:P4 -- criteria strings and a format code, read from inputs.
    'P1' => [[$t('>=200')], [$t('=SUM(A1)')], [$t('a*')], [$t('0.00')]],
    // Q1:R1 -- a lookup target of exactly 0.3.
    'Q1' => [[0.3, $t('found')]],
    // S1:T5 grid_3yr, U1:V5 grid_5yr, W1:W5 accel_options (Appendix A).
    'S1' => [[0, 0], [25, 0.05], [50, 0.08], [100, 0.12], [250, 0.15]],
    'U1' => [[0, 0.03], [25, 0.08], [50, 0.12], [100, 0.16], [250, 0.2]],
    'W1' => [[0], [0.05], [0.1], [0.15], [0.2]],
];

/*
 * Appendix A cases: one column each, starting at X, rows 1..13 in this order.
 * A-F are MOIC's (#880); G and H were added here to put a breakpoint exactly on
 * a 3-year grid row (50 units) and a match on the LAST grid row (250 units),
 * where the trace bracket's `to` is null.
 */
$pricingFields = [
    'term_years', 'seats_core', 'seats_adv_a', 'seats_adv_b',
    'price_core', 'price_adv_a', 'price_adv_b', 'accel_choice',
    'admin_mode', 'admin_price_entered', 'admin_seats', 'adv_a_in_admin', 'adv_b_in_admin',
];
$pricingCases = [
    'A' => [3, 30, 10, 0, 1000, 400, 600, 2, $t('entered'), 200, 2, false, false],
    'B' => [5, 100, 0, 0, 1000, 400, 600, 1, $t('entered'), 0, 0, false, false],
    'C' => [3, 24, 0, 0, 1000, 400, 600, 1, $t('entered'), 0, 0, false, false],
    'D' => [3, 10, 0, 0, 1000, 400, 600, 1, $t('derived'), 0, 1, true, false],
    'E' => [3, 0, 5, 0, 1000, 400, 600, 1, $t('entered'), 0, 0, false, false],
    'F' => [3, 30, 10, 0, 1000, 400, 600, 9, $t('entered'), 200, 2, false, false],
    'G' => [3, 50, 0, 0, 1000, 400, 600, 3, $t('entered'), 0, 0, false, false],
    'H' => [5, 200, 50, 0, 1000, 400, 600, 5, $t('derived'), 0, 3, true, true],
];
$pricingColumns = ['A' => 'X', 'B' => 'Y', 'C' => 'Z', 'D' => 'AA', 'E' => 'AB', 'F' => 'AC', 'G' => 'AD', 'H' => 'AE'];
foreach ($pricingCases as $case => $values) {
    $inputs[$pricingColumns[$case].'1'] = array_map(static fn ($v): array => [$v], $values);
}

/** The Appendix A formulas for one case column, each self-contained (no cell refers to another case row). */
$pricing = static function (string $col) use ($pricingFields): array {
    $f = [];
    foreach ($pricingFields as $i => $field) {
        $f[$field] = 'Inputs!'.$col.($i + 1);
    }
    $g3 = 'Inputs!S1:T5';
    $g5 = 'Inputs!U1:V5';
    $acc = 'Inputs!W1:W5';

    $tu = "({$f['seats_core']}+{$f['seats_adv_a']}+{$f['seats_adv_b']})";
    $gridV = "IF({$f['term_years']}=3,VLOOKUP({$tu},{$g3},2,TRUE),VLOOKUP({$tu},{$g5},2,TRUE))";
    $gridX = "IF({$f['term_years']}=3,XLOOKUP({$tu},INDEX({$g3},,1),INDEX({$g3},,2),,-1),XLOOKUP({$tu},INDEX({$g5},,1),INDEX({$g5},,2),,-1))";
    $gridM = "IF({$f['term_years']}=3,INDEX({$g3},MATCH({$tu},INDEX({$g3},,1),1),2),INDEX({$g5},MATCH({$tu},INDEX({$g5},,1),1),2))";
    $accel = "INDEX({$acc},{$f['accel_choice']})";
    $td = "({$gridV}+{$accel})";
    $line = static fn (string $seats, string $price): string => "ROUND({$seats}*{$price}*(1-{$td}),2)";
    $adminN = "IF({$f['admin_mode']}=\"entered\",{$f['admin_price_entered']},{$f['price_core']}+{$f['price_adv_a']}*N({$f['adv_a_in_admin']})+{$f['price_adv_b']}*N({$f['adv_b_in_admin']}))";
    $adminS = "IF({$f['admin_mode']}=\"entered\",{$f['admin_price_entered']},SUMPRODUCT({TRUE,{$f['adv_a_in_admin']},{$f['adv_b_in_admin']}}*1,{{$f['price_core']},{$f['price_adv_a']},{$f['price_adv_b']}}))";
    $lineAdmin = "ROUND({$f['admin_seats']}*{$adminN}*(1-{$td}),2)";
    $annual = 'SUM('.$line($f['seats_core'], $f['price_core']).','.$line($f['seats_adv_a'], $f['price_adv_a']).','.$line($f['seats_adv_b'], $f['price_adv_b']).",{$lineAdmin})";

    return [
        'total_units' => "=SUM({$f['seats_core']},{$f['seats_adv_a']},{$f['seats_adv_b']})",
        'grid_discount.vlookup' => "={$gridV}",
        'grid_discount.xlookup' => "={$gridX}",
        'grid_discount.index_match' => "={$gridM}",
        'accel_pct' => "={$accel}",
        'total_discount' => "={$td}",
        'line_core' => '='.$line($f['seats_core'], $f['price_core']),
        'line_adv_a' => '='.$line($f['seats_adv_a'], $f['price_adv_a']),
        'line_adv_b' => '='.$line($f['seats_adv_b'], $f['price_adv_b']),
        'admin_price' => "={$adminN}",
        'admin_price.sumproduct' => "={$adminS}",
        'line_admin' => "={$lineAdmin}",
        'annual_total' => "={$annual}",
        'contract_total' => "={$annual}*{$f['term_years']}",
        'needs_core' => "=IF(AND({$f['seats_adv_a']}+{$f['seats_adv_b']}>0,{$f['seats_core']}=0),\"Advanced modules need Core\",\"\")",
    ];
};

/** Which outputs each pricing case captures. A and H take all fifteen. */
$pricingOutputs = [
    'A' => null,
    'B' => ['grid_discount.vlookup', 'grid_discount.xlookup', 'grid_discount.index_match', 'total_discount', 'line_core'],
    'C' => ['grid_discount.vlookup', 'grid_discount.xlookup', 'grid_discount.index_match'],
    'D' => ['admin_price', 'admin_price.sumproduct', 'line_admin'],
    'E' => ['total_units', 'needs_core'],
    'F' => ['accel_pct', 'total_discount', 'line_core'],
    'G' => ['grid_discount.vlookup', 'grid_discount.xlookup', 'grid_discount.index_match', 'total_discount'],
    'H' => null,
];

/*
 * Hypotheses the capture decides. SPEC.md lists each with the rows that
 * decide it; CaptureWorkbookTest holds SPEC.md and this map to the same ids.
 */
$hypotheses = [
    'H-01' => 'Unary minus binds tighter than ^, so =-2^2 is 4 (as in Excel).',
    'H-02' => '^ is left-associative, so =2^3^2 is 64.',
    'H-03' => 'Equality compares at 15 significant digits, so =0.1+0.2=0.3 is TRUE.',
    'H-04' => 'A result within 15 significant digits of zero snaps to 0, e.g. (0.1+0.2)-0.3.',
    'H-05' => 'ROUND, ROUNDUP, ROUNDDOWN, MROUND and TRUNC work on the 15-significant-digit decimal string, rounding half away from zero (ROUND(1.005,2) = 1.01).',
    'H-06' => 'Sheets has no 1900 leap-year bug: serial 60 is 1900-02-28 and DATE(1900,2,29) is 1900-03-01.',
    'H-07' => 'Negative serials are dates before 1899-12-30 (or an error); TEXT/YEAR of a negative serial.',
    'H-08' => 'DATE adds 1900 to a year in 0..1899; a year below 0 or at least 10000 is #NUM!.',
    'H-09' => 'Approximate VLOOKUP/MATCH/LOOKUP over an unsorted column runs a binary search, and the result follows that search.',
    'H-10' => 'AND and OR do NOT short-circuit: an error in a later argument still surfaces.',
    'H-11' => 'A multi-cell range or array in a scalar context without ARRAYFORMULA takes its first element (or errors).',
    'H-12' => 'Aggregates coerce LITERAL text and boolean arguments (SUM(1,"2",TRUE) = 4) but skip them inside ranges.',
    'H-13' => 'Mixed types order as number < text < boolean in comparisons.',
    'H-14' => 'Text comparison and lookup are case-insensitive, including non-ASCII letters.',
    'H-15' => 'LEN, LEFT and MID count UTF-16 code units, so an emoji outside the BMP is 2.',
    'H-16' => '#NULL! and #ERROR! exist as Sheets error values with ERROR.TYPE codes.',
    'H-17' => 'The xlsx Sheets exports keeps full double precision (17 significant digits).',
    'H-18' => 'The text "TRUE" / "FALSE" (any case) coerces to a boolean in IF/AND/OR/NOT; other text is #VALUE!.',
    'H-19' => 'An empty cell compares as 0 against a number and as "" against text.',
    'H-20' => 'When several arguments are errors, the first in evaluation order wins.',
    'H-21' => 'Cell references are accepted inside array literals ({TRUE,A1}), as MOIC\'s SUMPRODUCT form needs.',
    'H-22' => 'INDEX with a row past the range is #REF! (MOIC expects #REF!; Sheets may say #NUM!).',
    'H-23' => 'Number-to-text (& and text functions) uses the 15-significant-digit general form (1/3 -> 0.333333333333333).',
    'H-24' => 'Numeric text coerces in arithmetic: "5%" -> 0.05, date-like text -> serial, surrounding spaces allowed; thousands separators and currency symbols are a question.',
    'H-25' => 'An open range (B:B) is sized by the populated extent of the inputs (the engine) -- Sheets sizes it by the grid, so ROWS(B:B) is a known divergence candidate.',
    'H-26' => 'Two-digit years in date TEXT ("1/1/29", "1/1/30") follow a fixed pivot in Sheets.',
    'H-27' => 'INDEX with an omitted row argument (INDEX(r,,1)) returns the whole column, as MOIC\'s XLOOKUP form needs.',
    'H-28' => 'Criteria strings are a closed grammar: "=SUM(A1)" means equality with the TEXT SUM(A1), and "==1+1" matches the text =1+1.',
    'H-29' => 'MOD takes the sign of the divisor (not fmod\'s sign of the dividend).',
    'H-30' => 'CEILING and FLOOR with a positive number and negative factor (or the reverse) follow Sheets, not Excel\'s #NUM!.',
    'H-31' => 'IFERROR with value_if_error omitted returns an empty string.',
    'H-32' => 'POWER(0,0) is 1.',
    'H-33' => 'SUMPRODUCT over arrays of different sizes is an error, not #N/A padding.',
    'H-34' => 'A text value longer than 50,000 characters is an error.',
    'H-35' => 'The TYPE of an error value is 16 and the TYPE of an array literal is 64.',
];

$c = [];

// --- K: canaries --------------------------------------------------------
$c[] = ['K', null, '=1+1', 'Canary: Sheets recalculated at all.', []];
$c[] = ['K', null, '=0.1+0.2', 'Canary: recalculated, and reveals export precision (0.3 vs 0.30000000000000004).', ['H-17']];

// --- P: grammar and precedence -----------------------------------------
$P = 'formula/eval-operators';
foreach ([
    ['=-2^2', 'Unary minus vs ^.', ['H-01']],
    ['=0-2^2', 'Binary minus is below ^: -4 either way; the control for the row above.', ['H-01']],
    ['=2^3^2', 'Associativity of ^.', ['H-02']],
    ['=-2^0.5', 'If unary minus binds first this is (-2)^0.5 = #NUM!.', ['H-01']],
    ['=50%', 'Postfix percent.', []],
    ['=2*50%', 'Percent binds tighter than *.', []],
    ['=2^50%', 'Percent binds tighter than ^: 2^0.5.', []],
    ['=-50%', 'Unary minus with percent.', []],
    ['=10%%', 'Postfix percent applied twice.', []],
    ['=1+2*3', '', []],
    ['=(1+2)*3', '', []],
    ['="a"&1+2', '& is below +: "a3".', []],
    ['=1&2=12', 'Comparison is lowest: ("12")=12 compares text with a number.', ['H-13']],
    ['=1=1=TRUE', 'Comparison is left-associative.', []],
    ['=--"3"', 'Double unary minus coerces text.', ['H-24']],
    ['=+"3"', 'Does unary plus coerce, or pass text through?', []],
    ['="say ""hi"""', 'The "" escape inside a string literal.', []],
    ['=.5+1E2', 'Leading-dot and exponent number literals.', []],
    ['=1E-3*1000', '', []],
    ['=SUM({1,2;3,4})', 'Array literal: , separates columns, ; rows.', []],
    ['=ROWS({1,2;3,4})', '', []],
    ['=COLUMNS({1,2,3})', '', []],
    ['=SUM({1,2}*{3,4})', 'Array arithmetic inside SUM without ARRAYFORMULA.', ['H-11']],
    ['=sum(1,2)', 'Function names are case-insensitive.', []],
    ['=SUM(Inputs!B1:B5)', 'A sheet-qualified range.', []],
    ['=SUM(Inputs!$B$1:$B$5)', 'Absolute markers are accepted.', []],
    ['=SUM(Inputs!B:B)', 'An open column range.', ['H-25']],
    ['=INDEX({10,20;30,40},2,1)', '', []],
    ['=SUM({Inputs!B1,Inputs!B2})', 'References inside an array literal.', ['H-21']],
    ['=ERROR.TYPE(#NULL!)', 'Does #NULL! exist?', ['H-16']],
    ['=ERROR.TYPE(#ERROR!)', 'Does #ERROR! exist as a literal?', ['H-16']],
    ['=ERROR.TYPE(#N/A)', 'An error literal.', []],
    ['=1/0', '', []],
    ['=TRUE+TRUE', 'Booleans in arithmetic.', []],
    ['=2^-1', 'Unary minus in an exponent.', []],
    ['=-(-3)', '', []],
    ['=3-(-2)', '', []],
    ['=1<>2', '', []],
    ['=-"abc"', 'Unary minus on non-numeric text.', []],
    ['=(((((((((1)))))))))', 'Deep but legal nesting.', []],
    ['=IF(TRUE,"yes")&""', 'An omitted else branch.', []],
    ['=SUM(1,,2)', 'An empty argument in a variadic call.', []],
] as [$formula, $note, $h]) {
    $c[] = ['P', $P, $formula, $note, $h];
}

// --- C: coercion ------------------------------------------------------------
$C = 'formula/coerce';
foreach ([
    // to number
    ['=1+"2"', 'Numeric text in arithmetic.', ['H-24']],
    ['=1+"5%"', 'Percent text.', ['H-24']],
    ['=1+"2026-10-27"', 'Date-like text becomes a serial.', ['H-24']],
    ['=1+"abc"', 'Non-numeric text.', ['H-24']],
    ['=1+TRUE', '', []],
    ['=1+Inputs!O1', 'An empty cell is 0.', ['H-19']],
    ['=1+NA()', 'An error propagates.', []],
    ['=1+" 2 "', 'Surrounding spaces.', ['H-24']],
    ['=1+"1e3"', 'Exponent text.', ['H-24']],
    ['=1+"$5"', 'A currency symbol in text.', ['H-24']],
    ['=1+"1,000"', 'A thousands separator in text.', ['H-24']],
    ['=1+"(5)"', 'Accounting negative in text.', ['H-24']],
    ['=1+""', 'Empty TEXT is not an empty cell.', ['H-24']],
    ['=1+Inputs!A2', 'A cell holding numeric TEXT, in arithmetic.', ['H-24']],
    // to text
    ['=1/3&""', '15 significant digits.', ['H-23']],
    ['=TRUE&""', '', []],
    ['=Inputs!O1&"x"', 'Empty is "".', ['H-19']],
    ['=NA()&"x"', '', []],
    ['=123456789012345678&""', 'A number past 15 digits, as text.', ['H-23']],
    ['=1E+20&""', 'Large numbers switch to scientific form?', ['H-23']],
    ['=1E-7&""', 'Small numbers switch to scientific form?', ['H-23']],
    ['=DATE(2026,10,27)&""', 'A date is its serial as text.', []],
    ['=-0&""', 'Negative zero.', []],
    // to boolean
    ['=IF(2,"t","f")', '', []],
    ['=IF(-0.5,"t","f")', '', []],
    ['=IF("TRUE","t","f")', '', ['H-18']],
    ['=IF("true","t","f")', '', ['H-18']],
    ['=IF("FALSE","t","f")', '', ['H-18']],
    ['=IF("yes","t","f")', '', ['H-18']],
    ['=IF(Inputs!O1,"t","f")', 'Empty is FALSE.', []],
    ['=IF(NA(),"t","f")', '', []],
    ['=NOT("FALSE")', '', ['H-18']],
    ['=IF("0","t","f")', 'The text "0" (PHP treats it as false).', ['H-18']],
    // aggregates: literal arguments vs ranges
    ['=SUM(1,"2",TRUE)', 'Literal text and bool in SUM.', ['H-12']],
    ['=SUM(1,"x")', '', ['H-12']],
    ['=SUM(Inputs!A1:A6)', 'The same kinds inside a range are skipped.', ['H-12']],
    ['=SUM(Inputs!A2)', 'A single cell holding numeric text.', ['H-12']],
    ['=SUM(Inputs!A3)', 'A single cell holding TRUE.', ['H-12']],
    ['=AVERAGE(1,"3")', '', ['H-12']],
    ['=MAX("7",2)', '', ['H-12']],
    ['=COUNT("1",TRUE,"x",1)', 'COUNT over literal arguments.', ['H-12']],
    ['=SUM({1,"2",TRUE})', 'An array literal: like a range, or like literal arguments?', ['H-12']],
    // comparison
    ['=1<"a"', '', ['H-13']],
    ['="a"<TRUE', '', ['H-13']],
    ['=1<TRUE', '', ['H-13']],
    ['="a"="A"', '', ['H-14']],
    ['="a"<"B"', '', ['H-14']],
    ['="ä"="Ä"', 'Case folding beyond ASCII.', ['H-14']],
    ['=Inputs!O1=0', '', ['H-19']],
    ['=Inputs!O1=""', '', ['H-19']],
    ['=Inputs!O1<1', '', ['H-19']],
    ['=Inputs!O1=FALSE', 'Empty against a boolean.', ['H-19']],
    ['="10"<"9"', 'Text compares as text.', []],
    ['=" a"="a"', 'Whitespace is significant.', []],
    ['=1=TRUE', '', ['H-13']],
    ['="1"=1', '', ['H-13']],
    // error precedence
    ['=NA()+1/0', '', ['H-20']],
    ['=1/0+NA()', '', ['H-20']],
    ['=SUM(1/0,NA())', '', ['H-20']],
    ['=CONCATENATE(NA(),1/0)', '', ['H-20']],
    ['=MAX(1/0,NA())', '', ['H-20']],
] as [$formula, $note, $h]) {
    $c[] = ['C', $C, $formula, $note, $h];
}

// --- F: floating point ------------------------------------------------------
$F = 'formula/float';
foreach ([
    ['=0.1+0.2=0.3', '', ['H-03']],
    ['=(0.1+0.2)-0.3', 'Does a near-zero result snap to 0?', ['H-04']],
    ['=0.1*3', '', ['H-17']],
    ['=1-0.9', '', ['H-17']],
    ['=(1-0.9)=0.1', '', ['H-03']],
    ['=ROUND(1.005,2)', 'Native rounding gives 1 (1.005 is 1.00499999999999989...).', ['H-05']],
    ['=ROUND(2.675,2)', '', ['H-05']],
    ['=ROUND(0.285,2)', '', ['H-05']],
    ['=ROUNDUP(0.1+0.2,1)', '0.3 if snapped to 15 digits first, 0.4 otherwise.', ['H-05']],
    ['=ROUNDDOWN(1-0.9,1)', '0.1 if snapped, 0 otherwise.', ['H-05']],
    ['=INT((1-0.9)*10)', '1 if snapped, 0 otherwise.', ['H-05']],
    ['=TRUNC((0.1+0.7)*10)', '8 if snapped, 7 otherwise.', ['H-05']],
    ['=TRUNC(4.35*100)', '435 if snapped, 434 otherwise.', ['H-05']],
    ['=MOD(1,0.1)', 'fmod gives 0.09999999999999995.', ['H-05']],
    ['=MOD(0.3,0.1)', '', ['H-05']],
    ['=1/3*3=1', '', ['H-03']],
    ['=1/3&""', '', ['H-23']],
    ['=2/3&""', 'Rounded, not truncated, at 15 digits.', ['H-23']],
    ['=1E+15+1', '', ['H-17']],
    ['=(1E+16+1)&""', '', ['H-23']],
    ['=123456789012345678', 'A literal past double precision.', ['H-17']],
    ['=9.99999999999999E+307*10', 'Overflow is #NUM!, never INF.', []],
    ['=1E-320', 'A subnormal literal.', ['H-17']],
    ['=SUM(0.1,0.2,0.3)=0.6', '', ['H-03']],
    ['=0.1+0.7', '', ['H-17']],
    ['=4.35*100', '', ['H-17']],
    ['=MATCH(0.1+0.2,Inputs!Q1:Q1,0)', 'Lookup equality uses the same comparator.', ['H-03']],
    ['=VLOOKUP(0.1+0.2,Inputs!Q1:R1,2,FALSE)', '', ['H-03']],
    ['=COUNTIF(Inputs!Q1:Q1,0.1+0.2)', 'Criteria equality uses the same comparator.', ['H-03']],
    ['=1.1*1.1=1.21', '', ['H-03']],
    ['=SQRT(2)^2=2', '', ['H-03']],
    ['=10/3*3=10', '', ['H-03']],
    ['=0.1+0.2>0.3', 'Ordering, not just equality.', ['H-03']],
    ['=(0.1+0.2)-0.3=0', '', ['H-04']],
    ['=ROUND(-1.005,2)', 'Half away from zero, negative side.', ['H-05']],
    ['=ROUND(1234567.891,-3)', '', ['H-05']],
] as [$formula, $note, $h]) {
    $c[] = ['F', $F, $formula, $note, $h];
}

// --- D: dates ---------------------------------------------------------------
$D = 'formula/dates';
foreach ([
    ['=DATE(1900,2,29)', 'Excel: serial 60 (a fictitious day). Sheets: 1900-03-01 = 61?', ['H-06']],
    ['=DATE(1900,3,1)-DATE(1900,2,28)', '1 without the bug, 2 with it.', ['H-06']],
    ['=TEXT(60,"yyyy-mm-dd")', '', ['H-06']],
    ['=TEXT(59,"yyyy-mm-dd")', '', ['H-06']],
    ['=TEXT(61,"yyyy-mm-dd")', 'Sheets and Excel agree from here on.', ['H-06']],
    ['=DATE(1900,1,1)', 'Sheets: 2. Excel: 1.', ['H-06']],
    ['=TEXT(1,"yyyy-mm-dd")', '', ['H-06']],
    ['=TEXT(0,"yyyy-mm-dd")', 'Serial 0 is 1899-12-30.', ['H-06']],
    ['=DAY(60)', '', ['H-06']],
    ['=MONTH(60)', '', ['H-06']],
    ['=TEXT(-1,"yyyy-mm-dd")', '', ['H-07']],
    ['=YEAR(-1)', '', ['H-07']],
    ['=DATE(1899,12,29)', 'A date before the epoch: 1899 is below 1900, so it may read as 3799.', ['H-07', 'H-08']],
    ['=DATE(2026,10,27)', 'Worked check: 46322.', []],
    ['=DATE(0,1,1)', 'Year 0 -> 1900.', ['H-08']],
    ['=DATE(99,12,31)', 'Sheets documents 1900+y; LibreOffice uses a 1930-2029 window.', ['H-08']],
    ['=DATE(119,2,1)', 'The documented example: 2019-02-01.', ['H-08']],
    ['=DATE(-1,1,1)', '', ['H-08']],
    ['=DATE(10000,1,1)', '', ['H-08']],
    ['=DATE(9999,12,31)', '', ['H-08']],
    ['=DATEVALUE("1/1/29")', '', ['H-26']],
    ['=DATEVALUE("1/1/30")', '', ['H-26']],
    ['=TEXT(DATE(2026,10,27)+0.5,"yyyy-mm-dd hh:mm")', 'A time fraction.', []],
    ['=DATE(2024,2,29)+365', '', []],
    ['=DATE(2100,2,29)', '2100 is not a leap year.', []],
    ['=TEXT(2958465,"yyyy-mm-dd")', '9999-12-31.', []],
    ['=TEXT(2958466,"yyyy-mm-dd")', 'Past 9999.', []],
    ['=VALUE("1900-02-29")', 'Text naming the fictitious day.', ['H-06']],
] as [$formula, $note, $h]) {
    $c[] = ['D', $D, $formula, $note, $h];
}

// --- L: lookup policy -------------------------------------------------------
$L = 'formula/eval-lookup';
foreach ([
    ['=VLOOKUP(25,Inputs!E1:F5,2,TRUE)', 'Unsorted keys 30,10,50,20,40: binary search lands on a different row from a linear scan.', ['H-09']],
    ['=VLOOKUP(45,Inputs!E1:F5,2,TRUE)', '', ['H-09']],
    ['=MATCH(35,Inputs!E1:E5,1)', '', ['H-09']],
    ['=MATCH(5,Inputs!E1:E5,1)', 'Below every key.', ['H-09']],
    ['=LOOKUP(25,Inputs!E1:E5,Inputs!F1:F5)', '', ['H-09']],
    ['=XLOOKUP(25,Inputs!E1:E5,Inputs!F1:F5,,-1)', 'XLOOKUP next-smaller is a linear scan: the control.', ['H-09']],
    ['=MATCH(TRUE,Inputs!A1:A6,0)', 'Exact match on a boolean in a mixed column.', ['H-13']],
    ['=MATCH("20",Inputs!A1:A6,0)', 'Text key against numeric text.', ['H-13']],
    ['=MATCH(20,Inputs!A1:A6,0)', 'Number key against numeric TEXT: no match expected.', ['H-13']],
    ['=VLOOKUP(Inputs!O1,Inputs!C2:D5,2,TRUE)', 'An empty key.', ['H-19']],
    ['=MATCH("apple",Inputs!G1:G4,0)', 'The first of two duplicates.', []],
    ['=XLOOKUP("apple",Inputs!G1:G4,Inputs!H1:H4,,0,-1)', 'Search from the end: the last duplicate.', []],
    ['=VLOOKUP("*an*",Inputs!G1:H4,2,FALSE)', 'Wildcards in an exact VLOOKUP.', []],
    ['=MATCH(100,Inputs!C2:C5,0)', '', []],
    ['=VLOOKUP(99.99999999999999,Inputs!C2:D5,2,TRUE)', 'A key a hair below a breakpoint.', ['H-03']],
    ['=ROWS(Inputs!B:B)', 'An open range is sized by the grid in Sheets.', ['H-25']],
    ['=COUNTA(Inputs!B:B)', '', ['H-25']],
    ['=MATCH(10,{30,20,10},-1)', 'Descending match.', []],
    ['=MATCH(25,{30,20,10},-1)', '', []],
    ['=VLOOKUP("BANANA",Inputs!G1:H4,2,FALSE)', 'Case-insensitive key.', ['H-14']],
    ['=HLOOKUP("C",Inputs!K1:N2,2,FALSE)', '', ['H-14']],
    ['=VLOOKUP(500,Inputs!C2:D5,2,TRUE)', 'Exactly the last breakpoint.', []],
] as [$formula, $note, $h]) {
    $c[] = ['L', $L, $formula, $note, $h];
}

// --- S: security ------------------------------------------------------------
$S = 'formula/security';
foreach ([
    ['=Inputs!J1', 'Text "=1+1" stays text.', []],
    ['=Inputs!J1&""', '', []],
    ['=LEN(Inputs!J1)', '', []],
    ['=ISTEXT(Inputs!J1)', '', []],
    ['=Inputs!J2&""', 'Text "+1".', []],
    ['=ISNUMBER(Inputs!J2)', '', []],
    ['=Inputs!J3', 'Text "@SUM(A1)".', []],
    ['=Inputs!J4+0', 'Text "-1" in arithmetic coerces like any numeric text.', ['H-24']],
    ['=LEN(Inputs!J5)', 'Text starting with TAB.', []],
    ['=LEN(CHAR(13)&"x")', 'Text starting with CR.', []],
    ['=COUNTIF(Inputs!J1:J6,"=1+1")', 'Criteria "=1+1" means equals the text 1+1.', ['H-28']],
    ['=COUNTIF(Inputs!J1:J6,"==1+1")', 'Criteria "==1+1" means equals the text =1+1.', ['H-28']],
    ['=COUNTIF(Inputs!P1:P4,Inputs!P2)', 'Criteria read from an input: "=SUM(A1)".', ['H-28']],
    ['=SUMIF(Inputs!G1:G4,Inputs!P2,Inputs!H1:H4)', '', ['H-28']],
    ['=COUNTIF(Inputs!G1:G4,Inputs!P3)', 'A wildcard criteria from an input.', []],
    ['=SUMIF(Inputs!H1:H4,Inputs!P1)', 'A comparison criteria from an input.', []],
    ['=VLOOKUP(Inputs!J1,Inputs!J1:J6,1,FALSE)', 'Formula-looking text as a lookup key.', []],
    ['=MATCH(Inputs!J6,Inputs!J1:J6,0)', '', []],
    ['=HYPERLINK("http://example.com","label")', 'HYPERLINK is pure: it returns its label.', []],
    ['=VALUE(Inputs!J1)', '', []],
    ['=Inputs!J1="=1+1"', '', []],
    ['=TEXT(5,Inputs!P4)', 'A format code read from an input.', []],
    ['=IFERROR(Inputs!J1+0,"refused")', '', []],
    ['=LEN(Inputs!J6)', '', []],
] as [$formula, $note, $h]) {
    $c[] = ['S', $S, $formula, $note, $h];
}

// --- E: one group per P1 function -------------------------------------------
$e = [
    'ADD' => [['=ADD(1,2)'], ['=ADD("1",2)'], ['=ADD(1,"x")']],
    'MINUS' => [['=MINUS(5,3)'], ['=MINUS(DATE(2026,10,27),DATE(2026,10,1))'], ['=MINUS(TRUE,1)']],
    'MULTIPLY' => [['=MULTIPLY(2,3)'], ['=MULTIPLY(TRUE,3)'], ['=MULTIPLY(Inputs!O1,3)']],
    'DIVIDE' => [['=DIVIDE(6,3)'], ['=DIVIDE(1,0)'], ['=DIVIDE(1,3)']],
    'POW' => [['=POW(2,3)'], ['=POW(-8,1/3)'], ['=POW(4,0.5)']],
    'UMINUS' => [['=UMINUS(3)'], ['=UMINUS("3")'], ['=UMINUS(TRUE)']],
    'UPLUS' => [['=UPLUS(3)'], ['=UPLUS("abc")'], ['=UPLUS(TRUE)']],
    'UNARY_PERCENT' => [['=UNARY_PERCENT(50)'], ['=UNARY_PERCENT("50")'], ['=UNARY_PERCENT(-5)']],
    'EQ' => [['=EQ(1,1)'], ['=EQ("a","A")', '', ['H-14']], ['=EQ(1,"1")', '', ['H-13']]],
    'NE' => [['=NE(1,2)'], ['=NE("a","A")', '', ['H-14']], ['=NE(TRUE,1)', '', ['H-13']]],
    'GT' => [['=GT(2,1)'], ['=GT("b","a")'], ['=GT("a",1)', '', ['H-13']]],
    'GTE' => [['=GTE(1,1)'], ['=GTE(TRUE,"z")', '', ['H-13']], ['=GTE(Inputs!O1,0)', '', ['H-19']]],
    'LT' => [['=LT(1,2)'], ['=LT("a","B")', '', ['H-14']], ['=LT(Inputs!O1,"")', '', ['H-19']]],
    'LTE' => [['=LTE(2,2)'], ['=LTE(-1,Inputs!O1)', '', ['H-19']], ['=LTE("10","9")']],
    'ISBETWEEN' => [['=ISBETWEEN(5,1,10)'], ['=ISBETWEEN(1,1,10,FALSE)'], ['=ISBETWEEN(10,1,10,TRUE,FALSE)']],
    'CONCAT' => [['=CONCAT("a","b")'], ['=CONCAT(1,2)'], ['=CONCAT(TRUE,"x")']],

    'SUM' => [['=SUM(1,2,3)'], ['=SUM(Inputs!B1:B5,10)'], ['=SUM()'], ['=SUM(Inputs!C2:D5)']],
    'SUMIF' => [
        ['=SUMIF(Inputs!H1:H4,">=200")'],
        ['=SUMIF(Inputs!G1:G4,"apple",Inputs!H1:H4)'],
        ['=SUMIF(Inputs!G1:G4,"a*",Inputs!H1:H4)'],
        ['=SUMIF(Inputs!G1:G4,"APPLE",Inputs!H1:H4)', 'Criteria matching is case-insensitive.', ['H-14']],
        ['=SUMIF(Inputs!H1:H4,"<>200")'],
    ],
    'SUMIFS' => [
        ['=SUMIFS(Inputs!H1:H4,Inputs!G1:G4,"apple",Inputs!H1:H4,">100")'],
        ['=SUMIFS(Inputs!H1:H4,Inputs!G1:G4,"<>apple")'],
        ['=SUMIFS(Inputs!H1:H4,Inputs!G1:G4,"?anana")'],
    ],
    'SUMPRODUCT' => [
        ['=SUMPRODUCT(Inputs!B1:B5,Inputs!B1:B5)'],
        ['=SUMPRODUCT({1,2,3},{4,5,6})'],
        ['=SUMPRODUCT({1,2},{1,2,3})', 'Mismatched sizes.', ['H-33']],
        ['=SUMPRODUCT((Inputs!H1:H4>150)*Inputs!H1:H4)'],
    ],
    'PRODUCT' => [['=PRODUCT(2,3,4)'], ['=PRODUCT(Inputs!B1:B5)'], ['=PRODUCT(Inputs!A1:A6)']],
    'ROUND' => [['=ROUND(2.5,0)'], ['=ROUND(-2.5,0)'], ['=ROUND(1234.5678,-2)'], ['=ROUND(3.14159)']],
    'ROUNDUP' => [['=ROUNDUP(3.2,0)'], ['=ROUNDUP(-3.2,0)'], ['=ROUNDUP(31415.92654,-2)']],
    'ROUNDDOWN' => [['=ROUNDDOWN(3.9,0)'], ['=ROUNDDOWN(-3.9,0)'], ['=ROUNDDOWN(1.239,2)']],
    'MROUND' => [['=MROUND(10,3)'], ['=MROUND(-10,-3)'], ['=MROUND(5,-2)'], ['=MROUND(1.3,0.2)', '', ['H-05']]],
    'CEILING' => [['=CEILING(2.5,1)'], ['=CEILING(-2.5,-2)'], ['=CEILING(-2.5,2)', '', ['H-30']], ['=CEILING(1.234,0.05)']],
    'CEILING.MATH' => [['=CEILING.MATH(-5.5)'], ['=CEILING.MATH(-5.5,2,1)'], ['=CEILING.MATH(24.3,5)']],
    'FLOOR' => [['=FLOOR(3.7,2)'], ['=FLOOR(-2.5,-2)'], ['=FLOOR(2.5,-2)', '', ['H-30']], ['=FLOOR(1.58,0.1)', '', ['H-05']]],
    'FLOOR.MATH' => [['=FLOOR.MATH(-5.5)'], ['=FLOOR.MATH(-5.5,2,1)'], ['=FLOOR.MATH(24.3,5)']],
    'INT' => [['=INT(8.9)'], ['=INT(-8.9)'], ['=INT("3.7")']],
    'TRUNC' => [['=TRUNC(8.9)'], ['=TRUNC(-8.9)'], ['=TRUNC(3.14159,2)']],
    'ABS' => [['=ABS(-4)'], ['=ABS(Inputs!O1)'], ['=ABS("x")']],
    'SIGN' => [['=SIGN(-0.0001)'], ['=SIGN(0)'], ['=SIGN(12)']],
    'MOD' => [['=MOD(5,3)'], ['=MOD(-5,3)', '', ['H-29']], ['=MOD(5,-3)', '', ['H-29']], ['=MOD(5,0)'], ['=MOD(5.5,2)']],
    'QUOTIENT' => [['=QUOTIENT(5,2)'], ['=QUOTIENT(-5,2)'], ['=QUOTIENT(5,0)']],
    'POWER' => [['=POWER(2,10)'], ['=POWER(-8,1/3)'], ['=POWER(0,0)', '', ['H-32']], ['=POWER(0,-1)']],
    'SQRT' => [['=SQRT(16)'], ['=SQRT(-1)'], ['=SQRT(2)']],

    'MIN' => [['=MIN(3,1,2)'], ['=MIN(Inputs!A1:A6)'], ['=MIN(Inputs!O1:O2)']],
    'MAX' => [['=MAX(3,1,2)'], ['=MAX(Inputs!A1:A6)'], ['=MAX(Inputs!O1:O2)']],
    'MINIFS' => [['=MINIFS(Inputs!H1:H4,Inputs!G1:G4,"apple")'], ['=MINIFS(Inputs!H1:H4,Inputs!G1:G4,"zzz")'], ['=MINIFS(Inputs!H1:H4,Inputs!H1:H4,">150")']],
    'MAXIFS' => [['=MAXIFS(Inputs!H1:H4,Inputs!G1:G4,"apple")'], ['=MAXIFS(Inputs!H1:H4,Inputs!H1:H4,"<300")'], ['=MAXIFS(Inputs!H1:H4,Inputs!G1:G4,"zzz")']],
    'AVERAGE' => [['=AVERAGE(1,2,3,4)'], ['=AVERAGE(Inputs!A1:A6)'], ['=AVERAGE(Inputs!O1:O2)'], ['=AVERAGE(1,TRUE)', '', ['H-12']]],
    'AVERAGEIF' => [['=AVERAGEIF(Inputs!H1:H4,">150")'], ['=AVERAGEIF(Inputs!G1:G4,"apple",Inputs!H1:H4)'], ['=AVERAGEIF(Inputs!H1:H4,">1000")']],
    'AVERAGEIFS' => [
        ['=AVERAGEIFS(Inputs!H1:H4,Inputs!G1:G4,"<>apple")'],
        ['=AVERAGEIFS(Inputs!H1:H4,Inputs!H1:H4,">=200",Inputs!H1:H4,"<=300")'],
        ['=AVERAGEIFS(Inputs!H1:H4,Inputs!G1:G4,"none")'],
    ],
    'COUNT' => [['=COUNT(Inputs!A1:A6)'], ['=COUNT(Inputs!B1:B5,1,2)'], ['=COUNT(Inputs!O1:O2)']],
    'COUNTA' => [['=COUNTA(Inputs!A1:A6)'], ['=COUNTA(1,"",TRUE)'], ['=COUNTA(Inputs!O1:O2)']],
    'COUNTBLANK' => [['=COUNTBLANK(Inputs!A1:A6)'], ['=COUNTBLANK(Inputs!O1:O2)'], ['=COUNTBLANK(Inputs!B1:B5)']],
    'COUNTIF' => [
        ['=COUNTIF(Inputs!G1:G4,"apple")'],
        ['=COUNTIF(Inputs!H1:H4,">200")'],
        ['=COUNTIF(Inputs!G1:G4,"*an*")'],
        ['=COUNTIF(Inputs!A1:A6,"20")', 'Criteria "20" against number 20 and text "20".', ['H-13']],
        ['=COUNTIF(Inputs!G1:G4,"<>apple")'],
    ],
    'COUNTIFS' => [['=COUNTIFS(Inputs!G1:G4,"apple",Inputs!H1:H4,">200")'], ['=COUNTIFS(Inputs!H1:H4,">=100",Inputs!H1:H4,"<=300")'], ['=COUNTIFS(Inputs!G1:G4,"~*")']],
    'MEDIAN' => [['=MEDIAN(1,2,3,4)'], ['=MEDIAN(Inputs!E1:E5)'], ['=MEDIAN(Inputs!A1:A6)']],
    'LARGE' => [['=LARGE(Inputs!E1:E5,2)'], ['=LARGE(Inputs!E1:E5,6)'], ['=LARGE({1,2,3},1)']],
    'SMALL' => [['=SMALL(Inputs!E1:E5,1)'], ['=SMALL(Inputs!E1:E5,0)'], ['=SMALL(Inputs!A1:A6,2)']],

    'IF' => [['=IF(TRUE,1,2)'], ['=IF(0,1,2)'], ['=IF("x",1,2)', '', ['H-18']], ['=IF(FALSE,1)'], ['=IF(1/0,1,2)'], ['=IF(TRUE,1,1/0)', 'The untaken branch is not evaluated.']],
    'IFS' => [['=IFS(FALSE,1,TRUE,2)'], ['=IFS(FALSE,1)'], ['=IFS(1>2,"a",2>1,"b")']],
    'SWITCH' => [['=SWITCH(2,1,"one",2,"two")'], ['=SWITCH(3,1,"one","default")'], ['=SWITCH(3,1,"one")'], ['=SWITCH("A","a","lower","other")', '', ['H-14']]],
    'IFERROR' => [['=IFERROR(1/0,"x")'], ['=IFERROR(5,"x")'], ['=IFERROR(NA())', 'value_if_error omitted.', ['H-31']]],
    'IFNA' => [['=IFNA(NA(),"x")'], ['=IFNA(1/0,"x")'], ['=IFNA(3,"x")']],
    'AND' => [['=AND(TRUE,1)'], ['=AND(TRUE,0)'], ['=AND(Inputs!A1:A6)'], ['=AND(FALSE,1/0)', '', ['H-10']], ['=AND("x")', '', ['H-18']]],
    'OR' => [['=OR(FALSE,0)'], ['=OR(TRUE,1/0)', '', ['H-10']], ['=OR(0,"TRUE")', '', ['H-18']]],
    'NOT' => [['=NOT(TRUE)'], ['=NOT(0)'], ['=NOT("x")', '', ['H-18']]],
    'XOR' => [['=XOR(TRUE,TRUE)'], ['=XOR(TRUE,FALSE,TRUE)'], ['=XOR(1,0)']],
    'TRUE' => [['=TRUE()'], ['=TRUE()+1'], ['=IF(TRUE(),"t","f")']],
    'FALSE' => [['=FALSE()'], ['=FALSE()*1'], ['=FALSE()=0']],
    'LET' => [['=LET(x,2,y,3,x*y)'], ['=LET(x,2,x+LET(x,10,x))', 'Inner x shadows outer x.'], ['=LET(a,Inputs!B1:B5,SUM(a))'], ['=LET(x,1/0,5)', 'An unused binding that errors.']],

    'VLOOKUP' => [
        ['=VLOOKUP(120,Inputs!C2:D5,2,TRUE)'],
        ['=VLOOKUP(100,Inputs!C2:D5,2,TRUE)', 'Exactly on a breakpoint.'],
        ['=VLOOKUP(0,Inputs!C2:D5,2,TRUE)', 'Below the first key.'],
        ['=VLOOKUP(1000,Inputs!C2:D5,2,TRUE)'],
        ['=VLOOKUP(50,Inputs!C2:D5,2,FALSE)'],
        ['=VLOOKUP(51,Inputs!C2:D5,2,FALSE)'],
        ['=VLOOKUP(50,Inputs!C2:D5,3,FALSE)', 'Column index past the range.'],
        ['=VLOOKUP("min_qty",Inputs!C1:D5,2,FALSE)', 'A header row is just a row.'],
        ['=VLOOKUP(120,Inputs!C1:D5,2,TRUE)', 'Approximate match with a text header in the key column.', ['H-13']],
    ],
    'HLOOKUP' => [['=HLOOKUP("b",Inputs!K1:N2,2,FALSE)'], ['=HLOOKUP("z",Inputs!K1:N2,2,FALSE)'], ['=HLOOKUP("bb",Inputs!K1:N2,2,TRUE)'], ['=HLOOKUP("a",Inputs!K1:N2,3,FALSE)']],
    'XLOOKUP' => [
        ['=XLOOKUP(50,Inputs!C2:C5,Inputs!D2:D5)'],
        ['=XLOOKUP(51,Inputs!C2:C5,Inputs!D2:D5,"none")'],
        ['=XLOOKUP(120,Inputs!C2:C5,Inputs!D2:D5,,-1)'],
        ['=XLOOKUP(120,Inputs!C2:C5,Inputs!D2:D5,,1)'],
        ['=XLOOKUP(51,Inputs!C2:C5,Inputs!D2:D5)'],
        ['=XLOOKUP("a?ple",Inputs!G1:G4,Inputs!H1:H4,,2)', 'Wildcard match mode.'],
    ],
    'INDEX' => [
        ['=INDEX(Inputs!C1:D5,3,2)'],
        ['=INDEX(Inputs!B1:B5,4)'],
        ['=INDEX(Inputs!B1:B5,9)', 'Past the range.', ['H-22']],
        ['=SUM(INDEX(Inputs!C2:D5,0,2))', 'Row 0 is the whole column.'],
        ['=SUM(INDEX(Inputs!C2:D5,,2))', 'An omitted row argument.', ['H-27']],
        ['=INDEX(Inputs!K1:N2,2,3)'],
    ],
    'MATCH' => [
        ['=MATCH(120,Inputs!C2:C5,1)'],
        ['=MATCH(50,Inputs!C2:C5,0)'],
        ['=MATCH("BANANA",Inputs!G1:G4,0)', '', ['H-14']],
        ['=MATCH(0,Inputs!C2:C5,1)'],
        ['=MATCH(15,{30,20,10},-1)'],
        ['=MATCH("b*",Inputs!G1:G4,0)', 'Wildcards in exact MATCH.'],
        ['=MATCH(120,Inputs!C2:C5)', 'Match type omitted defaults to 1.'],
    ],
    'LOOKUP' => [['=LOOKUP(120,Inputs!C2:C5,Inputs!D2:D5)'], ['=LOOKUP(0,Inputs!C2:C5,Inputs!D2:D5)'], ['=LOOKUP(120,Inputs!C2:D5)', 'The array form.']],
    'CHOOSE' => [['=CHOOSE(2,"a","b","c")'], ['=CHOOSE(4,"a","b")'], ['=CHOOSE(1.9,"a","b")', 'Truncation.'], ['=CHOOSE(0,"a")']],
    'ROWS' => [['=ROWS(Inputs!C1:D5)'], ['=ROWS({1;2;3})'], ['=ROWS(Inputs!K1:N2)']],
    'COLUMNS' => [['=COLUMNS(Inputs!C1:D5)'], ['=COLUMNS({1,2,3,4})'], ['=COLUMNS(Inputs!K1:N2)']],

    'ISBLANK' => [['=ISBLANK(Inputs!O1)'], ['=ISBLANK("")'], ['=ISBLANK(Inputs!B1)']],
    'ISERROR' => [['=ISERROR(1/0)'], ['=ISERROR(NA())'], ['=ISERROR(1)']],
    'ISERR' => [['=ISERR(NA())'], ['=ISERR(1/0)'], ['=ISERR("x")']],
    'ISNA' => [['=ISNA(NA())'], ['=ISNA(1/0)'], ['=ISNA(VLOOKUP(51,Inputs!C2:D5,2,FALSE))']],
    'ISNUMBER' => [['=ISNUMBER(1)'], ['=ISNUMBER("1")'], ['=ISNUMBER(Inputs!A2)'], ['=ISNUMBER(DATE(2026,1,1))']],
    'ISTEXT' => [['=ISTEXT("a")'], ['=ISTEXT(1)'], ['=ISTEXT(Inputs!O1)']],
    'ISLOGICAL' => [['=ISLOGICAL(TRUE)'], ['=ISLOGICAL(1)'], ['=ISLOGICAL("TRUE")']],
    'NA' => [['=NA()'], ['=NA()+1'], ['=IFNA(NA(),0)']],
    'N' => [['=N(TRUE)'], ['=N("5")'], ['=N(7)'], ['=N(Inputs!O1)']],
    'TYPE' => [['=TYPE(1)'], ['=TYPE("a")'], ['=TYPE(TRUE)'], ['=TYPE(1/0)', '', ['H-35']], ['=TYPE({1,2})', '', ['H-35']]],
    'ERROR.TYPE' => [['=ERROR.TYPE(1/0)'], ['=ERROR.TYPE(NA())'], ['=ERROR.TYPE(1)'], ['=ERROR.TYPE(SQRT(-1))'], ['=ERROR.TYPE(#REF!)'], ['=ERROR.TYPE(#VALUE!)'], ['=ERROR.TYPE(#NAME?)']],

    'CONCATENATE' => [['=CONCATENATE("a","b",1)'], ['=CONCATENATE(Inputs!G1:G2)', 'A range argument.', ['H-11']], ['=CONCATENATE(TRUE,1/3)', '', ['H-23']]],
    'TEXTJOIN' => [['=TEXTJOIN(",",TRUE,"a","","b")'], ['=TEXTJOIN(",",FALSE,"a","","b")'], ['=TEXTJOIN("-",TRUE,Inputs!A1:A6)'], ['=TEXTJOIN(", ",TRUE,Inputs!G1:G4)']],
    'LEN' => [
        ['=LEN("abc")'],
        ['=LEN("")'],
        ['=LEN("é")', 'One code point, one UTF-16 unit, two UTF-8 bytes.', ['H-15']],
        ['=LEN("'."\u{1F600}".'")', 'One code point, two UTF-16 units.', ['H-15']],
        ['=LEN(12.5)'],
        ['=LEN(REPT("a",50001))', 'Past the believed 50,000-character limit.', ['H-34']],
    ],
    'LEFT' => [['=LEFT("abcdef",2)'], ['=LEFT("abc")'], ['=LEFT("abc",10)'], ['=LEFT("abc",-1)'], ['=LEN(LEFT("'."\u{1F600}".'x",1))', 'Does LEFT split a surrogate pair?', ['H-15']]],
    'RIGHT' => [['=RIGHT("abcdef",2)'], ['=RIGHT("abc")'], ['=RIGHT(12345,2)']],
    'MID' => [['=MID("abcdef",2,3)'], ['=MID("abc",5,1)'], ['=MID("abc",0,1)'], ['=MID("abc",2,100)'], ['=MID("a'."\u{1F600}".'b",3,1)', '', ['H-15']]],
    'UPPER' => [['=UPPER("abc")'], ['=UPPER("straße")'], ['=UPPER(1)']],
    'LOWER' => [['=LOWER("ABC")'], ['=LOWER("ÀÉÎ")'], ['=LOWER(TRUE)']],
    'PROPER' => [['=PROPER("hello world")'], ['=PROPER("o\'neil mc-donald")'], ['=PROPER("ABC def2ghi")']],
    'TRIM' => [['=TRIM("  a   b  ")'], ['=LEN(TRIM(CHAR(9)&"a"))', 'Is TAB trimmed?'], ['=LEN(TRIM("a"&CHAR(160)&" b"))', 'Is a no-break space trimmed?']],
    'SUBSTITUTE' => [['=SUBSTITUTE("aaa","a","b")'], ['=SUBSTITUTE("aaa","a","b",2)'], ['=SUBSTITUTE("abc","","x")'], ['=SUBSTITUTE("aaa","a","b",0)']],
    'REPT' => [['=REPT("ab",3)'], ['=REPT("a",0)'], ['=REPT("a",-1)'], ['=REPT("a",2.9)']],
    'FIND' => [['=FIND("b","abcb")'], ['=FIND("B","abc")'], ['=FIND("b","abcb",3)'], ['=FIND("","abc")']],
    'SEARCH' => [['=SEARCH("B","abc")'], ['=SEARCH("b?","abcb")'], ['=SEARCH("~?","a?b")'], ['=SEARCH("z","abc")']],
    'EXACT' => [['=EXACT("a","A")'], ['=EXACT("a","a")'], ['=EXACT(1,"1")']],
    'VALUE' => [['=VALUE("12.5")'], ['=VALUE("5%")'], ['=VALUE("abc")'], ['=VALUE("2026-10-27")'], ['=VALUE(" 12 ")'], ['=VALUE("1,234")', '', ['H-24']]],
    'FIXED' => [['=FIXED(1234.567,1)'], ['=FIXED(1234.567,1,TRUE)'], ['=FIXED(-1234.5,-2)'], ['=FIXED(2.5,0)', '', ['H-05']]],
    'DOLLAR' => [['=DOLLAR(1234.567)'], ['=DOLLAR(-1234.567,1)'], ['=DOLLAR(0.5,0)']],
    'TEXT' => [
        ['=TEXT(1234.5,"#,##0.00")'],
        ['=TEXT(0.05,"0%")'],
        ['=TEXT(0.125,"0.0%")'],
        ['=TEXT(46322,"yyyy-mm-dd")'],
        ['=TEXT(1234.5,"$#,##0.00")'],
        ['=TEXT(2.5,"0")', '', ['H-05']],
        ['=TEXT(-1234.5,"#,##0.00;(#,##0.00)")'],
        ['=TEXT(46322,"dddd, mmmm d, yyyy")'],
        ['=TEXT(0.5,"0.00")'],
        ['=TEXT(1.005,"0.00")', 'Formatting rounds like ROUND?', ['H-05']],
    ],

    'DATE' => [['=DATE(2026,13,1)', 'Month rollover.'], ['=DATE(2026,1,0)', 'Day 0 is the last day of the previous month.'], ['=DATE(2026,2,30)'], ['=DATE(2026.9,1,1)', 'Fractional year.']],
    'YEAR' => [['=YEAR(46322)'], ['=YEAR(0)'], ['=YEAR("2026-10-27")']],
    'MONTH' => [['=MONTH(46322)'], ['=MONTH(DATE(2024,2,29))'], ['=MONTH("x")']],
    'DAY' => [['=DAY(46322)'], ['=DAY(DATE(2024,3,0))'], ['=DAY(46322.99)']],
    'EDATE' => [['=EDATE(DATE(2026,1,31),1)'], ['=EDATE(DATE(2024,1,31),1)'], ['=EDATE(DATE(2026,3,31),-1)'], ['=EDATE(DATE(2026,1,15),1.9)', 'Fractional months.']],
    'EOMONTH' => [['=EOMONTH(DATE(2026,1,15),0)'], ['=EOMONTH(DATE(2026,1,15),1)'], ['=EOMONTH(DATE(2024,1,15),1)'], ['=EOMONTH(DATE(2026,1,15),-13)']],
    'DAYS' => [['=DAYS(DATE(2026,12,25),DATE(2026,10,27))'], ['=DAYS("2026-01-02","2026-01-01")'], ['=DAYS(DATE(2026,1,1),DATE(2026,12,31))']],
    'DATEDIF' => [
        ['=DATEDIF(DATE(2020,1,15),DATE(2026,10,27),"Y")'],
        ['=DATEDIF(DATE(2020,1,15),DATE(2026,10,27),"M")'],
        ['=DATEDIF(DATE(2020,1,15),DATE(2026,10,27),"D")'],
        ['=DATEDIF(DATE(2020,1,31),DATE(2026,3,1),"MD")'],
        ['=DATEDIF(DATE(2020,1,15),DATE(2026,10,27),"YM")'],
        ['=DATEDIF(DATE(2020,1,15),DATE(2026,10,27),"YD")'],
        ['=DATEDIF(DATE(2026,1,1),DATE(2025,1,1),"D")', 'Start after end.'],
    ],
    'DATEVALUE' => [['=DATEVALUE("2026-10-27")'], ['=DATEVALUE("10/27/2026")'], ['=DATEVALUE("27/10/2026")', 'Day-first in the en_US locale.'], ['=DATEVALUE("Oct 27, 2026")']],
    'WEEKDAY' => [['=WEEKDAY(46322)'], ['=WEEKDAY(46322,2)'], ['=WEEKDAY(46322,3)'], ['=WEEKDAY(46322,11)']],
    'NETWORKDAYS' => [['=NETWORKDAYS(DATE(2026,10,1),DATE(2026,10,31))'], ['=NETWORKDAYS(DATE(2026,10,1),DATE(2026,10,31),Inputs!I1:I2)'], ['=NETWORKDAYS(DATE(2026,10,31),DATE(2026,10,1))']],
    'WORKDAY' => [['=WORKDAY(DATE(2026,10,30),1)'], ['=WORKDAY(DATE(2026,10,30),-5)'], ['=WORKDAY(DATE(2026,10,9),1,Inputs!I1:I2)']],
];

$p1 = require __DIR__.'/p1-functions.php';
foreach ($e as $fn => $rows) {
    if (! array_key_exists($fn, $p1) || $p1[$fn] === null) {
        throw new RuntimeException("E group {$fn} is not a captured P1 function.");
    }
    foreach ($rows as $row) {
        $c[] = ['E', $p1[$fn], $row[0], $row[1] ?? '', $row[2] ?? [], $fn];
    }
}

// --- A: Appendix A ----------------------------------------------------------
foreach ($pricingCases as $case => $_) {
    $all = $pricing($pricingColumns[$case]);
    $wanted = $pricingOutputs[$case] ?? array_keys($all);
    foreach ($wanted as $output) {
        $h = match ($output) {
            'admin_price.sumproduct' => ['H-21'],
            'grid_discount.xlookup' => ['H-27'],
            'accel_pct', 'total_discount', 'line_core' => $case === 'F' ? ['H-22'] : [],
            default => [],
        };
        $c[] = ['A', 'formula/pricing', $all[$output], "Appendix A case {$case}: {$output}.", $h, null, ['case' => $case, 'output' => $output]];
    }
}

return [
    'inputs' => $inputs,
    'hypotheses' => $hypotheses,
    'pricingFields' => $pricingFields,
    'pricingColumns' => $pricingColumns,
    'cases' => $c,
];
