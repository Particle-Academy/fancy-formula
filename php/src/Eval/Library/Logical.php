<?php

declare(strict_types=1);

namespace FancyFormula\Eval\Library;

use FancyFormula\Value\Blank;
use FancyFormula\Value\ErrorValue;
use FancyFormula\Value\Matrix;
use FancyFormula\Value\Values;

/**
 * IF, IFS, SWITCH, IFERROR, IFNA and CHOOSE are LAZY: only the branch taken is
 * evaluated, so an untaken branch's error or lookup never happens and never
 * reaches the trace. AND and OR are NOT lazy (HYPOTHESIS H-10).
 */
trait Logical
{
    /** @param list<array<string, mixed>> $args */
    private function fnIf(array $args, array $env): mixed
    {
        $cond = Values::toBool($this->scalar($this->ev($args[0], $env, false)));
        if ($cond instanceof ErrorValue) {
            return $cond;
        }
        if ($cond) {
            return $this->ev($args[1], $env, false);
        }
        if (! isset($args[2])) {
            return false; // IF(FALSE, x) is FALSE
        }

        return $this->ev($args[2], $env, false);
    }

    /** @param list<array<string, mixed>> $args */
    private function fnIfs(array $args, array $env): mixed
    {
        for ($k = 0; $k + 1 < count($args); $k += 2) {
            $cond = Values::toBool($this->scalar($this->ev($args[$k], $env, false)));
            if ($cond instanceof ErrorValue) {
                return $cond;
            }
            if ($cond) {
                return $this->ev($args[$k + 1], $env, false);
            }
        }

        return ErrorValue::na('IFS found no true condition.');
    }

    /** @param list<array<string, mixed>> $args */
    private function fnSwitch(array $args, array $env): mixed
    {
        $subject = $this->scalar($this->ev($args[0], $env, false));
        if ($subject instanceof ErrorValue) {
            return $subject;
        }
        $n = count($args);
        for ($k = 1; $k + 1 < $n; $k += 2) {
            $case = $this->scalar($this->ev($args[$k], $env, false));
            if ($case instanceof ErrorValue) {
                return $case;
            }
            if (Values::same($subject, $case)) {
                return $this->ev($args[$k + 1], $env, false);
            }
        }
        if ($n % 2 === 0) {
            return $this->ev($args[$n - 1], $env, false);
        }

        return ErrorValue::na('SWITCH matched no case and has no default.');
    }

    /**
     * IFERROR(value, [fallback]) and IFNA(value, fallback). An omitted
     * fallback is "" (HYPOTHESIS H-31).
     *
     * @param  list<array<string, mixed>>  $args
     */
    private function fnIfError(array $args, array $env, bool $naOnly): mixed
    {
        $value = $this->ev($args[0], $env, false);
        $probe = $this->scalar($value);
        $caught = $probe instanceof ErrorValue && (! $naOnly || $probe->code === '#N/A');
        if (! $caught) {
            return $value;
        }
        if (! isset($args[1]) || $args[1]['type'] === 'empty') {
            return Blank::get(); // captured, E-0186: EMPTY, not "" (H-31 was wrong)
        }

        return $this->ev($args[1], $env, false);
    }

    /** CHOOSE(index, ...): the index is truncated; outside 1..n is #VALUE!. */
    private function fnChoose(array $args, array $env): mixed
    {
        $index = $this->integer($this->ev($args[0], $env, false));
        if ($index instanceof ErrorValue) {
            return $index;
        }
        if ($index < 1 || $index >= count($args)) {
            return ErrorValue::num("CHOOSE has no option {$index}."); // captured, E-0250 / E-0252
        }

        return $this->ev($args[$index], $env, false);
    }

    /**
     * AND / OR over every argument; the first error wins. In a reference,
     * numbers and booleans count and text and empties are skipped; a literal
     * text argument must read as TRUE or FALSE (H-18). Nothing to read is
     * #VALUE!.
     */
    private function andOr(array $args, bool $and): bool|ErrorValue
    {
        $seen = false;
        $result = $and;
        foreach ($args as $arg) {
            $values = $arg instanceof Matrix ? $arg->cells() : [$arg ?? Blank::get()];
            foreach ($values as $v) {
                if ($v instanceof ErrorValue) {
                    return $v;
                }
                if ($arg instanceof Matrix && ($v instanceof Blank || is_string($v))) {
                    continue;
                }
                $b = Values::toBool($v);
                if ($b instanceof ErrorValue) {
                    return $b;
                }
                $seen = true;
                $result = $and ? ($result && $b) : ($result || $b);
            }
        }

        return $seen ? $result : ErrorValue::value(($and ? 'AND' : 'OR').' found no logical values.');
    }

    private function not(array $args): bool|ErrorValue
    {
        $b = Values::toBool($this->scalar($args[0] ?? Blank::get()));

        return $b instanceof ErrorValue ? $b : ! $b;
    }

    /** N: a number as itself, TRUE as 1, text and empty as 0, an error as itself. */
    private function n(mixed $v): float|ErrorValue
    {
        $s = $this->scalar($v ?? Blank::get());

        return match (true) {
            is_float($s) => $s,
            is_bool($s) => $s ? 1.0 : 0.0,
            $s instanceof ErrorValue => $s,
            default => 0.0,
        };
    }
}
