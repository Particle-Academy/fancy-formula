<?php

declare(strict_types=1);

namespace FancyFormula;

use InvalidArgumentException;

/**
 * A clock that always reads the same local date-time: what a host passes when
 * a quote must be reproducible, and what tests use.
 *
 * `new FixedClock('2026-10-27T09:00:00', 'Europe/London')`
 */
final readonly class FixedClock implements Clock
{
    public function __construct(private string $localDateTime, private string $zone)
    {
        if (Dates::parseIso($localDateTime, true) === null) {
            throw new InvalidArgumentException('A clock needs a real local date-time, YYYY-MM-DDTHH:MM:SS.');
        }
        if (preg_match('/^[A-Za-z_]+(?:\/[A-Za-z0-9_+\-]+)*$/', $zone) !== 1) {
            throw new InvalidArgumentException('A clock needs an IANA zone name, such as Europe/London.');
        }
    }

    public function localDateTime(): string
    {
        return $this->localDateTime;
    }

    public function zone(): string
    {
        return $this->zone;
    }
}
