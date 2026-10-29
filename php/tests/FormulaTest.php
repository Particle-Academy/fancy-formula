<?php

declare(strict_types=1);

use FancyFormula\Formula;
use FancyFormula\FormulaError;

/*
 * Formula::parse(), ->normalized(), ->ast(), ->references(): the static
 * surface, with no data and no run. Malformed input THROWS with a stable code
 * and a byte offset; it never becomes a value.
 */

function parseError(string $source, int $maxBytes = 8192, int $maxDepth = 64): FormulaError
{
    try {
        Formula::parse($source, $maxBytes, $maxDepth);
    } catch (FormulaError $e) {
        return $e;
    }
    throw new RuntimeException("{$source} parsed, and was expected not to.");
}

dataset('normalized', [
    'canonical spacing and case' => ['=vlookup(qty,price_breaks,2,true)', '=VLOOKUP(qty, price_breaks, 2, TRUE)'],
    'leading = optional' => ['1+2*3', '=1 + 2 * 3'],
    'parentheses kept' => ['=ROUND(unit_price*qty*(1-discount),2)', '=ROUND(unit_price * qty * (1 - discount), 2)'],
    'string escape' => ['="say ""hi"""', '="say ""hi"""'],
    'sheet refs, quoted sheets, $ kept' => ["='My Sheet'!\$a\$1+Inputs!b2:c3", "='My Sheet'!\$A\$1 + Inputs!B2:C3"],
    'empty argument kept' => ['=INDEX(r,,1)', '=INDEX(r, , 1)'],
    'array literal' => ['={1,2;3,4}', '={1, 2; 3, 4}'],
    'percent and unary' => ['=-50%', '=-50%'],
    'LET' => ['=let(x,2,x*3)', '=LET(x, 2, x * 3)'],
    'open ranges' => ['=SUM(Inputs!B:B)+SUM(Inputs!1:1)', '=SUM(Inputs!B:B) + SUM(Inputs!1:1)'],
    'exponent literal' => ['=1e-3', '=1E-3'],
    'dotted function name' => ['=ceiling.math(1)', '=CEILING.MATH(1)'],
]);

test('normalized()', function (string $source, string $expected) {
    expect(Formula::parse($source)->normalized())->toBe($expected);
})->with('normalized');

test('normalized() is a fixed point', function (string $source) {
    $once = Formula::parse($source)->normalized();
    expect(Formula::parse($once)->normalized())->toBe($once);
})->with('normalized');

test('references() matches the check-report schema example', function () {
    expect(Formula::parse('=VLOOKUP(qty, price_breaks, 2, TRUE)')->references()->toArray())->toBe([
        'names' => ['price_breaks', 'qty'],
        'cells' => [],
        'ranges' => [],
        'functions' => ['VLOOKUP'],
        'volatile' => [],
        'external' => [],
        'dynamic' => false,
    ]);
});

test('references() reads BOTH branches, sorts, dedupes, and skips LET names', function () {
    $refs = Formula::parse('=IF(a > 0, LET(x, b, x + c), SWITCH(d, 1, Inputs!$B$2, Sheet2!A1:B3)) + a + TODAY() + IMPORTXML("u","x") + INDIRECT("A1")')
        ->references()->toArray();
    expect($refs['names'])->toBe(['a', 'b', 'c', 'd'])
        ->and($refs['cells'])->toBe(['Inputs!B2'])
        ->and($refs['ranges'])->toBe(['Sheet2!A1:B3'])
        ->and($refs['functions'])->toBe(['IF', 'IMPORTXML', 'INDIRECT', 'LET', 'SWITCH', 'TODAY'])
        ->and($refs['volatile'])->toBe(['TODAY'])
        ->and($refs['external'])->toBe(['IMPORTXML'])
        ->and($refs['dynamic'])->toBeTrue();
});

