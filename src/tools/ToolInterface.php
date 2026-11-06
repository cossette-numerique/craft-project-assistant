<?php

namespace cossette\projectassistant\tools;

/**
 * A tool the assistant can call. Tools must be read-only: they may look at
 * code, content and data, never change them. Register extra tools from a
 * project module with Tools::EVENT_REGISTER_TOOLS.
 */
interface ToolInterface
{
    /** snake_case name the model calls the tool by. */
    public function getName(): string;

    /** What the tool does and when to use it, written for the model. */
    public function getDescription(): string;

    /** JSON Schema of the input object. */
    public function getInputSchema(): array;

    /** Short progress label for the chat UI, e.g. "Reading modules/foo.php". */
    public function getLabel(array $input): string;

    public function execute(array $input, ToolContext $context): ToolResult;
}
