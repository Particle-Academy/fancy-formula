<?php

declare(strict_types=1);

use FancyFormula\Engine;

/*
 * Input constraints (SPEC.md section 8.2): every rule has a request that
 * passes and one that is refused with exactly the violation it should name.
 * A refusal happens BEFORE any formula runs: the formula here would be
 * #DIV/0!, and a refused request never reports it.
 */

/** @return array<string, mixed> */
function constrained(array $inputs): array
{
    return ['version' => 1, 'formulas' => ['r' => '=1/0'], 'inputs' => $inputs, 'trace' => 'none'];
}

$grid = static fn (array $rows, array $constraints = [], array $extra = []): array => ['type' => 'range', 'rows' => $rows, 'constraints' => $constraints] + $extra;
$modules = static fn (array $rows, array $columnConstraints, array $key = []): array => [
    'type' => 'range', 'header' => true, 'columns' => ['text', 'text', 'text', 'number'],
    'rows' => array_merge([['name', 'kind', 'core_module', 'seats']], $rows),
    'constraints' => ['columnConstraints' => $columnConstraints] + ($key === [] ? [] : ['key' => $key]),
];

dataset('rules', [
    'required' => [
        ['n' => ['type' => 'number', 'value' => 1, 'constraints' => ['required' => true]]],
        ['n' => ['type' => 'number', 'constraints' => ['required' => true]]],
        ['input' => 'n', 'rule' => 'required'],
    ],
    'integer' => [
        ['n' => ['type' => 'number', 'value' => 3, 'constraints' => ['integer' => true]]],
        ['n' => ['type' => 'number', 'value' => 3.5, 'constraints' => ['integer' => true]]],
        ['got' => 3.5, 'input' => 'n', 'rule' => 'integer'],
    ],
    'min' => [
        ['n' => ['type' => 'number', 'value' => 0, 'constraints' => ['min' => 0]]],
        ['n' => ['type' => 'number', 'value' => -1, 'constraints' => ['min' => 0]]],
        ['got' => -1, 'input' => 'n', 'min' => 0, 'rule' => 'min'],
    ],
    'max on a date' => [
        ['d' => ['type' => 'date', 'value' => '2026-12-31', 'constraints' => ['max' => '2026-12-31']]],
        ['d' => ['type' => 'date', 'value' => '2027-01-01', 'constraints' => ['max' => '2026-12-31']]],
        ['got' => '2027-01-01', 'input' => 'd', 'max' => '2026-12-31', 'rule' => 'max'],
    ],
    'oneOf' => [
        ['term_years' => ['type' => 'number', 'value' => 5, 'constraints' => ['oneOf' => [3, 5]]]],
        ['term_years' => ['type' => 'number', 'value' => 4, 'constraints' => ['oneOf' => [3, 5]]]],
        ['allowed' => [3, 5], 'got' => 4, 'input' => 'term_years', 'rule' => 'oneOf'],
    ],
    'oneOf is case-insensitive text, engine equality' => [
        ['mode' => ['type' => 'text', 'value' => 'ENTERED', 'constraints' => ['oneOf' => ['entered']]]],
        ['mode' => ['type' => 'text', 'value' => 'entred', 'constraints' => ['oneOf' => ['entered']]]],
        ['allowed' => ['entered'], 'got' => 'entred', 'input' => 'mode', 'rule' => 'oneOf'],
    ],
    'indexInto' => [
        ['opts' => $grid([[0], [0.05]]), 'pick' => ['type' => 'number', 'value' => 2, 'constraints' => ['indexInto' => 'opts']]],
        ['opts' => $grid([[0], [0.05]]), 'pick' => ['type' => 'number', 'value' => 3, 'constraints' => ['indexInto' => 'opts']]],
        ['got' => 3, 'input' => 'pick', 'max' => 2, 'min' => 1, 'range' => 'opts', 'rule' => 'indexInto'],
    ],
    'oneOfRange' => [
        ['opts' => $grid([[0], [0.05]]), 'pct' => ['type' => 'percent', 'value' => '5%', 'constraints' => ['oneOfRange' => 'opts']]],
        ['opts' => $grid([[0], [0.05]]), 'pct' => ['type' => 'percent', 'value' => '6%', 'constraints' => ['oneOfRange' => 'opts']]],
        ['got' => 0.06, 'input' => 'pct', 'range' => 'opts', 'rule' => 'oneOfRange'],
    ],
    'columns' => [
        ['g' => $grid([[0, 0.1]], ['columns' => ['number', 'percent']])],
        ['g' => $grid([[0, 'ten']], ['columns' => ['number', 'percent']])],
        ['allowed' => 'percent', 'column' => 2, 'got' => 'ten', 'input' => 'g', 'row' => 1, 'rule' => 'columns'],
    ],
    'type (top-level columns only)' => [
        ['g' => $grid([[0, '0.1']], [], ['columns' => ['number', 'money'], 'currency' => 'USD'])],
        ['g' => $grid([[0, 'ten']], [], ['columns' => ['number', 'money'], 'currency' => 'USD'])],
        ['allowed' => 'money', 'column' => 2, 'got' => 'ten', 'input' => 'g', 'row' => 1, 'rule' => 'type'],
    ],
    'key.sorted' => [
        ['g' => $grid([[0, 0], [25, 0.05], [50, 0.08]], ['key' => ['column' => 1, 'sorted' => 'ascending']])],
        ['g' => $grid([[0, 0], [50, 0.08], [25, 0.05]], ['key' => ['column' => 1, 'sorted' => 'ascending']])],
        ['input' => 'g', 'row' => 3, 'rule' => 'key.sorted'],
    ],
    'key.unique' => [
        ['g' => $grid([[0, 0], [25, 0.05]], ['key' => ['column' => 1, 'unique' => true]])],
        ['g' => $grid([[0, 0], [0, 0.05]], ['key' => ['column' => 1, 'unique' => true]])],
        ['got' => 0, 'input' => 'g', 'row' => 2, 'rule' => 'key.unique'],
    ],
    'requiredWhen' => [
        ['mode' => ['type' => 'text', 'value' => 'list'], 'price' => ['type' => 'money', 'currency' => 'USD', 'constraints' => ['requiredWhen' => ['input' => 'mode', 'oneOf' => ['entered']]]]],
        ['mode' => ['type' => 'text', 'value' => 'entered'], 'price' => ['type' => 'money', 'currency' => 'USD', 'constraints' => ['requiredWhen' => ['input' => 'mode', 'oneOf' => ['entered']]]]],
        ['input' => 'price', 'rule' => 'requiredWhen', 'when' => ['input' => 'mode', 'value' => 'entered']],
    ],
    'column.required' => [
        ['m' => $modules([['Platform', 'Core', null, 30]], ['name' => ['required' => true]])],
        ['m' => $modules([[null, 'Core', null, 30]], ['name' => ['required' => true]])],
        ['column' => 1, 'header' => 'name', 'input' => 'm', 'row' => 2, 'rule' => 'column.required'],
    ],
    'column.integer' => [
        ['m' => $modules([['Platform', 'Core', null, 30]], ['4' => ['integer' => true]])],
        ['m' => $modules([['Platform', 'Core', null, 30.5]], ['4' => ['integer' => true]])],
        ['column' => 4, 'got' => 30.5, 'header' => 'seats', 'input' => 'm', 'row' => 2, 'rule' => 'column.integer'],
    ],
    'column.min' => [
        ['m' => $modules([['Platform', 'Core', null, 0]], ['seats' => ['min' => 0]])],
        ['m' => $modules([['Platform', 'Core', null, -1]], ['seats' => ['min' => 0]])],
        ['column' => 4, 'got' => -1, 'header' => 'seats', 'input' => 'm', 'min' => 0, 'row' => 2, 'rule' => 'column.min'],
    ],
    'column.max' => [
        ['m' => $modules([['Platform', 'Core', null, 100]], ['seats' => ['max' => 100]])],
        ['m' => $modules([['Platform', 'Core', null, 101]], ['seats' => ['max' => 100]])],
        ['column' => 4, 'got' => 101, 'header' => 'seats', 'input' => 'm', 'max' => 100, 'row' => 2, 'rule' => 'column.max'],
    ],
    'column.oneOf' => [
        ['m' => $modules([['Platform', 'core', null, 1]], ['kind' => ['oneOf' => ['Core', 'Advanced']]])],
        ['m' => $modules([['Platform', 'Basic', null, 1]], ['kind' => ['oneOf' => ['Core', 'Advanced']]])],
        ['allowed' => ['Core', 'Advanced'], 'column' => 2, 'got' => 'Basic', 'header' => 'kind', 'input' => 'm', 'row' => 2, 'rule' => 'column.oneOf'],
    ],
    'column.oneOfColumn with where' => [
        ['m' => $modules([['Platform', 'Core', null, 1], ['Analytics', 'Advanced', 'Platform', 1]], ['core_module' => ['oneOfColumn' => ['column' => 'name', 'where' => ['column' => 'kind', 'oneOf' => ['Core']]]]])],
        ['m' => $modules([['Platform', 'Core', null, 1], ['Analytics', 'Advanced', 'Analytics', 1]], ['core_module' => ['oneOfColumn' => ['column' => 'name', 'where' => ['column' => 'kind', 'oneOf' => ['Core']]]]])],
        ['column' => 3, 'got' => 'Analytics', 'header' => 'core_module', 'input' => 'm', 'range' => 'm', 'row' => 3, 'rule' => 'column.oneOfColumn'],
    ],
]);

