<?php

declare(strict_types=1);

namespace FancyFormula\Eval;

use RuntimeException;

/**
 * A broken limit, unwinding the WHOLE run. Internal: Engine::evaluate() turns
 * it into an abort result, and no partial result survives it.
 */
final class LimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $limitCode, public readonly int $limit, string $message)
    {
        parent::__construct($message);
    }
}
