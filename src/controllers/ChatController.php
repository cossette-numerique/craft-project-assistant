<?php

namespace cossette\projectassistant\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use cossette\projectassistant\Plugin;
use cossette\projectassistant\records\ConversationRecord;
use cossette\projectassistant\web\assets\chat\ChatAsset;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The chat page, the streaming endpoint and the ticket actions.
 */
class ChatController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-' . Plugin::HANDLE);

        return true;
    }

    public function actionIndex(?int $conversationId = null): Response
    {
        $plugin = Plugin::getInstance();
        $user = static::currentUser();
        $conversation = null;
        $readOnly = false;

        if ($conversationId !== null) {
            $conversation = $this->conversation($conversationId, false);
            $readOnly = !$plugin->conversations->canWrite($conversation, $user);
        }

        $view = $this->getView();
        $view->registerAssetBundle(ChatAsset::class);
        $view->registerTranslations(Plugin::HANDLE, ChatAsset::TRANSLATIONS);

        $provider = $plugin->tickets->getProvider();
        $owner = $conversation && $readOnly ? Craft::$app->getUsers()->getUserById($conversation->userId) : null;

        return $this->renderTemplate(Plugin::HANDLE . '/index', [
            'assistantName' => $plugin->getSettings()->getAssistantName(),
            'conversations' => $plugin->conversations->listForUser($user->id),
            'conversation' => $conversation,
            'owner' => $owner,
            'readOnly' => $readOnly,
            'configured' => $plugin->agent->isConfigured(),
            'budgetBlock' => $plugin->usage->budgetBlock($user->id),
            'config' => [
                'conversationId' => $conversation?->id,
                'transcript' => $conversation ? $plugin->conversations->getTranscript($conversation) : [],
                'readOnly' => $readOnly,
                'sendUrl' => 'project-assistant/chat/send',
                'ticketUrl' => 'project-assistant/chat/ticket',
                'baseUrl' => Plugin::HANDLE,
                'ticketProvider' => [
                    'name' => $provider->getName(),
                    'connected' => $provider->isConnected(),
                ],
                'categories' => \cossette\projectassistant\tools\ProposeTicketTool::CATEGORIES,
                'priorities' => \cossette\projectassistant\tools\ProposeTicketTool::PRIORITIES,
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
        } else {
            $conversation = $plugin->conversations->create($user, $message !== '' ? $message : Craft::t(Plugin::HANDLE, 'Ticket request'));
        }

        // A question can take a minute: don't hold the session lock, and finish
        // (and save) even if the browser goes away.
        Craft::$app->getSession()->close();
        ignore_user_abort(true);

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/event-stream; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');

        $response->stream = function () use ($plugin, $conversation, $user, $message, $requestTicket) {
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
                $plugin->agent->ask($conversation, $user, $message, $emit, $requestTicket);
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
     * Submits or dismisses the pending ticket draft.
     */
    public function actionTicket(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $plugin = Plugin::getInstance();
        $user = static::currentUser();
        $conversation = $this->conversation((int)$this->request->getRequiredBodyParam('conversationId'), true);

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
