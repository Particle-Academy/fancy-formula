<?php

declare(strict_types=1);

namespace FancyFormula;

use FancyFormula\Eval\Address;
use FancyFormula\Eval\Budget;
use FancyFormula\Eval\Constraints;
use FancyFormula\Eval\Evaluator;
use FancyFormula\Eval\Input;
use FancyFormula\Eval\LimitExceeded;
use FancyFormula\Eval\Recorder;
use FancyFormula\Eval\Workbook;
use FancyFormula\Value\Blank;
use FancyFormula\Value\ErrorValue;
use FancyFormula\Value\Matrix;
use FancyFormula\Value\Values;

/**
 * The engine (SPEC.md section 1). Policy is constructor state built by the
 * host; a request may only tighten limits.
 *
 * ```php
 * $engine = new Engine(new Options(Limits::defaults()->with(wallClockMs: 500)));
 * $engine->check($request);     // declarations only: a check report
 * $engine->evaluate($request);  // check(), constraints, then the run
 * ```
 *
 * Three outcomes never collapse: a malformed formula is a check diagnostic and
 * evaluate() refuses (FORMULA_CHECK_FAILED); a spreadsheet error is a VALUE;
 * a broken limit aborts the whole run with no partial results.
 */
final class Engine
{
    public const string ENGINE = 'fancy-formula 0.1.0';

    public const string SPEC = '1';

    private const array OUTPUT_FORMATS = ['percent', 'money', 'date', 'datetime'];

    private readonly Options $options;

    public function __construct(?Options $options = null)
    {
        $this->options = $options ?? new Options;
    }

    /**
     * The function inventory: what this engine evaluates.
     *
     * @return list<array<string, mixed>>
     */
    public static function functions(): array
    {
        return Functions::inventory();
    }

    /**
     * Static analysis with no data needed (the save-time call). Values, when
     * present, are checked for fitting their declared types.
     *
     * @param  array<string, mixed>  $request
     */
    public function check(array $request): CheckReport
    {
        $a = $this->analyse($request);
        $report = [
            'ok' => ! self::hasErrors($a['diagnostics']),
            'engine' => self::ENGINE,
            'spec' => self::SPEC,
            'diagnostics' => $a['diagnostics'],
        ];
        if ($a['references'] !== []) {
            $report['references'] = $a['references'];
        }

        return new CheckReport($report);
    }

