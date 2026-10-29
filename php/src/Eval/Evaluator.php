<?php

declare(strict_types=1);

namespace FancyFormula\Eval;

use FancyFormula\Decimal;
use FancyFormula\ExternalProvider;
use FancyFormula\Formula;
use FancyFormula\Functions;
use FancyFormula\ProviderContext;
use FancyFormula\Syntax\Printer;
use FancyFormula\Value\Blank;
use FancyFormula\Value\ErrorValue;
use FancyFormula\Value\Matrix;
use FancyFormula\Value\Values;

/**
 * The tree-walking evaluator (plan section 2). Pure: it reads the Workbook,
 * spends the Budget, and records into the current formula's Recorder.
 *
 * Values are float, string, bool, Blank, ErrorValue or Matrix. A name or cell
 * READ is always a reference Matrix (1x1 for a single cell), so aggregates can
 * tell a reference from a literal (H-12); scalar contexts unwrap it.
 *
 * Scalar context vs array context: outside SUMPRODUCT's arguments an operator
 * given a multi-cell array takes its first element (HYPOTHESIS H-11). Inside
 * them operators work element by element, which is what MOIC's
 * `SUMPRODUCT({TRUE,a,b}*1, {...})` needs.
 */
final class Evaluator
{
    use Library\Logical;
    use Library\Lookup;
    use Library\Math;
    use Library\TextDate;

    private Recorder $rec;

    private ?string $sheet = null;

    /** @var array<int, array{0: int, 1: int}> */
    private array $spans = [];

    private string $binding = '';

    /** @var array<string, mixed>|null the detail a lookup or criteria function leaves for its step */
    private ?array $detail = null;

    /** @var array<string, true> bindings whose approximate lookups ran over unsorted keys */
    public array $unsorted = [];

    /** @var list<array<string, mixed>> every provider call of the run, in order (for the fingerprint) */
    public array $external = [];

    /**
     * @param  float|null  $clockSerial  the host clock, read once for the run
     * @param  array<string, ExternalProvider>  $providers
     */
    public function __construct(
        private readonly Workbook $wb,
        private readonly Budget $budget,
        private readonly ?float $clockSerial = null,
        private readonly ?string $clockText = null,
        private readonly array $providers = [],
    ) {}

    /**
     * Evaluate one formula. Its dependencies are already in the workbook.
     *
     * @return mixed the value (a Matrix when the formula's result is an array)
     */
    public function run(string $binding, Formula $formula, Recorder $rec): mixed
    {
        $this->rec = $rec;
        $this->binding = $binding;
        $this->sheet = $this->wb->formulaAt[$binding]?->sheet;
        $this->spans = $formula->spans();

        return $this->ev($formula->ast(), [], false);
    }

    /**
     * @param  array<string, mixed>  $n
     * @param  array<string, mixed>  $env  LET names (upper-cased) => value
     */
    public function ev(array $n, array $env, bool $array): mixed
    {
        $this->budget->step();

        switch ($n['type']) {
            case 'number':
                return Decimal::finite($n['value']) ?? ErrorValue::num('The number is too large.');
            case 'string':
                return $n['value'];
            case 'bool':
                return $n['value'];
            case 'error':
                return new ErrorValue($n['value']);
            case 'empty':
                return Blank::get();
            case 'paren':
                return $this->ev($n['expr'], $env, $array);
            case 'name':
                return $this->name($n['name'], $env);
            case 'ref':
                return $this->ref($n);
            case 'array':
                return $this->arrayLiteral($n, $env);
            case 'unary':
                return $this->map1($this->ev($n['operand'], $env, $array), $array, fn ($v) => $this->negate($n['op'], $v));
            case 'percent':
                return $this->map1($this->ev($n['operand'], $env, $array), $array, static function ($v) {
                    $x = Values::toNumber($v);

                    return $x instanceof ErrorValue ? $x : $x / 100;
                });
            case 'binary':
                $left = $this->ev($n['left'], $env, $array);
                $right = $this->ev($n['right'], $env, $array);

                return $this->map2($left, $right, $array, fn ($a, $b) => $this->binary($n['op'], $a, $b));
            case 'let':
                foreach ($n['bindings'] as $b) {
                    $env[strtoupper($b['name'])] = $this->ev($b['value'], $env, false);
                }

                return $this->ev($n['body'], $env, $array);
            case 'call':
                return $this->call($n, $env);
            default:
                // lambda / invoke: refused by check() until P2.
                return ErrorValue::value('LAMBDA is evaluated from P2.');
        }
    }

