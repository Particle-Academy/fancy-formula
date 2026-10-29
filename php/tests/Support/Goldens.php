<?php

declare(strict_types=1);

namespace FancyFormula\Tests\Support;

use FancyFormula\Engine;
use FancyFormula\Formula;
use FancyFormula\FormulaError;
use FancyFormula\Functions;
use ParticleAcademy\Conformance\Conformance;

/**
 * Runs fancy-conformance's Google Sheets goldens (`formula/*`) against the
 * engine.
 *
 * A row is SKIPPED only when it needs something outside slice 1, decided
 * STATICALLY from the formula text and never from what the engine returned:
 * a skip read off the engine's own output would hide exactly the bugs the
 * goldens exist to find. Every skip carries a reason that names slice 2.
 */
final class Goldens
{
    public const array SUITES = [
        'formula/coerce', 'formula/dates', 'formula/float', 'formula/eval-date', 'formula/eval-info',
        'formula/eval-logical', 'formula/eval-lookup', 'formula/eval-math', 'formula/eval-operators',
        'formula/eval-stat', 'formula/eval-text', 'formula/pricing', 'formula/security',
    ];

    /**
     * Rows whose GOLDEN is lossier than its declared tolerance: the export did
     * not keep what the manifest says it kept. Each is still RUN and still
     * ASSERTED, at the precision the export really holds; none is skipped.
     * Reported upstream to fancy-conformance 0.39.0 (tolerances to correct).
     *
     * - `sig10`: the golden holds 10 significant digits under a 5e-15
     *   tolerance, so no correct double can match it.
     * - `tiny`: the golden is exactly 0 where the export flushed a tiny
     *   value. F-0034, captured in the same run, has `(0.1+0.2)-0.3=0` FALSE,
     *   so Sheets did NOT compute 0 for F-0002.
     */
    public const array EXPORT_PRECISION = [
        'E-0122' => ['sig10', 'SQRT(2) exported as 1.414213562'],
        'P-0007' => ['sig10', '2^0.5 exported as 1.414213562'],
        'F-0002' => ['tiny', '(0.1+0.2)-0.3 is 5.55E-17 in Sheets (F-0034), exported as 0'],
        'F-0023' => ['tiny', '1E-320 exported as 0; whether Sheets flushes subnormals stays open'],
    ];

    /** Compare a row listed in EXPORT_PRECISION at the precision its golden holds. */
    private static function exportEquals(string $mode, array $actual, array $expected): bool
    {
        $a = $actual['value'] ?? null;
        $e = $expected['value'];
        if (($a['t'] ?? null) !== 'number' || $e['t'] !== 'number') {
            return false;
        }
        if ($mode === 'tiny') {
            return (float) $e['v'] === 0.0 && abs((float) $a['v']) < 1e-15;
        }

        return sprintf('%.9e', (float) $a['v']) === sprintf('%.9e', (float) $e['v']);
    }

    /** Why a row is outside slice 1, or null when slice 1 must pass it. */
    public static function skipReason(array $case): ?string
    {
        $source = (string) ($case['input']['formulas']['result'] ?? '');
        try {
            $refs = Formula::parse($source)->references();
        } catch (FormulaError) {
            return null; // a parse error is in scope: it must come out as FORMULA_SYNTAX
        }
        $later = array_values(array_filter(
            $refs->functions,
            static fn (string $fn): bool => ! Functions::known($fn),
        ));
        if ($later !== []) {
            return 'slice 2: '.implode(', ', $later).' not in slice 1';
        }
        // TEXT is in slice 1 for number and percent codes only.
        if (preg_match('/TEXT\(/i', $source) === 1 && self::textDateCode($case)) {
            return 'slice 2: TEXT date and time format codes';
        }

        return null;
    }

    /** Does any TEXT format in the row (literal or read from an input) carry a date or time code? */
    private static function textDateCode(array $case): bool
    {
        $strings = [];
        preg_match_all('/"((?:[^"]|"")*)"/', (string) $case['input']['formulas']['result'], $m);
        array_push($strings, ...$m[1]);
        foreach ($case['input']['inputs'] ?? [] as $input) {
            if (is_string($input['value'] ?? null)) {
                $strings[] = $input['value'];
            }
        }
        foreach ($strings as $s) {
            $unquoted = preg_replace('/"[^"]*"/', '', $s);
            if (preg_match('/[yYdDhHsS]|(?<![0#])[mM]/', (string) $unquoted) === 1 && preg_match('/^[^a-zA-Z]*$/', $s) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the engine says for one row, in the row's own vocabulary:
     * `{value}`. A formula Sheets shows as a parse error (`#ERROR!`) is our
     * FORMULA_SYNTAX refusal (SPEC.md section 2): it maps to that value and
     * nothing else does.
     *
     * @return array<string, mixed>
     */
    public static function run(array $case): array
    {
        $out = (new Engine)->evaluate($case['input'])->toArray();
        if ($out['ok'] !== true) {
            // The two places our contract REFUSES what Sheets shows as a value
            // (SPEC.md section 2): a parse error (Sheets: #ERROR!) and a wrong
            // argument count (Sheets: #N/A, E-0051 =SUM()). Each refusal maps
            // to that one value only when it is the ONLY diagnostic code.
            $codes = $out['code'] === 'FORMULA_CHECK_FAILED' ? array_values(array_unique(array_column($out['diagnostics'], 'code'))) : [];
            if ($codes === ['FORMULA_SYNTAX']) {
                return ['value' => ['t' => 'error', 'v' => '#ERROR!']];
            }
            if ($codes === ['FORMULA_ARITY']) {
                return ['value' => ['t' => 'error', 'v' => '#N/A']];
            }

            return ['refused' => $out];
        }
        $value = $out['results']['result']['value'];
        unset($value['format'], $value['detail']);

        return ['value' => $value];
    }

    /**
     * @return array{passed: int, failed: list<array<string, mixed>>, skipped: list<array{id: string, reason: string}>}
     */
    public static function suite(string $suite): array
    {
        $passed = 0;
        $failed = [];
        $skipped = [];
        foreach (Conformance::cases($suite) as $case) {
            $reason = self::skipReason($case);
            if ($reason !== null) {
                $skipped[] = ['id' => $case['tags'][0], 'reason' => $reason];

                continue;
            }
            $actual = self::run($case);
            $tolerance = is_int($case['tolerance'] ?? null) || is_float($case['tolerance'] ?? null) ? (float) $case['tolerance'] : null;
            $export = self::EXPORT_PRECISION[$case['tags'][0]] ?? null;
            $equal = $export !== null
                ? self::exportEquals($export[0], $actual, $case['expected'])
                : Conformance::equals($actual, $case['expected'], $tolerance);
            if ($equal) {
                $passed++;
            } else {
                $failed[] = ['id' => $case['tags'][0], 'title' => $case['title'], 'expected' => $case['expected'], 'actual' => $actual];
            }
        }

        return ['passed' => $passed, 'failed' => $failed, 'skipped' => $skipped];
    }
}
