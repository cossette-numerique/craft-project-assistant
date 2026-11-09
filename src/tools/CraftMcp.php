<?php

namespace cossette\projectassistant\tools;

use Craft;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ToolReference;

/**
 * Exposes Craft's built-in MCP tools (Craft 5.12+) to the developer chat.
 *
 * Craft decides which tools the signed-in user may see (admin, permissions,
 * allowAdminChanges, plugin hooks); this class then keeps every read-only
 * tool and the write tools matching `developerWriteTools`.
 */
class CraftMcp
{
    public static function isAvailable(): bool
    {
        return class_exists(\craft\mcp\StdioCapabilityDiscovery::class) && Craft::$app->has('mcp');
    }

    /**
     * Every read-only Craft tool, plus the write tools matching $writePatterns.
     *
     * @param string[] $writePatterns fnmatch patterns on Craft's tool names
     * @return CraftMcpTool[]
     */
    public static function tools(array $writePatterns = []): array
    {
        if (!self::isAvailable() || !Craft::$app->getUser()->getIdentity()) {
            return [];
        }

        $container = Craft::$app->get('mcp')->getCapabilityContainer();
        /** @var \craft\mcp\StdioCapabilityDiscovery $discovery */
        $discovery = $container->get(\craft\mcp\StdioCapabilityDiscovery::class);
        $directory = dirname((new \ReflectionClass(\craft\mcp\ServerFactory::class))->getFileName()) . '/capabilities';
        $state = $discovery->discover($directory, ['.']);
        $handler = new ReferenceHandler($container);

        $tools = [];
        foreach ($state->getTools() as $reference) {
            if (!$reference instanceof ToolReference) {
                continue;
            }
            $tool = new CraftMcpTool($reference, $handler);
            if (!$tool->isReadOnly() && !self::matches($tool->getCraftName(), $writePatterns)) {
                continue;
            }
            $tools[] = $tool;
        }
        usort($tools, fn(CraftMcpTool $a, CraftMcpTool $b) => strcmp($a->getCraftName(), $b->getCraftName()));

        return $tools;
    }

    /** Craft's own instructions for MCP clients (how to use its tools). */
    public static function instructions(): string
    {
        if (!self::isAvailable() || !class_exists(\craft\mcp\AdminInstructions::class)) {
            return '';
        }
        try {
            return (string)Craft::$app->get('mcp')->getCapabilityContainer()->get(\craft\mcp\AdminInstructions::class)->brief();
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch((string)$pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
