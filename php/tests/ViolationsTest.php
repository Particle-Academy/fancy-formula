<?php

declare(strict_types=1);

use FancyFormula\Tests\Support\SchemaValidator;
use FancyFormula\Violations;

/*
 * A FORMULA_INPUT_CONSTRAINT refusal is ordered and capped by one rule
 * (SPEC.md section 8.2.3), so the same bad input always reports the same first
 * violations in every port. These tests shuffle the engine's raw findings and
 * the member order inside each one; an order that depended on discovery order,
 * on PHP array order or on JSON object order would fail them.
 */

function vSchemas(): SchemaValidator
{
    return new SchemaValidator(dirname(__DIR__, 2).'/schema');
}

/** @param array<string, mixed> $refusal */
function vJson(array $refusal): string
{
    return json_encode($refusal, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** @return list<array<string, mixed>> n violations on one input, rows 2..n+1 */
function vRows(string $input, int $n, string $rule = 'column.required'): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = ['input' => $input, 'rule' => $rule, 'row' => $i + 2, 'column' => 1];
    }

    return $out;
}

/** @return list<array<string, mixed>> the canonical order of a mixed set, written out by hand */
function vCanonical(): array
{
    return [
        ['input' => "'My Sheet'!A1", 'rule' => 'required'],
        ['input' => 'B', 'rule' => 'oneOf', 'allowed' => [3, 5], 'got' => 4],
        ['input' => 'Sheet!A1:B3', 'rule' => 'columns', 'row' => 2, 'column' => 2],
        ['input' => '_x', 'rule' => 'requiredWhen', 'when' => ['input' => 'mode', 'value' => 'entered']],
        ['input' => 'a', 'rule' => 'min', 'min' => 0, 'got' => -1],
        // modules: no row first (rule rank), then rows, then columns, then rule rank
        ['input' => 'modules', 'rule' => 'required'],
        ['input' => 'modules', 'rule' => 'columns', 'header' => 'kind'],
        ['input' => 'modules', 'rule' => 'columns', 'header' => 'name'],
        ['input' => 'modules', 'rule' => 'columns', 'column' => 9],
        ['input' => 'modules', 'rule' => 'key.sorted', 'row' => 2],
        ['input' => 'modules', 'rule' => 'column.required', 'row' => 2, 'column' => 1, 'header' => 'name'],
        ['input' => 'modules', 'rule' => 'column.oneOf', 'row' => 2, 'column' => 2, 'header' => 'kind', 'allowed' => ['Core', 'Advanced'], 'got' => 'Pro'],
        ['input' => 'modules', 'rule' => 'column.integer', 'row' => 2, 'column' => 4, 'got' => 2.5],
        ['input' => 'modules', 'rule' => 'column.min', 'row' => 2, 'column' => 4, 'min' => 0, 'got' => 2.5],
        ['input' => 'modules', 'rule' => 'key.unique', 'row' => 3],
        ['input' => 'modules', 'rule' => 'column.oneOfColumn', 'row' => 3, 'column' => 3, 'header' => 'core_module', 'got' => 'Analytics', 'range' => 'modules'],
        ['input' => 'modules', 'rule' => 'column.oneOf', 'row' => 10, 'column' => 2, 'got' => 'x', 'allowed' => ['Core']],
        ['input' => 'modules', 'rule' => 'column.oneOf', 'row' => 10, 'column' => 2, 'got' => 'y', 'allowed' => ['Core']],
        ['input' => 'term_years', 'rule' => 'oneOf', 'allowed' => [3, 5], 'got' => 4],
    ];
}

/** @param array<string, mixed> $v */
function vReverseMembers(array $v): array
{
    $out = array_reverse($v, true);
    foreach ($out as $k => $member) {
        if (is_array($member) && ! array_is_list($member)) {
            $out[$k] = array_reverse($member, true);
        }
    }

    return $out;
}

test('the refusal is in canonical order: input by UTF-8 bytes, then row, column, rule rank, header, content', function () {
    $expected = vCanonical();
    $refusal = Violations::refusal(array_reverse($expected));
    expect(json_decode(vJson($refusal), true)['violations'])
        ->toBe(json_decode(vJson(['v' => array_map([Violations::class, 'canonicalMembers'], $expected)]), true)['v']);
});

test('the same violations in ANY discovery order give byte-identical refusals', function () {
    $expected = vJson(Violations::refusal(vCanonical()));
    for ($seed = 1; $seed <= 200; $seed++) {
        mt_srand($seed);
        $shuffled = vCanonical();
        shuffle($shuffled);
        expect(vJson(Violations::refusal($shuffled)))->toBe($expected, "seed {$seed}");
    }
});