    /**
     * check(), then the input constraints, then the run.
     *
     * @param  array<string, mixed>  $request
     */
    public function evaluate(array $request): Evaluation
    {
        return new Evaluation($this->run($request));
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed> schema/evaluation.schema.json
     */
    private function run(array $request): array
    {
        $a = $this->analyse($request);
        if (self::hasErrors($a['diagnostics'])) {
            $errors = count(array_filter($a['diagnostics'], static fn (array $d): bool => $d['severity'] === 'error'));

            return [
                'ok' => false,
                'code' => 'FORMULA_CHECK_FAILED',
                'message' => sprintf('check() reported %d error%s; nothing was evaluated.', $errors, $errors === 1 ? '' : 's'),
                'diagnostics' => $a['diagnostics'],
            ];
        }
        /** @var Limits $limits */
        $limits = $a['limits'];
        /** @var Workbook $wb */
        $wb = $a['workbook'];

        $cells = $wb->totalInputCells();
        if ($cells > $limits->get('maxInputCells')) {
            return [
                'ok' => false,
                'code' => 'FORMULA_LIMIT_CELLS',
                'message' => sprintf('The inputs hold %d cells; the limit is %d.', $cells, $limits->get('maxInputCells')),
                'limit' => $limits->get('maxInputCells'),
            ];
        }

        // Constraints BEFORE any formula runs: a bad input is the host's data
        // error, never a spreadsheet value.
        $violations = Constraints::violations($wb->inputs);
        if ($violations !== []) {
            return Violations::refusal($violations);
        }

        $level = is_string($request['trace'] ?? null) ? $request['trace'] : 'lookups';
        $order = self::order($a['outputs'], $a['deps']);
        $budget = new Budget($limits);
        // The clock is read ONCE: every NOW() in a run sees the same instant.
        $clock = $this->options->clock;
        $evaluator = new Evaluator(
            $wb,
            $budget,
            $clock === null ? null : (float) Dates::parseIso($clock->localDateTime(), true),
            $clock === null ? null : $clock->localDateTime().' '.$clock->zone(),
            $this->options->providers,
        );
        $recorders = [];
        $current = null;
        try {
            foreach ($order as $binding) {
                $current = $binding;
                $recorders[$binding] = new Recorder($level, $limits->get('maxTraceSteps'));
                $wb->results[$binding] = $evaluator->run($binding, $wb->formulas[$binding], $recorders[$binding]);
            }
        } catch (LimitExceeded $e) {
            $abort = ['ok' => false, 'code' => $e->limitCode, 'message' => $e->getMessage(), 'limit' => $e->limit];
            if ($current !== null) {
                $abort['at'] = $current;
            }

            return $abort;
        }

        $results = [];
        $traces = [];
        foreach ($a['outputs'] as $binding => $declared) {
            $value = self::outputValue($wb->results[$binding] ?? Blank::get());
            $exchange = Values::toExchange($value);
            $format = is_float($value) ? $this->format($declared, $wb, $wb->formulas[$binding]) : null;
            if ($format !== null) {
                $exchange['format'] = $format;
            }
            $result = ['value' => $exchange];
            if (is_float($value)) {
                $result['display'] = match ($format['kind'] ?? null) {
                    'date' => Dates::iso($value),
                    'datetime' => Dates::isoDateTime($value),
                    default => Decimal::general($value),
                };
            }
            $results[$binding] = $result;
            if ($level !== 'none') {
                $traces[$binding] = $recorders[$binding]->trace($wb->formulas[$binding]->normalized(), $exchange);
            }
        }

        $warnings = array_values(array_filter($a['diagnostics'], static fn (array $d): bool => $d['severity'] === 'warning'));
        $unsorted = array_keys($evaluator->unsorted);
        sort($unsorted, SORT_STRING);
        foreach ($unsorted as $binding) {
            $warnings[] = [
                'code' => 'FORMULA_LOOKUP_UNSORTED',
                'severity' => 'warning',
                'target' => (string) $binding,
                'message' => 'Approximate match over an unsorted column; the result follows Sheets\' search and may surprise a reader.',
            ];
        }

        return [
            'ok' => true,
            'engine' => self::ENGINE,
            'spec' => self::SPEC,
            'fingerprint' => $this->fingerprint($wb, $a['outputs'], $evaluator->external),
            'results' => $results,
            'traces' => $traces,
            'warnings' => $warnings,
        ];
    }

    // ------------------------------------------------------------- analysis

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function analyse(array $request): array
    {
        $diagnostics = [];
        $error = static function (string $code, string $target, string $message, ?int $offset = null, ?array $related = null) use (&$diagnostics): void {
            $d = ['code' => $code, 'severity' => 'error', 'target' => $target];
            if ($offset !== null) {
                $d['offset'] = $offset;
            }
            $d['message'] = $message;
            if ($related !== null) {
                $d['related'] = $related;
            }
            $diagnostics[] = $d;
        };
        $warning = static function (string $code, string $target, string $message) use (&$diagnostics): void {
            $diagnostics[] = ['code' => $code, 'severity' => 'warning', 'target' => $target, 'message' => $message];
        };

        $limits = $this->options->limits->tighten(is_array($request['limits'] ?? null) ? $request['limits'] : []);
        $sources = is_array($request['formulas'] ?? null) ? $request['formulas'] : [];
        if ($sources === []) {
            $error('FORMULA_INPUT_INVALID', 'formulas', 'A request needs at least one formula.');
        }
        if (count($sources) > $limits->get('maxFormulas')) {
            $error('FORMULA_INPUT_INVALID', 'formulas', sprintf('%d formulas; the limit is %d.', count($sources), $limits->get('maxFormulas')));
        }
        uksort($sources, static fn ($x, $y): int => strcmp((string) $x, (string) $y));

        $formulas = [];
        foreach ($sources as $binding => $source) {
            $binding = (string) $binding;
            if (! is_string($source)) {
                $error('FORMULA_INPUT_INVALID', $binding, "{$binding}'s formula must be a string.");

                continue;
            }
            if (! self::isBinding($binding)) {
                $error('FORMULA_INPUT_INVALID', $binding, "{$binding} is neither a name nor a sheet-qualified cell.");

                continue;
            }
            try {
                $formulas[$binding] = Formula::parse($source, $limits->get('maxFormulaBytes'), $limits->get('maxDepth'));
            } catch (FormulaError $e) {
                $error($e->errorCode, $binding, self::bareMessage($e), $e->at);
            }
        }

        $inputs = [];
        $declared = is_array($request['inputs'] ?? null) ? $request['inputs'] : [];
        uksort($declared, static fn ($x, $y): int => strcmp((string) $x, (string) $y));
        foreach ($declared as $binding => $decl) {
            $binding = (string) $binding;
            if (! self::isBinding($binding) || ! is_array($decl)) {
                $error('FORMULA_INPUT_INVALID', $binding, "{$binding} is not a valid input binding and declaration.");

                continue;
            }
            $input = new Input($binding, $decl);
            foreach ($input->problems as $problem) {
                $error('FORMULA_INPUT_INVALID', $binding, $problem);
            }
            foreach ($input->suspicious as $message) {
                $warning('FORMULA_PERCENT_SUSPICIOUS', $binding, $message);
            }
            $inputs[$binding] = $input;
        }
        foreach (Constraints::declared($inputs) as [$target, $message]) {
            $error('FORMULA_INPUT_INVALID', $target, $message);
        }

        $names = is_array($request['names'] ?? null) ? $request['names'] : [];
        $wb = new Workbook($inputs, $formulas, $names);
        foreach ($wb->problems as [$target, $message]) {
            $error('FORMULA_INPUT_INVALID', $target, $message);
        }

        $deps = [];
        $references = [];
        foreach ($formulas as $binding => $formula) {
            $references[$binding] = $formula->references()->toArray();
            $deps[$binding] = [];
            $this->walk($formula->ast(), [], $binding, $wb, $deps[$binding], $error, $warning);
        }

        foreach (self::cycles($deps) as $cycle) {
            $error('FORMULA_CIRCULAR', $cycle[0], sprintf('%s depends on itself through %s.', $cycle[0], implode(' -> ', array_slice($cycle, 1))), null, $cycle);
        }

        $outputs = is_array($request['outputs'] ?? null) && $request['outputs'] !== []
            ? $request['outputs']
            : array_fill_keys(array_keys($sources), []);
        $resolved = [];
        foreach ($outputs as $binding => $decl) {
            $binding = (string) $binding;
            $decl = is_array($decl) ? $decl : [];
            if (($decl['type'] ?? null) === 'array') {
                $error('FORMULA_TYPE', $binding, "{$binding} is declared as an array output; array outputs arrive in P2.");

                continue;
            }
            if (! array_key_exists($binding, $sources)) {
                $error('FORMULA_INPUT_INVALID', $binding, "The output {$binding} names no formula.");

                continue;
            }
            $resolved[$binding] = $decl;
        }

        return [
            'diagnostics' => $diagnostics,
            'references' => $references,
            'limits' => $limits,
            'workbook' => $wb,
            'deps' => $deps,
            'outputs' => $resolved,
        ];
    }

    /**
     * Function policy, names, arity, coercion warnings and dependencies, over
     * EVERY branch (a static question has no run).
     *
     * @param  array<string, mixed>  $n
     * @param  array<string, true>  $scope  LET / LAMBDA names, upper-cased
     * @param  list<string>  $deps
     */
    private function walk(array $n, array $scope, string $binding, Workbook $wb, array &$deps, callable $error, callable $warning): void
    {
        $recurse = function (array $child, ?array $s = null) use ($scope, $binding, $wb, &$deps, $error, $warning): void {
            $this->walk($child, $s ?? $scope, $binding, $wb, $deps, $error, $warning);
        };

        switch ($n['type']) {
            case 'name':
                if (isset($scope[strtoupper($n['name'])])) {
                    return;
                }
                $resolved = $wb->resolve($n['name']);
                if ($resolved === null) {
                    $error('FORMULA_UNKNOWN_NAME', $binding, "{$n['name']} is neither an input, a formula nor a declared name.", $n['at']);

                    return;
                }
                if ($resolved[0] === 'formula') {
                    self::addDep($deps, $resolved[1]);
                } elseif ($resolved[0] === 'name') {
                    $a = $wb->names[$resolved[1]];
                    foreach ($wb->formulasIn($a->sheetKey(), $a->r1, $a->c1, $a->r2, $a->c2) as $dep) {
                        self::addDep($deps, $dep);
                    }
                }

                return;

            case 'ref':
                $sheet = $n['sheet'] ?? $wb->formulaAt[$binding]?->sheet;
                if ($sheet === null) {
                    $error('FORMULA_UNKNOWN_NAME', $binding, "{$n['text']} has no sheet, and {$binding} is not bound to a cell whose sheet it could use.", $n['at']);

                    return;
                }
                [$key, $r1, $c1, $r2, $c2] = $wb->corners($n, $sheet);
                foreach ($wb->formulasIn($key, $r1, $c1, $r2, $c2) as $dep) {
                    self::addDep($deps, $dep);
                }

                return;

            case 'unary':
            case 'percent':
                $recurse($n['operand']);

                return;

            case 'paren':
                $recurse($n['expr']);

                return;

            case 'binary':
                if (in_array($n['op'], ['+', '-', '*', '/', '^'], true)) {
                    $this->coercionWarnings($n, $wb, $binding, $warning);
                }
                $recurse($n['left']);
                $recurse($n['right']);

                return;

            case 'array':
                foreach ($n['rows'] as $row) {
                    foreach ($row as $cell) {
                        $recurse($cell);
                    }
                }

                return;

            case 'let':
                foreach ($n['bindings'] as $b) {
                    $recurse($b['value'], $scope);
                    $scope[strtoupper($b['name'])] = true;
                }
                $recurse($n['body'], $scope);

                return;

            case 'lambda':
            case 'invoke':
                $error('FORMULA_UNKNOWN_FUNCTION', $binding, 'LAMBDA is parsed, and evaluating it arrives in P2.', $n['at']);

                return;

            case 'call':
                $this->checkCall($n, $binding, $error);
                foreach ($n['args'] as $arg) {
                    $recurse($arg);
                }

                return;
        }
    }

    /** @param array<string, mixed> $n */
    private function checkCall(array $n, string $binding, callable $error): void
    {
        $name = $n['name'];
        $at = $n['at'];
        if (in_array($name, Functions::DYNAMIC, true)) {
            $error('FORMULA_DYNAMIC_REFERENCE', $binding, "{$name} is a dynamic reference, refused by default.", $at);

            return;
        }
        if (! $this->options->functions->allows($name)) {
            $error('FORMULA_REFUSED_FUNCTION', $binding, "{$name} is refused by the host's function policy.", $at);

            return;
        }
        if (in_array($name, Functions::EXTERNAL, true)) {
            if (! isset($this->options->providers[$name])) {
                $error('FORMULA_REFUSED_FUNCTION', $binding, "{$name} needs a provider, and none is registered.", $at);
            }

            return;
        }
        if (in_array($name, Functions::CLOCK, true) && $this->options->clock === null) {
            $error('FORMULA_NEEDS_CLOCK', $binding, "{$name}() needs a host clock, and none is configured.", $at);

            return;
        }
        if (in_array($name, Functions::SEED, true)) {
            $error(
                $this->options->random === null ? 'FORMULA_NEEDS_SEED' : 'FORMULA_UNKNOWN_FUNCTION',
                $binding,
                $this->options->random === null
                    ? "{$name}() needs a host seed, and none is configured."
                    : "{$name} is a P1 function that arrives in a later slice; this engine does not evaluate it yet.",
                $at,
            );

            return;
        }
        $spec = Functions::REGISTRY[$name] ?? null;
        if ($spec === null) {
            $error('FORMULA_UNKNOWN_FUNCTION', $binding, in_array($name, Functions::LATER, true)
                ? "{$name} is a P1 function that arrives in a later slice; this engine does not evaluate it yet."
                : "{$name} is not a function this engine knows.", $at);

            return;
        }
        [, $min, $max, , $parity] = $spec;
        $count = count($n['args']);
        $bad = $count < $min || ($max !== null && $count > $max)
            || ($parity === 'odd' && $count % 2 === 0) || ($parity === 'even' && $count % 2 === 1);
        if ($bad) {
            $range = $max === null ? "at least {$min}" : ($min === $max ? (string) $min : "{$min} to {$max}");
            $extra = $parity !== null ? " ({$parity} count)" : '';
            $error('FORMULA_ARITY', $binding, "{$name} takes {$range} arguments{$extra}; it was given {$count}.", $at);
        }
    }

    /** @param array<string, mixed> $n a binary arithmetic node */
    private function coercionWarnings(array $n, Workbook $wb, string $binding, callable $warning): void
    {
        $currencies = [];
        foreach ([$n['left'], $n['right']] as $side) {
            while ($side['type'] === 'paren') {
                $side = $side['expr'];
            }
            if ($side['type'] !== 'name') {
                continue;
            }
            $resolved = $wb->resolve($side['name']);
            if ($resolved === null || $resolved[0] !== 'input') {
                continue;
            }
            $input = $wb->inputs[$resolved[1]];
            if ($input->type === 'text') {
                $warning('FORMULA_TYPE_COERCES', $binding, "{$side['name']} is declared text and is used in arithmetic; it will be read as a number.");
            }
            if ($input->type === 'money' && $input->currency !== null) {
                $currencies[$input->currency] = true;
            }
        }
        if (in_array($n['op'], ['+', '-'], true) && count($currencies) > 1) {
            $warning('FORMULA_CURRENCY_MIXED', $binding, 'Money in '.implode(' and ', array_keys($currencies)).' is added or subtracted without conversion.');
        }
    }

    /** @param list<string> $deps */
    private static function addDep(array &$deps, string $binding): void
    {
        if (! in_array($binding, $deps, true)) {
            $deps[] = $binding;
        }
    }

    /**
     * Every cycle, as a path closing on its first binding (Tarjan's strongly
     * connected components; the path starts at the SCC's smallest binding).
     *
     * @param  array<string, list<string>>  $deps
     * @return list<list<string>>
     */
    private static function cycles(array $deps): array
    {
        $index = 0;
        $stack = [];
        $on = [];
        $idx = [];
        $low = [];
        $sccs = [];
        $strong = function (string $v) use (&$strong, &$index, &$stack, &$on, &$idx, &$low, &$sccs, $deps): void {
            $idx[$v] = $low[$v] = $index++;
            $stack[] = $v;
            $on[$v] = true;
            foreach ($deps[$v] ?? [] as $w) {
                if (! isset($deps[$w])) {
                    continue;
                }
                if (! isset($idx[$w])) {
                    $strong($w);
                    $low[$v] = min($low[$v], $low[$w]);
                } elseif (isset($on[$w])) {
                    $low[$v] = min($low[$v], $idx[$w]);
                }
            }
            if ($low[$v] === $idx[$v]) {
                $scc = [];
                do {
                    $w = array_pop($stack);
                    unset($on[$w]);
                    $scc[] = $w;
                } while ($w !== $v);
                $sccs[] = $scc;
            }
        };
        $nodes = array_map('strval', array_keys($deps));
        sort($nodes, SORT_STRING);
        foreach ($nodes as $v) {
            if (! isset($idx[$v])) {
                $strong($v);
            }
        }

        $out = [];
        foreach ($sccs as $scc) {
            sort($scc, SORT_STRING);
            $start = $scc[0];
            if (count($scc) === 1 && ! in_array($start, $deps[$start] ?? [], true)) {
                continue;
            }
            $out[] = self::cyclePath($start, array_flip($scc), $deps);
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $out;
    }

    /**
     * @param  array<string, int>  $members
     * @param  array<string, list<string>>  $deps
     * @return list<string>
     */
    private static function cyclePath(string $start, array $members, array $deps): array
    {
        $path = [$start];
        $seen = [$start => true];
        $current = $start;
        while (true) {
            $next = null;
            foreach ($deps[$current] ?? [] as $w) {
                if ($w === $start) {
                    $path[] = $start;

                    return $path;
                }
                if (isset($members[$w]) && ! isset($seen[$w])) {
                    $next ??= $w;
                }
            }
            if ($next === null) {
                // Cannot close from here (should not happen inside an SCC).
                $path[] = $start;

                return $path;
            }
            $path[] = $next;
            $seen[$next] = true;
            $current = $next;
        }
    }

    /**
     * Topological order over the formulas the outputs need: dependencies
     * first, in the order each formula reads them.
     *
     * @param  array<string, mixed>  $outputs
     * @param  array<string, list<string>>  $deps
     * @return list<string>
     */
    private static function order(array $outputs, array $deps): array
    {
        $order = [];
        $done = [];
        $visit = function (string $b) use (&$visit, &$order, &$done, $deps): void {
            if (isset($done[$b])) {
                return;
            }
            $done[$b] = true;
            foreach ($deps[$b] ?? [] as $d) {
                $visit($d);
            }
            $order[] = $b;
        };
        $roots = array_map('strval', array_keys($outputs));
        sort($roots, SORT_STRING);
        foreach ($roots as $b) {
            $visit($b);
        }

        return $order;
    }

    // --------------------------------------------------------------- results

    /**
     * A scalar output never spills (SPEC.md 9.3): a 1x1 array is its element,
     * anything bigger is a #REF! value.
     */
    private static function outputValue(mixed $v): mixed
    {
        if ($v instanceof Matrix) {
            if ($v->height() === 1 && $v->width() === 1) {
                return $v->first();
            }

            return ErrorValue::ref(sprintf('A %dx%d array reached a scalar output.', $v->height(), $v->width()));
        }

        return $v;
    }

    /**
     * The format tag of a number result: the output's declared type, or what
     * the formula reads straight from (a typed input, DATE, TODAY, NOW).
     *
     * @param  array<string, mixed>  $declared
     * @return array<string, mixed>|null
     */
    private function format(array $declared, Workbook $wb, Formula $formula): ?array
    {
        $type = $declared['type'] ?? null;
        if (is_string($type)) {
            if (! in_array($type, self::OUTPUT_FORMATS, true)) {
                return null;
            }
            if ($type === 'money') {
                $currency = $declared['currency'] ?? null;

                return is_string($currency) ? ['kind' => 'currency', 'currency' => $currency] : null;
            }

            return Input::formatFor($type, null);
        }
        $node = $formula->ast();
        while ($node['type'] === 'paren') {
            $node = $node['expr'];
        }
        if ($node['type'] === 'name') {
            $resolved = $wb->resolve($node['name']);
            if ($resolved !== null && $resolved[0] === 'input') {
                return $wb->inputs[$resolved[1]]->format;
            }
        }
        if ($node['type'] === 'call') {
            return match ($node['name']) {
                'DATE', 'EDATE', 'EOMONTH', 'TODAY' => ['kind' => 'date'],
                'NOW' => ['kind' => 'datetime'],
                default => null,
            };
        }

        return null;
    }

    /**
     * sha256 over the canonical JSON of the normalised formulas, the converted
     * inputs, names, outputs, clock, seed, providers, engine and spec.
     *
     * @param  array<string, mixed>  $outputs
     * @param  list<array<string, mixed>>  $external  every provider call, in order
     */
    private function fingerprint(Workbook $wb, array $outputs, array $external): string
    {
        $clock = $this->options->clock;
        $formulas = [];
        foreach ($wb->formulas as $binding => $formula) {
            $formulas[$binding] = $formula->normalized();
        }
        $inputs = [];
        foreach ($wb->inputs as $binding => $input) {
            $entry = ['type' => $input->type];
            if ($input->currency !== null) {
                $entry['currency'] = $input->currency;
            }
            if ($input->matrix !== null) {
                $entry['rows'] = Values::toExchange($input->matrix)['rows'];
                $entry['header'] = $input->header;
            } elseif ($input->present) {
                $entry['value'] = Values::toExchange($input->value);
            }
            $inputs[$binding] = $entry;
        }
        $names = [];
        foreach ($wb->names as $name => $address) {
            $names[$name] = Matrix::quoteSheet($address->sheet).'!'.Matrix::columnName($address->c1).$address->r1
                .':'.Matrix::columnName($address->c2).$address->r2;
        }
        $doc = [
            'clock' => $clock === null ? null : $clock->localDateTime().' '.$clock->zone(),
            'engine' => self::ENGINE,
            'formulas' => $formulas,
            'inputs' => $inputs,
            'names' => $names,
            'outputs' => $outputs,
            'providers' => $external,
            'seed' => $this->options->random === null ? null : 'sha256:'.$this->options->random->digest,
            'spec' => self::SPEC,
        ];

        return 'sha256:'.hash('sha256', self::canonicalJson($doc));
    }

    public static function canonicalJson(mixed $value): string
    {
        $canon = static function (mixed $v) use (&$canon): mixed {
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v, SORT_STRING);
                    $out = [];
                    foreach ($v as $k => $item) {
                        $out[(string) $k] = $canon($item);
                    }

                    return (object) $out;
                }

                return array_map($canon, $v);
            }
            if (is_float($v)) {
                return Values::jsonNumber($v);
            }

            return $v;
        };

        return (string) json_encode($canon($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<array<string, mixed>> $diagnostics */
    private static function hasErrors(array $diagnostics): bool
    {
        foreach ($diagnostics as $d) {
            if ($d['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }

    private static function isBinding(string $binding): bool
    {
        if (Address::parse($binding) !== null) {
            return true;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_.]{0,254}$/', $binding) === 1
            && preg_match('/^[A-Za-z]{1,3}[0-9]+$/', $binding) !== 1;
    }

    /** FormulaError's message without the quoted formula: a diagnostic carries the offset instead. */
    private static function bareMessage(FormulaError $e): string
    {
        $pos = strpos($e->getMessage(), ' (at offset ');

        return $pos === false ? $e->getMessage() : substr($e->getMessage(), 0, $pos).'.';
    }
}
