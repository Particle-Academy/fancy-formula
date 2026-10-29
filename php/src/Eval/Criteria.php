<?php

declare(strict_types=1);

namespace FancyFormula\Eval;

use FancyFormula\Value\Blank;
use FancyFormula\Value\ErrorValue;
use FancyFormula\Value\Values;

/**
 * A criteria string (SUMIF, COUNTIF, ...): the ONE place a string from an
 * input is interpreted (SPEC.md section 11.2), because that is Sheets
 * semantics. It is a closed grammar, an optional comparison operator and an
 * operand, with wildcards `* ? ~` on text equality. It has no reach into
 * references or functions: HYPOTHESIS H-28, `"=SUM(A1)"` means "equals the text
 * SUM(A1)".
 */
final readonly class Criteria
{
    private function __construct(
        public string $source,
        public string $op,
        public mixed $operand,
        public ?string $pattern,
    ) {}

    /** From a criteria argument's scalar value. */
    public static function from(mixed $value): self|ErrorValue
    {
        if ($value instanceof ErrorValue) {
            return $value;
        }
        if (is_float($value)) {
            return new self(Values::toText($value), '=', $value, null);
        }
        if (is_bool($value)) {
            return new self($value ? 'TRUE' : 'FALSE', '=', $value, null);
        }
        if ($value instanceof Blank) {
            return new self('', '=', Blank::get(), null);
        }
        $text = (string) $value;
        $op = '=';
        $rest = $text;
        foreach (['>=', '<=', '<>', '=', '>', '<'] as $candidate) {
            if (str_starts_with($text, $candidate)) {
                $op = $candidate;
                $rest = substr($text, strlen($candidate));
                break;
            }
        }
        if ($rest === '') {
            return new self($text, $op, Blank::get(), null);
        }
        $number = Values::parseNumber($rest);
        if ($number !== null) {
            return new self($text, $op, $number, null);
        }
        $upper = strtoupper($rest);
        if ($upper === 'TRUE' || $upper === 'FALSE') {
            return new self($text, $op, $upper === 'TRUE', null);
        }
        $pattern = ($op === '=' || $op === '<>') ? self::wildcard($rest) : null;

        return new self($text, $op, $rest, $pattern);
    }

    /** A regex for a pattern with an unescaped `*` or `?`; null when there is none. */
    public static function wildcard(string $text): ?string
    {
        if (preg_match('/(?<!~)[*?]/', $text) !== 1) {
            return null;
        }
        $regex = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === '~' && $i + 1 < $len && in_array($text[$i + 1], ['*', '?', '~'], true)) {
                $regex .= preg_quote($text[$i + 1], '/');
                $i++;
            } elseif ($ch === '*') {
                $regex .= '.*';
            } elseif ($ch === '?') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($ch, '/');
            }
        }

        return '/^'.$regex.'$/isu';
    }

    public function matches(mixed $cell): bool
    {
        if ($this->op === '<>') {
            return ! $this->equals($cell);
        }
        if ($this->op === '=') {
            return $this->equals($cell);
        }
        if ($cell instanceof Blank || $cell instanceof ErrorValue || $this->operand instanceof Blank) {
            return false;
        }
        if (Values::kindRank($cell) !== Values::kindRank($this->operand)) {
            return false;
        }
        $c = Values::compare($cell, $this->operand);

        return match ($this->op) {
            '<' => $c < 0,
            '>' => $c > 0,
            '<=' => $c <= 0,
            default => $c >= 0,
        };
    }

    private function equals(mixed $cell): bool
    {
        if ($this->operand instanceof Blank) {
            return $cell instanceof Blank || $cell === '';
        }
        if ($this->pattern !== null) {
            return is_string($cell) && preg_match($this->pattern, $cell) === 1;
        }
        // A numeric criterion also matches numeric TEXT (captured, E-0157:
        // COUNTIF over 10, "20", TRUE, ..., criterion "20", is 1).
        if (is_float($this->operand) && is_string($cell)) {
            $n = Values::parseNumber($cell);

            return $n !== null && Values::compareNumbers($n, $this->operand) === 0;
        }

        return Values::same($cell, $this->operand);
    }

    /** @return array<string, mixed> the trace's `{criteria, parsed}` */
    public function trace(): array
    {
        $parsed = ['op' => $this->op, 'value' => Values::toJson($this->operand)];
        if (is_string($this->operand)) {
            $parsed['wildcard'] = $this->pattern !== null;
        }

        return ['criteria' => $this->source, 'parsed' => $parsed];
    }
}
