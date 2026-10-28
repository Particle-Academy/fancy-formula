<?php

declare(strict_types=1);

use FancyFormula\FormulaError;
use FancyFormula\StubEngine;
use FancyFormula\Tests\Support\SchemaValidator;

/*
 * The schemas in schema/ are the contract Prism's doMath builds against. These
 * tests hold three things to it:
 *
 *   1. every example in every schema validates (the examples ARE documentation,
 *      and a wrong example teaches the wrong shape);
 *   2. a set of documents that must NOT validate is refused -- without these
 *      the first test could pass against a validator that accepts anything;
 *   3. the stable codes agree across FormulaError, the schemas and SPEC.md.
 */

const SCHEMAS = ['request', 'check-report', 'evaluation', 'trace'];

function schemaDir(): string
{
    return dirname(__DIR__, 2).'/schema';
}

function validator(): SchemaValidator
{
    return new SchemaValidator(schemaDir());
}

/** @return list<mixed> */
function examplesOf(string $schema): array
{
    $doc = validator()->document("{$schema}.schema.json");

    return $doc->examples ?? [];
}

test('every schema has examples', function (string $schema) {
    expect(count(examplesOf($schema)))->toBeGreaterThanOrEqual(1);
})->with(SCHEMAS);

test('every example validates against its own schema', function (string $schema) {
    $v = validator();
    foreach (examplesOf($schema) as $i => $example) {
        $errors = $v->validate($example, "{$schema}.schema.json");
        expect($errors)->toBe([], "{$schema} example {$i}: ".implode('; ', $errors));
    }
})->with(SCHEMAS);

test('every schema declares itself as part of the Prism contract', function (string $schema) {
    $doc = validator()->document("{$schema}.schema.json");
    expect(str_contains($doc->description, 'CONTRACT, spec version 1'))->toBeTrue("{$schema} does not say it is the contract");
})->with(SCHEMAS);

/*
 * Each of these differs from a valid example in ONE way, and is refused for
 * that way. A validator (or a schema) that let any of them through would make
 * the passing examples above meaningless.
 */
