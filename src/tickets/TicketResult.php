<?php

namespace cossette\projectassistant\tickets;

class TicketResult
{
    public function __construct(
        public readonly string $reference,
        public readonly ?string $url = null,
        public readonly string $status = 'submitted',
    ) {
    }
}
