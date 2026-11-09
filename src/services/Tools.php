<?php

namespace cossette\projectassistant\services;

use cossette\projectassistant\events\RegisterToolsEvent;
use cossette\projectassistant\Plugin;
use cossette\projectassistant\records\ConversationRecord;
use cossette\projectassistant\tools\CraftMcp;
use cossette\projectassistant\tools\DescribeContentModelTool;
use cossette\projectassistant\tools\ListFilesTool;
use cossette\projectassistant\tools\ProposeTicketTool;
use cossette\projectassistant\tools\QueryEntriesTool;
use cossette\projectassistant\tools\ReadFileTool;
use cossette\projectassistant\tools\ReadLogsTool;
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

    /** @var array<string, array<string, ToolInterface>> per mode */
    private array $tools = [];

    /**
     * @param string $mode `client` (read-only + ticket drafts) or `developer`
     *   (read-only + Craft's MCP tools, ticket drafts left out)
     * @return array<string, ToolInterface>
     */
    public function getTools(string $mode = ConversationRecord::MODE_CLIENT): array
    {
        if (isset($this->tools[$mode])) {
            return $this->tools[$mode];
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
        if ($mode === ConversationRecord::MODE_DEVELOPER) {
            if (Plugin::getInstance()->getSettings()->developerReadLogs) {
                $tools[] = new ReadLogsTool();
            }
            array_push($tools, ...CraftMcp::tools());
        } else {
            $tools[] = new ProposeTicketTool();
        }

        $event = new RegisterToolsEvent(['tools' => $tools, 'mode' => $mode]);
        $this->trigger(self::EVENT_REGISTER_TOOLS, $event);

        $this->tools[$mode] = [];
        foreach ($event->tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $this->tools[$mode][$tool->getName()] = $tool;
            }
        }

        return $this->tools[$mode];
    }

    public function getTool(string $name, string $mode = ConversationRecord::MODE_CLIENT): ?ToolInterface
    {
        return $this->getTools($mode)[$name] ?? null;
    }

    /** Tool definitions for the Messages API, in a stable order (keeps the prompt cache valid). */
    public function getDefinitions(string $mode = ConversationRecord::MODE_CLIENT): array
    {
        $definitions = [];
        foreach ($this->getTools($mode) as $tool) {
            $definitions[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return $definitions;
    }

    public function execute(string $name, array $input, ToolContext $context, string $mode = ConversationRecord::MODE_CLIENT): ToolResult
    {
        $tool = $this->getTool($name, $mode);
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
