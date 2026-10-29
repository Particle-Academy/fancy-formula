<?php

declare(strict_types=1);

use FancyFormula\Engine;
use FancyFormula\Functions;
use FancyFormula\Limits;
use FancyFormula\Options;
use FancyFormula\Tests\Support\SchemaValidator;

/*
 * check() diagnostics, warnings, limit aborts, and the schemas' own request
 * examples run through the real engine.
 */

function schemaValidator(): SchemaValidator
{
    return new SchemaValidator(dirname(__DIR__, 2).'/schema');
}

/** @return list<string> "CODE@target" */
function codes(array $request, ?Options $options = null): array
{
    $report = (new Engine($options))->check($request);
    expect(schemaValidator()->validate(SchemaValidator::decode((string) json_encode($report)), 'check-report.schema.json'))->toBe([]);

    return array_map(static fn (array $d): string => $d['code'].'@'.$d['target'], $report->diagnostics());
}

dataset('diagnostics', [
    'syntax, with its offset' => [['version' => 1, 'formulas' => ['x' => '=1|2']], 'FORMULA_SYNTAX@x'],
    'too long' => [['version' => 1, 'formulas' => ['x' => '=1+1'], 'limits' => ['maxFormulaBytes' => 3]], 'FORMULA_TOO_LONG@x'],
    'too deep' => [['version' => 1, 'formulas' => ['x' => '=((1))'], 'limits' => ['maxDepth' => 2]], 'FORMULA_TOO_DEEP@x'],
    'unknown function' => [['version' => 1, 'formulas' => ['x' => '=FROB(1)']], 'FORMULA_UNKNOWN_FUNCTION@x'],
    'a later-slice function' => [['version' => 1, 'formulas' => ['x' => '=TEXTJOIN(",",TRUE,"a")']], 'FORMULA_UNKNOWN_FUNCTION@x'],
    'LAMBDA, parsed but not evaluated' => [['version' => 1, 'formulas' => ['x' => '=LAMBDA(a,a)(1)']], 'FORMULA_UNKNOWN_FUNCTION@x'],
    'unknown name' => [['version' => 1, 'formulas' => ['x' => '=qty*2']], 'FORMULA_UNKNOWN_NAME@x'],
    'a sheetless cell in a name-bound formula' => [['version' => 1, 'formulas' => ['x' => '=A1']], 'FORMULA_UNKNOWN_NAME@x'],
    'arity' => [['version' => 1, 'formulas' => ['x' => '=ROUND(1,2,3)']], 'FORMULA_ARITY@x'],
    'arity: SUMIFS pairs' => [['version' => 1, 'formulas' => ['x' => '=SUMIFS(Inputs!A1:A2,Inputs!B1:B2)']], 'FORMULA_ARITY@x'],
    'an array output is P2' => [['version' => 1, 'formulas' => ['x' => '=1'], 'outputs' => ['x' => ['type' => 'array', 'columns' => ['number']]]], 'FORMULA_TYPE@x'],
    'external function without a provider' => [['version' => 1, 'formulas' => ['x' => '=IMPORTXML("u","q")']], 'FORMULA_REFUSED_FUNCTION@x'],
    'needs a clock' => [['version' => 1, 'formulas' => ['x' => '=TODAY()']], 'FORMULA_NEEDS_CLOCK@x'],
    'needs a seed' => [['version' => 1, 'formulas' => ['x' => '=RANDBETWEEN(1,6)']], 'FORMULA_NEEDS_SEED@x'],
    'dynamic reference' => [['version' => 1, 'formulas' => ['x' => '=INDIRECT("A1")']], 'FORMULA_DYNAMIC_REFERENCE@x'],
    'input that does not fit its type' => [['version' => 1, 'formulas' => ['x' => '=d'], 'inputs' => ['d' => ['type' => 'date', 'value' => '2026-02-30']]], 'FORMULA_INPUT_INVALID@d'],
    'a ragged range' => [['version' => 1, 'formulas' => ['x' => '=g'], 'inputs' => ['g' => ['type' => 'range', 'rows' => [[1, 2], [3]]]]], 'FORMULA_INPUT_INVALID@g'],
    'rows that do not fit their address' => [['version' => 1, 'formulas' => ['x' => '=1'], 'inputs' => ['S!A1:A3' => ['type' => 'range', 'rows' => [[1]]]]], 'FORMULA_INPUT_INVALID@S!A1:A3'],
    'overlapping inputs that disagree' => [['version' => 1, 'formulas' => ['x' => '=1'], 'inputs' => ['S!A1:A2' => ['type' => 'range', 'rows' => [[1], [2]]], 'S!A2' => ['type' => 'number', 'value' => 9]]], 'FORMULA_INPUT_INVALID@S!A2'],
    'too many formulas' => [['version' => 1, 'formulas' => ['a' => '=1', 'b' => '=2'], 'limits' => ['maxFormulas' => 1]], 'FORMULA_INPUT_INVALID@formulas'],
    'an output naming no formula' => [['version' => 1, 'formulas' => ['x' => '=1'], 'outputs' => ['y' => []]], 'FORMULA_INPUT_INVALID@y'],
]);

