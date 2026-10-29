<?php

declare(strict_types=1);

namespace FancyFormula;

/**
 * A host seed for RAND, RANDBETWEEN and RANDARRAY (SPEC.md section 11.3):
 * xoshiro128** state from the first 16 bytes of SHA-256 of the seed string.
 *
 * Slice 1 takes the seed and records its digest in the fingerprint, but does
 * not evaluate the random functions yet: with a seed they report
 * FORMULA_UNKNOWN_FUNCTION ("a later slice"), without one FORMULA_NEEDS_SEED.
 */
final readonly class Seed
{
    private function __construct(public string $digest) {}

    public static function fromString(string $seed): self
    {
        return new self(hash('sha256', $seed));
    }

    /** The 128-bit xoshiro128** state: the first 16 bytes of the digest. */
    public function state(): string
    {
        return (string) hex2bin(substr($this->digest, 0, 32));
    }
}
