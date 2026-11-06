<?php

namespace cossette\projectassistant\tickets;

/**
 * Sends a confirmed handoff to a ticket system.
 *
 * The handoff is a plain array (see Tickets::buildHandoff() and the README
 * for the shape). Throw an exception on failure; the user sees the message
 * and can try again.
 */
interface TicketProviderInterface
{
    /** Name shown in the UI, e.g. "Cossette Support". */
    public function getName(): string;

    /** False while the provider only keeps tickets in Craft (no team is notified). */
    public function isConnected(): bool;

    public function createTicket(array $handoff): TicketResult;
}
