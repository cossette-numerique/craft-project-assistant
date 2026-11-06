<?php

namespace cossette\projectassistant\tools;

use craft\elements\User;

/**
 * Who is asking, and in which conversation. Tools use the user to apply the
 * same permissions the CP would (e.g. only sections they can view).
 */
class ToolContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?int $conversationId = null,
    ) {
    }
}