dataset('invalid documents', [
    'request: a clock smuggled in' => ['request', '{"version":1,"formulas":{"x":"=NOW()"},"clock":"2026-10-27T09:00:00"}'],
    'request: a seed smuggled into limits' => ['request', '{"version":1,"formulas":{"x":"=RAND()"},"limits":{"seed":"abc"}}'],
    'request: loosening a limit past the ceiling' => ['request', '{"version":1,"formulas":{"x":"=1"},"limits":{"maxSteps":50000001}}'],
    'request: wrong version' => ['request', '{"version":2,"formulas":{"x":"=1"}}'],
    'request: no formulas' => ['request', '{"version":1,"formulas":{}}'],
    'request: a name that reads as a cell' => ['request', '{"version":1,"formulas":{"A1":"=1"}}'],
    'request: an address without a sheet' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"B2:C3":{"type":"range"}}}'],
    'request: a percent written as a bare numeric string' => ['request', '{"version":1,"formulas":{"x":"=d"},"inputs":{"d":{"type":"percent","value":"5"}}}'],
    'request: money without a currency' => ['request', '{"version":1,"formulas":{"x":"=p"},"inputs":{"p":{"type":"money","value":"9.75"}}}'],
    'request: a number given as a formula string' => ['request', '{"version":1,"formulas":{"x":"=n"},"inputs":{"n":{"type":"number","value":"=1+1"}}}'],
    'request: text given as a number' => ['request', '{"version":1,"formulas":{"x":"=t"},"inputs":{"t":{"type":"text","value":5}}}'],
    'request: an impossible date shape' => ['request', '{"version":1,"formulas":{"x":"=d"},"inputs":{"d":{"type":"date","value":"27/10/2026"}}}'],
    'request: an unknown constraint rule' => ['request', '{"version":1,"formulas":{"x":"=n"},"inputs":{"n":{"type":"number","constraints":{"between":[1,2]}}}}'],
    'request: a range constraint on a scalar' => ['request', '{"version":1,"formulas":{"x":"=n"},"inputs":{"n":{"type":"number","constraints":{"key":{"column":1}}}}}'],
    'request: key.sorted spelled wrong' => ['request', '{"version":1,"formulas":{"x":"=r"},"inputs":{"r":{"type":"range","constraints":{"key":{"column":1,"sorted":"asc"}}}}}'],
    'request: an unknown input type' => ['request', '{"version":1,"formulas":{"x":"=n"},"inputs":{"n":{"type":"formula","value":"=1"}}}'],
    'request: an unknown trace level' => ['request', '{"version":1,"formulas":{"x":"=1"},"trace":"everything"}'],
    'request: a provider smuggled in' => ['request', '{"version":1,"formulas":{"x":"=1"},"providers":["GOOGLEFINANCE"]}'],
    'check-report: an unknown code' => ['check-report', '{"ok":false,"engine":"fancy-formula 0.1.0","spec":"1","diagnostics":[{"code":"FORMULA_OOPS","severity":"error","target":"x","message":"m"}]}'],
    'check-report: a spreadsheet error as a code' => ['check-report', '{"ok":false,"engine":"fancy-formula 0.1.0","spec":"1","diagnostics":[{"code":"#N/A","severity":"error","target":"x","message":"m"}]}'],
    'check-report: an unknown severity' => ['check-report', '{"ok":true,"engine":"fancy-formula 0.1.0","spec":"1","diagnostics":[{"code":"FORMULA_TYPE_COERCES","severity":"info","target":"x","message":"m"}]}'],
    'check-report: a foreign engine string' => ['check-report', '{"ok":true,"engine":"phpspreadsheet","spec":"1","diagnostics":[]}'],
    'evaluation: ok with a code' => ['evaluation', '{"ok":true,"code":"FORMULA_LIMIT_STEPS","message":"m"}'],
    'evaluation: an abort carrying partial results' => ['evaluation', '{"ok":false,"code":"FORMULA_LIMIT_STEPS","message":"m","results":{"x":{"value":{"t":"number","v":1}}}}'],
    'evaluation: a constraint refusal with no violations' => ['evaluation', '{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[]}'],
    'evaluation: a parse code as an abort' => ['evaluation', '{"ok":false,"code":"FORMULA_SYNTAX","message":"m"}'],
    'evaluation: success without a fingerprint' => ['evaluation', '{"ok":true,"engine":"fancy-formula 0.1.0","spec":"1","results":{},"traces":{},"warnings":[]}'],
    'evaluation: a lambda as a value' => ['evaluation', '{"ok":true,"engine":"fancy-formula 0.1.0","spec":"1","fingerprint":"sha256:0000000000000000000000000000000000000000000000000000000000000000","results":{"x":{"value":{"t":"lambda"}}},"traces":{},"warnings":[]}'],
    'evaluation: NaN smuggled as a string number' => ['evaluation', '{"ok":true,"engine":"fancy-formula 0.1.0","spec":"1","fingerprint":"sha256:0000000000000000000000000000000000000000000000000000000000000000","results":{"x":{"value":{"t":"number","v":"NaN"}}},"traces":{},"warnings":[]}'],
    'evaluation: an unknown error value' => ['evaluation', '{"ok":true,"engine":"fancy-formula 0.1.0","spec":"1","fingerprint":"sha256:0000000000000000000000000000000000000000000000000000000000000000","results":{"x":{"value":{"t":"error","v":"#CIRC!"}}},"traces":{},"warnings":[]}'],
    'evaluation: an unknown violation rule' => ['evaluation', '{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"x","rule":"vibes"}]}'],
    'trace: a 0-based row' => ['trace', '{"formula":"=1","value":{"t":"number","v":1},"reads":[],"dependsOn":[],"steps":[{"id":1,"fn":"VLOOKUP","span":[1,2],"lookup":{"key":{"t":"number","v":1},"range":"r","match":{"row":0,"column":1,"cell":null},"sorted":true,"probes":1},"result":{"t":"number","v":1}}],"volatile":{"clock":null,"randomDraws":0},"external":[],"truncated":false}'],
    'trace: missing truncated' => ['trace', '{"formula":"=1","value":{"t":"number","v":1},"reads":[],"dependsOn":[],"steps":[],"volatile":{"clock":null,"randomDraws":0},"external":[]}'],
    'trace: matchedRows past the cap' => ['trace', '{"formula":"=1","value":{"t":"number","v":1},"reads":[],"dependsOn":[],"steps":[{"id":1,"fn":"SUMIF","span":[1,2],"criteria":[{"criteria":">1","parsed":{"op":">","value":1},"matchedRows":['.implode(',', range(1, 1001)).']}],"result":{"t":"number","v":1}}],"volatile":{"clock":null,"randomDraws":0},"external":[],"truncated":false}'],
    'trace: a lowercase function name' => ['trace', '{"formula":"=1","value":{"t":"number","v":1},"reads":[],"dependsOn":[],"steps":[{"id":1,"fn":"vlookup","span":[1,2],"result":{"t":"number","v":1}}],"volatile":{"clock":null,"randomDraws":0},"external":[],"truncated":false}'],
]);

