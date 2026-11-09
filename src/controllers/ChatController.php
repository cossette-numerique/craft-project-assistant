<?php

namespace cossette\projectassistant\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use cossette\projectassistant\Plugin;
use cossette\projectassistant\records\ConversationRecord;
use cossette\projectassistant\tools\CraftMcp;
use cossette\projectassistant\tools\ProposeTicketTool;
use cossette\projectassistant\web\assets\chat\ChatAsset;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The chat pages (client and developer), the streaming endpoints, and the
 * ticket and approval actions.
 *
 * Access: the client chat needs the plugin's CP permission; the developer
 * chat needs the user's ID in `developerUserIds`, whatever their permissions.
 */
class ChatController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requireLogin();

        return true;
    }

    public function actionIndex(?int $conversationId = null, string $mode = ConversationRecord::MODE_CLIENT): Response
    {
        $plugin = Plugin::getInstance();
        $user = static::currentUser();
        $conversation = null;
        $readOnly = false;

        if ($conversationId !== null) {
            $conversation = $this->conversation($conversationId, false);
            $mode = $conversation->mode;
            $readOnly = !$plugin->conversations->canWrite($conversation, $user);
        }
        $this->requireMode($mode);
        $developer = $mode === ConversationRecord::MODE_DEVELOPER;

        $view = $this->getView();
        $view->registerAssetBundle(ChatAsset::class);
        $view->registerTranslations(Plugin::HANDLE, ChatAsset::TRANSLATIONS);

        $provider = $plugin->tickets->getProvider();
        $owner = $conversation && $readOnly ? Craft::$app->getUsers()->getUserById($conversation->userId) : null;
        $pending = $conversation ? $plugin->agent->getPending($conversation) : null;

        return $this->renderTemplate(Plugin::HANDLE . '/index', [
            'assistantName' => $plugin->getSettings()->getAssistantName(),
            'mode' => $mode,
            'developer' => $developer,
            'craftToolsAvailable' => CraftMcp::isAvailable(),
            'confirmWrites' => !$developer || $plugin->getSettings()->developerConfirmWrites,
            'clientMcpAccess' => CraftMcp::isAvailable() ? $plugin->getSettings()->getClientMcpAccess() : 'none',
            'newUrl' => $developer ? Plugin::HANDLE . '/dev' : Plugin::HANDLE,
            'conversations' => $plugin->conversations->listForUser($user->id, $mode),
            'conversation' => $conversation,
            'owner' => $owner,
            'readOnly' => $readOnly,
            'configured' => $plugin->agent->isConfigured($mode),
            'budgetBlock' => $plugin->usage->budgetBlock($user->id, $mode),
            'config' => [
                'mode' => $mode,
                'conversationId' => $conversation?->id,
                'transcript' => $conversation ? $plugin->conversations->getTranscript($conversation) : [],
                'awaitingApproval' => $pending !== null,
                'readOnly' => $readOnly,
                'sendUrl' => 'project-assistant/chat/send',
                'resolveUrl' => 'project-assistant/chat/resolve',
                'ticketUrl' => 'project-assistant/chat/ticket',
                'baseUrl' => Plugin::HANDLE,
                'ticketProvider' => [
                    'name' => $provider->getName(),
                    'connected' => $provider->isConnected(),
                ],
                'categories' => ProposeTicketTool::CATEGORIES,
                'priorities' => ProposeTicketTool::PRIORITIES,
            ],
        ]);
    }

    /**
     * Runs one question and streams progress as server-sent events.
     */
    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        $user = static::currentUser();
        $request = $this->request;

        $message = trim((string)$request->getBodyParam('message', ''));
        $requestTicket = (bool)$request->getBodyParam('requestTicket', false);
        $conversationId = $request->getBodyParam('conversationId');

        if ($message === '' && !$requestTicket) {
            return $this->asFailure(Craft::t(Plugin::HANDLE, 'Write a question first.'));
        }
        if (mb_strlen($message) > 8000) {
            return $this->asFailure(Craft::t(Plugin::HANDLE, 'That message is too long.'));
        }

        if ($conversationId) {
            $conversation = $this->conversation((int)$conversationId, true);
            $this->requireMode($conversation->mode);
        } else {
            $mode = $request->getBodyParam('mode') === ConversationRecord::MODE_DEVELOPER
                ? ConversationRecord::MODE_DEVELOPER
                : ConversationRecord::MODE_CLIENT;
            $this->requireMode($mode);
            $conversation = $plugin->conversations->create($user, $message !== '' ? $message : Craft::t(Plugin::HANDLE, 'Ticket request'), $mode);
        }

        return $this->stream($conversation, function (callable $emit) use ($plugin, $conversation, $user, $message, $requestTicket) {
            $plugin->agent->ask($conversation, $user, $message, $emit, $requestTicket);
        });
    }

    /**
     * Approves or rejects a pending tool call (developer chat, or client chat
     * with Craft edit tools), then streams
     * the rest of the answer.
     */
    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        $user = static::currentUser();
        $conversation = $this->conversation((int)$this->request->getRequiredBodyParam('conversationId'), true);
        $this->requireMode($conversation->mode);
        $toolUseId = (string)$this->request->getRequiredBodyParam('toolUseId');
        $approve = $this->request->getRequiredBodyParam('decision') === 'approve';

        return $this->stream($conversation, function (callable $emit) use ($plugin, $conversation, $user, $toolUseId, $approve) {
            $plugin->agent->resolve($conversation, $user, $toolUseId, $approve, $emit);
        });
    }

    /**
     * Submits or dismisses the pending ticket draft.
     */
    public function actionTicket(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $plugin = Plugin::getInstance();
        $user = static::currentUser();
        $conversation = $this->conversation((int)$this->request->getRequiredBodyParam('conversationId'), true);
        $this->requireMode($conversation->mode);

        if ($this->request->getBodyParam('action') === 'dismiss') {
            $plugin->tickets->dismiss($conversation);

            return $this->asSuccess(data: ['item' => ['type' => 'ticket', 'state' => 'dismissed']]);
        }

        if ($conversation->ticketReference && !$plugin->conversations->getTicketDraft($conversation)) {
            return $this->asFailure(Craft::t(Plugin::HANDLE, 'This ticket was already sent.'));
        }

        $fields = $this->request->getBodyParam('fields', []);
        try {
            $item = $plugin->tickets->submit($conversation, is_array($fields) ? $fields : [], $user);
        } catch (\InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Ticket submission failed: ' . $e->getMessage(), Plugin::HANDLE);

            return $this->asFailure(Craft::t(Plugin::HANDLE, 'The ticket could not be sent: {error}', ['error' => $e->getMessage()]));
        }

        return $this->asSuccess(Craft::t(Plugin::HANDLE, 'Ticket sent.'), ['item' => $item]);
    }

    /**
     * Streams the agent's events as server-sent events.
     *
     * @param callable(callable(string, array): void): void $run
     */
    private function stream(ConversationRecord $conversation, callable $run): Response
    {
        $plugin = Plugin::getInstance();

        // A question can take a minute: don't hold the session lock, and finish
        // (and save) even if the browser goes away.
        Craft::$app->getSession()->close();
        ignore_user_abort(true);

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/event-stream; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');

        $response->stream = function () use ($plugin, $conversation, $run) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            $emit = function (string $event, array $data) {
                echo "event: {$event}\ndata: " . Json::encode($data) . "\n\n";
                flush();
            };
            $emit('conversation', [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'url' => $plugin->conversations->url($conversation),
            ]);
            try {
                $run($emit);
            } catch (\Throwable $e) {
                Craft::error('Assistant failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), Plugin::HANDLE);
                $emit('error', ['message' => Craft::t(Plugin::HANDLE, 'Something went wrong. The error was logged.')]);
                $emit('done', []);
            }

            return [];
        };

        return $response;
    }

    /**
     * Client chat: the plugin's CP permission. Developer chat: a developer user ID.
     */
    private function requireMode(string $mode): void
    {
        $user = static::currentUser();
        if ($mode === ConversationRecord::MODE_DEVELOPER) {
            if (!Plugin::getInstance()->getSettings()->isDeveloper($user)) {
                throw new ForbiddenHttpException('The developer chat isn’t available to you.');
            }
            return;
        }
        $this->requirePermission('accessPlugin-' . Plugin::HANDLE);
    }

    private function conversation(int $id, bool $write): ConversationRecord
    {
        $plugin = Plugin::getInstance();
        $user = static::currentUser();
        $conversation = $plugin->conversations->getById($id);
        if (!$conversation) {
            throw new NotFoundHttpException('Conversation not found');
        }
        $allowed = $write ? $plugin->conversations->canWrite($conversation, $user) : $plugin->conversations->canView($conversation, $user);
        if (!$allowed) {
            throw new ForbiddenHttpException('You can’t access this conversation.');
        }

        return $conversation;
    }
}
