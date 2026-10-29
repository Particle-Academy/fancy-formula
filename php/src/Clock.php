<?php

declare(strict_types=1);

namespace FancyFormula;

/**
 * A host clock (SPEC.md section 11.3): a LOCAL date-time and the IANA zone it
 * is in. The host does any zone conversion; the engine turns the local
 * date-time into a serial with its own integer calendar code and consults no
 * time zone database. Without one, NOW and TODAY are FORMULA_NEEDS_CLOCK.
 *
 * The engine reads it ONCE per evaluate() call, so every NOW() in a run sees
 * the same instant, and records it in the trace and the fingerprint.
 */
interface Clock
{
    /** `YYYY-MM-DDTHH:MM:SS`, a real calendar date and time. */
    public function localDateTime(): string;

    /** An IANA zone name, such as `Europe/London`. Recorded, never consulted. */
    public function zone(): string;
}
