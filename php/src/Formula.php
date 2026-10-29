<?php

declare(strict_types=1);

namespace FancyFormula;

use FancyFormula\Syntax\Parser;
use FancyFormula\Syntax\Printer;

/**
 * A parsed formula. Parsing needs no data and either succeeds or THROWS a
 * FormulaError with a stable code: a malformed formula never becomes a value.
 *
 * ```php
 * $f = Formula::parse('=VLOOKUP(qty, price_breaks, 2, TRUE)');
 * $f->normalized();              // "=VLOOKUP(qty, price_breaks, 2, TRUE)"
 * $f->references()->toArray();   // names: [price_breaks, qty], functions: [VLOOKUP], ...
 * ```
 */
final class Formula
{
    public const int DEFAULT_MAX_BYTES = 8192;

    public const int DEFAULT_MAX_DEPTH = 64;

    private ?string $normalized = null;

    /** @var array<int, array{0: int, 1: int}> */
    private array $spans = [];

    private ?References $references = null;

    /** @param array<string, mixed> $ast */
    private function __construct(public readonly string $source, private readonly array $ast) {}

    /**
     * @throws FormulaError FORMULA_SYNTAX, FORMULA_TOO_LONG or FORMULA_TOO_DEEP
     */
    public static function parse(string $source, int $maxBytes = self::DEFAULT_MAX_BYTES, int $maxDepth = self::DEFAULT_MAX_DEPTH): self
    {
        // Length FIRST, in UTF-8 bytes, before a token is read.
        if (strlen($source) > $maxBytes) {
            throw new FormulaError(
                sprintf('Formula too long: the limit is %d UTF-8 bytes', $maxBytes),
                $source,
                0,
                'FORMULA_TOO_LONG',
            );
        }

        return new self($source, Parser::parse($source, $maxDepth));
    }

    /** Canonical text: leading `=`, upper-case function names, `, ` between arguments. */
    public function normalized(): string
    {
        if ($this->normalized === null) {
            [$this->normalized, $this->spans] = Printer::print($this->ast);
        }

        return $this->normalized;
    }

    /**
     * Call id => [start, end) byte span in normalized().
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function spans(): array
    {
        $this->normalized();

        return $this->spans;
    }

    /**
     * The JSON-able AST: plain arrays, each node with `type` and `at` (byte
     * offset into the source).
     *
     * @return array<string, mixed>
     */
    public function ast(): array
    {
        return $this->ast;
    }

    public function references(): References
    {
        if ($this->references !== null) {
            return $this->references;
        }
        $acc = ['names' => [], 'cells' => [], 'ranges' => [], 'functions' => []];
        self::collect($this->ast, [], $acc);

        $functions = array_keys($acc['functions']);
        $volatile = array_values(array_intersect($functions, Functions::VOLATILE));
        $external = array_values(array_intersect($functions, Functions::EXTERNAL));
        $dynamic = array_intersect($functions, Functions::DYNAMIC) !== [];

        $sorted = static function (array $keys): array {
            $keys = array_map('strval', array_keys($keys));
            sort($keys, SORT_STRING);

            return $keys;
        };
        sort($functions, SORT_STRING);
        sort($volatile, SORT_STRING);
        sort($external, SORT_STRING);

        return $this->references = new References(
            $sorted($acc['names']),
            $sorted($acc['cells']),
            $sorted($acc['ranges']),
            $functions,
            $volatile,
            $external,
            $dynamic,
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $scope  LET / LAMBDA names in force (upper-cased)
     * @param  array<string, array<string, true>>  $acc
     */
    private static function collect(array $node, array $scope, array &$acc): void
    {
        switch ($node['type']) {
            case 'name':
                if (! isset($scope[strtoupper($node['name'])])) {
                    $acc['names'][$node['name']] = true;
                }

                return;
            case 'ref':
                $key = $node['kind'] === 'cell' ? 'cells' : 'ranges';
                $acc[$key][Printer::canonicalRef($node)] = true;

                return;
            case 'unary':
            case 'percent':
                self::collect($node['operand'], $scope, $acc);

                return;
            case 'binary':
                self::collect($node['left'], $scope, $acc);
                self::collect($node['right'], $scope, $acc);

                return;
            case 'paren':
                self::collect($node['expr'], $scope, $acc);

                return;
            case 'array':
                foreach ($node['rows'] as $row) {
                    foreach ($row as $cell) {
                        self::collect($cell, $scope, $acc);
                    }
                }

                return;
            case 'call':
                $acc['functions'][$node['name']] = true;
                // Every argument, every branch: a static question has no run.
                foreach ($node['args'] as $arg) {
                    self::collect($arg, $scope, $acc);
                }

                return;
            case 'let':
                $acc['functions']['LET'] = true;
                foreach ($node['bindings'] as $b) {
                    self::collect($b['value'], $scope, $acc);
                    $scope[strtoupper($b['name'])] = true;
                }
                self::collect($node['body'], $scope, $acc);

                return;
            case 'lambda':
                $acc['functions']['LAMBDA'] = true;
                foreach ($node['params'] as $p) {
                    $scope[strtoupper($p)] = true;
                }
                self::collect($node['body'], $scope, $acc);

                return;
            case 'invoke':
                self::collect($node['callee'], $scope, $acc);
                foreach ($node['args'] as $arg) {
                    self::collect($arg, $scope, $acc);
                }

                return;
            default:
                return;
        }
    }
}
