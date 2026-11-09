<?php

namespace cossette\projectassistant\tools;

use Craft;
use cossette\projectassistant\Plugin;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;

/**
 * One of Craft's own MCP tools (Craft 5.12+), called in-process as the
 * signed-in user. Craft applies its permission checks on every call.
 */
class CraftMcpTool implements ConfirmableToolInterface
{
    /** Tool names sent to the model: Anthropic allows [a-zA-Z0-9_-] only. */
    public const PREFIX = 'craft_';
    private const MAX_RESULT_CHARS = 60000;

    private static ?Session $session = null;

    public function __construct(
        private readonly ToolReference $reference,
        private readonly ReferenceHandler $handler,
    ) {
    }

    public static function modelName(string $craftName): string
    {
        return self::PREFIX . preg_replace('/[^a-zA-Z0-9_]/', '_', $craftName);
    }

    public function getCraftName(): string
    {
        return $this->reference->tool->name;
    }

    public function getName(): string
    {
        return self::modelName($this->getCraftName());
    }

    public function getDescription(): string
    {
        $annotations = $this->reference->tool->annotations;
        $kind = match (true) {
            $annotations?->readOnlyHint === true => 'Read-only.',
            $annotations?->destructiveHint === true => 'Changes or removes existing data.',
            default => 'Creates or changes data.',
        };

        return "Craft MCP tool `{$this->getCraftName()}`. {$kind} " . ($this->reference->tool->description ?? '');
    }

    public function getInputSchema(): array
    {
        $schema = $this->reference->tool->inputSchema;
        if (empty($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    public function getLabel(array $input): string
    {
        $target = $input['id'] ?? $input['elementId'] ?? $input['draftId'] ?? $input['type'] ?? null;

        return Craft::t(Plugin::HANDLE, 'Craft: {tool}', ['tool' => $this->getCraftName()])
            . (is_scalar($target) ? " ({$target})" : '');
    }

    public function isReadOnly(): bool
    {
        return $this->reference->tool->annotations?->readOnlyHint === true;
    }

    public function isDestructive(): bool
    {
        return $this->reference->tool->annotations?->destructiveHint === true;
    }

    public function requiresConfirmation(array $input): bool
    {
        return !$this->isReadOnly();
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        // Craft's capabilities act as the identity on the user component.
        $identity = Craft::$app->getUser()->getIdentity();
        if (!$identity || $identity->id !== $context->user->id) {
            return ToolResult::error('Craft tools must run as the signed-in user.');
        }

        self::$session ??= new Session(new InMemorySessionStore());

        try {
            $result = $this->handler->handle($this->reference, $input + ['_session' => self::$session]);
            $content = $this->reference->formatResult($result);
        } catch (ToolCallException $e) {
            return ToolResult::error($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error("Craft MCP tool {$this->getCraftName()} failed: " . $e->getMessage(), Plugin::HANDLE);

            return ToolResult::error(get_class($e) . ': ' . $e->getMessage());
        }

        $text = [];
        $isError = false;
        foreach ((array)$content as $item) {
            if (is_object($item) && property_exists($item, 'text')) {
                $text[] = (string)$item->text;
            } else {
                $text[] = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }
        if ($result instanceof \Mcp\Schema\Result\CallToolResult) {
            $isError = $result->isError;
        }
        $text = trim(implode("\n", $text));
        if (mb_strlen($text) > self::MAX_RESULT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_RESULT_CHARS) . "\n… truncated; narrow the request (limit, fields, criteria).";
        }

        return new ToolResult($text !== '' ? $text : '(done)', $isError);
    }
}