test('check() reports', function (array $request, string $expected) {
    expect(codes($request))->toContain($expected);
})->with('diagnostics');

test('a cycle is FORMULA_CIRCULAR with the path closing on its first binding', function () {
    $report = (new Engine)->check(['version' => 1, 'formulas' => ['a' => '=b+1', 'b' => '=IF(TRUE, 1, c)', 'c' => '=a', 'd' => '=d']])->toArray();
    $circular = array_values(array_filter($report['diagnostics'], static fn (array $d): bool => $d['code'] === 'FORMULA_CIRCULAR'));
    expect(array_column($circular, 'related'))->toBe([['a', 'b', 'c', 'a'], ['d', 'd']]);
    // Read through a cell binding, too.
    expect(codes(['version' => 1, 'formulas' => ['S!A1' => '=S!A2', 'S!A2' => '=SUM(S!A1:A1)']]))->toContain('FORMULA_CIRCULAR@S!A1');
});

test('the syntax diagnostic carries the byte offset', function () {
    $d = (new Engine)->check(['version' => 1, 'formulas' => ['x' => '=1|2']])->diagnostics()[0];
    expect($d['offset'])->toBe(2);
});

test('warnings never stop a run: coerces, percent, currency, unsorted', function () {
    $out = (new Engine)->evaluate([
        'version' => 1,
        'formulas' => [
            'a' => '=t * 2',
            'b' => '=p',
            'c' => '=usd + eur',
            'd' => '=VLOOKUP(25, g, 2, TRUE)',
        ],
        'inputs' => [
            't' => ['type' => 'text', 'value' => '3'],
            'p' => ['type' => 'percent', 'value' => 5],
            'usd' => ['type' => 'money', 'currency' => 'USD', 'value' => 1],
            'eur' => ['type' => 'money', 'currency' => 'EUR', 'value' => 1],
            'g' => ['type' => 'range', 'rows' => [[30, 'c'], [10, 'a'], [50, 'e']]],
        ],
    ])->toArray();
    expect($out['ok'])->toBeTrue()
        ->and($out['results']['a']['value']['v'])->toBe(6);
    expect(array_map(static fn (array $w): string => $w['code'].'@'.$w['target'], $out['warnings']))->toBe([
        // Inputs are analysed first, then formulas, then runtime warnings.
        'FORMULA_PERCENT_SUSPICIOUS@p',
        'FORMULA_TYPE_COERCES@a',
        'FORMULA_CURRENCY_MIXED@c',
        'FORMULA_LOOKUP_UNSORTED@d',
    ]);
    expect($out['traces']['d']['steps'][0]['lookup']['sorted'])->toBeFalse();
});

test('every later-slice P1 function is FORMULA_UNKNOWN_FUNCTION, never a crash', function () {
    foreach (Functions::LATER as $name) {
        if (in_array($name, Functions::SEED, true) || $name === 'LAMBDA') {
            continue;
        }
        $out = (new Engine)->evaluate(['version' => 1, 'formulas' => ['x' => "={$name}(1)"]])->toArray();
        expect($out['code'])->toBe('FORMULA_CHECK_FAILED')
            ->and(array_column($out['diagnostics'], 'code'))->toContain('FORMULA_UNKNOWN_FUNCTION');
    }
});

dataset('aborts', [
    'steps' => [['version' => 1, 'formulas' => ['x' => '=SUM(1,2,3)'], 'limits' => ['maxSteps' => 2]], 'FORMULA_LIMIT_STEPS', 2],
    'range' => [['version' => 1, 'formulas' => ['x' => '=SUM(S!A1:B10)'], 'limits' => ['maxRangeCells' => 10]], 'FORMULA_LIMIT_RANGE', 10],
    'text' => [['version' => 1, 'formulas' => ['x' => '="abcdef"&"ghijkl"'], 'limits' => ['maxTextLength' => 10]], 'FORMULA_LIMIT_TEXT', 10],
    'input cells' => [['version' => 1, 'formulas' => ['x' => '=1'], 'inputs' => ['g' => ['type' => 'range', 'rows' => [[1, 2], [3, 4]]]], 'limits' => ['maxInputCells' => 3]], 'FORMULA_LIMIT_CELLS', 3],
]);

test('a broken limit aborts the whole run with no partial results', function (array $request, string $code, int $limit) {
    $evaluation = (new Engine)->evaluate($request);
    $out = $evaluation->toArray();
    expect($out['ok'])->toBeFalse()
        ->and($out['code'])->toBe($code)
        ->and($out['limit'])->toBe($limit)
        ->and($out)->not->toHaveKey('results');
    expect(schemaValidator()->validate(SchemaValidator::decode((string) json_encode($evaluation)), 'evaluation.schema.json'))->toBe([]);
})->with('aborts');

