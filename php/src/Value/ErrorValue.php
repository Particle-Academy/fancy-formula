<?php

declare(strict_types=1);

namespace FancyFormula\Value;

/**
 * A spreadsheet error: a VALUE in a result, never a diagnostic and never
 * thrown (SPEC.md section 2). `detail` mirrors the Sheets tooltip and is not
 * contract until captured.
 */
final readonly class ErrorValue
{
    /** The error codes. #NULL! and #ERROR! are real Sheets values (captured, H-16). */
    public const array CODES = ['#N/A', '#DIV/0!', '#VALUE!', '#REF!', '#NAME?', '#NUM!', '#NULL!', '#ERROR!'];

    public function __construct(public string $code, public string $detail = '') {}

    public static function na(string $detail = ''): self
    {
        return new self('#N/A', $detail);
    }

    public static function div0(string $detail = 'Division by zero.'): self
    {
        return new self('#DIV/0!', $detail);
    }

    public static function value(string $detail = ''): self
    {
        return new self('#VALUE!', $detail);
    }

    public static function ref(string $detail = ''): self
    {
        return new self('#REF!', $detail);
    }

    public static function num(string $detail = ''): self
    {
        return new self('#NUM!', $detail);
    }
}
