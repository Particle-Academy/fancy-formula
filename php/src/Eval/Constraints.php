<?php

declare(strict_types=1);

namespace FancyFormula\Eval;

use FancyFormula\Dates;
use FancyFormula\Value\Blank;
use FancyFormula\Value\ErrorValue;
use FancyFormula\Value\Matrix;
use FancyFormula\Value\Values;

/**
 * Input constraints (SPEC.md section 8.2). The agent is never the validator.
 *
 * - declared(): the DECLARED shape, for check(): contradictions and dangling
 *   references, each a FORMULA_INPUT_INVALID diagnostic.
 * - violations(): the SUPPLIED values, for evaluate(), BEFORE any formula
 *   runs. Any violation refuses the whole request; Violations::refusal() puts
 *   them in the one canonical order and applies the caps.
 *
 * Membership (oneOf, oneOfRange, requiredWhen, a column's oneOf and
 * oneOfColumn) uses the engine's own equality, so a constraint agrees with a
 * formula comparing the same values: numbers at 15 significant digits, text
 * case-insensitively, no equality across kinds, and an empty cell is never a
 * member of anything.
 */
final class Constraints
{
    private const array NUMERIC = ['number', 'percent', 'money'];

    private const array DATES = ['date', 'datetime'];

    /**
     * @param  array<string, Input>  $inputs
     * @return list<array{0: string, 1: string}> [target, message]
     */
    public static function declared(array $inputs): array
    {
        $problems = [];
        foreach (self::sorted($inputs) as $binding => $input) {
            $c = $input->constraints;
            $type = $input->type;
            $say = static function (string $message) use (&$problems, $binding): void {
                $problems[] = [$binding, $message];
            };

            if (! $input->isRange()) {
                self::declaredBounds($c, in_array($type, self::DATES, true), $type, $say, "{$binding}");
                foreach (['indexInto', 'oneOfRange'] as $rule) {
                    if (isset($c[$rule])) {
                        $target = $inputs[$c[$rule]] ?? null;
                        if ($target === null || ! $target->isRange()) {
                            $say("{$binding}'s {$rule} names {$c[$rule]}, which is not a declared range or table input.");
                        }
                    }
                }
            }

            if (isset($c['requiredWhen']) && is_array($c['requiredWhen'])) {
                self::declaredRequiredWhen($binding, $c['requiredWhen'], $inputs, $say);
            }

            if ($input->isRange()) {
                self::declaredColumns($binding, $input, $inputs, $say);
            }
        }

        return $problems;
    }

    private static function declaredBounds(array $c, bool $dates, string $type, callable $say, string $what): void
    {
        $read = static fn (mixed $v): ?float => $dates
            ? (is_string($v) ? (Dates::parseIso($v, str_contains($v, 'T')) ?? null) : null)
            : (is_int($v) || is_float($v) ? (float) $v : null);
        $min = array_key_exists('min', $c) ? $read($c['min']) : null;
        $max = array_key_exists('max', $c) ? $read($c['max']) : null;
        if ($dates && ((array_key_exists('min', $c) && $min === null) || (array_key_exists('max', $c) && $max === null))) {
            $say("{$what} has a bound that is not a real date.");
        }
        if ($min !== null && $max !== null && $min > $max) {
            $say("{$what} has min above max.");
        }
        if (isset($c['oneOf']) && is_array($c['oneOf']) && ($min !== null || $max !== null)) {
            foreach ($c['oneOf'] as $allowed) {
                $v = $read($allowed);
                if ($v !== null && (($min !== null && $v < $min) || ($max !== null && $v > $max))) {
                    $say("{$what} lists ".json_encode($allowed).' in oneOf, outside its min..max.');
                }
            }
        }
    }

