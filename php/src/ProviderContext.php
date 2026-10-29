<?php

declare(strict_types=1);

namespace FancyFormula;

/** What a provider is told about the call it serves. */
final readonly class ProviderContext
{
    public function __construct(
        /** The function name, upper-case. */
        public string $function,
        /** The formula binding being evaluated. */
        public string $binding,
    ) {}
}