    // ---------------------------------------------------------------- reads

    private function name(string $name, array $env): mixed
    {
        $upper = strtoupper($name);
        if (array_key_exists($upper, $env)) {
            return $env[$upper];
        }
        $resolved = $this->wb->resolve($name);
        if ($resolved === null) {
            // check() refuses unknown names; this is the belt to its braces.
            return new ErrorValue('#NAME?', "Unknown name {$name}.");
        }
        [$kind, $binding] = $resolved;
        if ($kind === 'input') {
            $input = $this->wb->inputs[$binding];
            if ($input->formulaMetadataIgnored && ! in_array($binding, $this->rec->tables, true)) {
                $this->rec->tables[] = $binding;
            }
            $value = $input->matrix ?? new Matrix([[$input->value]], true);
            $this->rec->read($binding, 'name', 'input', $value);

            return $value;
        }
        if ($kind === 'formula') {
            $this->rec->depends($binding);
            $value = $this->wb->results[$binding] ?? Blank::get();
            $value = $value instanceof Matrix ? $value : new Matrix([[$value]], true);
            $this->rec->read($binding, 'name', 'formula', $value);

            return $value;
        }
        $a = $this->wb->names[$binding];
        $matrix = $this->readCells($a->sheetKey(), $a->r1, $a->c1, $a->r2, $a->c2, $binding);
        $this->rec->read($binding, 'name', $this->formulaSourced($a->sheetKey(), $a->r1, $a->c1, $a->r2, $a->c2) ? 'formula' : 'input', $matrix);

        return $matrix;
    }

    /** @param array<string, mixed> $n */
    private function ref(array $n): Matrix
    {
        $sheet = $n['sheet'] ?? $this->sheet;
        if ($sheet === null) {
            // check() refuses a sheetless reference in a name-bound formula.
            return Matrix::scalar(ErrorValue::ref('A cell reference needs a sheet.'));
        }
        [$key, $r1, $c1, $r2, $c2] = $this->wb->corners($n, $sheet);
        $label = Printer::canonicalRef(['sheet' => $sheet] + $n);
        $matrix = $this->readCells($key, $r1, $c1, $r2, $c2, $label);
        $kind = $n['kind'] === 'cell' ? 'cell' : 'range';
        $this->rec->read($label, $kind, $this->formulaSourced($key, $r1, $c1, $r2, $c2) ? 'formula' : 'input', $matrix);

        return $matrix;
    }

    private function readCells(string $key, int $r1, int $c1, int $r2, int $c2, string $label): Matrix
    {
        $cells = ($r2 - $r1 + 1) * ($c2 - $c1 + 1);
        $this->budget->rangeCells($cells, $label);
        $this->budget->step($cells);
        foreach ($this->wb->formulasIn($key, $r1, $c1, $r2, $c2) as $dep) {
            $this->rec->depends($dep);
        }
        $matrix = $this->wb->read($key, $r1, $c1, $r2, $c2);
        $input = $this->wb->inputAt($key, $r1, $c1, $r2, $c2);
        if ($input !== null) {
            if ($input->formulaMetadataIgnored && ! in_array($input->binding, $this->rec->tables, true)) {
                $this->rec->tables[] = $input->binding;
            }
            if ($input->header) {
                return new Matrix($matrix->rows, true, $matrix->sheet, $matrix->row0, $matrix->col0, true);
            }
        }

        return $matrix;
    }

    private function formulaSourced(string $key, int $r1, int $c1, int $r2, int $c2): bool
    {
        return $this->wb->formulasIn($key, $r1, $c1, $r2, $c2) !== [];
    }

    /** @param array<string, mixed> $n */
    private function arrayLiteral(array $n, array $env): Matrix
    {
        $rows = [];
        foreach ($n['rows'] as $row) {
            $out = [];
            foreach ($row as $cell) {
                // HYPOTHESIS H-21: a reference inside an array literal is its value.
                $out[] = $this->scalar($this->ev($cell, $env, false));
            }
            $rows[] = $out;
        }

        return new Matrix($rows);
    }

    // ------------------------------------------------------------- operators

    /**
     * A scalar from a value. A multi-cell array in a scalar context takes its
     * first element (HYPOTHESIS H-11).
     */
    public function scalar(mixed $v): mixed
    {
        return $v instanceof Matrix ? $v->first() : $v;
    }

