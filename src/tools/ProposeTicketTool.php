<?php

namespace cossette\projectassistant\tools;

use Craft;
use cossette\projectassistant\Plugin;

/**
 * Drafts a ticket for the dev team. It never sends anything: the draft is
 * shown to the user, who edits and confirms it in the chat. That keeps the
 * only outward action in a human's hands.
 */
class ProposeTicketTool extends BaseTool
{
    public const CATEGORIES = ['feature_request', 'bug', 'configuration_change', 'content_help', 'question'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function getName(): string
    {
        return 'propose_ticket';
    }

    public function getDescription(): string
    {
        return 'Draft a ticket for the development team and show it to the user for review. Nothing is sent until the user confirms. '
            . 'Use it when you have established that the CMS cannot do what the user needs as it is built today (a missing field, '
            . 'feature or integration, a bug, or a change only developers can make), or when the user asks for a ticket. '
            . 'Do not use it just because you could not find an answer: say what you checked instead. '
            . 'Write it so a developer who never saw the chat can act on it: the need in the user\'s words, what you verified and how, '
            . 'and a suggested approach. Write the ticket in the language of the conversation.';
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'title' => ['type' => 'string', 'description' => 'Short, specific title (under 90 characters).'],
            'category' => ['type' => 'string', 'enum' => self::CATEGORIES],
            'priority' => ['type' => 'string', 'enum' => self::PRIORITIES, 'description' => 'Based on what the user said about impact and deadlines. Default normal.'],
            'request' => ['type' => 'string', 'description' => 'What the user wants to achieve and why, in their words where possible.'],
            'finding' => ['type' => 'string', 'description' => 'Why the CMS cannot do it today, with the specifics you verified (sections, fields, files).'],
            'evidence' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What you checked: files read, content model looked at, queries run and their outcome, affected entries (with CP links).'],
            'suggestedApproach' => ['type' => 'string', 'description' => 'How a developer could address it, if you can tell. Say if unsure.'],
        ], ['title', 'category', 'request', 'finding']);
    }

    public function getLabel(array $input): string
    {
        return Craft::t(Plugin::HANDLE, 'Drafting a ticket for the dev team');
    }

    public function execute(array $input, ToolContext $context): ToolResult
    {
        $draft = [
            'title' => mb_substr(self::str($input, 'title'), 0, 200),
            'category' => in_array($input['category'] ?? null, self::CATEGORIES, true) ? $input['category'] : 'feature_request',
            'priority' => in_array($input['priority'] ?? null, self::PRIORITIES, true) ? $input['priority'] : 'normal',
            'request' => self::str($input, 'request'),
            'finding' => self::str($input, 'finding'),
            'evidence' => array_values(array_filter(array_map(fn($e) => is_scalar($e) ? trim((string)$e) : '', (array)($input['evidence'] ?? [])))),
            'suggestedApproach' => self::str($input, 'suggestedApproach'),
        ];

        if ($draft['title'] === '' || $draft['request'] === '') {
            return ToolResult::error('title and request are required.');
        }

        if ($context->conversationId) {
            Plugin::getInstance()->conversations->saveTicketDraft($context->conversationId, $draft);
        }

        return new ToolResult(
            'The draft is now shown to the user as an editable card. Nothing has been sent yet: the user will review, edit and confirm it, '
            . 'or dismiss it. Tell them briefly what you drafted and that they can adjust it before sending. Do not say the ticket was created.',
            false,
            ['type' => 'ticket_draft', 'draft' => $draft],
        );
    }
}