test('member order inside a violation (JSON object order) changes nothing', function () {
    $expected = vJson(Violations::refusal(vCanonical()));
    $reordered = array_map('vReverseMembers', array_reverse(vCanonical()));
    expect(vJson(Violations::refusal($reordered)))->toBe($expected);
});

test('input order is byte order, not case-folded and not declaration order', function () {
    $names = ['b', 'a', 'B', 'A_1', '_z', "'Q'!A1", 'Z!A1', 'é'];
    $refusal = Violations::refusal(array_map(fn ($n) => ['input' => $n, 'rule' => 'required'], $names));
    expect(array_column($refusal['violations'], 'input'))
        ->toBe(["'Q'!A1", 'A_1', 'B', 'Z!A1', '_z', 'a', 'b', 'é']);
});

test('row 10 sorts after row 9: rows and columns compare as numbers', function () {
    $refusal = Violations::refusal([
        ['input' => 'r', 'rule' => 'column.required', 'row' => 10, 'column' => 1],
        ['input' => 'r', 'rule' => 'column.required', 'row' => 9, 'column' => 10],
        ['input' => 'r', 'rule' => 'column.required', 'row' => 9, 'column' => 2],
    ]);
    expect(array_map(fn ($v) => [$v['row'], $v['column']], $refusal['violations']))->toBe([[9, 2], [9, 10], [10, 1]]);
});

test('an input over 100 violations reports its first 100 and counts the rest', function () {
    $raw = vRows('grid', 150);
    shuffle($raw);
    $refusal = Violations::refusal($raw);
    expect($refusal['violations'])->toHaveCount(100);
    expect(array_column($refusal['violations'], 'row'))->toBe(range(2, 101));
    expect($refusal['truncatedViolations'])->toBe(['grid' => 50]);
    expect(array_key_exists('truncatedByRequestCap', $refusal))->toBeFalse();
    expect(vSchemas()->validate(SchemaValidator::fromArray($refusal), 'evaluation.schema.json'))->toBe([]);
});

test('exactly 100 on an input is not truncated', function () {
    $refusal = Violations::refusal(vRows('grid', 100));
    expect($refusal['violations'])->toHaveCount(100);
    expect(array_key_exists('truncatedViolations', $refusal))->toBeFalse();
});

test('the request cap keeps the first 500 in canonical order and still names every input it cut', function () {
    $raw = [];
    foreach (['in7', 'in3', 'in1', 'in6', 'in2', 'in5', 'in4'] as $name) {
        array_push($raw, ...vRows($name, 100));
    }
    array_push($raw, ...vRows('in0', 130));
    $refusal = Violations::refusal($raw);

    expect($refusal['violations'])->toHaveCount(500);
    // in0 (100 after its own cap), in1..in4 fill 500.
    expect(array_values(array_unique(array_column($refusal['violations'], 'input'))))->toBe(['in0', 'in1', 'in2', 'in3', 'in4']);
    expect($refusal['truncatedViolations'])->toBe(['in0' => 30, 'in5' => 100, 'in6' => 100, 'in7' => 100]);
    expect($refusal['truncatedByRequestCap'])->toBe(300);
    expect(vSchemas()->validate(SchemaValidator::fromArray($refusal), 'evaluation.schema.json'))->toBe([]);
});

test('the request cap splits an input: its unreported tail is counted on it', function () {
    $raw = [...vRows('a', 100), ...vRows('b', 100), ...vRows('c', 100), ...vRows('d', 100), ...vRows('e', 60), ...vRows('f', 80)];
    $refusal = Violations::refusal($raw);
    expect($refusal['violations'])->toHaveCount(500);
    expect(count(array_filter($refusal['violations'], fn ($v) => $v['input'] === 'f')))->toBe(40);
    expect($refusal['truncatedViolations'])->toBe(['f' => 40]);
    expect($refusal['truncatedByRequestCap'])->toBe(40);
});

test('reported plus truncated is always every violation found', function () {
    $raw = [...vRows('a', 250), ...vRows('b', 7), ...vRows('c', 420), ...vRows('d', 99)];
    $refusal = Violations::refusal($raw);
    $total = count($refusal['violations']) + array_sum($refusal['truncatedViolations'] ?? []);
    expect($total)->toBe(count($raw));
});

test('the truncation map is emitted in input byte order too', function () {
    $refusal = Violations::refusal([...vRows('zeta', 101), ...vRows('Alpha', 101), ...vRows('beta', 101)]);
    expect(array_keys($refusal['truncatedViolations']))->toBe(['Alpha', 'beta', 'zeta']);
});