    private function map1(mixed $v, bool $array, callable $f): mixed
    {
        if ($array && $v instanceof Matrix) {
            $rows = [];
            foreach ($v->rows as $row) {
                $rows[] = array_map($f, $row);
            }
            $this->budget->step($v->height() * $v->width());

            return new Matrix($rows);
        }

        return $f($this->scalar($v));
    }

    /** Element-wise with broadcasting in array context; out-of-shape cells are #N/A. */
    private function map2(mixed $a, mixed $b, bool $array, callable $f): mixed
    {
        if (! $array || (! $a instanceof Matrix && ! $b instanceof Matrix)) {
            return $f($this->scalar($a), $this->scalar($b));
        }
        $ma = $a instanceof Matrix ? $a : Matrix::scalar($a);
        $mb = $b instanceof Matrix ? $b : Matrix::scalar($b);
        $h = max($ma->height(), $mb->height());
        $w = max($ma->width(), $mb->width());
        $this->budget->step($h * $w);
        $pick = static function (Matrix $m, int $r, int $c): mixed {
            $rr = $m->height() === 1 ? 0 : $r;
            $cc = $m->width() === 1 ? 0 : $c;
            if ($rr >= $m->height() || $cc >= $m->width()) {
                return ErrorValue::na('The arrays are different sizes.');
            }

            return $m->at($rr, $cc);
        };
        $rows = [];
        for ($r = 0; $r < $h; $r++) {
            $row = [];
            for ($c = 0; $c < $w; $c++) {
                $x = $pick($ma, $r, $c);
                $y = $pick($mb, $r, $c);
                $row[] = $x instanceof ErrorValue ? $x : ($y instanceof ErrorValue ? $y : $f($x, $y));
            }
            $rows[] = $row;
        }

        return new Matrix($rows);
    }

    private function negate(string $op, mixed $v): mixed
    {
        if ($op === '+') {
            // Unary plus passes its operand through (P-0016 decides whether Sheets coerces).
            return $v;
        }
        $x = Values::toNumber($v);

        return $x instanceof ErrorValue ? $x : ($x == 0.0 ? 0.0 : -$x);
    }

    /** One scalar binary operation. The left error wins (HYPOTHESIS H-20). */
    public function binary(string $op, mixed $a, mixed $b): mixed
    {
        if ($a instanceof ErrorValue) {
            return $a;
        }
        if ($b instanceof ErrorValue) {
            return $b;
        }

        if ($op === '&') {
            $text = Values::toText($a).Values::toText($b);
            $this->budget->text(Values::length($text));

            return $text;
        }

        if (in_array($op, ['=', '<>', '<', '>', '<=', '>='], true)) {
            $c = Values::compare($a, $b);

            return match ($op) {
                '=' => $c === 0,
                '<>' => $c !== 0,
                '<' => $c < 0,
                '>' => $c > 0,
                '<=' => $c <= 0,
                default => $c >= 0,
            };
        }

        $x = Values::toNumber($a);
        if ($x instanceof ErrorValue) {
            return $x;
        }
        $y = Values::toNumber($b);
        if ($y instanceof ErrorValue) {
            return $y;
        }

        return self::arithmetic($op, $x, $y);
    }

    public static function arithmetic(string $op, float $x, float $y): float|ErrorValue
    {
        switch ($op) {
            case '+':
                // NO near-zero snap: captured F-0034, =(0.1+0.2)-0.3=0, is
                // FALSE. (F-0002's golden of 0 is the export, not Sheets: see
                // SPEC.md H-04.)
                $r = $x + $y;
                break;
            case '-':
                $r = $x - $y;
                break;
            case '*':
                $r = $x * $y;
                break;
            case '/':
                if ($y == 0.0) {
                    return ErrorValue::div0();
                }
                $r = $x / $y;
                break;
            default:
                return self::power($x, $y);
        }

        return Decimal::finite($r) ?? ErrorValue::num('The result is too large.');
    }

    public static function power(float $x, float $y): float|ErrorValue
    {
        if ($x == 0.0 && $y == 0.0) {
            return 1.0; // HYPOTHESIS H-32
        }
        if ($x == 0.0 && $y < 0) {
            return ErrorValue::num('Zero to a negative power.'); // captured, E-0119
        }
        if ($x < 0 && $y != floor($y)) {
            // An odd root of a negative number is real (captured, E-0117:
            // POWER(-8,1/3) is -2); any other fractional power is #NUM!.
            $root = Decimal::snap(1 / $y);
            if ($root == floor($root) && fmod(abs($root), 2.0) == 1.0) {
                return Decimal::finite(-((-$x) ** $y)) ?? ErrorValue::num('The result is too large.');
            }

            return ErrorValue::num('A negative number to a fractional power.');
        }

        return Decimal::finite($x ** $y) ?? ErrorValue::num('The result is too large.');
    }