test('the wall clock is a backstop with its own code', function () {
    $rows = array_fill(0, 2000, array_fill(0, 50, 1));
    $out = (new Engine(new Options(limits: Limits::defaults()->with(wallClockMs: 1, maxRangeCells: 1_000_000))))->evaluate([
        'version' => 1,
        'formulas' => ['x' => '=SUMPRODUCT(g, g) + SUMPRODUCT(g, g) + SUMPRODUCT(g, g)'],
        'inputs' => ['g' => ['type' => 'range', 'rows' => $rows]],
    ])->toArray();
    expect($out['code'])->toBe('FORMULA_LIMIT_TIME');
});

test('the trace has its own step limit, which truncates and never aborts', function () {
    $out = (new Engine)->evaluate(['version' => 1, 'formulas' => ['x' => '=ABS(ABS(ABS(1)))'], 'trace' => 'full', 'limits' => ['maxTraceSteps' => 2]])->toArray();
    expect($out['ok'])->toBeTrue()
        ->and($out['traces']['x']['steps'])->toHaveCount(2)
        ->and($out['traces']['x']['truncated'])->toBeTrue();
});

test('trace levels: none, lookups (lookup and criteria steps only), full (every call)', function () {
    $req = ['version' => 1, 'formulas' => ['x' => '=ROUND(VLOOKUP(2, {1,10;2,20}, 2, FALSE) * 1.5, 0)']];
    $levels = [];
    foreach (['none', 'lookups', 'full'] as $level) {
        $levels[$level] = (new Engine)->evaluate($req + ['trace' => $level])->toArray()['traces'];
    }
    expect($levels['none'])->toBe([])
        ->and(array_column($levels['lookups']['x']['steps'], 'fn'))->toBe(['VLOOKUP'])
        ->and(array_column($levels['full']['x']['steps'], 'fn'))->toBe(['VLOOKUP', 'ROUND']);
});

test('the fingerprint: same request same hash, key order irrelevant, any value change changes it', function () {
    $a = ['version' => 1, 'formulas' => ['x' => '=q*2', 'y' => '=q+1'], 'inputs' => ['q' => ['type' => 'number', 'value' => 3]]];
    $b = ['inputs' => ['q' => ['value' => 3, 'type' => 'number']], 'formulas' => ['y' => '=q + 1', 'x' => '=Q*2'], 'version' => 1];
    $fa = (new Engine)->evaluate($a)->toArray()['fingerprint'];
    $fb = (new Engine)->evaluate($b)->toArray()['fingerprint'];
    expect($fa)->toMatch('/^sha256:[0-9a-f]{64}$/');
    // 'Q' resolves to the same input but is a different formula text.
    expect($fb)->not->toBe($fa);
    $b['formulas']['x'] = '=q * 2';
    expect((new Engine)->evaluate($b)->toArray()['fingerprint'])->toBe($fa);
    $a['inputs']['q']['value'] = 4;
    expect((new Engine)->evaluate($a)->toArray()['fingerprint'])->not->toBe($fa);
});

test('every request example in the schema checks and evaluates to a schema-valid result', function () {
    $examples = schemaValidator()->document('request.schema.json')->examples;
    foreach ($examples as $i => $example) {
        $request = json_decode((string) json_encode($example), true);
        $report = (new Engine)->check($request);
        expect(schemaValidator()->validate(SchemaValidator::decode((string) json_encode($report)), 'check-report.schema.json'))->toBe([], "request example {$i} check");
        $evaluation = (new Engine)->evaluate($request);
        expect(schemaValidator()->validate(SchemaValidator::decode((string) json_encode($evaluation)), 'evaluation.schema.json'))->toBe([], "request example {$i} evaluate");
    }
    // Example 0 evaluates (unit_price 9.75 at 120 units); example 1 is MOIC's grid.
    $zero = (new Engine)->evaluate(json_decode((string) json_encode($examples[0]), true))->toArray();
    expect($zero['results']['unit_price']['value'])->toBe(['t' => 'number', 'v' => 9.75])
        ->and($zero['results']['Quote!E5']['value']['v'])->toBe(1111.5);
    $one = (new Engine)->evaluate(json_decode((string) json_encode($examples[1]), true))->toArray();
    expect($one['results']['line_core']['value']['v'])->toBe(27000);
    // Example 2 has no values: check passes, evaluate refuses the missing required qty.
    $two = (new Engine)->evaluate(json_decode((string) json_encode($examples[2]), true))->toArray();
    expect($two['code'])->toBe('FORMULA_INPUT_CONSTRAINT');
    // Example 3 needs FILTER and an array output, both later: a definite refusal.
    $three = (new Engine)->evaluate(json_decode((string) json_encode($examples[3]), true))->toArray();
    expect($three['code'])->toBe('FORMULA_CHECK_FAILED');
});