    /** @param array<string, Input> $inputs */
    private static function declaredRequiredWhen(string $binding, array $rw, array $inputs, callable $say): void
    {
        $controller = is_string($rw['input'] ?? null) ? $rw['input'] : '';
        $target = $inputs[$controller] ?? null;
        if ($target === null) {
            $say("{$binding}'s requiredWhen names {$controller}, which is not a declared input.");

            return;
        }
        if ($controller === $binding) {
            $say("{$binding}'s requiredWhen names the input itself.");

            return;
        }
        if ($target->isRange()) {
            $say("{$binding}'s requiredWhen names {$controller}, a range; a controller is a scalar.");

            return;
        }
        $own = is_array($target->constraints['oneOf'] ?? null) ? $target->constraints['oneOf'] : null;
        foreach ((array) ($rw['oneOf'] ?? []) as $listed) {
            $v = Input::convert($target->type, $listed);
            if ($v === null) {
                $say("{$binding}'s requiredWhen lists ".json_encode($listed).", which {$controller} ({$target->type}) can never hold.");

                continue;
            }
            if ($own !== null && ! self::member($v, $own, $target->type)) {
                $say("{$binding}'s requiredWhen lists ".json_encode($listed).", outside {$controller}'s own oneOf.");
            }
        }
    }

    /** @param array<string, Input> $inputs */
    private static function declaredColumns(string $binding, Input $input, array $inputs, callable $say): void
    {
        $rules = $input->constraints['columnConstraints'] ?? null;
        if (! is_array($rules)) {
            return;
        }
        $width = $input->columns !== null ? count($input->columns) : null;
        foreach ($rules as $key => $rule) {
            $key = (string) $key;
            $index = ctype_digit($key) ? (int) $key : null;
            if ($index === null && $input->type === 'range' && ! $input->header) {
                $say("{$binding} names column \"{$key}\" by header, and it has no header row.");

                continue;
            }
            if ($index === null && $input->matrix !== null && $input->header) {
                $index = self::headerIndex($input->matrix, $key);
            }
            if ($index !== null && $width !== null && $index > $width) {
                $say("{$binding} has a rule for column {$key}, past its {$width} declared columns.");

                continue;
            }
            if (! is_array($rule)) {
                continue;
            }
            $columnType = $index !== null ? ($input->columns[$index - 1] ?? null) : null;
            $numeric = in_array($columnType, self::NUMERIC, true);
            $dated = in_array($columnType, self::DATES, true);
            foreach (['integer', 'min', 'max'] as $r) {
                if (! array_key_exists($r, $rule)) {
                    continue;
                }
                if ($input->columns === null) {
                    $say("{$binding} column {$key} has `{$r}`, and the column has no declared type to compare.");

                    continue;
                }
                if ($columnType === null) {
                    continue; // a header key that cannot resolve without data
                }
                $stringBound = $r !== 'integer' && is_string($rule[$r]);
                if ($r === 'integer' ? ! $numeric : ($stringBound ? ! $dated : ! $numeric)) {
                    $say("{$binding} column {$key} has `{$r}`, and its declared type {$columnType} cannot hold it.");
                }
            }
            self::declaredBounds($rule, $dated, (string) $columnType, $say, "{$binding} column {$key}");

            if (isset($rule['oneOfColumn']) && is_array($rule['oneOfColumn'])) {
                $ref = $rule['oneOfColumn'];
                $targetName = is_string($ref['input'] ?? null) ? $ref['input'] : $binding;
                $target = $inputs[$targetName] ?? null;
                if ($target === null || ! $target->isRange()) {
                    $say("{$binding} column {$key}'s oneOfColumn names {$targetName}, which is not a declared range or table input.");

                    continue;
                }
                $sameColumn = (string) ($ref['column'] ?? '') === $key
                    || ($index !== null && is_int($ref['column'] ?? null) && $ref['column'] === $index);
                if ($targetName === $binding && $sameColumn && ! isset($ref['where'])) {
                    $say("{$binding} column {$key}'s oneOfColumn points at its own column with no `where`, so it can never fail.");
                }
            }
        }
    }

    // ----------------------------------------------------------- supplied

