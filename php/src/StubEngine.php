<?php

declare(strict_types=1);

namespace FancyFormula;

/**
 * A STAND-IN for the engine, so a host can be built against the frozen shapes
 * before the evaluator exists (plan phase P0). It computes NOTHING.
 *
 * - check() returns a schema-valid report with no diagnostics.
 * - evaluate() returns a schema-valid success whose every result is a `#N/A`
 *   VALUE carrying a detail that says "stub". An error value is the honest
 *   stand-in: it is a legal result, a host must already handle it, and it can
 *   never be mistaken for arithmetic. A stub that returned plausible numbers
 *   would be the most dangerous object in this repository.
 * - example() hands back the schema files' own examples, so the refusal and
 *   abort shapes can be exercised too. Those examples are validated by the
 *   test suite, so what the stub returns is what the schemas accept.
 *
 * Its engine string is `fancy-formula 0.0.0-stub`, so a stub result that leaks
 * into a log or a cache says what it is. Removed or kept as a test double when
 * the real Engine lands in 0.1.0; either way the shapes do not move.
 */
final class StubEngine
{
    public const string ENGINE = 'fancy-formula 0.0.0-stub';

    public const string SPEC = '1';

    private const string DETAIL = 'fancy-formula stub: no evaluator before 0.1.0, so nothing was computed.';

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function check(array $request): array
    {
        return [
            'ok' => true,
            'engine' => self::ENGINE,
            'spec' => self::SPEC,
            'diagnostics' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function evaluate(array $request): array
    {
        $formulas = is_array($request['formulas'] ?? null) ? $request['formulas'] : [];
        $outputs = is_array($request['outputs'] ?? null) && $request['outputs'] !== []
            ? array_keys($request['outputs'])
            : array_keys($formulas);
        $traceLevel = $request['trace'] ?? 'lookups';

        $value = ['t' => 'error', 'v' => '#N/A', 'detail' => self::DETAIL];
        $results = [];
        $traces = [];
        foreach ($outputs as $binding) {
            $binding = (string) $binding;
            $results[$binding] = ['value' => $value];
            if ($traceLevel !== 'none') {
                $traces[$binding] = [
                    'formula' => (string) ($formulas[$binding] ?? ''),
                    'value' => $value,
                    'reads' => [],
                    'dependsOn' => [],
                    'steps' => [],
                    'volatile' => ['clock' => null, 'randomDraws' => 0],
                    'external' => [],
                    'truncated' => false,
                ];
            }
        }

        return [
            'ok' => true,
            'engine' => self::ENGINE,
            'spec' => self::SPEC,
            'fingerprint' => 'sha256:'.hash('sha256', self::ENGINE."\n".json_encode($request, JSON_PRESERVE_ZERO_FRACTION)),
            'results' => (object) $results,
            'traces' => (object) $traces,
            'warnings' => [],
        ];
    }

    /**
     * One of a schema's own examples, decoded to arrays: `example('evaluation', 2)`
     * is the FORMULA_LIMIT_STEPS abort. Names are the schema file stems:
     * request, check-report, evaluation, trace.
     *
     * @return array<string, mixed>
     */
    public static function example(string $schema, int $index = 0): array
    {
        if (! preg_match('/^[a-z-]+$/', $schema)) {
            throw new \InvalidArgumentException("Unknown schema {$schema}.");
        }
        $path = dirname(__DIR__, 2)."/schema/{$schema}.schema.json";
        if (! is_file($path)) {
            throw new \InvalidArgumentException("Unknown schema {$schema}.");
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $examples = $decoded['examples'] ?? [];
        if (! isset($examples[$index])) {
            throw new \InvalidArgumentException("{$schema} has no example {$index}.");
        }

        return $examples[$index];
    }
}
