<?php

declare(strict_types=1);

namespace FancyFormula;

/**
 * What a formula reads, as a static question with no data and no run
 * (SPEC.md section 3.5). Every list is sorted and unique. Both branches of
 * IF / IFS / SWITCH are read; LET and LAMBDA parameter names are not
 * references.
 */
final readonly class References
{
    /**
     * @param  list<string>  $names
     * @param  list<string>  $cells
     * @param  list<string>  $ranges
     * @param  list<string>  $functions
     * @param  list<string>  $volatile
     * @param  list<string>  $external
     */
    public function __construct(
        public array $names,
        public array $cells,
        public array $ranges,
        public array $functions,
        public array $volatile,
        public array $external,
        public bool $dynamic,
    ) {}

    /** @return array{names: list<string>, cells: list<string>, ranges: list<string>, functions: list<string>, volatile: list<string>, external: list<string>, dynamic: bool} */
    public function toArray(): array
    {
        return [
            'names' => $this->names,
            'cells' => $this->cells,
            'ranges' => $this->ranges,
            'functions' => $this->functions,
            'volatile' => $this->volatile,
            'external' => $this->external,
            'dynamic' => $this->dynamic,
        ];
    }
}
