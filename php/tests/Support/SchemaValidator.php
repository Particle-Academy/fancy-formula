<?php

declare(strict_types=1);

namespace FancyFormula\Tests\Support;

use RuntimeException;
use stdClass;

/**
 * A JSON Schema (draft 2020-12) validator for the SUBSET of keywords this
 * repository's schemas use. Test-only: the schemas are the contract, and this
 * is how the build proves their examples satisfy them.
 *
 * Written here rather than taken from a library because every piece of
 * third-party code needs approval, and a test helper is not an exception.
 *
 * The one property that makes it trustworthy: an UNSUPPORTED KEYWORD THROWS.
 * A validator that skips a keyword it does not know passes every document that
 * keyword would have refused -- a check that does not check. So adding a new
 * keyword to a schema forces support for it here, or the build goes red.
 *
 * Instances and schemas are decoded WITHOUT `assoc`, so a JSON object
 * (stdClass) and a JSON array (list) stay distinguishable; `{}` and `[]` are
 * different values and the schemas care.
 */
final class SchemaValidator
{
    /** Keywords that carry no assertion. */
    private const array ANNOTATIONS = [
        '$schema', '$id', '$comment', 'title', 'description', 'examples', 'default', '$defs',
    ];

    /** Keywords this validator asserts. */
    private const array ASSERTIONS = [
        'type', 'enum', 'const', 'required', 'properties', 'additionalProperties',
        'patternProperties', 'propertyNames', 'minProperties', 'maxProperties',
        'items', 'prefixItems', 'minItems', 'maxItems', 'uniqueItems',
        'minLength', 'maxLength', 'pattern', 'minimum', 'maximum',
        'oneOf', 'anyOf', 'allOf', 'not', 'if', 'then', 'else', '$ref',
    ];

    /** @var array<string, stdClass> file name => decoded schema */
    private array $documents = [];

    public function __construct(private readonly string $schemaDir) {}

    /** @return stdClass the decoded schema document */
    public function document(string $file): stdClass
    {
        if (! isset($this->documents[$file])) {
            $path = $this->schemaDir.'/'.$file;
            if (! is_file($path)) {
                throw new RuntimeException("No schema file {$path}.");
            }
            $decoded = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof stdClass) {
                throw new RuntimeException("{$file} is not a JSON object.");
            }
            $this->documents[$file] = $decoded;
        }

