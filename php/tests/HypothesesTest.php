<?php

declare(strict_types=1);

use FancyFormula\Dates;
use FancyFormula\Formula;
use FancyFormula\FormulaError;
use FancyFormula\Tests\Support\Capture;

/*
 * Each SPEC.md hypothesis slice 1 touches, asserted through the capture row
 * that decides it: the row's OWN request (the same cell-bound inputs the
 * workbook holds) run through the engine.
 *
 * Written BEFORE the capture as the hypotheses, and moved to the captured
 * answer where the capture refuted one (fancy-conformance 0.39.0; every move
 * says "refuted" or names the captured value). ConformanceTest runs the
 * goldens themselves; this file keeps each decision named, so a mutant that
 * reverts one goes red under the hypothesis it reverts.
 *
 * Rows whose functions are a later slice (TEXT date codes, LEFT, MID, TYPE,
 * ERROR.TYPE, REPT, VALUE, FIXED, DATEVALUE, ...) are not pinned here.
 */

dataset('hypotheses', [
    // H-01 / H-02: precedence. P-0004 is CONFIRMED by the partial first capture.
    'H-01 P-0001 =-2^2' => ['H-01', 'P-0001', n(4)],
    'H-01 P-0002 =0-2^2' => ['H-01', 'P-0002', n(-4)],
    'H-01 P-0004 =-2^0.5 (confirmed #NUM!)' => ['H-01', 'P-0004', e('#NUM!')],
    'H-02 P-0003 =2^3^2 is RIGHT-associative (refuted H-02)' => ['H-02', 'P-0003', n(512)],

    // H-03: one comparator at 15 significant digits, lookups and criteria included.
    'H-03 F-0001 =0.1+0.2=0.3' => ['H-03', 'F-0001', b(true)],
    'H-03 F-0005 =(1-0.9)=0.1' => ['H-03', 'F-0005', b(true)],
    'H-03 F-0016 =1/3*3=1' => ['H-03', 'F-0016', b(true)],
    'H-03 F-0024 =SUM(0.1,0.2,0.3)=0.6' => ['H-03', 'F-0024', b(true)],
    'H-03 F-0027 MATCH exact on 0.1+0.2' => ['H-03', 'F-0027', n(1)],
    'H-03 F-0028 VLOOKUP exact on 0.1+0.2' => ['H-03', 'F-0028', s('found')],
    'H-03 F-0029 COUNTIF on 0.1+0.2' => ['H-03', 'F-0029', n(1)],
    'H-03 F-0030 =1.1*1.1=1.21' => ['H-03', 'F-0030', b(true)],
    'H-03 F-0031 =SQRT(2)^2=2' => ['H-03', 'F-0031', b(true)],
    'H-03 F-0032 =10/3*3=10' => ['H-03', 'F-0032', b(true)],
    'H-03 F-0033 =0.1+0.2>0.3' => ['H-03', 'F-0033', b(false)],
    'H-03 L-0015 approximate ORDER is the raw double' => ['H-03', 'L-0015', n(11)],

    // H-04: near-zero sums snap to 0.
    'H-04 F-0002 =(0.1+0.2)-0.3 does not snap' => ['H-04', 'F-0002', n(5.551115123125783E-17)],
    'H-04 F-0034 =(0.1+0.2)-0.3=0 is FALSE (refuted H-04)' => ['H-04', 'F-0034', b(false)],

    // H-05: rounding on the 15-digit decimal string, half away from zero.
    'H-05 F-0006 =ROUND(1.005,2)' => ['H-05', 'F-0006', n(1.01)],
    'H-05 F-0007 =ROUND(2.675,2)' => ['H-05', 'F-0007', n(2.68)],
    'H-05 F-0008 =ROUND(0.285,2)' => ['H-05', 'F-0008', n(0.29)],
    'H-05 F-0009 =ROUNDUP(0.1+0.2,1)' => ['H-05', 'F-0009', n(0.3)],
    'H-05 F-0010 =ROUNDDOWN(1-0.9,1)' => ['H-05', 'F-0010', n(0.1)],
    'H-05 F-0011 =INT((1-0.9)*10)' => ['H-05', 'F-0011', n(1)],
    'H-05 F-0012 =TRUNC((0.1+0.7)*10)' => ['H-05', 'F-0012', n(8)],
    'H-05 F-0013 =TRUNC(4.35*100)' => ['H-05', 'F-0013', n(435)],
    'H-05 F-0014 =MOD(1,0.1)' => ['H-05', 'F-0014', n(0)],
    'H-05 F-0015 =MOD(0.3,0.1) is 0' => ['H-05', 'F-0015', n(0)],
    'H-05 F-0035 =ROUND(-1.005,2)' => ['H-05', 'F-0035', n(-1.01)],
    'H-05 F-0036 =ROUND(1234567.891,-3)' => ['H-05', 'F-0036', n(1235000)],
    'H-05 E-0081 =MROUND(1.3,0.2)' => ['H-05', 'E-0081', n(1.4)],
    'H-05 E-0092 =FLOOR(1.58,0.1)' => ['H-05', 'E-0092', n(1.5)],
    'H-05 E-0375 =TEXT(2.5,"0")' => ['H-05', 'E-0375', s('3')],
    'H-05 E-0379 =TEXT(1.005,"0.00")' => ['H-05', 'E-0379', s('1.01')],

    // H-06 / H-07 / H-08: dates.
    'H-06 D-0001 =DATE(1900,2,29)' => ['H-06', 'D-0001', n(61)],
    'H-06 D-0002 1900-03-01 minus 1900-02-28' => ['H-06', 'D-0002', n(1)],
    'H-06 D-0006 =DATE(1900,1,1)' => ['H-06', 'D-0006', n(2)],
    'H-06 D-0009 =DAY(60)' => ['H-06', 'D-0009', n(28)],
    'H-06 D-0010 =MONTH(60)' => ['H-06', 'D-0010', n(2)],
    'H-07 D-0012 =YEAR(-1)' => ['H-07', 'D-0012', e('#NUM!')],
    'H-07 D-0013 =DATE(1899,12,29) reads as 3799' => ['H-07', 'D-0013', n(Dates::serial(3799, 12, 29))],
    'H-08 D-0015 =DATE(0,1,1)' => ['H-08', 'D-0015', n(2)],
    'H-08 D-0016 =DATE(99,12,31)' => ['H-08', 'D-0016', n(Dates::serial(1999, 12, 31))],
    'H-08 D-0017 =DATE(119,2,1)' => ['H-08', 'D-0017', n(Dates::serial(2019, 2, 1))],
    'H-08 D-0018 =DATE(-1,1,1)' => ['H-08', 'D-0018', e('#NUM!')],
    'H-08 D-0019 =DATE(10000,1,1)' => ['H-08', 'D-0019', e('#NUM!')],
    'H-08 D-0020 =DATE(9999,12,31)' => ['H-08', 'D-0020', n(2958465)],

    // H-09: approximate match is a binary search, sorted or not.
    'H-09 L-0001 VLOOKUP(25) over 30,10,50,20,40' => ['H-09', 'L-0001', s('a')],
    'H-09 L-0002 VLOOKUP(45) over 30,10,50,20,40' => ['H-09', 'L-0002', s('a')],
    'H-09 L-0003 MATCH(35,...,1)' => ['H-09', 'L-0003', n(2)],
    'H-09 L-0004 MATCH(5,...,1)' => ['H-09', 'L-0004', e('#N/A')],
    'H-09 L-0005 LOOKUP(25)' => ['H-09', 'L-0005', s('a')],
    'H-09 L-0006 XLOOKUP next smaller is linear (control)' => ['H-09', 'L-0006', s('b')],

    // H-10: AND and OR do not short-circuit.
    'H-10 E-0193 =AND(FALSE,1/0)' => ['H-10', 'E-0193', e('#DIV/0!')],
    'H-10 E-0196 =OR(TRUE,1/0)' => ['H-10', 'E-0196', e('#DIV/0!')],

    // H-11: an array in a scalar context takes its first element.
    'H-11 P-0023 =SUM({1,2}*{3,4})' => ['H-11', 'P-0023', n(3)],
    'H-11 E-0301 CONCATENATE joins a range (Sheets documents this)' => ['H-11', 'E-0301', s('appleBanana')],

    // H-12: aggregates coerce literals, skip text and booleans in references.
    'H-12 C-0034 =SUM(1,"2",TRUE)' => ['H-12', 'C-0034', n(4)],
    'H-12 C-0035 =SUM(1,"x")' => ['H-12', 'C-0035', e('#VALUE!')],
    'H-12 C-0036 SUM over a mixed range' => ['H-12', 'C-0036', n(15)],
    'H-12 C-0037 SUM of one cell of numeric text' => ['H-12', 'C-0037', n(0)],
    'H-12 C-0038 SUM of one cell holding TRUE' => ['H-12', 'C-0038', n(0)],
    'H-12 C-0039 =AVERAGE(1,"3")' => ['H-12', 'C-0039', n(2)],
    'H-12 C-0040 =MAX("7",2)' => ['H-12', 'C-0040', n(7)],
    'H-12 C-0041 =COUNT("1",TRUE,"x",1)' => ['H-12', 'C-0041', n(3)],
    'H-12 C-0042 =SUM({1,"2",TRUE}) (array literal like a range)' => ['H-12', 'C-0042', n(1)],
    'H-12 E-0138 =AVERAGE(1,TRUE)' => ['H-12', 'E-0138', n(1)],

    // H-13: number < text < bool, and no equality across kinds.
    'H-13 P-0013 =1&2=12' => ['H-13', 'P-0013', b(false)],
    'H-13 C-0043 =1<"a"' => ['H-13', 'C-0043', b(true)],
    'H-13 C-0044 ="a"<TRUE' => ['H-13', 'C-0044', b(true)],
    'H-13 C-0045 =1<TRUE' => ['H-13', 'C-0045', b(true)],
    'H-13 C-0055 =1=TRUE' => ['H-13', 'C-0055', b(false)],
    'H-13 C-0056 ="1"=1' => ['H-13', 'C-0056', b(false)],
    'H-13 L-0007 MATCH(TRUE) in a mixed column' => ['H-13', 'L-0007', n(3)],
    'H-13 L-0008 MATCH("20") finds the text' => ['H-13', 'L-0008', n(2)],
    'H-13 L-0009 MATCH(20) does not find the text "20"' => ['H-13', 'L-0009', e('#N/A')],
    'H-13 E-0157 COUNTIF(..., "20") matches the numeric text' => ['H-13', 'E-0157', n(1)],
    'H-13 E-0222 approximate VLOOKUP over a header row' => ['H-13', 'E-0222', n(9.75)],

    // H-14: case-insensitive text, including non-ASCII.
    'H-14 C-0046 ="a"="A"' => ['H-14', 'C-0046', b(true)],
    'H-14 C-0047 ="a"<"B"' => ['H-14', 'C-0047', b(true)],
    'H-14 C-0048 non-ASCII case folding' => ['H-14', 'C-0048', b(true)],
    'H-14 L-0020 VLOOKUP("BANANA")' => ['H-14', 'L-0020', n(200)],
    'H-14 L-0021 HLOOKUP("C")' => ['H-14', 'L-0021', n(3)],
    'H-14 E-0056 SUMIF "APPLE"' => ['H-14', 'E-0056', n(500)],
    'H-14 E-0183 SWITCH("A","a",...)' => ['H-14', 'E-0183', s('lower')],
    'H-14 E-0241 MATCH("BANANA")' => ['H-14', 'E-0241', n(2)],

    // H-15: UTF-16 code units.
    'H-15 E-0309 =LEN("é")' => ['H-15', 'E-0309', n(1)],
    'H-15 E-0310 =LEN(emoji)' => ['H-15', 'E-0310', n(2)],

    // H-18: text "TRUE"/"FALSE" only.
    'H-18 C-0026 IF("TRUE")' => ['H-18', 'C-0026', s('t')],
    'H-18 C-0027 IF("true")' => ['H-18', 'C-0027', s('t')],
    'H-18 C-0028 IF("FALSE")' => ['H-18', 'C-0028', s('f')],
    'H-18 C-0029 IF("yes")' => ['H-18', 'C-0029', e('#VALUE!')],
    'H-18 C-0032 NOT("FALSE")' => ['H-18', 'C-0032', b(true)],
    'H-18 C-0033 IF("0") is not PHP truthiness' => ['H-18', 'C-0033', e('#VALUE!')],
    'H-18 E-0173 IF("x",1,2)' => ['H-18', 'E-0173', e('#VALUE!')],
    'H-18 E-0194 AND("x")' => ['H-18', 'E-0194', e('#VALUE!')],
    'H-18 E-0197 OR(0,"TRUE")' => ['H-18', 'E-0197', b(true)],
    'H-18 E-0200 NOT("x")' => ['H-18', 'E-0200', e('#VALUE!')],

    // H-19: empty compares as 0, "", FALSE.
    'H-19 C-0006 1+empty' => ['H-19', 'C-0006', n(1)],
    'H-19 C-0017 empty&"x"' => ['H-19', 'C-0017', s('x')],
    'H-19 C-0049 empty=0' => ['H-19', 'C-0049', b(true)],
    'H-19 C-0050 empty=""' => ['H-19', 'C-0050', b(true)],
    'H-19 C-0051 empty<1' => ['H-19', 'C-0051', b(true)],
    'H-19 C-0052 empty=FALSE' => ['H-19', 'C-0052', b(true)],
    'H-19 L-0010 approximate VLOOKUP of an empty key' => ['H-19', 'L-0010', e('#N/A')],

    // H-20: the first error in evaluation order wins.
    'H-20 C-0057 =NA()+1/0' => ['H-20', 'C-0057', e('#N/A')],
    'H-20 C-0058 =1/0+NA()' => ['H-20', 'C-0058', e('#DIV/0!')],
    'H-20 C-0059 =SUM(1/0,NA())' => ['H-20', 'C-0059', e('#DIV/0!')],
    'H-20 C-0060 =CONCATENATE(NA(),1/0)' => ['H-20', 'C-0060', e('#N/A')],
    'H-20 C-0061 =MAX(1/0,NA())' => ['H-20', 'C-0061', e('#DIV/0!')],

    // H-21: references inside array literals (MOIC's SUMPRODUCT form).
    'H-21 P-0029 =SUM({B1,B2})' => ['H-21', 'P-0029', n(3)],
    'H-21 A-0011 case A admin_price.sumproduct' => ['H-21', 'A-0011', n(200)],
    'H-21 A-0025 case D admin_price.sumproduct' => ['H-21', 'A-0025', n(1400)],
    'H-21 A-0046 case H admin_price.sumproduct' => ['H-21', 'A-0046', n(2000)],

    // H-22: INDEX past the range is #REF! (MOIC's case F).
    'H-22 E-0235 =INDEX(B1:B5,9) is #NUM! (refuted H-22)' => ['H-22', 'E-0235', e('#NUM!')],
    'H-22 A-0029 case F accel_pct' => ['H-22', 'A-0029', e('#NUM!')],
    'H-22 A-0030 case F total_discount' => ['H-22', 'A-0030', e('#NUM!')],
    'H-22 A-0031 case F line_core' => ['H-22', 'A-0031', e('#NUM!')],

    // H-23: number to text in the 15-digit general form.
    'H-23 C-0015 =1/3&""' => ['H-23', 'C-0015', s('0.333333333333333')],
    'H-23 C-0019 18 digits as text, truncated' => ['H-23', 'C-0019', s('123456789012345000')],
    'H-23 C-0020 =1E+20&"" stays decimal' => ['H-23', 'C-0020', s('100000000000000000000')],
    'H-23 C-0021 =1E-7&""' => ['H-23', 'C-0021', s('0.0000001')],
    'H-23 F-0017 =1/3&""' => ['H-23', 'F-0017', s('0.333333333333333')],
    'H-23 F-0018 =2/3&"" rounds' => ['H-23', 'F-0018', s('0.666666666666667')],
    'H-23 F-0020 =(1E+16+1)&""' => ['H-23', 'F-0020', s('10000000000000000')],
    'H-23 E-0302 =CONCATENATE(TRUE,1/3)' => ['H-23', 'E-0302', s('TRUE0.333333333333333')],

    // H-24: numeric text.
    'H-24 P-0015 =--"3"' => ['H-24', 'P-0015', n(3)],
    'H-24 C-0001 =1+"2"' => ['H-24', 'C-0001', n(3)],
    'H-24 C-0002 =1+"5%"' => ['H-24', 'C-0002', n(1.05)],
    'H-24 C-0003 =1+"2026-10-27"' => ['H-24', 'C-0003', n(46323)],
    'H-24 C-0004 =1+"abc"' => ['H-24', 'C-0004', e('#VALUE!')],
    'H-24 C-0008 =1+" 2 "' => ['H-24', 'C-0008', n(3)],
    'H-24 C-0009 =1+"1e3"' => ['H-24', 'C-0009', n(1001)],
    'H-24 C-0010 =1+"$5"' => ['H-24', 'C-0010', n(6)],
    'H-24 C-0011 =1+"1,000"' => ['H-24', 'C-0011', n(1001)],
    'H-24 C-0012 =1+"(5)"' => ['H-24', 'C-0012', n(-4)],
    'H-24 C-0013 =1+"" reads empty text as 0' => ['H-24', 'C-0013', n(1)],
    'H-24 C-0014 a cell of numeric text' => ['H-24', 'C-0014', n(21)],
    'H-24 S-0008 text "-1" in arithmetic' => ['H-24', 'S-0008', n(-1)],

    // H-25: open ranges clamp to the populated extent.
    'H-25 P-0027 =SUM(B:B)' => ['H-25', 'P-0027', n(15)],
    'H-25 L-0016 =ROWS(B:B) is the 1000-row grid' => ['H-25', 'L-0016', n(1000)],
    'H-25 L-0017 =COUNTA(B:B)' => ['H-25', 'L-0017', n(5)],

    // H-27: INDEX(r,,c) is the whole column.
    'H-27 E-0237 =SUM(INDEX(r,,2))' => ['H-27', 'E-0237', n(41.65)],
    'H-27 A-0003 case A grid_discount.xlookup' => ['H-27', 'A-0003', n(0.05)],
    'H-27 A-0017 case B grid_discount.xlookup' => ['H-27', 'A-0017', n(0.16)],
    'H-27 A-0022 case C grid_discount.xlookup' => ['H-27', 'A-0022', n(0)],
    'H-27 A-0033 case G grid_discount.xlookup' => ['H-27', 'A-0033', n(0.08)],
    'H-27 A-0038 case H grid_discount.xlookup' => ['H-27', 'A-0038', n(0.2)],

    // H-28: criteria are a closed grammar.
    'H-28 S-0011 "=1+1" is the text 1+1' => ['H-28', 'S-0011', n(0)],
    'H-28 S-0012 "==1+1" is the text =1+1' => ['H-28', 'S-0012', n(1)],
    'H-28 S-0013 "=SUM(A1)" from an input' => ['H-28', 'S-0013', n(0)],
    'H-28 S-0014 SUMIF with "=SUM(A1)"' => ['H-28', 'S-0014', n(0)],

    // H-29 .. H-33.
    'H-29 E-0109 =MOD(-5,3)' => ['H-29', 'E-0109', n(1)],
    'H-29 E-0110 =MOD(5,-3)' => ['H-29', 'E-0110', n(-1)],
    'H-30 E-0084 =CEILING(-2.5,2)' => ['H-30', 'E-0084', n(-2)],
    'H-30 E-0091 =FLOOR(2.5,-2)' => ['H-30', 'E-0091', e('#NUM!')],
    'H-31 E-0186 =IFERROR(NA()) is EMPTY' => ['H-31', 'E-0186', ['t' => 'empty']],
    'H-32 E-0118 =POWER(0,0)' => ['H-32', 'E-0118', n(1)],
    'H-33 E-0063 SUMPRODUCT of different sizes' => ['H-33', 'E-0063', e('#VALUE!')],
]);

