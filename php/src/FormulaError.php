<?php

declare(strict_types=1);

namespace FancyFormula;

use RuntimeException;

/**
 * Thrown for a formula that cannot be lexed or parsed. NEVER returned, and
 * never turned into a `#ERROR!` value.
 *
 * Three outcomes exist and they must stay apart (SPEC.md "Outcomes"):
 *
 *   - malformed input THROWS this, with a stable `$errorCode`;
 *   - a spreadsheet error such as `#N/A` is a VALUE in a result;
 *   - a broken limit ABORTS the run with a typed code.
 *
 * `$errorCode` is the contract; the message is for people and may change. It is
 * not `$code` because Exception already owns an untyped integer `$code`.
 *
 * The code lists below are the single PHP statement of SPEC.md section 9. A
 * test holds them, the JSON schemas and SPEC.md's tables to the same sets, so
 * adding a code means adding it in all three places or the build goes red.
 */
final class FormulaError extends RuntimeException
{
    /** Parse failures: thrown by parse(), reported by check(). */
    public const array PARSE = [
        'FORMULA_SYNTAX',
        'FORMULA_TOO_LONG',
        'FORMULA_TOO_DEEP',
    ];

    /** check() diagnostics with severity `error`. */
    public const array CHECK_ERRORS = [
        'FORMULA_UNKNOWN_FUNCTION',
        'FORMULA_UNKNOWN_NAME',
        'FORMULA_CIRCULAR',
        'FORMULA_ARITY',
        'FORMULA_TYPE',
        'FORMULA_REFUSED_FUNCTION',
        'FORMULA_NEEDS_CLOCK',
        'FORMULA_NEEDS_SEED',
        'FORMULA_INPUT_INVALID',
        'FORMULA_DYNAMIC_REFERENCE',
    ];

    /** Diagnostics with severity `warning`. They never stop a run. */
    public const array WARNINGS = [
        'FORMULA_TYPE_COERCES',
        'FORMULA_PERCENT_SUSPICIOUS',
        'FORMULA_CURRENCY_MIXED',
        'FORMULA_LOOKUP_UNSORTED',
    ];

    /** evaluate() aborts: the whole run fails, nothing is reported as success. */
    public const array LIMITS = [
        'FORMULA_LIMIT_RANGE',
        'FORMULA_LIMIT_ARRAY',
        'FORMULA_LIMIT_LAMBDA_DEPTH',
        'FORMULA_LIMIT_STEPS',
        'FORMULA_LIMIT_TEXT',
        'FORMULA_LIMIT_CELLS',
        'FORMULA_LIMIT_TIME',
    ];

    /** evaluate() refusals that happen before any formula runs. */
    public const array REFUSALS = [
        'FORMULA_INPUT_CONSTRAINT',
        'FORMULA_CHECK_FAILED',
    ];

    /** Longest formula quoted back in a message; longer ones are elided. */
    private const int QUOTE_LIMIT = 120;

    public function __construct(
        string $message,
        public readonly string $formula,
        public readonly int $at,
        public readonly string $errorCode = 'FORMULA_SYNTAX',
    ) {
        if (! in_array($errorCode, self::PARSE, true)) {
            throw new \InvalidArgumentException("FormulaError carries parse codes only; got {$errorCode}.");
        }

        // Quoted back for the author, never in full: a formula refused for being
        // 64 KiB should not produce a 64 KiB message.
        $quoted = $formula;
        if (strlen($formula) > self::QUOTE_LIMIT) {
            // Back off to a character boundary so the message stays valid UTF-8.
            $cut = self::QUOTE_LIMIT;
            while ($cut > 0 && (ord($formula[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $quoted = substr($formula, 0, $cut).'...';
        }

        parent::__construct(sprintf('%s (at offset %d in `%s`)', $message, $at, $quoted));
    }

    /**
     * Every stable code, grouped as SPEC.md section 9 groups them.
     *
     * @return array<string, list<string>>
     */
    public static function codes(): array
    {
        return [
            'parse' => self::PARSE,
            'check' => self::CHECK_ERRORS,
            'warning' => self::WARNINGS,
            'limit' => self::LIMITS,
            'refusal' => self::REFUSALS,
        ];
    }
}