test('documents that must not validate are refused', function (string $schema, string $json) {
    $errors = validator()->validate(SchemaValidator::decode($json), "{$schema}.schema.json");
    expect($errors)->not->toBe([]);
})->with('invalid documents');

/*
 * The three additive rules MOIC's pricing model asked for (2026-10-28):
 * requiredWhen, columnConstraints (with oneOfColumn + where), and array-typed
 * outputs. Each valid document below was REFUSED by the schemas before the
 * addition; each near-miss differs from a valid one in one way and is refused
 * for that way. The near-misses exist to fail against an over-permissive
 * schema -- for instance one that accepted any object under columnConstraints.
 */
dataset('additive valid documents', [
    'request: requiredWhen on a money input' => ['request', '{"version":1,"formulas":{"x":"=admin_price_entered"},"inputs":{"admin_mode":{"type":"text","value":"entered","constraints":{"required":true,"oneOf":["entered","list"]}},"admin_price_entered":{"type":"money","currency":"USD","value":"950","constraints":{"requiredWhen":{"input":"admin_mode","oneOf":["entered"]},"min":0}}}}'],
    'request: requiredWhen on every scalar type' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"n":{"type":"number","constraints":{"requiredWhen":{"input":"b","oneOf":[true]}}},"p":{"type":"percent","constraints":{"requiredWhen":{"input":"b","oneOf":[true]}}},"t":{"type":"text","constraints":{"requiredWhen":{"input":"n","oneOf":[1,2]}}},"b":{"type":"boolean","constraints":{"requiredWhen":{"input":"t","oneOf":["y"]}}},"d":{"type":"date","constraints":{"requiredWhen":{"input":"t","oneOf":["y"]}}},"dt":{"type":"datetime","constraints":{"requiredWhen":{"input":"t","oneOf":["y"]}}}}}'],
    'request: requiredWhen on a range, controller bound by address' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"Inputs!B2":{"type":"text","value":"custom"},"custom_grid":{"type":"range","constraints":{"requiredWhen":{"input":"Inputs!B2","oneOf":["custom"]}}}}}'],
    'request: columnConstraints by header and by index, oneOfColumn with where' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"modules":{"type":"range","header":true,"columns":["text","text","text","number","money"],"currency":"USD","rows":[["name","kind","core_module","seats","price"],["Core","Core",null,10,"1000"],["Adv A","Advanced","Core",5,"400"]],"constraints":{"columnConstraints":{"name":{"required":true},"kind":{"required":true,"oneOf":["Core","Advanced"]},"core_module":{"oneOfColumn":{"column":"name","where":{"column":"kind","oneOf":["Core"]}}},"4":{"required":true,"integer":true,"min":0,"max":10000},"price":{"min":0}}}}}}'],
    'request: oneOfColumn into another range by index' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"catalog":{"type":"range","rows":[["Core"],["Adv A"]]},"lines":{"type":"table","table":{},"constraints":{"columnConstraints":{"1":{"oneOfColumn":{"input":"catalog","column":1}}}}}}}'],
    'request: column min and max as dates' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","columns":["date"],"constraints":{"columnConstraints":{"1":{"min":"2026-01-01","max":"2026-12-31"}}}}}}'],
    'request: an array output' => ['request', '{"version":1,"formulas":{"lines":"=FILTER(modules, seats > 0)"},"outputs":{"lines":{"type":"array","columns":["text","text","text","number","money"],"currency":"USD","maxRows":50}}}'],
    'request: an array output without maxRows' => ['request', '{"version":1,"formulas":{"lines":"=SORT(r)"},"outputs":{"lines":{"type":"array","columns":["text"]}}}'],
    'evaluation: an array result' => ['evaluation', '{"ok":true,"engine":"fancy-formula 0.2.0","spec":"1","fingerprint":"sha256:0000000000000000000000000000000000000000000000000000000000000000","results":{"lines":{"value":{"t":"array","rows":[[{"t":"string","v":"Core"},{"t":"number","v":1000,"format":{"kind":"currency","currency":"USD"}}]]}}},"traces":{},"warnings":[]}'],
    'evaluation: requiredWhen and column violations' => ['evaluation', '{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"admin_price_entered","rule":"requiredWhen","when":{"input":"admin_mode","value":"entered"}},{"input":"modules","rule":"column.oneOfColumn","row":3,"column":3,"header":"core_module","got":"Core X","range":"modules"},{"input":"modules","rule":"column.required","row":2,"column":1,"header":"name"},{"input":"modules","rule":"column.integer","row":2,"column":4,"got":2.5},{"input":"modules","rule":"column.min","row":2,"column":5,"min":0,"got":-1},{"input":"modules","rule":"column.max","row":2,"column":4,"max":10000,"got":20000},{"input":"modules","rule":"column.oneOf","row":2,"column":2,"allowed":["Core","Advanced"],"got":"Pro"}]}'],
]);

