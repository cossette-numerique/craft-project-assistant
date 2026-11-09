<?php

namespace cossette\projectassistant\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings. Set them per project in `config/project-assistant.php`;
 * every string value can be an environment variable reference (`$MY_VAR`).
 *
 * The assistant name, the models and client MCP access can also be set by an
 * admin in Settings → Plugins → Project Assistant (when admin changes are
 * allowed, so normally locally). Those are stored in project config; a value
 * set in the config file wins over them.
 */
class Settings extends Model
{
    /** Client chat: no Craft MCP tools (default). */
    public const MCP_NONE = 'none';
    /** Client chat: Craft's read-only MCP tools. */
    public const MCP_READ = 'read';
    /** Client chat: read-only tools plus the write tools in clientWriteTools, each approved in the chat. */
    public const MCP_WRITE = 'write';

    /** Craft MCP write tools offered by default (content only, no structure). */
    public const DEFAULT_WRITE_TOOLS = [
        'elements.create',
        'elements.update',
        'elements.duplicate',
        'elements.delete',
        'elements.restore',
        'drafts.create',
        'drafts.apply',
        'drafts.delete',
        'revisions.apply',
        'assets.create',
        'assets.replace',
        'asset-folders.create',
        'structures.move-element',
    ];

    /** Model used when the setting is empty. */
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    // -- Model -------------------------------------------------------------

    /** API key, normally `$ANTHROPIC_API_KEY`. */
    public string $apiKey = '$ANTHROPIC_API_KEY';

    /** API base URL override (gateways, local testing). Empty = https://api.anthropic.com. */
    public string $baseUrl = '$PROJECT_ASSISTANT_BASE_URL';

    /** Model ID for the client chat. `claude-sonnet-5-5` is the cheaper alternative. */
    public string $model = self::DEFAULT_MODEL;

    /** Reasoning effort: low, medium, high, xhigh, max. */
    public string $effort = 'medium';

    /** Max output tokens per model call. */
    public int $maxTokens = 16000;

    /** Max model calls for one question (tool loop guard). */
    public int $maxTurns = 25;

    /**
     * Server-side refusal fallback (Anthropic API only). When a request is
     * declined by a safety classifier, the API retries it on a fallback model.
     */
    public bool $refusalFallback = true;

    // -- Project context ---------------------------------------------------

    /** Name shown in the CP nav and the chat header. */
    public string $assistantName = 'Project Assistant';

    /** Project name used in the prompt and in ticket handoffs. Defaults to the system name. */
    public string $projectName = '';

    /** Project-specific instructions appended to the system prompt. */
    public string $instructions = '';

    /**
     * Files loaded into the prompt up front (paths or globs relative to the
     * project root), e.g. `CLAUDE.md`, `.cursor/rules/*.mdc`. Keep these to
     * what helps answer most questions; the rest is reachable with read_file.
     *
     * @var string[]
     */
    public array $contextFiles = [];

    /** Character cap on all context files together. */
    public int $maxContextChars = 200000;

    /**
     * Directories and files (relative to the project root) the code tools may read.
     *
     * @var string[]
     */
    public array $allowedPaths = ['config/project', 'modules', 'templates', 'documentation'];

    /**
     * Glob patterns (relative to the project root) the code tools never read,
     * on top of the built-in deny list (.env, keys, storage, vendor, .git, ...).
     *
     * @var string[]
     */
    public array $deniedPaths = [];

    // -- Database ------------------------------------------------------------

    /**
     * Read-only SQL tool. Disabled unless configured with a dedicated
     * database user that only has SELECT on the tables/views listed here:
     *
     * ```php
     * 'sql' => [
     *     'enabled' => true,
     *     'dsn' => '$PROJECT_ASSISTANT_DB_DSN',      // mysql:host=...;dbname=...
     *     'username' => '$PROJECT_ASSISTANT_DB_USER',
     *     'password' => '$PROJECT_ASSISTANT_DB_PASSWORD',
     *     'tables' => ['v_members' => 'One row per member company', ...],
     * ],
     * ```
     */
    public array $sql = [];

    // -- Budgets -------------------------------------------------------------

    /** Monthly spend cap for the whole site, in USD. 0 = no cap. */
    public float $monthlyBudget = 100.0;

    /** Monthly spend cap per user, in USD. 0 = no cap. */
    public float $userMonthlyBudget = 30.0;

    /**
     * Prices in USD per million tokens, used to estimate costs. Check
     * https://www.anthropic.com/pricing and override here if they change.
     * Cache writes are billed at 1.25x input (5-minute cache).
     */
    public array $prices = [
        'claude-opus-5-5' => ['input' => 4.0, 'output' => 20.0, 'cacheRead' => 0.20, 'cacheWrite' => 5.0],
        'claude-sonnet-5-5' => ['input' => 2.0, 'output' => 10.0, 'cacheRead' => 0.20, 'cacheWrite' => 2.5],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cacheRead' => 0.01, 'cacheWrite' => 0.125],
        'claude-fable-5-1' => ['input' => 10.0, 'output' => 50.0, 'cacheRead' => 0.25, 'cacheWrite' => 12.5],
        'claude-opus-5' => ['input' => 5.0, 'output' => 25.0, 'cacheRead' => 0.50, 'cacheWrite' => 6.25],
        'claude-sonnet-5' => ['input' => 2.0, 'output' => 10.0, 'cacheRead' => 0.20, 'cacheWrite' => 2.5],
    ];

    // -- Tickets -------------------------------------------------------------

