<?php

namespace cossette\projectassistant\controllers;

use Craft;
use craft\web\Controller;
use cossette\projectassistant\Plugin;
use yii\web\Response;

/**
 * Monthly usage, estimated costs, budgets, and every conversation.
 */
class UsageController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_USAGE);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $conversations = $plugin->conversations->listAll(100, $settings->isDeveloper(static::currentUser()));
        $userIds = array_unique(array_map(fn($c) => $c->userId, $conversations));
        $users = [];
        foreach ($userIds as $id) {
            $users[$id] = Craft::$app->getUsers()->getUserById($id);
        }

        return $this->renderTemplate(Plugin::HANDLE . '/usage', [
            'assistantName' => $settings->getAssistantName(),
            'settings' => $settings,
            'spent' => $plugin->usage->spentThisMonth(),
            'report' => $plugin->usage->report(6),
            'conversations' => $conversations,
            'users' => $users,
            'provider' => $plugin->tickets->getProvider(),
        ]);
    }
}