    /**
     * Every violation in the supplied values, in no particular order.
     *
     * @param  array<string, Input>  $inputs
     * @return list<array<string, mixed>>
     */
    public static function violations(array $inputs): array
    {
        $out = [];
        foreach (self::sorted($inputs) as $binding => $input) {
            $c = $input->constraints;

            if (! $input->present) {
                if (($c['required'] ?? false) === true) {
                    $out[] = ['input' => $binding, 'rule' => 'required'];
                } elseif (isset($c['requiredWhen']) && is_array($c['requiredWhen'])) {
                    $rw = $c['requiredWhen'];
                    $controller = $inputs[$rw['input'] ?? ''] ?? null;
                    if ($controller !== null && $controller->present && ! $controller->isRange()
                        && self::member($controller->value, (array) ($rw['oneOf'] ?? []), $controller->type)) {
                        $out[] = [
                            'input' => $binding,
                            'rule' => 'requiredWhen',
                            'when' => ['input' => $controller->binding, 'value' => self::canonical($controller->value, $controller->type)],
                        ];
                    }
                }

                continue;
            }

            if ($input->isRange()) {
                array_push($out, ...self::rangeViolations($binding, $input, $inputs));

                continue;
            }
            array_push($out, ...self::scalarViolations($binding, $input, $inputs));
        }

        return $out;
    }

