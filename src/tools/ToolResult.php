<?php

namespace cossette\projectassistant\tools;

class ToolResult
{
    /**
     * @param string $content What the model reads.
     * @param bool $isError Marks the result as an error for the model.
     * @param array|null $uiEvent Optional event for the chat UI: ['type' => ..., ...].
     */
    public function __construct(
        public readonly string $content,
        public readonly bool $isError = false,
        public readonly ?array $uiEvent = null,
    ) {
    }

    public static function error(string $message): self
    {
        return new self($message, true);
    }

    public static function json(mixed $data): self
    {
        return new self(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