test('documents the additive rules exist to accept are accepted', function (string $schema, string $json) {
    $errors = validator()->validate(SchemaValidator::decode($json), "{$schema}.schema.json");
    expect($errors)->toBe([], implode('; ', $errors));
})->with('additive valid documents');

dataset('additive near-misses', [
    'request: requiredWhen without oneOf' => ['request', '{"version":1,"formulas":{"x":"=p"},"inputs":{"p":{"type":"number","constraints":{"requiredWhen":{"input":"mode"}}}}}'],
    'request: requiredWhen with an empty oneOf' => ['request', '{"version":1,"formulas":{"x":"=p"},"inputs":{"p":{"type":"number","constraints":{"requiredWhen":{"input":"mode","oneOf":[]}}}}}'],
    'request: requiredWhen spelled with equals sugar' => ['request', '{"version":1,"formulas":{"x":"=p"},"inputs":{"p":{"type":"number","constraints":{"requiredWhen":{"input":"mode","equals":"entered"}}}}}'],
    'request: requiredWhen naming a bare cell' => ['request', '{"version":1,"formulas":{"x":"=p"},"inputs":{"p":{"type":"number","constraints":{"requiredWhen":{"input":"B2","oneOf":[1]}}}}}'],
    'request: requiredWhen with a null in oneOf' => ['request', '{"version":1,"formulas":{"x":"=p"},"inputs":{"p":{"type":"number","constraints":{"requiredWhen":{"input":"mode","oneOf":[null]}}}}}'],
    'request: requiredWhen with a general predicate' => ['request', '{"version":1,"formulas":{"x":"=p"},"inputs":{"p":{"type":"number","constraints":{"requiredWhen":{"input":"mode","oneOf":[1],"op":">"}}}}}'],
    'request: columnConstraints on a scalar' => ['request', '{"version":1,"formulas":{"x":"=n"},"inputs":{"n":{"type":"number","constraints":{"columnConstraints":{"1":{"required":true}}}}}}'],
    'request: a column key of 0' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"0":{"required":true}}}}}}'],
    'request: a zero-padded column key' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"01":{"required":true}}}}}}'],
    'request: an empty column key' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"":{"required":true}}}}}}'],
    'request: uniqueness in a column (it lives in key)' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"1":{"unique":true}}}}}}'],
    'request: sortedness in a column (it lives in key)' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"1":{"sorted":"ascending"}}}}}}'],
    'request: a type in a column (it lives in columns)' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"1":{"type":"number"}}}}}}'],
    'request: an empty column oneOf' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"1":{"oneOf":[]}}}}}}'],
    'request: a column min that is neither a number nor a date' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"1":{"min":"low"}}}}}}'],
    'request: oneOfColumn without a column' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"2":{"oneOfColumn":{"input":"catalog"}}}}}}}'],
    'request: oneOfColumn with column 0' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"2":{"oneOfColumn":{"column":0}}}}}}}'],
    'request: oneOfColumn with a numeric-string column' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"2":{"oneOfColumn":{"column":"1"}}}}}}}'],
    'request: where without oneOf' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"3":{"oneOfColumn":{"column":1,"where":{"column":2}}}}}}}}'],
    'request: where with a general predicate' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"3":{"oneOfColumn":{"column":1,"where":{"column":2,"op":">","value":5}}}}}}}}'],
    'request: where with a second condition' => ['request', '{"version":1,"formulas":{"x":"=1"},"inputs":{"r":{"type":"range","constraints":{"columnConstraints":{"3":{"oneOfColumn":{"column":1,"where":[{"column":2,"oneOf":["Core"]}]}}}}}}}'],
    'request: an array output without columns' => ['request', '{"version":1,"formulas":{"x":"=r"},"outputs":{"x":{"type":"array"}}}'],
    'request: an array output with empty columns' => ['request', '{"version":1,"formulas":{"x":"=r"},"outputs":{"x":{"type":"array","columns":[]}}}'],
    'request: an array output with maxRows 0' => ['request', '{"version":1,"formulas":{"x":"=r"},"outputs":{"x":{"type":"array","columns":["number"],"maxRows":0}}}'],
    'request: an array output nesting an array column' => ['request', '{"version":1,"formulas":{"x":"=r"},"outputs":{"x":{"type":"array","columns":["array"]}}}'],
    'request: a scalar output with columns' => ['request', '{"version":1,"formulas":{"x":"=r"},"outputs":{"x":{"type":"number","columns":["number"]}}}'],
    'request: a scalar output with maxRows' => ['request', '{"version":1,"formulas":{"x":"=r"},"outputs":{"x":{"maxRows":5}}}'],
    'evaluation: an array result with a display' => ['evaluation', '{"ok":true,"engine":"fancy-formula 0.2.0","spec":"1","fingerprint":"sha256:0000000000000000000000000000000000000000000000000000000000000000","results":{"x":{"value":{"t":"array","rows":[[{"t":"number","v":1}]]},"display":"1"}},"traces":{},"warnings":[]}'],
    'evaluation: a column rule that does not exist' => ['evaluation', '{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"r","rule":"column.unique","row":2,"column":1}]}'],
    'evaluation: a requiredWhen violation without its controller' => ['evaluation', '{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"p","rule":"requiredWhen","when":{"oneOf":["entered"]}}]}'],
    'evaluation: a column 0 in a violation' => ['evaluation', '{"ok":false,"code":"FORMULA_INPUT_CONSTRAINT","violations":[{"input":"r","rule":"column.required","row":2,"column":0}]}'],
]);

