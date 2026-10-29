<?php

declare(strict_types=1);

namespace FancyFormula;

use InvalidArgumentException;

/**
 * Host policy: constructor state, NEVER request data (SPEC.md section 8.3).
 *
 * ```php
 * new Engine(new Options(
 *     limits:    Limits::defaults()->with(wallClockMs: 500),
 *     clock:     new FixedClock('2026-10-27T09:00:00', 'Europe/London'),
 *     random:    Seed::fromString('quote-8812'),
 *     providers: [],                       // ExternalProvider[]
 *     functions: FunctionPolicy::all(),
 * ));
 * ```
 *
 * Every parameter is optional; `new Options()` and `new Engine()` are the
 * defaults. `limits` and `functions` are nullable only because PHP cannot call
 * a static method in a default: null means Limits::defaults() and
 * FunctionPolicy::all().
 */
final readonly class Options
{
    public Limits $limits;

    public FunctionPolicy $functions;

    /** @var array<string, ExternalProvider> upper-case name => provider */
    public array $providers;

    /** @param list<ExternalProvider> $providers */
    public function __construct(
        ?Limits $limits = null,
        public ?Clock $clock = null,
        public ?Seed $random = null,
        array $providers = [],
        ?FunctionPolicy $functions = null,
    ) {
        $this->limits = $limits ?? Limits::defaults();
        $this->functions = $functions ?? FunctionPolicy::all();
        if ($clock !== null && Dates::parseIso($clock->localDateTime(), true) === null) {
            throw new InvalidArgumentException('The clock must give a real local date-time, YYYY-MM-DDTHH:MM:SS.');
        }
        $byName = [];
        foreach ($providers as $provider) {
            if (! $provider instanceof ExternalProvider) {
                throw new InvalidArgumentException('Every provider must implement ExternalProvider.');
            }
            $name = strtoupper($provider->name());
            if (! in_array($name, Functions::EXTERNAL, true)) {
                throw new InvalidArgumentException("{$name} is not an external function; providers serve only ".implode(', ', Functions::EXTERNAL).'.');
            }
            if (isset($byName[$name])) {
                throw new InvalidArgumentException("Two providers are registered for {$name}.");
            }
            $byName[$name] = $provider;
        }
        $this->providers = $byName;
    }
}