    /**
     * Where confirmed tickets go:
     *
     * - `['type' => 'placeholder']` keeps the handoff in Craft only (default,
     *   until the ticket system is connected).
     * - `['type' => 'webhook', 'url' => '$TICKETS_WEBHOOK_URL', 'secret' => '$TICKETS_WEBHOOK_SECRET']`
     *   POSTs the handoff as signed JSON.
     * - `['type' => MyProvider::class, ...]` any class implementing
     *   TicketProviderInterface; the other keys are passed to its constructor config.
     */
    public array $ticketProvider = ['type' => 'placeholder'];

    // -- Client chat: Craft MCP ---------------------------------------------

    /**
     * Craft's own MCP tools (Craft 5.12+) in the client chat, acting as the
     * signed-in user within their Craft permissions:
     *
     * - `none`: none; the client chat stays read-only (default).
     * - `read`: Craft's read-only tools.
     * - `write`: also the write tools in clientWriteTools. Every change waits
     *   for the user to approve it in the chat; this can't be turned off.
     */
    public string $clientMcpAccess = self::MCP_NONE;

    /**
     * Craft MCP write tools offered in the client chat when clientMcpAccess is
     * `write` (fnmatch patterns on Craft's tool names).
     *
     * @var string[]
     */
    public array $clientWriteTools = self::DEFAULT_WRITE_TOOLS;

    // -- Developer chat ------------------------------------------------------

    /**
     * Users who can open the developer chat (user IDs). Empty = no developer chat.
     *
     * The developer chat adds Craft's own MCP tools (Craft 5.12+), so the
     * assistant can create and edit content as the signed-in user, within
     * their permissions. It's meant for the agency's developers, not clients.
     *
     * @var int[]
     */
    public array $developerUserIds = [];

    /** Model for the developer chat. Empty = same as `model`. */
    public string $developerModel = '';

    /**
     * API key for the developer chat, so developer usage isn't billed to the
     * client's key. Empty = same as `apiKey`.
     */
    public string $developerApiKey = '';

    /**
     * Every tool call that can change something waits for the developer to
     * click Approve in the chat. Turning this off lets the assistant act on its own.
     */
    public bool $developerConfirmWrites = true;

    /**
     * Craft MCP tools that can change something and are offered in the
     * developer chat (fnmatch patterns on Craft's tool names, e.g. `drafts.*`).
     * Read-only Craft tools are always offered. Project config, plugins,
     * permissions and system settings are left out by default: structural
     * changes should go through project config YAML in git.
     *
     * @var string[]
     */
    public array $developerWriteTools = self::DEFAULT_WRITE_TOOLS;

    /**
     * The developer chat can read Craft's log files (storage/logs) with the
     * read_logs tool. Secret-looking values are redacted. The client chat never can.
     */
    public bool $developerReadLogs = true;

    /** Extra instructions for the developer chat only. */
    public string $developerInstructions = '';

    // -- Getters -------------------------------------------------------------

    public function getApiKey(): string
    {
        return (string)App::parseEnv($this->apiKey);
    }

    /**
     * Always explicit, so an unrelated ANTHROPIC_BASE_URL on the server can't redirect requests.
     */
    public function getBaseUrl(): string
    {
        $url = (string)App::parseEnv($this->baseUrl);

        return $url !== '' ? rtrim($url, '/') : 'https://api.anthropic.com';
    }

    public function getAssistantName(): string
    {
        return (string)App::parseEnv($this->assistantName) ?: 'Project Assistant';
    }

    public function getProjectName(): string
    {
        $name = (string)App::parseEnv($this->projectName);

        return $name !== '' ? $name : (string)Craft::$app->getSystemName();
    }

    public function isDeveloper(?\craft\elements\User $user): bool
    {
        return $user !== null && in_array((int)$user->id, array_map('intval', $this->developerUserIds), true);
    }

    public function getModel(): string
    {
        $model = trim((string)App::parseEnv($this->model));

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    public function getDeveloperModel(): string
    {
        $model = trim((string)App::parseEnv($this->developerModel));

        return $model !== '' ? $model : $this->getModel();
    }

    /** Model IDs with known prices, for the settings page. */
    public function getModelOptions(): array
    {
        return array_keys($this->prices);
    }

    public function getClientMcpAccess(): string
    {
        return in_array($this->clientMcpAccess, [self::MCP_READ, self::MCP_WRITE], true) ? $this->clientMcpAccess : self::MCP_NONE;
    }

    /** Craft MCP write tools the client chat may use (empty unless clientMcpAccess is `write`). */
    public function getClientWriteTools(): array
    {
        return $this->getClientMcpAccess() === self::MCP_WRITE ? $this->clientWriteTools : [];
    }

    public function getDeveloperApiKey(): string
    {
        $key = (string)App::parseEnv($this->developerApiKey);

        return $key !== '' ? $key : $this->getApiKey();
    }

    public function isSqlEnabled(): bool
    {
        return !empty($this->sql['enabled']) && (string)App::parseEnv($this->sql['dsn'] ?? '') !== '';
    }

    /**
     * @return array{input: float, output: float, cacheRead: float, cacheWrite: float}|null
     */
    public function getPrices(string $model): ?array
    {
        return $this->prices[$model] ?? null;
    }

    protected function defineRules(): array
    {
        return [
            [['model', 'effort', 'assistantName'], 'required'],
            ['clientMcpAccess', 'in', 'range' => [self::MCP_NONE, self::MCP_READ, self::MCP_WRITE]],
            ['effort', 'in', 'range' => ['low', 'medium', 'high', 'xhigh', 'max']],
            [['maxTokens', 'maxTurns', 'maxContextChars'], 'integer', 'min' => 1],
            [['monthlyBudget', 'userMonthlyBudget'], 'number', 'min' => 0],
        ];
    }
}
