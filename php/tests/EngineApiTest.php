<?php

declare(strict_types=1);

use FancyFormula\CheckReport;
use FancyFormula\Engine;
use FancyFormula\Evaluation;
use FancyFormula\ExternalProvider;
use FancyFormula\FixedClock;
use FancyFormula\FunctionPolicy;
use FancyFormula\Limits;
use FancyFormula\Options;
use FancyFormula\ProviderContext;
use FancyFormula\Seed;
use FancyFormula\Tests\Support\SchemaValidator;

/*
 * The construction contract Prism codes against (SPEC.md section 1.1).
 */

function req(string $formula, array $extra = []): array
{
    return ['version' => 1, 'formulas' => ['r' => $formula]] + $extra;
}

test('new Engine() is the defaults, and check/evaluate return objects with toArray()', function () {
    $a = (new Engine)->evaluate(req('=1+1'));
    $b = (new Engine(new Options(limits: Limits::defaults(), clock: null, random: null, providers: [], functions: FunctionPolicy::all())))->evaluate(req('=1+1'));
    expect($a)->toBeInstanceOf(Evaluation::class)
        ->and($a->toArray())->toBe($b->toArray())
        ->and($a->ok())->toBeTrue()
        ->and($a->toArray()['engine'])->toBe(Engine::ENGINE)
        ->and(Engine::ENGINE)->toBe('fancy-formula 0.1.0');
    expect((new Engine)->check(req('=1+1')))->toBeInstanceOf(CheckReport::class);
});

test('json_encode of an Evaluation is schema-valid even with trace none (empty traces is {})', function () {
    $evaluation = (new Engine)->evaluate(req('=1+1', ['trace' => 'none']));
    expect($evaluation->toArray()['traces'])->toBe([])
        ->and(json_encode($evaluation))->toContain('"traces":{}');
    $errors = (new SchemaValidator(dirname(__DIR__, 2).'/schema'))->validate(SchemaValidator::decode((string) json_encode($evaluation)), 'evaluation.schema.json');
    expect($errors)->toBe([]);
});

test('a FixedClock drives TODAY and NOW, and is recorded in the trace and fingerprint', function () {
    $engine = new Engine(new Options(clock: new FixedClock('2026-10-27T18:00:00', 'Europe/London')));
    $out = $engine->evaluate(req('=TODAY()'))->toArray();
    expect($out['results']['r'])->toBe(['value' => ['t' => 'number', 'v' => 46322, 'format' => ['kind' => 'date']], 'display' => '2026-10-27'])
        ->and($out['traces']['r']['volatile']['clock'])->toBe('2026-10-27T18:00:00 Europe/London');
    $now = $engine->evaluate(req('=NOW()'))->toArray();
    expect($now['results']['r']['display'])->toBe('2026-10-27T18:00:00');
    $other = (new Engine(new Options(clock: new FixedClock('2026-10-28T18:00:00', 'Europe/London'))))->evaluate(req('=TODAY()'))->toArray();
    expect($other['fingerprint'])->not->toBe($out['fingerprint']);

    expect((new Engine)->check(req('=TODAY()'))->diagnostics()[0]['code'])->toBe('FORMULA_NEEDS_CLOCK');
    expect(fn () => new FixedClock('2026-02-30T00:00:00', 'UTC'))->toThrow(InvalidArgumentException::class);
});

test('a Seed is accepted; RAND without one needs a seed, with one is a later slice', function () {
    expect((new Engine)->check(req('=RAND()'))->diagnostics()[0]['code'])->toBe('FORMULA_NEEDS_SEED');
    $seeded = new Engine(new Options(random: Seed::fromString('quote-8812')));
    expect($seeded->check(req('=RAND()'))->diagnostics()[0]['code'])->toBe('FORMULA_UNKNOWN_FUNCTION');
    expect(strlen(Seed::fromString('quote-8812')->state()))->toBe(16);
});

test('FunctionPolicy::only and deny refuse at check()', function () {
    $only = new Engine(new Options(functions: FunctionPolicy::only(['SUM'])));
    expect($only->check(req('=SUM(1)'))->ok())->toBeTrue();
    expect($only->check(req('=ROUND(1,0)'))->diagnostics()[0]['code'])->toBe('FORMULA_REFUSED_FUNCTION');
    $deny = new Engine(new Options(functions: FunctionPolicy::all()->deny(['vlookup'])));
    expect($deny->check(req('=VLOOKUP(1,{1,2},2)'))->diagnostics()[0]['code'])->toBe('FORMULA_REFUSED_FUNCTION');
});

test('an ExternalProvider serves its function, and the call is traced and fingerprinted', function () {
    $provider = new class implements ExternalProvider
    {
        public function name(): string
        {
            return 'GOOGLEFINANCE';
        }

        public function call(array $args, ProviderContext $context): array
        {
            return ['t' => 'number', 'v' => $args[0]['v'] === 'GOOG' ? 101.5 : 0];
        }
    };
    expect((new Engine)->check(req('=GOOGLEFINANCE("GOOG")'))->diagnostics()[0]['code'])->toBe('FORMULA_REFUSED_FUNCTION');

    $out = (new Engine(new Options(providers: [$provider])))->evaluate(req('=GOOGLEFINANCE("GOOG")*2'))->toArray();
    expect($out['results']['r']['value'])->toBe(['t' => 'number', 'v' => 203])
        ->and($out['traces']['r']['external'][0])->toMatchArray([
            'fn' => 'GOOGLEFINANCE', 'args' => [['t' => 'string', 'v' => 'GOOG']], 'response' => ['t' => 'number', 'v' => 101.5],
        ]);

    expect(fn () => new Options(providers: [new class implements ExternalProvider
    {
        public function name(): string
        {
            return 'SUM';
        }

        public function call(array $args, ProviderContext $context): array
        {
            return [];
        }
    }]))->toThrow(InvalidArgumentException::class);
});

test('limits: a host loosens up to the ceiling, a request only tightens', function () {
    expect(Limits::defaults()->with(maxSteps: 2_000_000)->get('maxSteps'))->toBe(2_000_000);
    expect(fn () => Limits::defaults()->with(maxSteps: 50_000_001))->toThrow(InvalidArgumentException::class);
    expect(Limits::defaults()->tighten(['maxSteps' => 10])->get('maxSteps'))->toBe(10);
    expect(Limits::defaults()->tighten(['maxSteps' => 5_000_000])->get('maxSteps'))->toBe(1_000_000);

    $out = (new Engine)->evaluate(req('=SUM(1,2,3,4,5)', ['limits' => ['maxSteps' => 3]]))->toArray();
    expect($out)->toMatchArray(['ok' => false, 'code' => 'FORMULA_LIMIT_STEPS', 'at' => 'r', 'limit' => 3]);
});

test('Engine::functions() is the slice-1 inventory', function () {
    $names = array_column(Engine::functions(), 'name');
    expect($names)->toContain('VLOOKUP', 'XLOOKUP', 'SUMIFS', 'TEXT', 'EOMONTH', 'LET')
        ->not->toContain('TEXTJOIN', 'LEFT', 'FILTER');
    expect(Engine::functions()[0])->toHaveKeys(['name', 'category', 'phase', 'minArgs', 'maxArgs', 'volatile', 'external', 'returnsArray']);
});
