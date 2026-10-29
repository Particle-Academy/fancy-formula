<?php

declare(strict_types=1);

namespace FancyFormula;

use InvalidArgumentException;

/**
 * The budgets of SPEC.md section 12. Host-built: a request may only TIGHTEN
 * them (the stricter value wins), and nothing may exceed a hard ceiling.
 */
final readonly class Limits
{
    /** name => [default, ceiling] */
    public const array TABLE = [
        'maxFormulaBytes' => [8192, 65536],
        'maxDepth' => [64, 256],
        'maxFormulas' => [500, 5000],
        'maxRangeCells' => [100000, 1000000],
        'maxInputCells' => [1000000, 5000000],
        'maxArrayCells' => [100000, 1000000],
        'maxLambdaDepth' => [64, 256],
        'maxSteps' => [1000000, 50000000],
        'maxTextLength' => [50000, 50000],
        'wallClockMs' => [1000, 30000],
        'maxTraceSteps' => [10000, 100000],
    ];

    /** @param array<string, int> $values */
    private function __construct(public array $values) {}

    public static function defaults(): self
    {
        return new self(array_map(static fn (array $pair): int => $pair[0], self::TABLE));
    }

    /**
     * A host override. Refuses an unknown name, a value below 1, or one above
     * the hard ceiling: a host can loosen a default, never past the ceiling.
     */
    public function with(int ...$values): self
    {
        $next = $this->values;
        foreach ($values as $name => $value) {
            $name = (string) $name;
            if (! isset(self::TABLE[$name])) {
                throw new InvalidArgumentException("Unknown limit {$name}.");
            }
            if ($value < 1 || $value > self::TABLE[$name][1]) {
                throw new InvalidArgumentException("{$name} must be within 1..".self::TABLE[$name][1].'.');
            }
            $next[$name] = $value;
        }

        return new self($next);
    }

    /**
     * The request's limits applied: each one only where it is STRICTER. A
     * request value that would loosen a limit is ignored, never honoured.
     *
     * @param  array<string, mixed>  $request  the request's `limits` object
     */
    public function tighten(array $request): self
    {
        $next = $this->values;
        foreach ($request as $name => $value) {
            if (isset(self::TABLE[$name]) && is_int($value) && $value >= 1 && $value < $next[$name]) {
                $next[$name] = $value;
            }
        }

        return new self($next);
    }

    public function get(string $name): int
    {
        return $this->values[$name];
    }
}
