<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Checks real responses against the schemas documented in openapi/openapi.json.
 */
trait ValidatesOpenApi
{
    /** @var array<string, mixed>|null */
    private ?array $openApi = null;

    /**
     * @return array<string, mixed>
     */
    protected function openApi(): array
    {
        return $this->openApi ??= json_decode((string) File::get(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function assertMatchesSchema(string $name, mixed $value): void
    {
        $errors = $this->validateSchema($this->openApi()['components']['schemas'][$name], $value, $name);

        $this->assertSame([], $errors, "Response does not match schema {$name}");
    }

    /**
     * Minimal JSON Schema check: $ref, type, enum, pattern, required, properties, additionalProperties, items.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateSchema(array $schema, mixed $value, string $path): array
    {
        if (isset($schema['$ref'])) {
            return $this->validateSchema($this->openApi()['components']['schemas'][basename($schema['$ref'])], $value, $path);
        }

        $errors = [];
        $types = (array) ($schema['type'] ?? []);
        $actual = match (true) {
            is_null($value) => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && ($value === [] || array_is_list($value)) => in_array('object', $types, true) && $value === [] ? 'object' : 'array',
            default => 'object',
        };

        // JSON Schema: every integer is also a number.
        if ($types !== [] && ! in_array($actual, $types, true) && ! ($actual === 'integer' && in_array('number', $types, true))) {
            return ["{$path}: expected ".implode('|', $types).", got {$actual}"];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: value not in enum";
        }
        if (isset($schema['pattern']) && is_string($value) && preg_match('/'.$schema['pattern'].'/', $value) !== 1) {
            $errors[] = "{$path}: does not match pattern";
        }

        if ($actual === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$path}.{$key}: missing";
                }
            }
            foreach ($value as $key => $item) {
                if (isset($schema['properties'][$key])) {
                    array_push($errors, ...$this->validateSchema($schema['properties'][$key], $item, "{$path}.{$key}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$key}: undocumented property";
                }
            }
        }

        if ($actual === 'array' && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                array_push($errors, ...$this->validateSchema($schema['items'], $item, "{$path}[{$index}]"));
            }
        }

        return $errors;
    }
}
