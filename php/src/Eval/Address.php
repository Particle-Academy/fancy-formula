<?php

declare(strict_types=1);

namespace FancyFormula\Eval;

/**
 * A sheet-qualified cell or rectangular range, as a binding or a `names`
 * target: `Sheet!A1`, `'My Sheet'!A1:B5`. `$` markers are accepted and ignored.
 */
final readonly class Address
{
    public function __construct(
        public string $sheet,
        public int $r1,
        public int $c1,
        public int $r2,
        public int $c2,
    ) {}

    public static function parse(string $text): ?self
    {
        $pattern = "/^(?:'((?:[^']|'')+)'|([A-Za-z_][A-Za-z0-9_]*))!\\$?([A-Za-z]{1,3})\\$?([0-9]{1,7})(?::\\$?([A-Za-z]{1,3})\\$?([0-9]{1,7}))?$/";
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }
        $sheet = $m[1] !== '' ? str_replace("''", "'", $m[1]) : $m[2];
        $c1 = self::column($m[3]);
        $r1 = (int) $m[4];
        $c2 = isset($m[5]) && $m[5] !== '' ? self::column($m[5]) : $c1;
        $r2 = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : $r1;
        if ($r1 < 1 || $r2 < 1) {
            return null;
        }

        return new self($sheet, min($r1, $r2), min($c1, $c2), max($r1, $r2), max($c1, $c2));
    }

    public static function column(string $letters): int
    {
        $col = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }

        return $col;
    }

    public function height(): int
    {
        return $this->r2 - $this->r1 + 1;
    }

    public function width(): int
    {
        return $this->c2 - $this->c1 + 1;
    }

    public function isCell(): bool
    {
        return $this->r1 === $this->r2 && $this->c1 === $this->c2;
    }

    /** Sheet names compare case-insensitively, as in Sheets. */
    public function sheetKey(): string
    {
        return strtoupper($this->sheet);
    }
}
