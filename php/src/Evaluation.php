<?php

declare(strict_types=1);

namespace FancyFormula;

use JsonSerializable;

/**
 * What Engine::evaluate() returns (schema/evaluation.schema.json): exactly one
 * of a success, a FORMULA_CHECK_FAILED refusal, a FORMULA_INPUT_CONSTRAINT
 * refusal, or a limit abort. Never a partial success.
 *
 * `toArray()` is plain PHP arrays. `json_encode($evaluation)` gives the
 * schema-valid JSON: it encodes the `results` and `traces` maps as objects
 * even when empty (trace `none` leaves `traces` empty).
 */
final readonly class Evaluation implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data) {}

    public function ok(): bool
    {
        return $this->data['ok'];
    }

    /** The refusal or abort code; null on success. */
    public function code(): ?string
    {
        return $this->data['code'] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): mixed
    {
        $data = $this->data;
        foreach (['results', 'traces'] as $map) {
            if (array_key_exists($map, $data)) {
                $data[$map] = (object) $data[$map];
            }
        }
        if (isset($data['truncatedViolations'])) {
            $data['truncatedViolations'] = (object) $data['truncatedViolations'];
        }

        return $data;
    }
}
