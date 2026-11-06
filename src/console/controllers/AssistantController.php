<?php

namespace cossette\projectassistant\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use craft\helpers\Console;
use craft\helpers\Json;
use cossette\projectassistant\Plugin;
use cossette\projectassistant\tools\ToolContext;
use yii\console\ExitCode;

/**
 * Developer commands for checking the assistant's setup.
 *
 *   php craft project-assistant/assistant/tools
 *   php craft project-assistant/assistant/run-tool read_file '{"path":"modules/foo/Module.php"}' --user=admin
 *   php craft project-assistant/assistant/system-prompt --user=admin
 *   php craft project-assistant/assistant/ask "How many news articles are published?" --user=admin
 */
class AssistantController extends Controller
{
    /** Username or email of the user to act as (tools apply their permissions). Default: the first admin. */
    public ?string $user = null;

    /** Continue this conversation ID (ask). */
    public ?int $conversation = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['user', 'conversation']);
    }

    /** Lists the tools the assistant has, with their descriptions. */
    public function actionTools(): int
    {
        foreach (Plugin::getInstance()->tools->getTools() as $tool) {
            $this->stdout($tool->getName(), Console::FG_GREEN);
            $this->stdout("\n  " . wordwrap($tool->getDescription(), 100, "\n  ") . "\n\n");
        }

        return ExitCode::OK;
    }

    /** Runs one tool with a JSON input and prints the result. */
    public function actionRunTool(string $name, string $input = '{}'): int
    {
        $user = $this->resolveUser();
        if (!$user) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $result = Plugin::getInstance()->tools->execute($name, Json::decode($input) ?? [], new ToolContext($user));
        $this->stdout(($result->isError ? '[error] ' : '') . $result->content . "\n", $result->isError ? Console::FG_RED : Console::RESET);

        return $result->isError ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /** Prints the system prompt as the model receives it, with its size. */
    public function actionSystemPrompt(): int
    {
        $user = $this->resolveUser();
        if (!$user) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $blocks = Plugin::getInstance()->agent->systemPrompt($user);
        $text = implode("\n\n----- (cache breakpoint) -----\n\n", array_column($blocks, 'text'));
        $this->stdout($text . "\n\n");
        $this->stdout(sprintf("%d characters (~%d tokens)\n", mb_strlen($text), mb_strlen($text) / 4), Console::FG_YELLOW);

        return ExitCode::OK;
    }

    /** Asks a question end to end (real model calls, real costs) and prints the events. */
    public function actionAsk(string $question): int
    {
        $user = $this->resolveUser();
        if (!$user) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $plugin = Plugin::getInstance();
        $conversation = $this->conversation
            ? $plugin->conversations->getById($this->conversation)
            : $plugin->conversations->create($user, $question);
        if (!$conversation) {
            $this->stderr("Conversation not found\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("Conversation #{$conversation->id}\n", Console::FG_GREY);

        $plugin->agent->ask($conversation, $user, $question, function (string $event, array $data) {
            match ($event) {
                'tool' => $this->stdout("  → {$data['label']}\n", Console::FG_CYAN),
                'assistant' => $this->stdout("\n{$data['text']}\n\n"),
                'ticket_draft' => $this->stdout("[ticket draft]\n" . Json::encode($data['draft'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", Console::FG_YELLOW),
                'error', 'notice' => $this->stdout("[{$event}] {$data['message']}\n", Console::FG_RED),
                'done' => $this->stdout(sprintf("Cost: $%.4f\n", $data['questionCostUsd'] ?? 0), Console::FG_GREY),
                default => null,
            };
        });

        return ExitCode::OK;
    }

    private function resolveUser(): ?User
    {
        $user = $this->user
            ? Craft::$app->getUsers()->getUserByUsernameOrEmail($this->user)
            : User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();
        if (!$user) {
            $this->stderr("User not found\n", Console::FG_RED);
        }

        return $user;
    }
}
