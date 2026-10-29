<?php

declare(strict_types=1);

namespace FancyFormula\Syntax;

use FancyFormula\FormulaError;

/**
 * Recursive descent over SPEC.md section 3.3, lowest precedence first:
 *
 *   comparison (= <> < > <= >=)  left
 *   &                            left
 *   + -                          left
 *   * /                          left
 *   ^                            left            (HYPOTHESIS H-02)
 *   postfix %                    once
 *   unary - +                                    (HYPOTHESIS H-01: -2^2 is 4)
 *   primary, references          (`:` lives inside the reference token)
 *
 * Confirmed by the partial first capture (2026-10-28): `=-2^0.5` is #NUM! in
 * Sheets, so unary minus binds before `^`; and `=10%%` is a Sheets parse error,
 * so `%` applies at most once.
 *
 * Depth is fancy-expr's definition (GRAMMAR.md "Limits"), enforced on the way
 * down (so `((((...` never recurses past the limit) and on the way up (a binary
 * chain is built in a loop but is a deep tree).
 *
 * AST nodes are plain arrays with `type` and `at` (source byte offset). Calls
 * carry an `id`, unique within the formula, that the printer maps to a span.
 */
final class Parser
{
    /** @var list<array<string, mixed>> */
    private array $tokens;

    private int $i = 0;

    private int $nest = 0;

    private int $depth = 0;

    private int $nextId = 1;

    private bool $inArray = false;

    private function __construct(private readonly string $source, private readonly int $maxDepth)
    {
        $this->tokens = Lexer::tokenize($source);
    }

