<?php

declare(strict_types=1);

namespace FancyFormula\Tests\Support;

use FancyFormula\Engine;
use FancyFormula\Options;
use RuntimeException;

/**
 * Capture rows (capture/v1/request.json) run through the engine: each row's
 * own request, the same cell-bound inputs the workbook holds.
 */
final class Capture
{
    /** @var array<string, mixed>|null */
    private static ?array $doc = null;

    /** @return array<string, mixed> */
    public static function doc(): array
    {
        return self::$doc ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/capture/v1/request.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    public static function row(string $id): array
    {
        foreach (self::doc()['cases'] as $case) {
            if ($case['id'] === $id) {
                return $case;
            }
        }
        throw new RuntimeException("No capture row {$id}.");
    }

    /** @return list<string> */
    public static function hypothesisRows(string $hypothesis): array
    {
        return self::doc()['hypotheses'][$hypothesis]['rows'] ?? [];
    }

    /**
     * The row's `result` value in the exchange vocabulary, or the whole
     * evaluation when it did not succeed.
     *
     * @return array<string, mixed>
     */
    public static function value(string $id, ?Options $options = null): array
    {
        $out = (new Engine($options))->evaluate(self::row($id)['request'])->toArray();
        if ($out['ok'] !== true) {
            return $out;
        }
        // Format tags and error details are not what a capture row decides.
        $value = $out['results']['result']['value'];
        unset($value['format'], $value['detail']);

        return $value;
    }
}
