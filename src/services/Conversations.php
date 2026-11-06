<?php

namespace cossette\projectassistant\services;

use Craft;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use cossette\projectassistant\Plugin;
use cossette\projectassistant\records\ConversationRecord;
use cossette\projectassistant\records\EventRecord;
use yii\base\Component;

/**
 * Stores conversations (model history + chat transcript) and the audit log.
 */
class Conversations extends Component
{
    /**
     * One record instance per conversation per request, so the agent and the
     * tools it runs never overwrite each other's changes with stale copies.
     *
     * @var array<int, ConversationRecord>
     */
    private array $loaded = [];

    public function create(User $user, string $firstMessage): ConversationRecord
    {
        $record = new ConversationRecord();
        $record->userId = $user->id;
        $title = trim(preg_replace('/\s+/', ' ', $firstMessage) ?? '');
        $record->title = mb_strlen($title) > 80 ? mb_substr($title, 0, 79) . '…' : $title;
        $record->messages = '[]';
        $record->transcript = '[]';
        $record->save(false);

        return $this->loaded[$record->id] = $record;
    }

    public function getById(int $id): ?ConversationRecord
    {
        if (!isset($this->loaded[$id])) {
            $record = ConversationRecord::findOne($id);
            if (!$record) {
                return null;
            }
            $this->loaded[$id] = $record;
        }

        return $this->loaded[$id];
    }

    /** Owners can write to their conversations; usage viewers can read everyone's. */
    public function canView(ConversationRecord $conversation, User $user): bool
    {
        return $conversation->userId === $user->id || $user->can(Plugin::PERMISSION_VIEW_USAGE);
    }

    public function canWrite(ConversationRecord $conversation, User $user): bool
    {
        return $conversation->userId === $user->id;
    }

    /** @return ConversationRecord[] */
    public function listForUser(int $userId, int $limit = 50): array
    {
        return ConversationRecord::find()
            ->select(['id', 'userId', 'title', 'ticketReference', 'dateCreated', 'dateUpdated'])
            ->where(['userId' => $userId])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /** @return ConversationRecord[] */
    public function listAll(int $limit = 100): array
    {
        return ConversationRecord::find()
            ->select(['id', 'userId', 'title', 'ticketReference', 'ticketStatus', 'costUsd', 'dateCreated', 'dateUpdated'])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function url(ConversationRecord|int $conversation): string
    {
        $id = $conversation instanceof ConversationRecord ? $conversation->id : $conversation;

        return UrlHelper::cpUrl(Plugin::HANDLE . '/c/' . $id);
    }

    // -- History & transcript ------------------------------------------------

    /**
     * The model-facing history, ready to send back to the API.
     */
    public function getMessages(ConversationRecord $conversation): array
    {
        $messages = Json::decodeIfJson($conversation->messages ?? '[]');
        if (!is_array($messages)) {
            return [];
        }
        // json_decode turns an empty tool input `{}` into `[]`; the API needs an object.
        foreach ($messages as &$message) {
            if (is_array($message['content'] ?? null)) {
                foreach ($message['content'] as &$block) {
                    if (($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? null) === []) {
                        $block['input'] = new \stdClass();
                    }
                }
                unset($block);
            }
        }
        unset($message);

        return $messages;
    }

    public function saveMessages(ConversationRecord $conversation, array $messages): void
    {
        $conversation->messages = Json::encode($messages);
        $conversation->save(false);
    }

    public function getTranscript(ConversationRecord $conversation): array
    {
        $items = Json::decodeIfJson($conversation->transcript ?? '[]');

        return is_array($items) ? $items : [];
    }

    public function appendTranscript(ConversationRecord $conversation, array $item): void
    {
        $items = $this->getTranscript($conversation);
        $item['time'] ??= (new \DateTime())->format(DATE_ATOM);
        $items[] = $item;
        $conversation->transcript = Json::encode($items);
        $conversation->save(false);
    }

    /**
     * Replaces the last transcript item of a given type matching $match (e.g. the
     * pending ticket card once it's submitted).
     */
    public function updateLastTranscriptItem(ConversationRecord $conversation, string $type, array $changes): void
    {
        $items = $this->getTranscript($conversation);
        for ($i = count($items) - 1; $i >= 0; $i--) {
            if (($items[$i]['type'] ?? null) === $type) {
                $items[$i] = array_merge($items[$i], $changes);
                break;
            }
        }
        $conversation->transcript = Json::encode($items);
        $conversation->save(false);
    }

    // -- Tickets -------------------------------------------------------------

    public function saveTicketDraft(int $conversationId, array $draft): void
    {
        $conversation = $this->getById($conversationId);
        if (!$conversation) {
            return;
        }
        $conversation->ticketDraft = Json::encode($draft);
        $conversation->save(false);
        $this->appendTranscript($conversation, ['type' => 'ticket', 'state' => 'draft', 'draft' => $draft]);
    }

    public function getTicketDraft(ConversationRecord $conversation): ?array
    {
        $draft = Json::decodeIfJson($conversation->ticketDraft ?? '');

        return is_array($draft) ? $draft : null;
    }

    // -- Usage & audit log ---------------------------------------------------

    public function addUsage(ConversationRecord $conversation, array $usage, float $cost): void
    {
        $conversation->inputTokens += (int)($usage['input_tokens'] ?? 0);
        $conversation->outputTokens += (int)($usage['output_tokens'] ?? 0);
        $conversation->cacheReadTokens += (int)($usage['cache_read_input_tokens'] ?? 0);
        $conversation->cacheWriteTokens += (int)($usage['cache_creation_input_tokens'] ?? 0);
        $conversation->costUsd = (float)$conversation->costUsd + $cost;
        $conversation->save(false);
    }

    public function log(string $type, ?ConversationRecord $conversation, ?int $userId, ?string $name = null, mixed $detail = null, array $usage = [], float $cost = 0.0, ?string $model = null): void
    {
        $event = new EventRecord();
        $event->type = $type;
        $event->conversationId = $conversation?->id;
        $event->userId = $userId;
        $event->name = $name !== null ? mb_substr($name, 0, 255) : null;
        $event->detail = $detail === null ? null : (is_string($detail) ? $detail : Json::encode($detail));
        $event->model = $model;
        $event->inputTokens = (int)($usage['input_tokens'] ?? 0);
        $event->outputTokens = (int)($usage['output_tokens'] ?? 0);
        $event->cacheReadTokens = (int)($usage['cache_read_input_tokens'] ?? 0);
        $event->cacheWriteTokens = (int)($usage['cache_creation_input_tokens'] ?? 0);
        $event->costUsd = $cost;
        if (!$event->save(false)) {
            Craft::warning('Could not save assistant audit event', Plugin::HANDLE);
        }
    }
}
