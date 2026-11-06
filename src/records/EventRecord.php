<?php

namespace cossette\projectassistant\records;

use craft\db\ActiveRecord;
use cossette\projectassistant\migrations\Install;

/**
 * @property int $id
 * @property int|null $conversationId
 * @property int|null $userId
 * @property string $type question | api_call | tool | ticket | error
 * @property string|null $name
 * @property string|null $detail
 * @property string|null $model
 * @property int $inputTokens
 * @property int $outputTokens
 * @property int $cacheReadTokens
 * @property int $cacheWriteTokens
 * @property float $costUsd
 */
class EventRecord extends ActiveRecord
{
    public const TYPE_QUESTION = 'question';
    public const TYPE_API_CALL = 'api_call';
    public const TYPE_TOOL = 'tool';
    public const TYPE_TICKET = 'ticket';
    public const TYPE_ERROR = 'error';

    public static function tableName(): string
    {
        return Install::EVENTS;
    }
}