test('near-misses of the additive rules are refused', function (string $schema, string $json) {
    $errors = validator()->validate(SchemaValidator::decode($json), "{$schema}.schema.json");
    expect($errors)->not->toBe([]);
})->with('additive near-misses');

test('the stub returns a schema-valid ARRAY result for an array output', function () {
    $request = [
        'version' => 1,
        'formulas' => ['lines' => '=FILTER(modules, seats > 0)', 'total' => '=SUM(seats)'],
        'outputs' => ['lines' => ['type' => 'array', 'columns' => ['text', 'number', 'money'], 'currency' => 'USD'], 'total' => []],
    ];
    $result = (new StubEngine)->evaluate($request);
    expect(validator()->validate(SchemaValidator::fromArray($result), 'evaluation.schema.json'))->toBe([]);

    $lines = $result['results']->lines['value'];
    expect($lines['t'])->toBe('array');
    expect($lines['rows'])->toHaveCount(1);
    expect($lines['rows'][0])->toHaveCount(3);
    foreach ($lines['rows'][0] as $cell) {
        expect($cell['t'])->toBe('error');
        expect($cell['v'])->toBe('#N/A');
        expect(str_contains($cell['detail'], 'stub'))->toBeTrue();
    }
    expect(array_key_exists('display', $result['results']->lines))->toBeFalse();
    expect($result['traces']->lines['value'])->toBe($lines);
    // A scalar output beside it stays a scalar.
    expect($result['results']->total['value']['t'])->toBe('error');
});

test('the validator refuses a keyword it does not support, rather than skipping it', function () {
    $dir = sys_get_temp_dir().'/ff-schema-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/x.schema.json', '{"type":"string","format":"email","dependentRequired":{}}');
    try {
        expect(fn () => (new SchemaValidator($dir))->validate('a', 'x.schema.json'))
            ->toThrow(RuntimeException::class, 'unsupported keyword');
    } finally {
        unlink($dir.'/x.schema.json');
        rmdir($dir);
    }
});