test('a LET name is a reference before it is bound, not after', function () {
    expect(Formula::parse('=LET(x, x + 1, x)')->references()->names)->toBe(['x']);
    expect(Formula::parse('=LET(x, 1, y, x, y)')->references()->names)->toBe([]);
});

test('LAMBDA parameters are not references', function () {
    expect(Formula::parse('=LAMBDA(n, n * rate)(3)')->references()->names)->toBe(['rate']);
});

test('ast() is plain JSON with byte offsets', function () {
    $ast = Formula::parse('=SUM(a, {1;2})')->ast();
    // JSON-clean: it survives a round trip (1.0 comes back as 1, which is equal).
    expect(json_decode((string) json_encode($ast), true))->toEqual($ast)
        ->and($ast['type'])->toBe('call')
        ->and($ast['name'])->toBe('SUM')
        ->and($ast['at'])->toBe(1)
        ->and($ast['args'][0])->toBe(['type' => 'name', 'name' => 'a', 'at' => 5])
        ->and($ast['args'][1]['type'])->toBe('array')
        ->and(count($ast['args'][1]['rows']))->toBe(2);
});

test('an EMPTY argument is a node, distinct from an omitted one', function () {
    $two = Formula::parse('=INDEX(r,,1)')->ast()['args'];
    expect(count($two))->toBe(3)->and($two[1]['type'])->toBe('empty');
    expect(count(Formula::parse('=INDEX(r,1)')->ast()['args']))->toBe(2);
});

test('LET and LAMBDA are syntax nodes', function () {
    expect(Formula::parse('=LET(x,1,x)')->ast()['type'])->toBe('let');
    expect(Formula::parse('=LAMBDA(a,b,a+b)')->ast())->toMatchArray(['type' => 'lambda', 'params' => ['a', 'b']]);
    expect(Formula::parse('=LAMBDA(a,a)(1)')->ast()['type'])->toBe('invoke');
});

test('precedence: unary binds before ^, % before ^, & below +, comparison lowest', function () {
    $ast = Formula::parse('=-2^2')->ast();
    expect($ast['type'])->toBe('binary')->and($ast['op'])->toBe('^')->and($ast['left']['type'])->toBe('unary');

    $ast = Formula::parse('=2^50%')->ast();
    expect($ast['op'])->toBe('^')->and($ast['right']['type'])->toBe('percent');

    $ast = Formula::parse('="a"&1+2')->ast();
    expect($ast['op'])->toBe('&')->and($ast['right']['op'])->toBe('+');

    $ast = Formula::parse('=1&2=12')->ast();
    expect($ast['op'])->toBe('=')->and($ast['left']['op'])->toBe('&');

    // ^ is RIGHT-associative (captured P-0003: =2^3^2 is 512): 2^(3^2).
    $ast = Formula::parse('=2^3^2')->ast();
    expect($ast['right']['op'])->toBe('^')->and($ast['left']['type'])->toBe('number');
});

dataset('syntax errors', [
    'confirmed: %% (P-0009)' => ['=10%%', 4],
    'DDE form' => ["=cmd|' /C calc'!A0", 4],
    'pipe' => ['=1|2', 2],
    'square bracket' => ['=[1]', 1],
    'brace outside an array' => ['={1,{2}}', 4],
    'ragged array' => ['={1,2;3}', 1],
    'empty array element' => ['={1,,2}', 4],
    'unterminated string' => ['="abc', 1],
    'unclosed call' => ['=SUM(1,2', 8],
    'trailing junk' => ['=1 2', 3],
    'empty formula' => ['=', 1],
    'operator with no operand' => ['=1+', 3],
    'a sheet with no cell' => ['=Inputs!', 8],
    'row 0' => ['=A0', 1],
    'LET with an even count' => ['=LET(x,1)', 1],
    'LET name that is a cell' => ['=LET(A1,1,A1)', 5],
    'semicolon outside an array' => ['=SUM(1;2)', 6],
]);

