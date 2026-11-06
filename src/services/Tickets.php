<?php

namespace cossette\projectassistant\services;

use Craft;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use cossette\projectassistant\Plugin;
use cossette\projectassistant\records\ConversationRecord;
use cossette\projectassistant\records\EventRecord;
use cossette\projectassistant\tickets\PlaceholderProvider;
use cossette\projectassistant\tickets\TicketProviderInterface;
use cossette\projectassistant\tickets\WebhookProvider;
use cossette\projectassistant\tools\ProposeTicketTool;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Turns a confirmed ticket draft into a handoff and sends it to the
 * configured ticket provider.
 */
class Tickets extends Component
{
    /** Bump when the handoff shape changes in a way receivers must know about. */
    public const HANDOFF_SCHEMA = 'project-assistant.handoff/1';

    private ?TicketProviderInterface $provider = null;

    public function getProvider(): TicketProviderInterface
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $config = Plugin::getInstance()->getSettings()->ticketProvider;
        $type = $config['type'] ?? 'placeholder';
        unset($config['type']);

        $this->provider = match (true) {
            $type === 'placeholder' => new PlaceholderProvider(),
            $type === 'webhook' => new WebhookProvider($config),
            is_string($type) && is_subclass_of($type, TicketProviderInterface::class) => new $type($config),
            default => throw new InvalidConfigException("Unknown ticket provider: $type"),
        };

        return $this->provider;
    }

    /**
     * Validates the fields the user confirmed in the ticket card.
     *
     * @return array the cleaned ticket
     */
    public function normalize(array $fields): array
    {
        $str = fn(string $key, int $max = 20000) => mb_substr(trim((string)($fields[$key] ?? '')), 0, $max);
        $evidence = $fields['evidence'] ?? [];
        if (is_string($evidence)) {
            $evidence = preg_split('/\R/', $evidence);
        }

        return [
            'title' => $str('title', 200),
            'category' => in_array($fields['category'] ?? null, ProposeTicketTool::CATEGORIES, true) ? $fields['category'] : 'feature_request',
            'priority' => in_array($fields['priority'] ?? null, ProposeTicketTool::PRIORITIES, true) ? $fields['priority'] : 'normal',
            'request' => $str('request'),
            'finding' => $str('finding'),
            'evidence' => array_values(array_filter(array_map(fn($e) => trim((string)$e), (array)$evidence))),
            'suggestedApproach' => $str('suggestedApproach'),
            'userNotes' => $str('userNotes'),
        ];
    }

    /**
     * The handoff sent to the ticket system. Documented in the README; keep
     * HANDOFF_SCHEMA in sync when changing it.
     */
    public function buildHandoff(ConversationRecord $conversation, array $ticket, User $user): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $primarySite = Craft::$app->getSites()->getPrimarySite();

        $transcript = [];
        $steps = [];
        foreach ($plugin->conversations->getTranscript($conversation) as $item) {
            $type = $item['type'] ?? null;
            if ($type === 'user' || $type === 'assistant') {
                $transcript[] = ['role' => $type, 'text' => $item['text'] ?? '', 'time' => $item['time'] ?? null];
            } elseif ($type === 'tool') {
                $steps[] = $item['label'] ?? $item['name'] ?? '';
            }
        }

        return [
            'schema' => self::HANDOFF_SCHEMA,
            'ticket' => $ticket,
            'requester' => [
                'id' => $user->id,
                'name' => $user->getFriendlyName(),
                'email' => $user->email,
                'groups' => array_map(fn($g) => $g->handle, $user->getGroups()),
            ],
            'project' => [
                'name' => $settings->getProjectName(),
                'siteUrl' => $primarySite->getBaseUrl(),
                'cpUrl' => UrlHelper::cpUrl(),
                'environment' => Craft::$app->env,
                'craftVersion' => Craft::$app->getVersion(),
                'pluginVersion' => $plugin->getVersion(),
            ],
            'conversation' => [
                'id' => $conversation->id,
                'url' => $plugin->conversations->url($conversation),
                'title' => $conversation->title,
                'startedAt' => $conversation->getCreatedAt()->format(DATE_ATOM),
                'transcript' => $transcript,
                'steps' => array_values(array_unique(array_filter($steps))),
            ],
            'createdAt' => (new \DateTime())->format(DATE_ATOM),
        ];
    }

    /**
     * Sends the confirmed ticket. Returns the transcript item for the UI.
     *
     * @throws \Throwable when the provider fails
     */
    public function submit(ConversationRecord $conversation, array $fields, User $user): array
    {
        $plugin = Plugin::getInstance();
        $ticket = $this->normalize($fields);
        if ($ticket['title'] === '' || $ticket['request'] === '') {
            throw new \InvalidArgumentException(Craft::t(Plugin::HANDLE, 'A title and a description of the request are required.'));
        }

        $handoff = $this->buildHandoff($conversation, $ticket, $user);
        $provider = $this->getProvider();

        try {
            $result = $provider->createTicket($handoff);
        } catch (\Throwable $e) {
            $plugin->conversations->log(EventRecord::TYPE_ERROR, $conversation, $user->id, 'ticket', $e->getMessage());
            throw $e;
        }

        $conversation->ticketReference = $result->reference;
        $conversation->ticketUrl = $result->url;
        $conversation->ticketStatus = $result->status;
        $conversation->ticketPayload = Json::encode($handoff);
        $conversation->ticketDraft = null;
        $connected = $provider->isConnected();
        // Tell the model on the next turn, so it doesn't offer the same ticket again.
        $conversation->pendingNote = $connected
            ? "The user confirmed the ticket draft and it was sent to the ticket system as {$result->reference} (\"{$ticket['title']}\")."
            : "The user confirmed the ticket draft (\"{$ticket['title']}\"). The ticket system is not connected yet, so it was saved in the CMS as {$result->reference} and the dev team was not notified automatically.";
        $conversation->save(false);

        $plugin->conversations->log(EventRecord::TYPE_TICKET, $conversation, $user->id, $result->reference, $handoff);

        $item = [
            'state' => 'submitted',
            'ticket' => $ticket,
            'reference' => $result->reference,
            'url' => $result->url,
            'status' => $result->status,
            'connected' => $connected,
            'provider' => $provider->getName(),
        ];
        $plugin->conversations->updateLastTranscriptItem($conversation, 'ticket', $item);

        return ['type' => 'ticket'] + $item;
    }

    public function dismiss(ConversationRecord $conversation): void
    {
        $conversation->ticketDraft = null;
        $conversation->pendingNote = 'The user dismissed the ticket draft. Do not propose it again unless they ask.';
        $conversation->save(false);
        Plugin::getInstance()->conversations->updateLastTranscriptItem($conversation, 'ticket', ['state' => 'dismissed']);
    }
}
