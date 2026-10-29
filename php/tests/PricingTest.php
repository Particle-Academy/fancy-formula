<?php

declare(strict_types=1);

use FancyFormula\Engine;
use FancyFormula\Tests\Support\Capture;
use FancyFormula\Tests\Support\SchemaValidator;

/*
 * MOIC's pricing (plan Appendix A, from #880), end to end through named
 * inputs, with MOIC's hand-computed values. fixtures/pricing-880.json is
 * MOIC's real request, unchanged: it must validate against the request
 * schema, evaluate, and give case A's numbers.
 *
 * These are the values Prism's doMath will put in a quote. They are hand
 * computed, not captured; the capture rows A-0001..A-0050 hold the same
 * formulas written with cell addresses, and the last test runs them too.
 */

/** @return array<string, mixed> */
function pricing880(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/fixtures/pricing-880.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * MOIC's request with some input values replaced.
 *
 * @param  array<string, mixed>  $values  input binding => new value
 * @return array<string, mixed>
 */
function pricingCase(array $values, bool $keepConstraints = true): array
{
    $request = pricing880();
    foreach ($values as $binding => $value) {
        $request['inputs'][$binding]['value'] = $value;
    }
    if (! $keepConstraints) {
        foreach ($request['inputs'] as &$input) {
            unset($input['constraints']);
        }
    }

    return $request;
}

/** @return array<string, mixed> binding => exchange value */
function pricingValues(array $request): array
{
    $out = (new Engine)->evaluate($request)->toArray();
    expect($out['ok'])->toBeTrue(json_encode($out));
    $values = [];
    foreach ($out['results'] as $binding => $result) {
        $values[$binding] = $result['value'];
    }

    return $values;
}

test('MOIC\'s #880 request validates against the request schema', function () {
    $errors = (new SchemaValidator(dirname(__DIR__, 2).'/schema'))->validate(SchemaValidator::fromArray(pricing880()), 'request.schema.json');
    expect($errors)->toBe([]);
});

test('MOIC\'s #880 request evaluates to a schema-valid evaluation with case A\'s numbers', function () {
    $evaluation = (new Engine)->evaluate(pricing880());
    $errors = (new SchemaValidator(dirname(__DIR__, 2).'/schema'))->validate(SchemaValidator::decode((string) json_encode($evaluation)), 'evaluation.schema.json');
    $out = $evaluation->toArray();
    expect($errors)->toBe([]);
    expect($out['ok'])->toBeTrue()->and($out['warnings'])->toBe([]);

    $r = $out['results'];
    // Appendix A case A, re-checked by claude . fancy.
    expect($r['grid_discount'])->toBe(['value' => ['t' => 'number', 'v' => 0.05, 'format' => ['kind' => 'percent']], 'display' => '0.05'])
        ->and($r['accel_pct']['value']['v'])->toBe(0.05)
        ->and($r['total_discount']['value']['v'])->toBe(0.1)
        ->and($r['line_core'])->toBe(['value' => ['t' => 'number', 'v' => 27000, 'format' => ['kind' => 'currency', 'currency' => 'USD']], 'display' => '27000'])
        ->and($r['line_adv_a']['value']['v'])->toBe(3600)
        ->and($r['line_adv_b']['value']['v'])->toBe(0)
        ->and($r['admin_price']['value']['v'])->toBe(200)
        ->and($r['line_admin']['value']['v'])->toBe(360)
        ->and($r['annual_total']['value']['v'])->toBe(30960)
        ->and($r['contract_total']['value']['v'])->toBe(92880)
        ->and($r['needs_core'])->toBe(['value' => ['t' => 'string', 'v' => '']]);

    // total_units is not an output, but the outputs that need it ran it.
    expect(array_keys($r))->not->toContain('total_units');
});

test('case A supports the footnote: the bracket is the 25-49 row, plus option 2', function () {
    $out = (new Engine)->evaluate(pricing880())->toArray();
    $grid = $out['traces']['grid_discount'];
    expect($grid['dependsOn'])->toBe(['total_units']);
    expect(array_column($grid['reads'], 'ref'))->toBe(['term_years', 'total_units', 'grid_3yr']);
    // Only the branch taken ran: one lookup, over the 3-year grid.
    expect($grid['steps'])->toHaveCount(1);
    $lookup = $grid['steps'][0]['lookup'];
    expect($grid['steps'][0]['fn'])->toBe('VLOOKUP')
        ->and($grid['steps'][0]['mode'])->toBe('approximate')
        ->and($lookup['range'])->toBe('grid_3yr')
        ->and($lookup['key'])->toBe(['t' => 'number', 'v' => 40])
        ->and($lookup['bracket'])->toBe(['from' => ['row' => 2, 'key' => 25], 'to' => ['row' => 3, 'key' => 50]])
        ->and($lookup['returned'])->toBe(['row' => 2, 'column' => 2, 'value' => ['t' => 'number', 'v' => 0.05]])
        ->and($lookup['match']['cell'])->toBeNull()
        ->and($lookup['sorted'])->toBeTrue()
        // Upper-middle binary search (captured L-0001): 50 then 25.
        ->and($lookup['probes'])->toBe(2);

    $accel = $out['traces']['accel_pct'];
    expect($accel['steps'][0]['fn'])->toBe('INDEX')
        ->and($accel['steps'][0]['lookup']['match']['row'])->toBe(2)
        ->and($accel['steps'][0]['lookup']['returned']['value'])->toBe(['t' => 'number', 'v' => 0.05])
        ->and($accel['reads'][1])->toBe(['ref' => 'accel_choice', 'kind' => 'name', 'source' => 'input', 'value' => ['t' => 'number', 'v' => 2]]);
});

test('case B: an exact breakpoint takes its own row', function () {
    $v = pricingValues(pricingCase([
        'term_years' => 5, 'seats_core' => 100, 'seats_adv_a' => 0, 'seats_adv_b' => 0, 'accel_choice' => 1,
    ]));
    expect($v['grid_discount']['v'])->toBe(0.16)
        ->and($v['total_discount']['v'])->toBe(0.16)
        ->and($v['line_core']['v'])->toBe(84000);
});

test('case C: 24 units is below the first breakpoint above 0', function () {
    $v = pricingValues(pricingCase(['term_years' => 3, 'seats_core' => 24, 'seats_adv_a' => 0, 'seats_adv_b' => 0]));
    expect($v['grid_discount']['v'])->toBe(0);
});

test('case D: admin derived from the modules it includes', function () {
    $v = pricingValues(pricingCase(['admin_mode' => 'derived', 'adv_a_in_admin' => true, 'adv_b_in_admin' => false]));
    expect($v['admin_price']['v'])->toBe(1400);
});

test('case E: advanced seats without core', function () {
    $v = pricingValues(pricingCase(['seats_core' => 0, 'seats_adv_a' => 5, 'seats_adv_b' => 0]));
    expect($v['needs_core'])->toBe(['t' => 'string', 'v' => 'Advanced modules need Core']);
});

test('case F: accel_choice 9 is REFUSED by its indexInto constraint before any formula runs', function () {
    $out = (new Engine)->evaluate(pricingCase(['accel_choice' => 9]))->toArray();
    expect($out)->toBe([
        'ok' => false,
        'code' => 'FORMULA_INPUT_CONSTRAINT',
        'message' => '1 input constraint violation across 1 input; no formula ran.',
        'violations' => [['got' => 9, 'input' => 'accel_choice', 'max' => 5, 'min' => 1, 'range' => 'accel_options', 'rule' => 'indexInto']],
    ]);
});

test('case F without the constraint: #NUM!, as Sheets says (captured A-0029), and the trace names accel_choice', function () {
    $out = (new Engine)->evaluate(pricingCase(['accel_choice' => 9], keepConstraints: false))->toArray();
    expect($out['ok'])->toBeTrue();
    $r = $out['results'];
    expect($r['accel_pct']['value']['v'])->toBe('#NUM!')
        ->and($r['total_discount']['value']['v'])->toBe('#NUM!')
        ->and($r['line_core']['value']['v'])->toBe('#NUM!')
        ->and($r['contract_total']['value']['v'])->toBe('#NUM!');
    $trace = $out['traces']['accel_pct'];
    expect(array_column($trace['reads'], 'ref'))->toContain('accel_choice');
    expect($trace['steps'][0]['lookup']['match'])->toBeNull()
        ->and($trace['steps'][0]['lookup']['key'])->toBe(['t' => 'number', 'v' => 9]);
});

test('VLOOKUP, XLOOKUP(-1) over INDEX(grid,,1) and INDEX/MATCH agree, on every breakpoint', function (int $units, int $term) {
    $grid = $term === 3 ? 'grid_3yr' : 'grid_5yr';
    $request = pricing880();
    $request['formulas'] = [
        'v' => "=VLOOKUP(units, {$grid}, 2, TRUE)",
        'x' => "=XLOOKUP(units, INDEX({$grid},,1), INDEX({$grid},,2), , -1)",
        'm' => "=INDEX({$grid}, MATCH(units, INDEX({$grid},,1), 1), 2)",
    ];
    $request['inputs'] = ['units' => ['type' => 'number', 'value' => $units], $grid => $request['inputs'][$grid]];
    $request['outputs'] = ['v' => [], 'x' => [], 'm' => []];
    $v = pricingValues($request);
    expect($v['x'])->toBe($v['v'])->and($v['m'])->toBe($v['v']);
})->with([0, 1, 24, 25, 26, 49, 50, 99, 100, 249, 250, 1000])->with([3, 5]);

dataset('pricing capture rows', [
    // case A
    'A-0001' => ['A-0001', n(40)], 'A-0002' => ['A-0002', n(0.05)], 'A-0003' => ['A-0003', n(0.05)],
    'A-0004' => ['A-0004', n(0.05)], 'A-0005' => ['A-0005', n(0.05)], 'A-0006' => ['A-0006', n(0.1)],
    'A-0007' => ['A-0007', n(27000)], 'A-0008' => ['A-0008', n(3600)], 'A-0009' => ['A-0009', n(0)],
    'A-0010' => ['A-0010', n(200)], 'A-0011' => ['A-0011', n(200)], 'A-0012' => ['A-0012', n(360)],
    'A-0013' => ['A-0013', n(30960)], 'A-0014' => ['A-0014', n(92880)], 'A-0015' => ['A-0015', s('')],
    // case B
    'A-0016' => ['A-0016', n(0.16)], 'A-0017' => ['A-0017', n(0.16)], 'A-0018' => ['A-0018', n(0.16)],
    'A-0019' => ['A-0019', n(0.16)], 'A-0020' => ['A-0020', n(84000)],
    // case C
    'A-0021' => ['A-0021', n(0)], 'A-0022' => ['A-0022', n(0)], 'A-0023' => ['A-0023', n(0)],
    // case D
    'A-0024' => ['A-0024', n(1400)], 'A-0025' => ['A-0025', n(1400)], 'A-0026' => ['A-0026', n(1400)],
    // case E
    'A-0027' => ['A-0027', n(5)], 'A-0028' => ['A-0028', s('Advanced modules need Core')],
    // case F: #NUM! in Sheets, not the #REF! MOIC expected (H-22 refuted)
    'A-0029' => ['A-0029', e('#NUM!')], 'A-0030' => ['A-0030', e('#NUM!')], 'A-0031' => ['A-0031', e('#NUM!')],
    // case G: 50 units sits on a breakpoint; option 3 is 10%
    'A-0032' => ['A-0032', n(0.08)], 'A-0033' => ['A-0033', n(0.08)], 'A-0034' => ['A-0034', n(0.08)],
    'A-0035' => ['A-0035', n(0.18)],
    // case H: 5 years, 250 units, option 5, admin derived with both modules for 3 seats
    'A-0036' => ['A-0036', n(250)], 'A-0037' => ['A-0037', n(0.2)], 'A-0038' => ['A-0038', n(0.2)],
    'A-0039' => ['A-0039', n(0.2)], 'A-0040' => ['A-0040', n(0.2)], 'A-0041' => ['A-0041', n(0.4)],
    'A-0042' => ['A-0042', n(120000)], 'A-0043' => ['A-0043', n(12000)], 'A-0044' => ['A-0044', n(0)],
    'A-0045' => ['A-0045', n(2000)], 'A-0046' => ['A-0046', n(2000)], 'A-0047' => ['A-0047', n(3600)],
    'A-0048' => ['A-0048', n(135600)], 'A-0049' => ['A-0049', n(678000)], 'A-0050' => ['A-0050', s('')],
]);

test('pricing capture row', function (string $id, array $expected) {
    expectValue(Capture::value($id), $expected, $id.' '.Capture::row($id)['note']);
})->with('pricing capture rows');

test('every formula/pricing capture row is pinned above', function () {
    $pricing = array_values(array_filter(Capture::doc()['cases'], static fn (array $c): bool => $c['suite'] === 'formula/pricing'));
    expect(count($pricing))->toBe(50);
});
