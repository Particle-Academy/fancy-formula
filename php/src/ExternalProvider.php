<?php

declare(strict_types=1);

namespace FancyFormula;

/**
 * The ONLY way an external function (IMPORTXML, GOOGLEFINANCE, ...) runs: a
 * host registers a provider under that function's name, and the provider does
 * the I/O. The engine does none (SPEC.md section 11.1).
 *
 * Arguments and the response are exchange cells (`{t, v}`, SPEC.md section 4),
 * never internal objects. Every call and its response is recorded in the
 * trace's `external` list and folded into the fingerprint, so a run replays
 * from its trace. A response that is not a scalar exchange cell is a #VALUE!.
 */
interface ExternalProvider
{
    /** The upper-case function name served, one of Functions::EXTERNAL. */
    public function name(): string;

    /**
     * @param  list<array<string, mixed>>  $args  exchange cells, in argument order
     * @return array<string, mixed> one exchange cell
     */
    public function call(array $args, ProviderContext $context): array;
}
