<?php

namespace cossette\projectassistant\tools;

use Craft;
use cossette\projectassistant\Plugin;

abstract class BaseTool implements ToolInterface
{
    public function getLabel(array $input): string
    {
        return Craft::t(Plugin::HANDLE, 'Running {tool}', ['tool' => $this->getName()]);
    }

    /**
     * Input schema helper: an object with these properties, of which $required are required.
     */
    protected static function schema(array $properties, array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties ?: new \stdClass(),
            'additionalProperties' => false,
        ];
        if ($required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    protected static function str(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? $default;

        return is_scalar($value) ? trim((string)$value) : $default;
    }

    protected static function int(array $input, string $key, int $default, int $min, int $max): int
    {
        $value = isset($input[$key]) && is_numeric($input[$key]) ? (int)$input[$key] : $default;

        return max($min, min($max, $value));
    }
}