test('malformed formulas throw FORMULA_SYNTAX with a byte offset', function (string $source, int $at) {
    $e = parseError($source);
    expect($e->errorCode)->toBe('FORMULA_SYNTAX')->and($e->at)->toBe($at, $e->getMessage());
})->with('syntax errors');

test('length is checked in UTF-8 bytes before lexing', function () {
    $e = parseError('='.str_repeat('é', 5), 10);
    expect($e->errorCode)->toBe('FORMULA_TOO_LONG');
    // Any content at all: a too-long formula of garbage is TOO_LONG, not SYNTAX.
    expect(parseError(str_repeat('|', 9000))->errorCode)->toBe('FORMULA_TOO_LONG');
    expect(Formula::parse('='.str_repeat('1', 9), 10)->normalized())->toBe('='.str_repeat('1', 9));
});

test('depth uses fancy-expr\'s definition and is enforced on the way down', function () {
    // (((1))) is depth 4: every parenthesis is a level.
    expect(Formula::parse('=(((1)))', 8192, 4)->normalized())->toBe('=(((1)))');
    expect(parseError('=(((1)))', 8192, 3)->errorCode)->toBe('FORMULA_TOO_DEEP');

    // 8000 unclosed parentheses: TOO_DEEP, never a stack overflow or SYNTAX.
    expect(parseError('='.str_repeat('(', 8000))->errorCode)->toBe('FORMULA_TOO_DEEP');

    // A binary chain built in a loop is still a deep tree.
    expect(parseError('='.implode('+', array_fill(0, 66, '1')))->errorCode)->toBe('FORMULA_TOO_DEEP');
    expect(Formula::parse('='.implode('+', array_fill(0, 64, '1')))->normalized())->toStartWith('=1 + 1');

    // Width is not depth.
    expect(Formula::parse('=SUM('.implode(',', array_fill(0, 500, '1')).')')->ast()['args'])->toHaveCount(500);

    // P-0040 is legal nesting.
    expect(Formula::parse('=(((((((((1)))))))))')->normalized())->toBe('=(((((((((1)))))))))');
});

test('spans point at each call in normalized()', function () {
    $f = Formula::parse('=sumif(qtys,">=100",totals)');
    expect($f->normalized())->toBe('=SUMIF(qtys, ">=100", totals)');
    $span = array_values($f->spans())[0];
    expect($span)->toBe([1, 29])
        ->and(substr($f->normalized(), $span[0], $span[1] - $span[0]))->toBe('SUMIF(qtys, ">=100", totals)');
});

test('every trace example\'s step spans lie inside its formula and start at the function', function () {
    $doc = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/schema/trace.schema.json'), true);
    foreach ($doc['examples'] as $i => $trace) {
        foreach ($trace['steps'] as $step) {
            [$start, $end] = $step['span'];
            expect($end)->toBeLessThanOrEqual(strlen($trace['formula']), "trace example {$i}")
                ->and(substr($trace['formula'], $start, $end - $start))->toStartWith($step['fn'].'(')
                ->and(substr($trace['formula'], $end - 1, 1))->toBe(')');
            // And the engine prints the same text with the same span.
            $spans = Formula::parse($trace['formula'])->spans();
            expect(array_values($spans)[0])->toBe($step['span']);
        }
    }
});

test('#NULL! and #ERROR! are error literals (captured, H-16)', function () {
    expect(Formula::parse('=#NULL!')->ast())->toMatchArray(['type' => 'error', 'value' => '#NULL!']);
    expect(Formula::parse('=#error!')->ast())->toMatchArray(['type' => 'error', 'value' => '#ERROR!']);
});

test('a function name that looks like a cell is a function', function () {
    expect(Formula::parse('=LOG10(100)')->ast())->toMatchArray(['type' => 'call', 'name' => 'LOG10']);
    expect(Formula::parse('=AB12')->ast())->toMatchArray(['type' => 'ref', 'kind' => 'cell', 'r1' => 12, 'c1' => 28]);
});