test('the stub check() output is a valid check report for every request example', function () {
    $v = validator();
    $engine = new StubEngine;
    foreach (array_keys(examplesOf('request')) as $i) {
        $report = SchemaValidator::fromArray($engine->check(StubEngine::example('request', $i)));
        expect($v->validate($report, 'check-report.schema.json'))->toBe([]);
    }
});

test('the stub evaluate() output is a valid evaluation for every request example', function () {
    $v = validator();
    $engine = new StubEngine;
    foreach (array_keys(examplesOf('request')) as $i) {
        $request = StubEngine::example('request', $i);
        $result = $engine->evaluate($request);
        $errors = $v->validate(SchemaValidator::fromArray($result), 'evaluation.schema.json');
        expect($errors)->toBe([], "request example {$i}: ".implode('; ', $errors));
    }
});

test('the stub computes nothing and says so', function () {
    $result = (new StubEngine)->evaluate(StubEngine::example('request', 0));
    expect($result['engine'])->toBe('fancy-formula 0.0.0-stub');
    foreach ((array) $result['results'] as $binding => $r) {
        expect($r['value']['t'])->toBe('error');
        expect($r['value']['v'])->toBe('#N/A');
        expect(str_contains($r['value']['detail'], 'stub'))->toBeTrue();
    }
    expect(array_keys((array) $result['results']))->toBe(['Quote!E5', 'unit_price']);
});

test('the stub honours trace none', function () {
    $request = ['version' => 1, 'formulas' => ['x' => '=1'], 'trace' => 'none'];
    $result = (new StubEngine)->evaluate($request);
    expect((array) $result['traces'])->toBe([]);
    expect(validator()->validate(SchemaValidator::fromArray($result), 'evaluation.schema.json'))->toBe([]);
});

test('StubEngine::example refuses a name that is not a schema', function () {
    expect(fn () => StubEngine::example('../composer'))->toThrow(InvalidArgumentException::class);
    expect(fn () => StubEngine::example('evaluation', 99))->toThrow(InvalidArgumentException::class);
});

/** @return list<string> */
function enumOf(string $file, string $pointer): array
{
    $node = validator()->document($file);
    foreach (explode('/', $pointer) as $part) {
        $node = $node->{$part};
    }

    return $node;
}

test('diagnostic codes in the schema are exactly the parse, check and warning codes', function () {
    $schema = enumOf('check-report.schema.json', '$defs/diagnosticCode/enum');
    $php = [...FormulaError::PARSE, ...FormulaError::CHECK_ERRORS, ...FormulaError::WARNINGS];
    sort($schema);
    sort($php);
    expect($schema)->toBe($php);
});

test('abort codes in the schema are exactly the limit codes', function () {
    $schema = enumOf('evaluation.schema.json', '$defs/abort/properties/code/enum');
    $php = FormulaError::LIMITS;
    sort($schema);
    sort($php);
    expect($schema)->toBe($php);
});

test('refusal codes in the schema are exactly the refusal codes', function () {
    $doc = validator()->document('evaluation.schema.json');
    $schema = [
        $doc->{'$defs'}->constraintRefusal->properties->code->const,
        $doc->{'$defs'}->checkFailed->properties->code->const,
    ];
    $php = FormulaError::REFUSALS;
    sort($schema);
    sort($php);
    expect($schema)->toBe($php);
});

test('SPEC.md names every stable code and no others', function () {
    $spec = (string) file_get_contents(dirname(__DIR__, 2).'/SPEC.md');
    preg_match_all('/\bFORMULA_[A-Z_]+\b/', $spec, $m);
    $named = array_values(array_unique($m[0]));
    $all = array_merge(...array_values(FormulaError::codes()));
    sort($named);
    sort($all);
    expect($named)->toBe($all);
});

test('FormulaError carries parse codes only, and elides a long formula', function () {
    expect(fn () => new FormulaError('m', '=1', 0, 'FORMULA_LIMIT_STEPS'))->toThrow(InvalidArgumentException::class);

    $long = '='.str_repeat('é', 200);
    $e = new FormulaError('Too long', $long, 0, 'FORMULA_TOO_LONG');
    expect($e->errorCode)->toBe('FORMULA_TOO_LONG');
    expect(mb_check_encoding($e->getMessage(), 'UTF-8'))->toBeTrue();
    expect(strlen($e->getMessage()))->toBeLessThan(200);
});