    /**
     * @param  array<string, Input>  $inputs
     * @return list<array<string, mixed>>
     */
    private static function scalarViolations(string $binding, Input $input, array $inputs): array
    {
        $c = $input->constraints;
        $v = $input->value;
        $got = self::canonical($v, $input->type);
        $out = [];
        if (($c['integer'] ?? false) === true && is_float($v) && floor($v) !== $v) {
            $out[] = ['input' => $binding, 'rule' => 'integer', 'got' => $got];
        }
        foreach (['min', 'max'] as $rule) {
            if (! array_key_exists($rule, $c) || ! is_float($v)) {
                continue;
            }
            $bound = self::bound($c[$rule], $input->type);
            if ($bound === null) {
                continue;
            }
            $cmp = Values::compareNumbers($v, $bound);
            if ($rule === 'min' ? $cmp < 0 : $cmp > 0) {
                $out[] = ['input' => $binding, 'rule' => $rule, $rule => $c[$rule], 'got' => $got];
            }
        }
        if (isset($c['oneOf']) && is_array($c['oneOf']) && ! self::member($v, $c['oneOf'], $input->type)) {
            $out[] = ['input' => $binding, 'rule' => 'oneOf', 'allowed' => array_values($c['oneOf']), 'got' => $got];
        }
        if (isset($c['indexInto'])) {
            $target = $inputs[$c['indexInto']] ?? null;
            $rows = $target?->matrix?->height() ?? 0;
            if (! is_float($v) || floor($v) !== $v || $v < 1 || $v > $rows) {
                $out[] = ['input' => $binding, 'rule' => 'indexInto', 'got' => $got, 'range' => $c['indexInto'], 'min' => 1, 'max' => $rows];
            }
        }
        if (isset($c['oneOfRange'])) {
            $target = $inputs[$c['oneOfRange']] ?? null;
            $found = false;
            if ($target?->matrix !== null) {
                foreach ($target->matrix->rows as $r => $row) {
                    if ($r === 0 && $target->header) {
                        continue;
                    }
                    foreach ($row as $cell) {
                        if (Values::same($cell, $v)) {
                            $found = true;
                            break 2;
                        }
                    }
                }
            }
            if (! $found) {
                $out[] = ['input' => $binding, 'rule' => 'oneOfRange', 'got' => $got, 'range' => $c['oneOfRange']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, Input>  $inputs
     * @return list<array<string, mixed>>
     */
    private static function rangeViolations(string $binding, Input $input, array $inputs): array
    {
        $c = $input->constraints;
        $m = $input->matrix;
        $out = [];
        $declared = is_array($c['columns'] ?? null);

        // Cells that do not fit their column: `columns` when the constraint
        // declares them, `type` when only the top-level typing does.
        foreach ($input->mismatches as [$row, $column, $raw, $type]) {
            $v = ['input' => $binding, 'rule' => $declared ? 'columns' : 'type', 'row' => $row, 'column' => $column, 'got' => $raw, 'allowed' => $type];
            $out[] = self::withHeader($v, $input, $column);
        }
        if ($declared && count($c['columns']) !== $m->width()) {
            $out[] = ['input' => $binding, 'rule' => 'columns', 'allowed' => count($c['columns']), 'got' => $m->width()];
        }

        $first = $input->header ? 1 : 0;
        if (isset($c['key']) && is_array($c['key'])) {
            $k = (int) ($c['key']['column'] ?? 1);
            if ($k > $m->width()) {
                $out[] = ['input' => $binding, 'rule' => 'columns', 'column' => $k];
            } else {
                $prev = null;
                $seen = [];
                for ($r = $first; $r < $m->height(); $r++) {
                    $cell = $m->at($r, $k - 1);
                    if ($cell instanceof Blank || $cell instanceof ErrorValue) {
                        continue;
                    }
                    $sorted = $c['key']['sorted'] ?? null;
                    if ($sorted !== null && $prev !== null) {
                        $cmp = Values::compare($prev, $cell);
                        if ($sorted === 'ascending' ? $cmp > 0 : $cmp < 0) {
                            $out[] = ['input' => $binding, 'rule' => 'key.sorted', 'row' => $r + 1];
                        }
                    }
                    if (($c['key']['unique'] ?? false) === true) {
                        foreach ($seen as $earlier) {
                            if (Values::same($earlier, $cell)) {
                                $out[] = ['input' => $binding, 'rule' => 'key.unique', 'row' => $r + 1, 'got' => Values::toJson($cell)];
                                break;
                            }
                        }
                        $seen[] = $cell;
                    }
                    $prev = $cell;
                }
            }
        }

        if (isset($c['columnConstraints']) && is_array($c['columnConstraints'])) {
            foreach ($c['columnConstraints'] as $key => $rule) {
                array_push($out, ...self::columnViolations($binding, $input, (string) $key, (array) $rule, $inputs));
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, Input>  $inputs
     * @return list<array<string, mixed>>
     */
    private static function columnViolations(string $binding, Input $input, string $key, array $rule, array $inputs): array
    {
        $m = $input->matrix;
        $index = self::resolveColumn($input, $key);
        if (is_array($index)) {
            return [['input' => $binding, 'rule' => 'columns'] + $index];
        }
        $type = $input->columns[$index - 1] ?? 'any';
        $allowedSet = null;
        $target = null;
        if (isset($rule['oneOfColumn']) && is_array($rule['oneOfColumn'])) {
            $targetName = is_string($rule['oneOfColumn']['input'] ?? null) ? $rule['oneOfColumn']['input'] : $binding;
            $target = $targetName;
            $allowedSet = self::columnSet($inputs[$targetName] ?? null, $rule['oneOfColumn']);
            if (is_array($allowedSet) && isset($allowedSet['problem'])) {
                return [['input' => $binding, 'rule' => 'columns'] + $allowedSet['problem']];
            }
        }

        $out = [];
        for ($r = $input->header ? 1 : 0; $r < $m->height(); $r++) {
            $cell = $m->at($r, $index - 1);
            $at = ['input' => $binding, 'row' => $r + 1, 'column' => $index];
            if ($cell instanceof Blank) {
                if (($rule['required'] ?? false) === true) {
                    $out[] = self::withHeader($at + ['rule' => 'column.required'], $input, $index);
                }

                continue;
            }
            $got = self::canonical($cell, $type);
            if (($rule['integer'] ?? false) === true && (! is_float($cell) || floor($cell) !== $cell)) {
                $out[] = self::withHeader($at + ['rule' => 'column.integer', 'got' => $got], $input, $index);
            }
            foreach (['min', 'max'] as $b) {
                if (! array_key_exists($b, $rule) || ! is_float($cell)) {
                    continue;
                }
                $bound = self::bound($rule[$b], is_string($rule[$b]) ? (str_contains($rule[$b], 'T') ? 'datetime' : 'date') : 'number');
                if ($bound === null) {
                    continue;
                }
                $cmp = Values::compareNumbers($cell, $bound);
                if ($b === 'min' ? $cmp < 0 : $cmp > 0) {
                    $out[] = self::withHeader($at + ['rule' => 'column.'.$b, $b => $rule[$b], 'got' => $got], $input, $index);
                }
            }
            if (isset($rule['oneOf']) && is_array($rule['oneOf']) && ! self::member($cell, $rule['oneOf'], $type)) {
                $out[] = self::withHeader($at + ['rule' => 'column.oneOf', 'allowed' => array_values($rule['oneOf']), 'got' => $got], $input, $index);
            }
            if ($allowedSet !== null) {
                $found = false;
                foreach ($allowedSet as $allowed) {
                    if (Values::same($cell, $allowed)) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $out[] = self::withHeader($at + ['rule' => 'column.oneOfColumn', 'got' => $got, 'range' => $target], $input, $index);
                }
            }
        }

        return $out;
    }

    /**
     * The non-empty values of a target column among the data rows `where`
     * admits; ['problem' => ...] when a column does not resolve.
     *
     * @param  array<string, mixed>  $ref
     * @return list<mixed>|array{problem: array<string, mixed>}
     */
    private static function columnSet(?Input $target, array $ref): array
    {
        if ($target?->matrix === null) {
            return [];
        }
        $m = $target->matrix;
        $col = self::resolveColumn($target, (string) $ref['column']);
        if (is_array($col)) {
            return ['problem' => $col];
        }
        $filter = null;
        if (isset($ref['where']) && is_array($ref['where'])) {
            $filter = self::resolveColumn($target, (string) $ref['where']['column']);
            if (is_array($filter)) {
                return ['problem' => $filter];
            }
        }
        $filterType = $filter !== null ? ($target->columns[$filter - 1] ?? 'any') : 'any';
        $set = [];
        for ($r = $target->header ? 1 : 0; $r < $m->height(); $r++) {
            if ($filter !== null && ! self::member($m->at($r, $filter - 1), (array) $ref['where']['oneOf'], $filterType)) {
                continue;
            }
            $v = $m->at($r, $col - 1);
            if (! $v instanceof Blank) {
                $set[] = $v;
            }
        }

        return $set;
    }

    /**
     * A column key or rule column against the SUPPLIED data: an index, or the
     * `columns` violation members saying why it does not resolve.
     *
     * @return int|array<string, mixed>
     */
    private static function resolveColumn(Input $input, string $key): int|array
    {
        $m = $input->matrix;
        if (ctype_digit($key)) {
            $index = (int) $key;

            return $index >= 1 && $index <= $m->width() ? $index : ['column' => $index];
        }
        $index = $input->header ? self::headerIndex($m, $key) : null;

        return $index ?? ['header' => $key];
    }

    /** The 1-based column whose header is exactly $text; null when none or several. */
    private static function headerIndex(Matrix $m, string $text): ?int
    {
        $found = null;
        foreach ($m->rows[0] as $c => $cell) {
            if ($cell === $text) {
                if ($found !== null) {
                    return null;
                }
                $found = $c + 1;
            }
        }

        return $found;
    }

    /** @param array<string, mixed> $v */
    private static function withHeader(array $v, Input $input, int $column): array
    {
        if ($input->header && $input->matrix !== null) {
            $h = $input->matrix->at(0, $column - 1);
            if (is_string($h)) {
                $v['header'] = $h;
            }
        }

        return $v;
    }

    /**
     * Engine-equality membership. Each listed value is read as the declared
     * type would read it (a date as its serial, "5%" as 0.05).
     *
     * @param  list<mixed>  $list
     */
    public static function member(mixed $value, array $list, string $type): bool
    {
        if ($value instanceof Blank || $value instanceof ErrorValue) {
            return false;
        }
        foreach ($list as $listed) {
            $candidate = match (true) {
                in_array($type, self::DATES, true) && is_string($listed) => Dates::parseIso($listed, str_contains($listed, 'T')),
                $type === 'percent' && is_string($listed) => Input::convert('percent', $listed),
                is_int($listed) => (float) $listed,
                default => $listed,
            };
            if ($candidate !== null && Values::same($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function bound(mixed $bound, string $type): ?float
    {
        if (is_string($bound)) {
            return Dates::parseIso($bound, str_contains($bound, 'T'));
        }

        return is_int($bound) || is_float($bound) ? (float) $bound : null;
    }

    /** A value as a violation reports it: dates as their canonical strings. */
    private static function canonical(mixed $v, string $type): mixed
    {
        if (is_float($v) && $type === 'date') {
            return Dates::iso($v);
        }
        if (is_float($v) && $type === 'datetime') {
            return Dates::isoDateTime($v);
        }

        return Values::toJson($v);
    }

    /**
     * Inputs in UTF-8 byte order of their binding: never declaration order.
     *
     * @param  array<string, Input>  $inputs
     * @return array<string, Input>
     */
    private static function sorted(array $inputs): array
    {
        uksort($inputs, static fn ($a, $b): int => strcmp((string) $a, (string) $b));

        return $inputs;
    }
}