        return $this->documents[$file];
    }

    /**
     * Validate an instance against a schema file. Returns the list of errors;
     * an empty list means valid.
     *
     * @return list<string>
     */
    public function validate(mixed $instance, string $file): array
    {
        $errors = [];
        $this->check($instance, $this->document($file), $file, '', $errors);

        return $errors;
    }

    /** Decode JSON the way this validator expects instances. */
    public static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /** Convert a PHP associative array (e.g. a stub result) into a JSON-shaped instance. */
    public static function fromArray(array $value): mixed
    {
        return self::decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param list<string> $errors */
    private function check(mixed $value, mixed $schema, string $file, string $at, array &$errors): void
    {
        if ($schema === true) {
            return;
        }
        if ($schema === false) {
            $errors[] = "{$at}: no value is allowed here";

            return;
        }
        if (! $schema instanceof stdClass) {
            throw new RuntimeException("{$file}: a schema at {$at} is not an object");
        }

        foreach (array_keys(get_object_vars($schema)) as $keyword) {
            if (! in_array($keyword, self::ANNOTATIONS, true) && ! in_array($keyword, self::ASSERTIONS, true)) {
                throw new RuntimeException("{$file}: unsupported keyword `{$keyword}` at {$at}; teach SchemaValidator before using it.");
            }
        }

        if (isset($schema->{'$ref'})) {
            [$refFile, $target] = $this->resolve($schema->{'$ref'}, $file);
            $this->check($value, $target, $refFile, $at, $errors);
        }

        if (isset($schema->type)) {
            $types = is_array($schema->type) ? $schema->type : [$schema->type];
            $ok = false;
            foreach ($types as $type) {
                if ($this->isType($value, $type)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                $errors[] = "{$at}: expected ".implode('|', $types).', got '.$this->typeOf($value);

                return;
            }
        }

        if (property_exists($schema, 'const') && ! $this->equal($value, $schema->const)) {
            $errors[] = "{$at}: must equal ".json_encode($schema->const);
        }

        if (isset($schema->enum)) {
            $found = false;
            foreach ($schema->enum as $candidate) {
                if ($this->equal($value, $candidate)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $errors[] = "{$at}: ".json_encode($value).' is not one of '.json_encode($schema->enum);
            }
        }

        if (is_string($value)) {
            $length = mb_strlen($value, 'UTF-8');
            if (isset($schema->minLength) && $length < $schema->minLength) {
                $errors[] = "{$at}: shorter than {$schema->minLength}";
            }
            if (isset($schema->maxLength) && $length > $schema->maxLength) {
                $errors[] = "{$at}: longer than {$schema->maxLength}";
            }
            if (isset($schema->pattern) && ! $this->matches($schema->pattern, $value)) {
                $errors[] = "{$at}: ".json_encode($value)." does not match {$schema->pattern}";
            }
        }

        if ((is_int($value) || is_float($value))) {
            if (isset($schema->minimum) && $value < $schema->minimum) {
                $errors[] = "{$at}: below minimum {$schema->minimum}";
            }
            if (isset($schema->maximum) && $value > $schema->maximum) {
                $errors[] = "{$at}: above maximum {$schema->maximum}";
            }
        }

        if (is_array($value)) {
            $this->checkArray($value, $schema, $file, $at, $errors);
        }

        if ($value instanceof stdClass) {
            $this->checkObject($value, $schema, $file, $at, $errors);
        }

        if (isset($schema->allOf)) {
            foreach ($schema->allOf as $i => $sub) {
                $this->check($value, $sub, $file, $at, $errors);
            }
        }

        if (isset($schema->anyOf)) {
            $passed = false;
            foreach ($schema->anyOf as $sub) {
                if ($this->passes($value, $sub, $file, $at)) {
                    $passed = true;
                    break;
                }
            }
            if (! $passed) {
                $errors[] = "{$at}: matches none of anyOf";
            }
        }

        if (isset($schema->oneOf)) {
            $count = 0;
            foreach ($schema->oneOf as $sub) {
                if ($this->passes($value, $sub, $file, $at)) {
                    $count++;
                }
            }
            if ($count !== 1) {
                $errors[] = "{$at}: matches {$count} of oneOf, expected exactly 1";
            }
        }

        if (property_exists($schema, 'not') && $this->passes($value, $schema->not, $file, $at)) {
            $errors[] = "{$at}: matches a schema it must not";
        }

        if (property_exists($schema, 'if')) {
            if ($this->passes($value, $schema->if, $file, $at)) {
                if (property_exists($schema, 'then')) {
                    $this->check($value, $schema->then, $file, $at, $errors);
                }
            } elseif (property_exists($schema, 'else')) {
                $this->check($value, $schema->else, $file, $at, $errors);
            }
        }
    }

    /**
     * @param  list<mixed>  $value
     * @param  list<string>  $errors
     */
    private function checkArray(array $value, stdClass $schema, string $file, string $at, array &$errors): void
    {
        $count = count($value);
        if (isset($schema->minItems) && $count < $schema->minItems) {
            $errors[] = "{$at}: fewer than {$schema->minItems} items";
        }
        if (isset($schema->maxItems) && $count > $schema->maxItems) {
            $errors[] = "{$at}: more than {$schema->maxItems} items";
        }
        if (! empty($schema->uniqueItems)) {
            $seen = [];
            foreach ($value as $item) {
                $key = json_encode($item);
                if (isset($seen[$key])) {
                    $errors[] = "{$at}: duplicate item {$key}";
                }
                $seen[$key] = true;
            }
        }
        $prefix = $schema->prefixItems ?? [];
        foreach ($value as $i => $item) {
            if (isset($prefix[$i])) {
                $this->check($item, $prefix[$i], $file, "{$at}/{$i}", $errors);
            } elseif (property_exists($schema, 'items')) {
                $this->check($item, $schema->items, $file, "{$at}/{$i}", $errors);
            }
        }
    }

    /** @param list<string> $errors */
    private function checkObject(stdClass $value, stdClass $schema, string $file, string $at, array &$errors): void
    {
        $members = get_object_vars($value);
        $count = count($members);
        if (isset($schema->minProperties) && $count < $schema->minProperties) {
            $errors[] = "{$at}: fewer than {$schema->minProperties} properties";
        }
        if (isset($schema->maxProperties) && $count > $schema->maxProperties) {
            $errors[] = "{$at}: more than {$schema->maxProperties} properties";
        }
        foreach ($schema->required ?? [] as $name) {
            if (! array_key_exists($name, $members)) {
                $errors[] = "{$at}: missing required `{$name}`";
            }
        }
        $properties = isset($schema->properties) ? get_object_vars($schema->properties) : [];
        $patterns = isset($schema->patternProperties) ? get_object_vars($schema->patternProperties) : [];
        foreach ($members as $name => $member) {
            $name = (string) $name;
            if (property_exists($schema, 'propertyNames')) {
                $this->check($name, $schema->propertyNames, $file, "{$at}/(name {$name})", $errors);
            }
            $covered = false;
            if (array_key_exists($name, $properties)) {
                $covered = true;
                $this->check($member, $properties[$name], $file, "{$at}/{$name}", $errors);
            }
            foreach ($patterns as $pattern => $sub) {
                if ($this->matches((string) $pattern, $name)) {
                    $covered = true;
                    $this->check($member, $sub, $file, "{$at}/{$name}", $errors);
                }
            }
            if (! $covered && property_exists($schema, 'additionalProperties')) {
                $this->check($member, $schema->additionalProperties, $file, "{$at}/{$name}", $errors);
            }
        }
    }

    private function passes(mixed $value, mixed $schema, string $file, string $at): bool
    {
        $errors = [];
        $this->check($value, $schema, $file, $at, $errors);

        return $errors === [];
    }

    /** @return array{0: string, 1: mixed} [file the target lives in, target schema] */
    private function resolve(string $ref, string $file): array
    {
        [$target, $pointer] = array_pad(explode('#', $ref, 2), 2, '');
        $targetFile = $target === '' ? $file : $target;
        if (str_contains($targetFile, '/') || str_contains($targetFile, '\\')) {
            throw new RuntimeException("{$file}: \$ref {$ref} leaves the schema directory");
        }
        $node = $this->document($targetFile);
        if ($pointer !== '') {
            foreach (explode('/', ltrim($pointer, '/')) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                if (! $node instanceof stdClass || ! property_exists($node, $part)) {
                    throw new RuntimeException("{$file}: \$ref {$ref} does not resolve");
                }
                $node = $node->{$part};
            }
        }

        return [$targetFile, $node];
    }

    private function matches(string $pattern, string $subject): bool
    {
        $result = preg_match('~'.str_replace('~', '\~', $pattern).'~u', $subject);
        if ($result === false) {
            throw new RuntimeException("Pattern {$pattern} does not compile");
        }

        return $result === 1;
    }

    private function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value && is_finite($value)),
            'number' => is_int($value) || is_float($value),
            'array' => is_array($value),
            'object' => $value instanceof stdClass,
            default => throw new RuntimeException("Unknown type {$type}"),
        };
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_string($value) => 'string',
            is_int($value), is_float($value) => 'number',
            is_array($value) => 'array',
            default => 'object',
        };
    }

    private function equal(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        if (is_bool($a) || is_bool($b) || $a === null || $b === null || is_string($a) || is_string($b)) {
            return $a === $b;
        }

        return json_encode($a) === json_encode($b);
    }
}
