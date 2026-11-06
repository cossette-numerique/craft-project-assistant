<?php

namespace cossette\projectassistant\services;

use cossette\projectassistant\events\RegisterToolsEvent;
use cossette\projectassistant\Plugin;
use cossette\projectassistant\tools\DescribeContentModelTool;
use cossette\projectassistant\tools\ListFilesTool;
use cossette\projectassistant\tools\ProposeTicketTool;
use cossette\projectassistant\tools\QueryEntriesTool;
use cossette\projectassistant\tools\ReadFileTool;
use cossette\projectassistant\tools\RunSqlTool;
use cossette\projectassistant\tools\SearchCodeTool;
use cossette\projectassistant\tools\ToolContext;
use cossette\projectassistant\tools\ToolInterface;
use cossette\projectassistant\tools\ToolResult;
use yii\base\Component;

/**
 * The tools the assistant can call.
 *
 * Projects add their own (read-only) tools from a module:
 *
 * ```php
 * Event::on(Tools::class, Tools::EVENT_REGISTER_TOOLS, function (RegisterToolsEvent $e) {
 *     $e->tools[] = new MyRenewalsTool();
 * });
 * ```
 */
class Tools extends Component
{
    public const EVENT_REGISTER_TOOLS = 'registerTools';

    /** @var array<string, ToolInterface>|null */
    private ?array $tools = null;

    /** @return array<string, ToolInterface> */
    public function getTools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $tools = [
            new DescribeContentModelTool(),
            new QueryEntriesTool(),
            new ListFilesTool(),
            new ReadFileTool(),
            new SearchCodeTool(),
        ];
        if (Plugin::getInstance()->getSettings()->isSqlEnabled()) {
            $tools[] = new RunSqlTool();
        }
        $tools[] = new ProposeTicketTool();

        $event = new RegisterToolsEvent(['tools' => $tools]);
        $this->trigger(self::EVENT_REGISTER_TOOLS, $event);

        $this->tools = [];
        foreach ($event->tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $this->tools[$tool->getName()] = $tool;
            }
        }

        return $this->tools;
    }

    public function getTool(string $name): ?ToolInterface
    {
        return $this->getTools()[$name] ?? null;
    }

    /** Tool definitions for the Messages API, in a stable order (keeps the prompt cache valid). */
    public function getDefinitions(): array
    {
        $definitions = [];
        foreach ($this->getTools() as $tool) {
            $definitions[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return $definitions;
    }

    public function execute(string $name, array $input, ToolContext $context): ToolResult
    {
        $tool = $this->getTool($name);
        if ($tool === null) {
            return ToolResult::error("Unknown tool: $name");
        }

        try {
            return $tool->execute($input, $context);
        } catch (\Throwable $e) {
            \Craft::error("Tool $name failed: " . $e->getMessage() . "\n" . $e->getTraceAsString(), Plugin::HANDLE);

            return ToolResult::error('The tool failed: ' . $e->getMessage());
        }
    }
}