test('constraint rule', function (array $passing, array $failing, array $violation) {
    $ok = (new Engine)->evaluate(constrained($passing))->toArray();
    expect($ok['ok'])->toBeTrue(json_encode($ok))
        ->and($ok['results']['r']['value']['v'])->toBe('#DIV/0!');

    $refused = (new Engine)->evaluate(constrained($failing))->toArray();
    expect($refused['ok'])->toBeFalse()
        ->and($refused['code'])->toBe('FORMULA_INPUT_CONSTRAINT')
        ->and($refused['violations'])->toBe([$violation])
        ->and($refused)->not->toHaveKey('results');
})->with('rules');

test('violations come back in the canonical order whatever order they were found', function () {
    $out = (new Engine)->evaluate(constrained([
        'zeta' => ['type' => 'number', 'value' => 9, 'constraints' => ['max' => 1]],
        'alpha' => ['type' => 'number', 'constraints' => ['required' => true]],
        'grid' => ['type' => 'range', 'rows' => [[2], [1], [1]], 'constraints' => ['key' => ['column' => 1, 'sorted' => 'ascending', 'unique' => true]]],
    ]))->toArray();
    expect(array_map(static fn (array $v): string => $v['input'].':'.$v['rule'].':'.($v['row'] ?? ''), $out['violations']))
        ->toBe(['alpha:required:', 'grid:key.sorted:2', 'grid:key.unique:3', 'zeta:max:']);
});

