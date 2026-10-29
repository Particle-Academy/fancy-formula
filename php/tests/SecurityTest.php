<?php

declare(strict_types=1);

use FancyFormula\Engine;
use FancyFormula\Tests\Support\Capture;

/*
 * Inputs are DATA (SPEC.md section 11.2). A text input that looks like a
 * formula is the text itself: never lexed, parsed or evaluated. The capture
 * rows S-0001.. are Google Sheets' own answers for the same cells.
 */

dataset('text inputs are data', [
    'S-0001 =1+1 stays the text' => ['S-0001', s('=1+1')],
    'S-0002 and concatenates as text' => ['S-0002', s('=1+1')],
    'S-0003 LEN counts its four characters' => ['S-0003', n(4)],
    'S-0004 ISTEXT' => ['S-0004', b(true)],
    'S-0007 @SUM(A1) stays the text' => ['S-0007', s('@SUM(A1)')],
    'S-0021 equals the literal text' => ['S-0021', b(true)],
    'S-0023 is not a number' => ['S-0023', s('refused')],
    'S-0024 =HYPERLINK("x") is 15 characters' => ['S-0024', n(15)],
]);

test('text input', function (string $row, array $expected) {
    expectValue(Capture::value($row), $expected, $row.' '.Capture::row($row)['formula']);
})->with('text inputs are data');

test('a number input given formula text is refused at check, never evaluated', function () {
    $out = (new Engine)->evaluate([
        'version' => 1,
        'formulas' => ['r' => '=n'],
        'inputs' => ['n' => ['type' => 'number', 'value' => '=1+1']],
    ])->toArray();
    expect($out['code'])->toBe('FORMULA_CHECK_FAILED')
        ->and($out['diagnostics'][0])->toMatchArray(['code' => 'FORMULA_INPUT_INVALID', 'target' => 'n']);
});

test('a table cell\'s formula metadata is ignored and recorded', function () {
    $out = (new Engine)->evaluate([
        'version' => 1,
        'formulas' => ['r' => '=SUM(t)'],
        'inputs' => ['t' => ['type' => 'table', 'table' => ['type' => 'table', 'rows' => [
            ['cells' => [['t' => 'number', 'v' => 2, 'formula' => '=1+1'], ['t' => 'number', 'v' => 3]]],
        ]]]],
    ])->toArray();
    expect($out['results']['r']['value'])->toBe(['t' => 'number', 'v' => 5])
        ->and($out['traces']['r']['formulaMetadataIgnored'])->toBe(['t']);
});