test('hypothesis', function (string $hypothesis, string $row, array $expected) {
    // The tag cannot drift: request.json must list this row under this hypothesis.
    expect(Capture::hypothesisRows($hypothesis))->toContain($row);

    expectValue(Capture::value($row), $expected, "{$row} ({$hypothesis}) ".Capture::row($row)['formula']);
})->with('hypotheses');

test('CONFIRMED P-0009: =10%% is a parse error, never a #ERROR! value', function () {
    $source = Capture::row('P-0009')['formula'];
    expect($source)->toBe('=10%%');

    expect(fn () => Formula::parse($source))->toThrow(FormulaError::class);
    try {
        Formula::parse($source);
    } catch (FormulaError $e) {
        expect($e->errorCode)->toBe('FORMULA_SYNTAX')->and($e->at)->toBe(4);
    }

    $out = Capture::value('P-0009');
    expect($out['ok'])->toBeFalse()
        ->and($out['code'])->toBe('FORMULA_CHECK_FAILED')
        ->and($out['diagnostics'][0]['code'])->toBe('FORMULA_SYNTAX');
});

test('every hypothesis pinned above names rows the capture really has', function () {
    $doc = Capture::doc();
    foreach (array_keys($doc['hypotheses']) as $h) {
        expect(preg_match('/^H-\d\d$/', $h))->toBe(1);
    }
    $ids = array_column($doc['cases'], 'id');
    foreach (['P-0004', 'P-0009', 'L-0001', 'A-0029'] as $id) {
        expect($ids)->toContain($id);
    }
});
