<?php

declare(strict_types=1);

namespace FancyFormula\Eval;

use FancyFormula\Limits;

/**
 * The deterministic step budget plus the wall-clock backstop. One per
 * evaluate() call, shared by every formula in it.
 *
 * A step is a node visit or a unit of per-cell work, so the count is the same
 * on every machine; only FORMULA_LIMIT_TIME can differ between runs, which is
 * why it has its own code.
 */
final class Budget
{
    private int $steps = 0;

    private int $nextClockCheck = 1024;

    private readonly int $deadline;

    public function __construct(public readonly Limits $limits)
    {
        $this->deadline = hrtime(true) + $limits->get('wallClockMs') * 1_000_000;
    }

    public function step(int $n = 1): void
    {
        $this->steps += $n;
        $max = $this->limits->get('maxSteps');
        if ($this->steps > $max) {
            throw new LimitExceeded('FORMULA_LIMIT_STEPS', $max, "Evaluation exceeded {$max} steps.");
        }
        if ($this->steps >= $this->nextClockCheck) {
            $this->nextClockCheck = $this->steps + 1024;
            if (hrtime(true) > $this->deadline) {
                $ms = $this->limits->get('wallClockMs');
                throw new LimitExceeded('FORMULA_LIMIT_TIME', $ms, "Evaluation exceeded {$ms} ms of wall-clock time.");
            }
        }
    }

    public function steps(): int
    {
        return $this->steps;
    }

    /** A text value longer than maxTextLength UTF-16 units aborts (HYPOTHESIS H-34). */
    public function text(int $length): void
    {
        $max = $this->limits->get('maxTextLength');
        if ($length > $max) {
            throw new LimitExceeded('FORMULA_LIMIT_TEXT', $max, "A text value reached {$length} characters; the limit is {$max}.");
        }
    }

    public function rangeCells(int $cells, string $ref): void
    {
        $max = $this->limits->get('maxRangeCells');
        if ($cells > $max) {
            throw new LimitExceeded('FORMULA_LIMIT_RANGE', $max, "{$ref} covers {$cells} cells; the limit is {$max}.");
        }
    }
}
