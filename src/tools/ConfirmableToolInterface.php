<?php

namespace cossette\projectassistant\tools;

/**
 * A tool whose calls may need the user's approval before they run
 * (developer chat writes). Implemented on top of ToolInterface.
 */
interface ConfirmableToolInterface extends ToolInterface
{
    /** Whether this call changes something, so it waits for approval when confirmation is on. */
    public function requiresConfirmation(array $input): bool;
}
