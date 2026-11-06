<?php

namespace cossette\projectassistant\tickets;

use Craft;
use cossette\projectassistant\Plugin;

/**
 * Stand-in until the real ticket system is connected: the handoff is saved on
 * the conversation (and in the audit log) and nothing leaves Craft. The UI
 * says so, so nobody thinks the dev team was notified.
 */
class PlaceholderProvider implements TicketProviderInterface
{
    public function getName(): string
    {
        return Craft::t(Plugin::HANDLE, 'Ticket system (not connected yet)');
    }

    public function isConnected(): bool
    {
        return false;
    }

    public function createTicket(array $handoff): TicketResult
    {
        return new TicketResult(
            reference: 'DRAFT-' . ($handoff['conversation']['id'] ?? '0'),
            url: $handoff['conversation']['url'] ?? null,
            status: 'saved_locally',
        );
    }
}