test('the worked example in SPEC.md 8.2.3 is what the code does', function () {
    $raw = vRows('modules', 130);
    foreach (range(0, 6) as $i) {
        array_push($raw, ...vRows("in{$i}", 100));
    }
    shuffle($raw);
    $refusal = Violations::refusal($raw);
    expect(array_values(array_unique(array_column($refusal['violations'], 'input'))))->toBe(['in0', 'in1', 'in2', 'in3', 'in4']);
    expect($refusal['truncatedViolations'])->toBe(['in5' => 100, 'in6' => 100, 'modules' => 130]);
    expect($refusal['truncatedByRequestCap'])->toBe(300);

    $spec = (string) file_get_contents(dirname(__DIR__, 2).'/SPEC.md');
    expect(str_contains($spec, '`{"in5": 100, "in6": 100, "modules": 130}`'))->toBeTrue();
    expect(preg_match('/and `truncatedByRequestCap`\s+is 300\./', $spec))->toBe(1);
});

test('the rule rank lists exactly the schema violation rules', function () {
    $doc = vSchemas()->document('evaluation.schema.json');
    $schema = $doc->{'$defs'}->violation->properties->rule->enum;
    $rank = Violations::RULE_ORDER;
    sort($schema);
    sort($rank);
    expect($rank)->toBe($schema);
});

test('the caps in the schema are the caps in the code', function () {
    $doc = vSchemas()->document('evaluation.schema.json');
    expect($doc->{'$defs'}->constraintRefusal->properties->violations->maxItems)->toBe(Violations::REQUEST_CAP);
    expect(Violations::PER_INPUT_CAP)->toBe(100);
    expect(Violations::REQUEST_CAP)->toBe(500);
});

test('every constraint refusal in the schema examples is already in canonical order', function () {
    $doc = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/schema/evaluation.schema.json'), true);
    $seen = 0;
    foreach ($doc['examples'] as $i => $example) {
        if (($example['code'] ?? null) !== 'FORMULA_INPUT_CONSTRAINT') {
            continue;
        }
        $seen++;
        $sorted = Violations::refusal($example['violations']);
        expect(array_map([Violations::class, 'canonicalMembers'], $example['violations']))
            ->toBe($sorted['violations'], "evaluation example {$i}");
    }
    expect($seen)->toBeGreaterThanOrEqual(2);
});

test('a refusal needs at least one violation, each with an input and a known rule', function () {
    expect(fn () => Violations::refusal([]))->toThrow(InvalidArgumentException::class);
    expect(fn () => Violations::refusal([['input' => 'x', 'rule' => 'vibes']]))->toThrow(InvalidArgumentException::class);
    expect(fn () => Violations::refusal([['rule' => 'required']]))->toThrow(InvalidArgumentException::class);
});

dataset('truncation near-misses', [
    'a request-cap count with fewer than 500 violations' => ['{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"a","rule":"required"}],"truncatedByRequestCap":3,"truncatedViolations":{"b":3}}'],
    'a request-cap count of 0' => ['{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"a","rule":"required"}],"truncatedByRequestCap":0}'],
    'a request-cap count with no per-input counts' => ['{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"a","rule":"required"}],"truncatedByRequestCap":3}'],
    'a per-input count of 0' => ['{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"a","rule":"required"}],"truncatedViolations":{"a":0}}'],
    'an empty per-input map' => ['{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"a","rule":"required"}],"truncatedViolations":{}}'],
    'a per-input count as a list' => ['{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"a","rule":"required"}],"truncatedViolations":[5]}'],
    'a per-input count on a check failure' => ['{"ok":false,"code":"FORMULA_CHECK_FAILED","message":"m","diagnostics":[{"code":"FORMULA_SYNTAX","severity":"error","target":"x","message":"m"}],"truncatedViolations":{"a":1}}'],
]);

test('truncation near-misses are refused by the evaluation schema', function (string $json) {
    expect(vSchemas()->validate(SchemaValidator::decode($json), 'evaluation.schema.json'))->not->toBe([]);
})->with('truncation near-misses');

test('501 violations are refused by the evaluation schema', function () {
    $refusal = ['ok' => false, 'code' => 'FORMULA_INPUT_CONSTRAINT', 'violations' => vRows('a', 501)];
    expect(vSchemas()->validate(SchemaValidator::fromArray($refusal), 'evaluation.schema.json'))->not->toBe([]);
});

test('a truncated refusal at exactly the request cap validates', function () {
    $refusal = ['ok' => false, 'code' => 'FORMULA_INPUT_CONSTRAINT', 'violations' => vRows('a', 500), 'truncatedViolations' => ['a' => 9], 'truncatedByRequestCap' => 9];
    expect(vSchemas()->validate(SchemaValidator::fromArray($refusal), 'evaluation.schema.json'))->toBe([]);
});
