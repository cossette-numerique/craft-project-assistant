<?php

namespace cossette\projectassistant\records;

use craft\db\ActiveRecord;
use cossette\projectassistant\migrations\Install;

/**
 * @property int $id
 * @property int $userId
 * @property string $title
 * @property string|null $messages
 * @property string|null $transcript
 * @property string|null $pendingNote
 * @property string|null $ticketDraft
 * @property string|null $ticketReference
 * @property string|null $ticketUrl
 * @property string|null $ticketStatus
 * @property string|null $ticketPayload
 * @property int $inputTokens
 * @property int $outputTokens
 * @property int $cacheReadTokens
 * @property int $cacheWriteTokens
 * @property float $costUsd
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class ConversationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Install::CONVERSATIONS;
    }

    /** dateUpdated in the system timezone (the column holds UTC). */
    public function getUpdatedAt(): \DateTime
    {
        return self::localDate($this->dateUpdated);
    }

    /** dateCreated in the system timezone (the column holds UTC). */
    public function getCreatedAt(): \DateTime
    {
        return self::localDate($this->dateCreated);
    }

    private static function localDate(?string $value): \DateTime
    {
        $date = new \DateTime($value ?? 'now', new \DateTimeZone('UTC'));
        $date->setTimezone(new \DateTimeZone(\Craft::$app->getTimeZone()));

        return $date;
    }
}
