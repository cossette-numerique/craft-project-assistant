<?php

namespace cossette\projectassistant;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use cossette\projectassistant\models\Settings;
use cossette\projectassistant\services\Agent;
use cossette\projectassistant\services\Conversations;
use cossette\projectassistant\services\Tickets;
use cossette\projectassistant\services\Tools;
use cossette\projectassistant\services\Usage;
use cossette\projectassistant\tools\CraftMcp;
use yii\base\Event;

/**
 * Project Assistant: a read-only AI assistant in the control panel.
 *
 * It answers questions about the project's content, content model and code,
 * and when the CMS can't do what's asked, drafts a ticket for the dev team
 * that the user reviews and confirms.
 *
 * Project-specific setup lives in `config/project-assistant.php`
 * (see the plugin README and models/Settings.php).
 *
 * @property-read Agent $agent
 * @property-read Conversations $conversations
 * @property-read Tickets $tickets
 * @property-read Tools $tools
 * @property-read Usage $usage
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'project-assistant';

    /** Lets a user see the usage page and read every user's conversations. */
    public const PERMISSION_VIEW_USAGE = 'projectAssistant-viewUsage';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'agent' => Agent::class,
                'conversations' => Conversations::class,
                'tickets' => Tickets::class,
                'tools' => Tools::class,
                'usage' => Usage::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function () {
            $this->_registerCpRoutes();
            $this->_registerPermissions();
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = $this->getSettings()->getAssistantName();
        $item['icon'] = 'comments';

        $user = Craft::$app->getUser();
        $subnav = ['chat' => ['label' => Craft::t(self::HANDLE, 'Chat'), 'url' => self::HANDLE]];
        if ($this->getSettings()->isDeveloper($user->getIdentity())) {
            $subnav['developer'] = ['label' => Craft::t(self::HANDLE, 'Developer chat'), 'url' => self::HANDLE . '/dev'];
        }
        if ($user->checkPermission(self::PERMISSION_VIEW_USAGE)) {
            $subnav['usage'] = ['label' => Craft::t(self::HANDLE, 'Usage'), 'url' => self::HANDLE . '/usage'];
        }
        if (count($subnav) > 1) {
            $item['subnav'] = $subnav;
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Settings → Plugins → Project Assistant. Craft only shows it to admins,
     * and only where admin changes are allowed, so it's edited locally and
     * deployed with project config. Values set in config/project-assistant.php
     * win, so those fields are shown read-only.
     */
    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();
        $fromFile = Craft::$app->getConfig()->getConfigFromFile(self::HANDLE);
        $models = array_map(fn(string $m) => ['label' => $m, 'value' => $m], $settings->getModelOptions());

        return Craft::$app->getView()->renderTemplate(self::HANDLE . '/_settings', [
            'settings' => $settings,
            'overridden' => array_fill_keys(array_keys($fromFile), true),
            'modelSuggestions' => [['label' => Craft::t(self::HANDLE, 'Models'), 'data' => $models]],
            'craftToolsAvailable' => CraftMcp::isAvailable(),
        ]);
    }

    private function _registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules[self::HANDLE] = self::HANDLE . '/chat/index';
            $event->rules[self::HANDLE . '/c/<conversationId:\d+>'] = self::HANDLE . '/chat/index';
            $event->rules[self::HANDLE . '/dev'] = ['route' => self::HANDLE . '/chat/index', 'defaults' => ['mode' => 'developer']];
            $event->rules[self::HANDLE . '/usage'] = self::HANDLE . '/usage/index';
        });
    }

    private function _registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => $this->getSettings()->getAssistantName(),
                'permissions' => [
                    self::PERMISSION_VIEW_USAGE => [
                        'label' => Craft::t(self::HANDLE, 'View usage, costs and every user’s conversations'),
                    ],
                ],
            ];
        });
    }
}