test('an absent controller never triggers requiredWhen; required beside it always does', function () {
    $req = ['price' => ['type' => 'money', 'currency' => 'USD', 'constraints' => ['requiredWhen' => ['input' => 'mode', 'oneOf' => ['entered']]]],
        'mode' => ['type' => 'text']];
    expect((new Engine)->evaluate(constrained($req))->ok())->toBeTrue();
    $req['price']['constraints']['required'] = true;
    expect((new Engine)->evaluate(constrained($req))->toArray()['violations'])->toBe([['input' => 'price', 'rule' => 'required']]);
});

dataset('declared shape errors', [
    'min above max' => [['n' => ['type' => 'number', 'constraints' => ['min' => 5, 'max' => 1]]], 'n'],
    'oneOf outside min..max' => [['n' => ['type' => 'number', 'constraints' => ['min' => 0, 'oneOf' => [-1]]]], 'n'],
    'indexInto a missing input' => [['n' => ['type' => 'number', 'constraints' => ['indexInto' => 'nope']]], 'n'],
    'oneOfRange naming a scalar' => [['s' => ['type' => 'number'], 'n' => ['type' => 'number', 'constraints' => ['oneOfRange' => 's']]], 'n'],
    'requiredWhen on an undeclared input' => [['p' => ['type' => 'number', 'constraints' => ['requiredWhen' => ['input' => 'x', 'oneOf' => [1]]]]], 'p'],
    'requiredWhen on itself' => [['p' => ['type' => 'number', 'constraints' => ['requiredWhen' => ['input' => 'p', 'oneOf' => [1]]]]], 'p'],
    'requiredWhen on a range' => [['g' => ['type' => 'range'], 'p' => ['type' => 'number', 'constraints' => ['requiredWhen' => ['input' => 'g', 'oneOf' => [1]]]]], 'p'],
    'requiredWhen value the controller cannot hold' => [['b' => ['type' => 'boolean'], 'p' => ['type' => 'number', 'constraints' => ['requiredWhen' => ['input' => 'b', 'oneOf' => ['yes']]]]], 'p'],
    'requiredWhen value outside the controller oneOf' => [['m' => ['type' => 'text', 'constraints' => ['oneOf' => ['a']]], 'p' => ['type' => 'number', 'constraints' => ['requiredWhen' => ['input' => 'm', 'oneOf' => ['b']]]]], 'p'],
    'a header key without a header row' => [['g' => ['type' => 'range', 'columns' => ['text'], 'constraints' => ['columnConstraints' => ['name' => ['required' => true]]]]], 'g'],
    'a column index past the declared width' => [['g' => ['type' => 'range', 'columns' => ['text'], 'constraints' => ['columnConstraints' => ['3' => ['required' => true]]]]], 'g'],
    'min on a text column' => [['g' => ['type' => 'range', 'columns' => ['text'], 'constraints' => ['columnConstraints' => ['1' => ['min' => 0]]]]], 'g'],
    'integer on an undeclared column' => [['g' => ['type' => 'range', 'constraints' => ['columnConstraints' => ['1' => ['integer' => true]]]]], 'g'],
    'oneOfColumn on its own column with no where' => [['g' => ['type' => 'range', 'columns' => ['text'], 'constraints' => ['columnConstraints' => ['1' => ['oneOfColumn' => ['column' => 1]]]]]], 'g'],
    'oneOfColumn naming a missing input' => [['g' => ['type' => 'range', 'columns' => ['text'], 'constraints' => ['columnConstraints' => ['1' => ['oneOfColumn' => ['input' => 'nope', 'column' => 1]]]]]], 'g'],
    'columns and constraints.columns disagree' => [['g' => ['type' => 'range', 'columns' => ['text'], 'constraints' => ['columns' => ['number']]]], 'g'],
]);

test('check() refuses a contradictory or dangling declaration', function (array $inputs, string $target) {
    $report = (new Engine)->check(constrained($inputs))->toArray();
    expect($report['ok'])->toBeFalse();
    $codes = array_map(static fn (array $d): string => $d['code'].'@'.$d['target'], $report['diagnostics']);
    expect($codes)->toContain('FORMULA_INPUT_INVALID@'.$target);
})->with('declared shape errors');