    // ------------------------------------------------------------- functions

    /** @param array<string, mixed> $n */
    private function call(array $n, array $env): mixed
    {
        $name = $n['name'];
        $spec = Functions::REGISTRY[$name] ?? null;
        if ($spec === null) {
            if (isset($this->providers[$name])) {
                return $this->external($name, $n, $env);
            }

            return new ErrorValue('#NAME?', "Unknown function {$name}.");
        }

        $saved = $this->detail;
        $this->detail = null;
        if ($spec[3]) {
            $result = $this->lazy($name, $n['args'], $env);
        } else {
            $args = [];
            foreach ($n['args'] as $arg) {
                // SUMPRODUCT's arguments are an array context.
                $args[] = $arg['type'] === 'empty' ? null : $this->ev($arg, $env, $name === 'SUMPRODUCT');
            }
            $result = $this->eager($name, $args, $n['args']);
        }
        $detail = $this->detail;
        $this->detail = $saved;

        $traced = in_array($name, Functions::LOOKUPS, true) || in_array($name, Functions::CRITERIA, true);
        if ($this->rec->wants($traced)) {
            $step = ['fn' => $name, 'span' => $this->spans[$n['id']] ?? [0, 0]];
            if ($detail !== null) {
                $step += $detail;
            }
            $single = $result instanceof Matrix && $result->height() === 1 && $result->width() === 1;
            $step['result'] = Values::toExchange($single ? $result->first() : $result);
            $this->rec->step($step);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $args  unevaluated
     */
    private function lazy(string $name, array $args, array $env): mixed
    {
        return match ($name) {
            'IF' => $this->fnIf($args, $env),
            'IFS' => $this->fnIfs($args, $env),
            'SWITCH' => $this->fnSwitch($args, $env),
            'IFERROR' => $this->fnIfError($args, $env, false),
            'IFNA' => $this->fnIfError($args, $env, true),
            'CHOOSE' => $this->fnChoose($args, $env),
            default => ErrorValue::value(),
        };
    }

    /**
     * @param  list<mixed>  $a  evaluated arguments; null for an EMPTY argument
     * @param  list<array<string, mixed>>  $nodes
     */
    private function eager(string $name, array $a, array $nodes): mixed
    {
        return match ($name) {
            'SUM' => $this->aggregate($a, 'sum'),
            'PRODUCT' => $this->aggregate($a, 'product'),
            'MIN' => $this->aggregate($a, 'min'),
            'MAX' => $this->aggregate($a, 'max'),
            'AVERAGE' => $this->aggregate($a, 'average'),
            'COUNT' => $this->count($a),
            'COUNTA' => $this->countA($a),
            'SUMPRODUCT' => $this->sumProduct($a),
            'SUMIF' => $this->sumIf($a, $nodes, 'sum'),
            'AVERAGEIF' => $this->sumIf($a, $nodes, 'average'),
            'COUNTIF' => $this->countIfs($a, $nodes),
            'COUNTIFS' => $this->countIfs($a, $nodes),
            'SUMIFS' => $this->sumIfs($a, $nodes),
            'ROUND' => $this->rounding($a, Decimal::HALF),
            'ROUNDUP' => $this->rounding($a, Decimal::UP),
            'ROUNDDOWN' => $this->rounding($a, Decimal::DOWN),
            'TRUNC' => $this->rounding($a, Decimal::DOWN),
            'MROUND' => $this->mround($a),
            'CEILING' => $this->ceilingFloor($a, true),
            'FLOOR' => $this->ceilingFloor($a, false),
            'INT' => $this->int($a),
            'ABS' => $this->unaryMath($a, static fn (float $x) => abs($x)),
            'SQRT' => $this->unaryMath($a, static fn (float $x) => $x < 0 ? ErrorValue::num('SQRT of a negative number.') : sqrt($x)),
            'MOD' => $this->mod($a),
            'POWER' => $this->binaryMath($a, self::power(...)),
            'AND' => $this->andOr($a, true),
            'OR' => $this->andOr($a, false),
            'NOT' => $this->not($a),
            'TRUE' => true,
            'FALSE' => false,
            'VLOOKUP' => $this->vlookup($a, $nodes, true),
            'HLOOKUP' => $this->vlookup($a, $nodes, false),
            'XLOOKUP' => $this->xlookup($a, $nodes),
            'INDEX' => $this->index($a, $nodes),
            'MATCH' => $this->match($a, $nodes),
            'LOOKUP' => $this->lookup($a, $nodes),
            'ROWS' => (float) $this->asMatrix($a[0])->height(),
            'COLUMNS' => (float) $this->asMatrix($a[0])->width(),
            'N' => $this->n($a[0]),
            'ISBLANK' => $this->scalar($a[0] ?? Blank::get()) instanceof Blank,
            'ISERROR' => $this->scalar($a[0]) instanceof ErrorValue,
            'ISNA' => ($v = $this->scalar($a[0])) instanceof ErrorValue && $v->code === '#N/A',
            'ISNUMBER' => is_float($this->scalar($a[0])),
            'ISTEXT' => is_string($this->scalar($a[0])),
            'NA' => ErrorValue::na('NA() was called.'),
            'CONCATENATE' => $this->concatenate($a),
            'CONCAT' => $this->concatenate([$this->scalar($a[0] ?? Blank::get()), $this->scalar($a[1] ?? Blank::get())]),
            'LEN' => $this->len($a),
            'TEXT' => $this->text($a),
            'DATE' => $this->date($a),
            'YEAR' => $this->datePart($a, 0),
            'MONTH' => $this->datePart($a, 1),
            'DAY' => $this->datePart($a, 2),
            'EDATE' => $this->edate($a, false),
            'EOMONTH' => $this->edate($a, true),
            'TODAY' => $this->today(false),
            'NOW' => $this->today(true),
            default => ErrorValue::value(),
        };
    }

    // ---------------------------------------------------------------- helpers

    public function asMatrix(mixed $v): Matrix
    {
        if ($v instanceof Matrix) {
            return $v;
        }

        return Matrix::scalar($v ?? Blank::get());
    }

    /** A number argument, or the error that stops the function. */
    public function number(mixed $v, float $default = 0.0): float|ErrorValue
    {
        if ($v === null) {
            return $default;
        }

        return Values::toNumber($this->scalar($v));
    }

    /** Integer argument truncated toward zero. */
    public function integer(mixed $v, float $default = 0.0): int|ErrorValue
    {
        $x = $this->number($v, $default);
        if ($x instanceof ErrorValue) {
            return $x;
        }
        if (abs($x) > 1e15) {
            return ErrorValue::num('The number is out of range.');
        }

        return (int) Decimal::round($x, 0, Decimal::DOWN);
    }

    /** The source text of an argument, for a trace's `range`. */
    public function label(array $node): string
    {
        return substr(Printer::print($node)[0], 1);
    }

    /** @param array<string, mixed> $detail */
    public function detail(array $detail): void
    {
        $this->detail = $detail;
    }

    public function budget(): Budget
    {
        return $this->budget;
    }

    public function clockSerial(): ?float
    {
        return $this->clockSerial;
    }

    public function clockText(): ?string
    {
        return $this->clockText;
    }

    /**
     * Call a host provider. Arguments go out and the response comes back as
     * exchange cells; the call is recorded in the trace and the fingerprint.
     *
     * @param  array<string, mixed>  $n
     */
    private function external(string $name, array $n, array $env): mixed
    {
        $args = [];
        foreach ($n['args'] as $arg) {
            $v = $this->scalar($arg['type'] === 'empty' ? Blank::get() : $this->ev($arg, $env, false));
            if ($v instanceof ErrorValue) {
                return $v;
            }
            $args[] = Values::toExchange($v);
        }
        $started = hrtime(true);
        $response = $this->providers[$name]->call($args, new ProviderContext($name, $this->binding));
        $elapsed = (hrtime(true) - $started) / 1e6;
        $value = is_array($response) && isset($response['t']) && $response['t'] !== 'array' ? Input::fromExchange($response) : null;
        $value ??= ErrorValue::value("{$name}'s provider returned something that is not a scalar exchange cell.");
        $record = ['fn' => $name, 'args' => $args, 'response' => Values::toExchange($value)];
        $this->external[] = $record;
        $this->rec->external($record + ['elapsedMs' => round($elapsed, 3)]);
        $this->budget->step();

        return $value;
    }

    public function recorder(): Recorder
    {
        return $this->rec;
    }

    public function markUnsorted(): void
    {
        $this->unsorted[$this->binding] = true;
    }
}
