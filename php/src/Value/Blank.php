<?php

declare(strict_types=1);

namespace FancyFormula\Value;

/**
 * The empty cell. Distinct from the empty TEXT "" (SPEC.md section 4): an
 * unbound cell, a null range cell, an omitted input. One instance.
 */
final class Blank
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function get(): self
    {
        return self::$instance ??= new self;
    }
}
