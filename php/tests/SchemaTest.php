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
