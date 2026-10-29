<?php

declare(strict_types=1);

namespace FancyFormula;

use JsonSerializable;

/**
 * What Engine::check() returns (schema/check-report.schema.json).
 *
 * `toArray()` is plain PHP arrays. `json_encode($report)` gives the
 * schema-valid JSON: it encodes the `references` map as an object even when it
 * is empty, which a PHP array alone cannot say.
 */
final readonly class CheckReport implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data) {}

    public function ok(): bool
    {
        return $this->data['ok'];
    }

    /** @return list<array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->data['diagnostics'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): mixed
    {
        $data = $this->data;
        if (array_key_exists('references', $data)) {
            $data['references'] = (object) $data['references'];
        }

        return $data;
    }
}
