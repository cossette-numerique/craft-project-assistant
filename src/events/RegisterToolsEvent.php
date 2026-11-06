<?php

namespace cossette\projectassistant\events;

use cossette\projectassistant\tools\ToolInterface;
use yii\base\Event;

class RegisterToolsEvent extends Event
{
    /** @var ToolInterface[] Add, replace or remove tools. Keep them read-only. */
    public array $tools = [];
}
