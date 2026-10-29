<?php

declare(strict_types=1);

namespace FancyFormula\Eval;

use FancyFormula\Value\Matrix;
use FancyFormula\Value\Values;

/**
 * Builds one formula's trace (SPEC.md section 10, schema/trace.schema.json):
 * what it read, which other formulas it depended on, and its steps. Levels:
 * `none` records nothing; `lookups` records lookup and criteria steps; `full`
 * records every function call. Hitting maxTraceSteps sets `truncated` and
 * never aborts.
 */
final class Recorder
{
    /** @var array<string, array<string, mixed>> ref => read */
    private array $reads = [];

    /** @var array<string, true> */
    private array $dependsOn = [];

    /** @var list<array<string, mixed>> */
    private array $steps = [];

    private int $nextId = 1;

    private bool $truncated = false;

    public ?string $clock = null;

    public bool $unsorted = false;

    public bool $formulaMetadataIgnored = false;

    /** @var list<string> */
    public array $tables = [];

    public function __construct(public readonly string $level, private readonly int $maxSteps) {}

    public function enabled(): bool
    {
        return $this->level !== 'none';
    }

    /** Should a call to $fn get a step at this level? */
    public function wants(bool $lookupOrCriteria): bool
    {
        return $this->level === 'full' || ($this->level === 'lookups' && $lookupOrCriteria);
    }

    public function read(string $ref, string $kind, string $source, mixed $value): void
    {
        if ($this->level === 'none' || isset($this->reads[$ref])) {
            return;
        }
        $read = ['ref' => $ref, 'kind' => $kind, 'source' => $source];
        if ($value instanceof Matrix && ($value->height() > 1 || $value->width() > 1 || $kind === 'range')) {
            $read['shape'] = [$value->height(), $value->width()];
            $read['digest'] = self::digest($value);
        } else {
            $read['value'] = Values::toExchange($value instanceof Matrix ? $value->first() : $value);
        }
        $this->reads[$ref] = $read;
    }

    public function depends(string $binding): void
    {
        $this->dependsOn[$binding] = true;
    }

    /** @param array<string, mixed> $step without its id */
    public function step(array $step): void
    {
        if (count($this->steps) >= $this->maxSteps) {
            $this->truncated = true;

            return;
        }
        $this->steps[] = ['id' => $this->nextId++] + $step;
    }

    /** @var list<array<string, mixed>> */
    private array $external = [];

    /** @param array<string, mixed> $call {fn, args, response, elapsedMs} */
    public function external(array $call): void
    {
        // Recorded at EVERY level, `none` included: a run replays from these.
        $this->external[] = $call;
    }

    public function truncate(): void
    {
        $this->truncated = true;
    }

    /**
     * @param  array<string, mixed>  $value  the formula's result, exchange form
     * @return array<string, mixed>
     */
    public function trace(string $formula, array $value): array
    {
        $trace = [
            'formula' => $formula,
            'value' => $value,
            'reads' => array_values($this->reads),
            'dependsOn' => array_keys($this->dependsOn),
            'steps' => $this->steps,
            'volatile' => ['clock' => $this->clock, 'randomDraws' => 0],
            'external' => $this->external,
            'truncated' => $this->truncated,
        ];
        if ($this->tables !== []) {
            $trace['formulaMetadataIgnored'] = $this->tables;
        }

        return $trace;
    }

    public static function digest(Matrix $m): string
    {
        $rows = array_map(static fn (array $row): array => array_map(Values::toExchange(...), $row), $m->rows);

        return 'sha256:'.hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
