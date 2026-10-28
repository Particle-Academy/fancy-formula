<?php

declare(strict_types=1);

namespace FancyFormula;

use InvalidArgumentException;

/**
 * Orders and caps input-constraint violations into a FORMULA_INPUT_CONSTRAINT
 * refusal (SPEC.md section 8.2.3). The engine finds violations in whatever order
 * its loops run; this turns them into the ONE order every port reports, so the
 * same bad input always shows the same first violations.
 *
 * The order never depends on discovery order, on PHP array order, or on JSON
 * object member order: every comparison is on a value.
 */
final class Violations
{
    public const int PER_INPUT_CAP = 100;

    public const int REQUEST_CAP = 500;

    /** Rule rank: the tiebreak after input, row and column. Input-level rules first. */
    public const array RULE_ORDER = [
        'type', 'required', 'requiredWhen',
        'integer', 'min', 'max', 'oneOf', 'indexInto', 'oneOfRange',
        'columns', 'key.sorted', 'key.unique',
        'column.required', 'column.integer', 'column.min', 'column.max', 'column.oneOf', 'column.oneOfColumn',
    ];

    /**
     * @param  list<array<string, mixed>>  $violations  every violation found, in any order
     * @return array<string, mixed> the refusal, schema-valid against evaluation.schema.json
     */
    public static function refusal(array $violations, ?string $message = null): array
    {
        if ($violations === []) {
            throw new InvalidArgumentException('A constraint refusal needs at least one violation.');
        }
        $rank = array_flip(self::RULE_ORDER);
        $keyed = [];
        foreach ($violations as $v) {
            if (! is_array($v) || ! is_string($v['input'] ?? null) || $v['input'] === '') {
                throw new InvalidArgumentException('A violation needs an input binding.');
            }
            if (! is_string($v['rule'] ?? null) || ! isset($rank[$v['rule']])) {
                throw new InvalidArgumentException('Unknown violation rule '.json_encode($v['rule'] ?? null).'.');
            }
            $v = self::canonicalMembers($v);
            $keyed[] = [
                'input' => $v['input'],
                'row' => is_int($v['row'] ?? null) ? $v['row'] : 0,
                'column' => is_int($v['column'] ?? null) ? $v['column'] : 0,
                'rank' => $rank[$v['rule']],
                'header' => is_string($v['header'] ?? null) ? $v['header'] : '',
                'json' => self::canonicalJson($v),
                'v' => $v,
            ];
        }
        usort($keyed, static fn (array $a, array $b): int => strcmp($a['input'], $b['input'])
            ?: $a['row'] <=> $b['row']
            ?: $a['column'] <=> $b['column']
            ?: $a['rank'] <=> $b['rank']
            ?: strcmp($a['header'], $b['header'])
            ?: strcmp($a['json'], $b['json']));

        $reported = [];
        $truncated = [];
        $perInput = [];
        $byRequest = 0;
        foreach ($keyed as $k) {
            $input = $k['input'];
            $perInput[$input] = ($perInput[$input] ?? 0) + 1;
            if ($perInput[$input] > self::PER_INPUT_CAP) {
                $truncated[$input] = ($truncated[$input] ?? 0) + 1;
            } elseif (count($reported) >= self::REQUEST_CAP) {
                $truncated[$input] = ($truncated[$input] ?? 0) + 1;
                $byRequest++;
            } else {
                $reported[] = $k['v'];
            }
        }

        $total = count($keyed);
        $refusal = [
            'ok' => false,
            'code' => 'FORMULA_INPUT_CONSTRAINT',
            'message' => $message ?? sprintf(
                '%d input constraint violation%s across %d input%s; no formula ran.',
                $total, $total === 1 ? '' : 's', count($perInput), count($perInput) === 1 ? '' : 's'
            ),
            'violations' => $reported,
        ];
        if ($truncated !== []) {
            // Already in input byte order: it was filled walking the sorted list.
            $refusal['truncatedViolations'] = $truncated;
        }
        if ($byRequest > 0) {
            $refusal['truncatedByRequestCap'] = $byRequest;
        }

        return $refusal;
    }

    /**
     * Members sorted by name, recursively for objects (lists keep their order).
     * Member order carries no meaning in JSON; sorting it makes the output
     * byte-stable whatever order a host built the violation in.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    public static function canonicalMembers(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $member) {
            if (is_array($member)) {
                $value[$k] = self::canonicalMembers($member);
            }
        }

        return $value;
    }

    /** @param array<mixed> $value already canonicalMembers()'d */
    private static function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