    /** @return array<string, mixed> the AST */
    public static function parse(string $source, int $maxDepth): array
    {
        $parser = new self($source, $maxDepth);
        if ($parser->peek()['type'] === 'eof') {
            throw new FormulaError('Empty formula', $source, $parser->peek()['at']);
        }
        $node = $parser->comparison();
        $rest = $parser->peek();
        if ($rest['type'] !== 'eof') {
            throw new FormulaError(sprintf("Unexpected '%s'", self::show($rest)), $source, $rest['at']);
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function peek(): array
    {
        return $this->tokens[$this->i];
    }

    private function isOp(string ...$ops): bool
    {
        $t = $this->tokens[$this->i];

        return $t['type'] === 'op' && in_array($t['value'], $ops, true);
    }

    private function expectOp(string $op): void
    {
        if (! $this->isOp($op)) {
            $t = $this->peek();
            throw new FormulaError(
                $t['type'] === 'eof' ? "Expected '{$op}' before the end" : sprintf("Expected '%s', found '%s'", $op, self::show($t)),
                $this->source,
                $t['at'],
            );
        }
        $this->i++;
    }

    /** @param array<string, mixed> $t */
    private static function show(array $t): string
    {
        return match ($t['type']) {
            'ref' => (string) $t['value']['text'],
            'string' => '"'.$t['value'].'"',
            'eof' => 'end',
            default => (string) $t['value'],
        };
    }

    private function tooDeep(): FormulaError
    {
        return new FormulaError(
            sprintf('Formula nested too deeply: %d levels is the limit', $this->maxDepth),
            $this->source,
            $this->peek()['at'],
            'FORMULA_TOO_DEEP',
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $parse
     * @return array<string, mixed>
     */
    private function child(callable $parse): array
    {
        $this->nest++;
        try {
            if ($this->nest >= $this->maxDepth) {
                throw $this->tooDeep();
            }

            return $parse();
        } finally {
            $this->nest--;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function finish(array $node, int $depth): array
    {
        if ($depth > $this->maxDepth) {
            throw $this->tooDeep();
        }
        $this->depth = $depth;

        return $node;
    }

    /**
     * One left-associative level, as a loop.
     *
     * @param  callable(): array<string, mixed>  $operand
     * @return array<string, mixed>
     */
    private function chain(callable $operand, string ...$ops): array
    {
        $left = $operand();
        while ($this->isOp(...$ops)) {
            $t = $this->peek();
            $this->i++;
            $leftDepth = $this->depth;
            $right = $operand();
            $left = $this->finish(
                ['type' => 'binary', 'op' => $t['value'], 'left' => $left, 'right' => $right, 'at' => $t['at']],
                1 + max($leftDepth, $this->depth),
            );
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function comparison(): array
    {
        return $this->chain($this->concat(...), '=', '<>', '<', '>', '<=', '>=');
    }

    /** @return array<string, mixed> */
    private function concat(): array
    {
        return $this->chain($this->additive(...), '&');
    }

    /** @return array<string, mixed> */
    private function additive(): array
    {
        return $this->chain($this->multiplicative(...), '+', '-');
    }

    /** @return array<string, mixed> */
    private function multiplicative(): array
    {
        return $this->chain($this->power(...), '*', '/');
    }

    /** @return array<string, mixed> */
    private function power(): array
    {
        return $this->chain($this->percent(...), '^');
    }

    /** @return array<string, mixed> */
    private function percent(): array
    {
        $operand = $this->unary();
        if (! $this->isOp('%')) {
            return $operand;
        }
        $t = $this->peek();
        $this->i++;
        if ($this->isOp('%')) {
            // Confirmed: Sheets refuses `=10%%` as a parse error.
            throw new FormulaError('Postfix % may be applied once', $this->source, $this->peek()['at']);
        }

        return $this->finish(['type' => 'percent', 'operand' => $operand, 'at' => $t['at']], 1 + $this->depth);
    }

    /** @return array<string, mixed> */
    private function unary(): array
    {
        if ($this->isOp('-', '+')) {
            $t = $this->peek();
            $this->i++;
            $operand = $this->child($this->unary(...));

            return $this->finish(['type' => 'unary', 'op' => $t['value'], 'operand' => $operand, 'at' => $t['at']], 1 + $this->depth);
        }

        return $this->primary();
    }

    /** @return array<string, mixed> */
    private function primary(): array
    {
        $t = $this->peek();
        $at = $t['at'];

        switch ($t['type']) {
            case 'number':
                $this->i++;

                return $this->finish(['type' => 'number', 'value' => (float) $t['value'], 'raw' => strtoupper($t['value']), 'at' => $at], 1);

            case 'string':
                $this->i++;

                return $this->finish(['type' => 'string', 'value' => $t['value'], 'at' => $at], 1);

            case 'error':
                $this->i++;

                return $this->finish(['type' => 'error', 'value' => $t['value'], 'at' => $at], 1);

            case 'ref':
                $this->i++;

                return $this->finish(['type' => 'ref'] + $t['value'] + ['at' => $at], 1);

            case 'ident':
                $this->i++;
                if ($this->isOp('(')) {
                    return $this->call($t);
                }
                $upper = strtoupper($t['value']);
                if ($upper === 'TRUE' || $upper === 'FALSE') {
                    return $this->finish(['type' => 'bool', 'value' => $upper === 'TRUE', 'at' => $at], 1);
                }

                return $this->finish(['type' => 'name', 'name' => $t['value'], 'at' => $at], 1);

            case 'op':
                if ($t['value'] === '(') {
                    $this->i++;
                    $inner = $this->child($this->comparison(...));
                    $this->expectOp(')');

                    return $this->finish(['type' => 'paren', 'expr' => $inner, 'at' => $at], 1 + $this->depth);
                }
                if ($t['value'] === '{') {
                    return $this->arrayLiteral();
                }
                break;
        }

        if ($t['type'] === 'eof') {
            throw new FormulaError('Unexpected end of formula', $this->source, $at);
        }

        throw new FormulaError(sprintf("Unexpected '%s'", self::show($t)), $this->source, $at);
    }

    /**
     * @param  array<string, mixed>  $nameToken
     * @return array<string, mixed>
     */
    private function call(array $nameToken): array
    {
        $at = $nameToken['at'];
        $name = strtoupper((string) $nameToken['value']);
        [$args, $depth] = $this->arguments();

        if ($name === 'LET') {
            $node = $this->letNode($args, $at);
        } elseif ($name === 'LAMBDA') {
            $node = $this->lambdaNode($args, $at);
        } else {
            $node = ['type' => 'call', 'name' => $name, 'args' => $args, 'at' => $at, 'id' => $this->nextId++];
        }
        $node = $this->finish($node, $depth);

        // LAMBDA(x, x + 1)(3): an immediate invocation.
        while ($node['type'] === 'lambda' && $this->isOp('(')) {
            $calleeDepth = $this->depth;
            [$invokeArgs, $argDepth] = $this->arguments();
            $node = $this->finish(
                ['type' => 'invoke', 'callee' => $node, 'args' => $invokeArgs, 'at' => $at, 'id' => $this->nextId++],
                max($calleeDepth + 1, $argDepth),
            );
        }

        return $node;
    }

    /**
     * `(` arguments `)`. An argument may be EMPTY (`INDEX(r,,1)`), which is a
     * node of its own, distinct from an omitted trailing argument.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} the arguments and the call's depth
     */
    private function arguments(): array
    {
        $this->expectOp('(');
        $args = [];
        $deepest = 0;
        if ($this->isOp(')')) {
            $this->i++;

            return [$args, 1];
        }
        while (true) {
            if ($this->isOp(',', ')')) {
                $args[] = ['type' => 'empty', 'at' => $this->peek()['at']];
                $deepest = max($deepest, 1);
            } else {
                $args[] = $this->child($this->comparison(...));
                $deepest = max($deepest, $this->depth);
            }
            if ($this->isOp(',')) {
                $this->i++;

                continue;
            }
            $this->expectOp(')');
            break;
        }

        return [$args, 1 + $deepest];
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @return array<string, mixed>
     */
    private function letNode(array $args, int $at): array
    {
        if (count($args) < 3 || count($args) % 2 === 0) {
            throw new FormulaError('LET takes name, value pairs and then a body', $this->source, $at);
        }
        $bindings = [];
        for ($k = 0; $k < count($args) - 1; $k += 2) {
            if ($args[$k]['type'] !== 'name') {
                throw new FormulaError('LET names must be identifiers', $this->source, $args[$k]['at']);
            }
            $bindings[] = ['name' => $args[$k]['name'], 'value' => $args[$k + 1], 'at' => $args[$k]['at']];
        }

        return ['type' => 'let', 'bindings' => $bindings, 'body' => $args[count($args) - 1], 'at' => $at];
    }

    /**
     * @param  list<array<string, mixed>>  $args
     * @return array<string, mixed>
     */
    private function lambdaNode(array $args, int $at): array
    {
        if ($args === []) {
            throw new FormulaError('LAMBDA needs a body', $this->source, $at);
        }
        $params = [];
        foreach (array_slice($args, 0, -1) as $p) {
            if ($p['type'] !== 'name') {
                throw new FormulaError('LAMBDA parameters must be identifiers', $this->source, $p['at']);
            }
            $params[] = $p['name'];
        }

        return ['type' => 'lambda', 'params' => $params, 'body' => $args[count($args) - 1], 'at' => $at];
    }

    /**
     * `{1,2;3,4}`: `,` separates columns, `;` rows; rows the same length.
     * Elements are expressions (HYPOTHESIS H-21: `{TRUE,A1}`), never another
     * array literal.
     *
     * @return array<string, mixed>
     */
    private function arrayLiteral(): array
    {
        $open = $this->peek();
        if ($this->inArray) {
            throw new FormulaError("Unexpected '{' inside an array literal", $this->source, $open['at']);
        }
        $this->i++;
        $this->inArray = true;
        try {
            $rows = [[]];
            $deepest = 0;
            while (true) {
                if ($this->isOp(',', ';', '}')) {
                    throw new FormulaError('An array literal element is missing', $this->source, $this->peek()['at']);
                }
                $rows[count($rows) - 1][] = $this->child($this->comparison(...));
                $deepest = max($deepest, $this->depth);
                if ($this->isOp(',')) {
                    $this->i++;

                    continue;
                }
                if ($this->isOp(';')) {
                    $this->i++;
                    $rows[] = [];

                    continue;
                }
                $this->expectOp('}');
                break;
            }
        } finally {
            $this->inArray = false;
        }
        $width = count($rows[0]);
        foreach ($rows as $row) {
            if (count($row) !== $width) {
                throw new FormulaError('Array literal rows must be the same length', $this->source, $open['at']);
            }
        }

        return $this->finish(['type' => 'array', 'rows' => $rows, 'at' => $open['at']], 1 + $deepest);
    }
}
