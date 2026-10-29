<?php

declare(strict_types=1);

use FancyFormula\Value\Values;

/*
 * Exchange-value builders for expectations, shared by every test file.
 */

function n(int|float $v): array
{
    return ['t' => 'number', 'v' => Values::jsonNumber((float) $v)];
}

function s(string $v): array
{
    return ['t' => 'string', 'v' => $v];
}

function b(bool $v): array
{
    return ['t' => 'bool', 'v' => $v];
}

function e(string $code): array
{
    return ['t' => 'error', 'v' => $code];
}

/** Numbers compare at 15 significant digits: the engine's one comparator. */
function expectValue(array $actual, array $expected, string $why): void
{
    if (($expected['t'] ?? null) === 'number' && ($actual['t'] ?? null) === 'number') {
        expect(Values::compareNumbers((float) $actual['v'], (float) $expected['v']))->toBe(0, $why.' got '.json_encode($actual));

        return;
    }
    expect($actual)->toBe($expected, $why);
}
