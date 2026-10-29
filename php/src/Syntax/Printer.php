<?php

declare(strict_types=1);

namespace FancyFormula\Syntax;

use FancyFormula\Value\Matrix;

/**
 * The canonical text of an AST: a leading `=`, upper-case function names,
 * `, ` between arguments, a space either side of a binary operator, the
 * source's own parentheses kept. While printing it records each call's
 * [start, end) byte span, which is what a trace step's `span` points into.
 */
final class Printer
{
    /** @var array<int, array{0: int, 1: int}> call id => span */
    private array $spans = [];

    private string $out = '';

    /**
     * @param  array<string, mixed>  $ast
     * @return array{0: string, 1: array<int, array{0: int, 1: int}>}
     */
    public static function print(array $ast): array
    {
        $p = new self;
        $p->out = '=';
        $p->node($ast);

        return [$p->out, $p->spans];
    }

    /** @param array<string, mixed> $n */
    private function node(array $n): void
    {
        switch ($n['type']) {
            case 'number':
                $this->out .= $n['raw'];
                break;
            case 'string':
                $this->out .= '"'.str_replace('"', '""', $n['value']).'"';
                break;
            case 'bool':
                $this->out .= $n['value'] ? 'TRUE' : 'FALSE';
                break;
            case 'error':
                $this->out .= $n['value'];
                break;
            case 'ref':
                $this->out .= self::refText($n);
                break;
            case 'name':
                $this->out .= $n['name'];
                break;
            case 'empty':
                break;
            case 'unary':
                $this->out .= $n['op'];
                $this->node($n['operand']);
                break;
            case 'percent':
                $this->node($n['operand']);
                $this->out .= '%';
                break;
            case 'binary':
                $this->node($n['left']);
                $this->out .= ' '.$n['op'].' ';
                $this->node($n['right']);
                break;
            case 'paren':
                $this->out .= '(';
                $this->node($n['expr']);
                $this->out .= ')';
                break;
            case 'array':
                $this->out .= '{';
                foreach ($n['rows'] as $r => $row) {
                    if ($r > 0) {
                        $this->out .= '; ';
                    }
                    foreach ($row as $c => $cell) {
                        if ($c > 0) {
                            $this->out .= ', ';
                        }
                        $this->node($cell);
                    }
                }
                $this->out .= '}';
                break;
            case 'call':
                $start = strlen($this->out);
                $this->out .= $n['name'];
                $this->args($n['args']);
                $this->spans[$n['id']] = [$start, strlen($this->out)];
                break;
            case 'let':
                $this->out .= 'LET(';
                foreach ($n['bindings'] as $b) {
                    $this->out .= $b['name'].', ';
                    $this->node($b['value']);
                    $this->out .= ', ';
                }
                $this->node($n['body']);
                $this->out .= ')';
                break;
            case 'lambda':
                $this->out .= 'LAMBDA(';
                foreach ($n['params'] as $param) {
                    $this->out .= $param.', ';
                }
                $this->node($n['body']);
                $this->out .= ')';
                break;
            case 'invoke':
                $start = strlen($this->out);
                $this->node($n['callee']);
                $this->args($n['args']);
                $this->spans[$n['id']] = [$start, strlen($this->out)];
                break;
        }
    }

    /** @param list<array<string, mixed>> $args */
    private function args(array $args): void
    {
        $this->out .= '(';
        foreach ($args as $k => $arg) {
            if ($k > 0) {
                $this->out .= ', ';
            }
            $this->node($arg);
        }
        $this->out .= ')';
    }

    /** @param array<string, mixed> $n a ref node */
    public static function refText(array $n): string
    {
        return ($n['sheet'] !== null ? Matrix::quoteSheet($n['sheet']).'!' : '').$n['text'];
    }

    /**
     * The reference without `$` markers: what references() lists.
     *
     * @param  array<string, mixed>  $n
     */
    public static function canonicalRef(array $n): string
    {
        return ($n['sheet'] !== null ? Matrix::quoteSheet($n['sheet']).'!' : '').str_replace('$', '', $n['text']);
    }
}
