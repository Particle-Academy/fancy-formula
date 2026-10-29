<?php

declare(strict_types=1);

namespace FancyFormula;

/**
 * Which functions a host allows (host policy, never request data). A function
 * the policy refuses is FORMULA_REFUSED_FUNCTION at check().
 *
 * `FunctionPolicy::all()`, `FunctionPolicy::only(['SUM', 'VLOOKUP'])`,
 * `FunctionPolicy::all()->deny(['NOW'])`.
 */
final readonly class FunctionPolicy
{
    /**
     * @param  list<string>|null  $only  null = every function
     * @param  list<string>  $denied
     */
    private function __construct(private ?array $only, private array $denied) {}

    public static function all(): self
    {
        return new self(null, []);
    }

    /** @param list<string> $names */
    public static function only(array $names): self
    {
        return new self(self::upper($names), []);
    }

    /** @param list<string> $names */
    public function deny(array $names): self
    {
        return new self($this->only, array_values(array_unique([...$this->denied, ...self::upper($names)])));
    }

    public function allows(string $name): bool
    {
        $name = strtoupper($name);

        return ! in_array($name, $this->denied, true) && ($this->only === null || in_array($name, $this->only, true));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function upper(array $names): array
    {
        return array_values(array_map(static fn ($n): string => strtoupper((string) $n), $names));
    }
}
