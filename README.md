# Project Assistant for Craft CMS

A read-only AI assistant in the Craft control panel. Clients ask questions about their website ("how many events are published?", "can I add a video to a news article?", "why doesn't this page show up?") and get answers based on the project's real content, content model and code.

When the CMS can't do what they need, the assistant drafts a ticket for the development team. The user reviews and edits the draft, then confirms it, and it's handed off with the conversation's context.

- Craft CMS 5, PHP 8.2+
- Claude through the Anthropic API ([`anthropic-ai/sdk`](https://github.com/anthropics/anthropic-sdk-php))
- No extra service to run: everything runs inside Craft

> **Status:** beta. Extracted from `vital-craft`, where it was first built. It's built to be reused across projects: nothing in `src/` is specific to Vital.

## What it can and can't do

The assistant's tools are **read-only**:

| Tool | What it does |
|---|---|
| `describe_content_model` | Sites, sections, entry types, fields (types, options, relations, Matrix), global sets, through Craft's APIs |
| `query_entries` | Find, count and read entries, filtered by section, search, status, site and field values |
| `list_files`, `read_file`, `search_code` | Read the project's code, project config and docs, limited to `allowedPaths` |
| `run_sql` | Read-only SQL on a dedicated connection (off unless configured) |
| `propose_ticket` | Draft a ticket. Nothing is sent: the user confirms in the UI |

Projects can add their own read-only tools (see [Project tools](#project-tools)). The [developer chat](#developer-chat) adds write tools for the agency's developers.

### How read-only is enforced

The model never gets a way to write, and the important limits don't depend on the prompt:

1. **Content** is read through element queries, only for the sections the user is allowed to view in the CP. Drafts and revisions aren't returned, and related users show as names only.
2. **Files** must be under `allowedPaths`. Every path is resolved to its real path, so `..` and symlinks can't escape. A built-in deny list always wins: `.env*`, keys and certificates, `storage/`, `vendor/`, `.git/`, `node_modules/`, `*.sql`, `*secret*`, `*credentials*`, and others (see `PathGuard::ALWAYS_DENIED`).
3. **SQL** uses its own connection with a database user you create that has `SELECT` only on the tables or views you list. On top of that, the tool:
   - accepts a single `SELECT`/`WITH`/`SHOW`/`DESCRIBE`/`EXPLAIN` statement
   - rejects comments and write or file keywords
   - checks the tables used against the allowlist
   - runs inside a read-only transaction that's always rolled back, with a timeout and a row cap
4. **Tickets** go out only when a person clicks **Send ticket**. Content written by the public (form submissions) can't make the assistant send anything.
5. **Answers** are rendered from Markdown on the server and sanitized. Images and external resources are stripped, so an answer can't leak data by loading a URL.

Every question, model call (with tokens and cost), tool call (input + truncated output), ticket and error is logged in `projectassistant_events`.

## Install

```bash
composer require cossette-numerique/craft-project-assistant
php craft plugin/install project-assistant
```

The package isn't on Packagist, so add this repository to the project's `composer.json` first (the project needs read access to this private repo: locally your GitHub login is enough, servers need a token in `COMPOSER_AUTH`):

```jsonc
"repositories": [
  { "type": "vcs", "url": "https://github.com/cossette-numerique/craft-project-assistant.git" }
]
```

While it's in beta, require it with `composer require cossette-numerique/craft-project-assistant:^1.0@beta`.

Then:

1. Add `ANTHROPIC_API_KEY` to the environment. The client can own the Anthropic account; see [Costs and billing](#costs-and-billing).
2. Give the user group(s) that should use it:
   - **Access Project Assistant** (`accessplugin-project-assistant`)
   - **View usage, costs and every user's conversations** (`projectassistant-viewusage`), optionally
3. Create `config/project-assistant.php` (below).

## Configuration

All settings live in `config/project-assistant.php`. Strings can be environment variables (`'$MY_VAR'`). See `src/models/Settings.php` for the complete list.

```php
<?php
use craft\helpers\App;

return [
    'assistantName' => 'Assistant',
    'projectName' => 'My Project',          // default: the system name
    'model' => 'claude-opus-5-5',           // or 'claude-sonnet-5-5' (cheaper)
    'effort' => 'medium',                   // low | medium | high | xhigh | max

    // Spend caps in USD per month (0 = no cap)
    'monthlyBudget' => 100,
    'userMonthlyBudget' => 30,

    // Project-specific instructions for the assistant
    'instructions' => <<<'TXT'
- The site is bilingual (fr/en); most editors work in French.
- ...
TXT,

    // Loaded into the prompt for every question (cached). Keep it to what most
    // questions need; everything else stays reachable through read_file.
    'contextFiles' => ['documentation/editor-guide.md', '.cursor/rules/roles.mdc'],

    // What the code tools can read (relative to the project root)
    'allowedPaths' => ['config/project', 'modules', 'templates', 'documentation'],
    'deniedPaths' => [],                    // extra globs, on top of the built-in deny list

    // Optional read-only SQL
    'sql' => [
        'enabled' => (bool)App::env('PROJECT_ASSISTANT_DB_DSN'),
        'dsn' => '$PROJECT_ASSISTANT_DB_DSN',
        'username' => '$PROJECT_ASSISTANT_DB_USER',
        'password' => '$PROJECT_ASSISTANT_DB_PASSWORD',
        'tables' => [
            'v_members' => 'One row per member company: name, status, renewal date',
        ],
        'maxRows' => 200,
        'timeoutSeconds' => 10,
    ],

    // Where confirmed tickets go (see "Tickets")
    'ticketProvider' => ['type' => 'placeholder'],
];
```

### Read-only database user (MySQL)

Expose views rather than raw Craft tables: they're easier for the model to query (Craft 5 stores field values as JSON), and you choose exactly which columns are visible.

```sql
CREATE VIEW v_members AS SELECT ...;   -- only the columns the assistant should see
CREATE USER 'assistant_ro'@'%' IDENTIFIED BY '...';
GRANT SELECT ON mydb.v_members TO 'assistant_ro'@'%';
```

Never grant it the `users` or `sessions` tables, or any table holding tokens.

## Tickets

When the assistant concludes the CMS can't do something, it offers a ticket and calls `propose_ticket`. The user can also click **Create a ticket** at any time. The draft appears in the chat as an editable card. When the user clicks **Send ticket**, the plugin builds a handoff and passes it to the configured provider.

### Providers

| `ticketProvider` | Behavior |
|---|---|
| `['type' => 'placeholder']` (default) | The handoff is saved on the conversation and in the audit log. Nothing leaves Craft. The UI says the ticket system isn't connected. |
| `['type' => 'webhook', 'url' => '$TICKETS_WEBHOOK_URL', 'secret' => '$TICKETS_WEBHOOK_SECRET', 'name' => 'Support']` | POSTs the handoff as JSON. With a secret, it adds `X-Project-Assistant-Signature: sha256=<HMAC-SHA256 of the raw body>`. Expects a 2xx and reads `reference` (or `id`/`key`), `url` and `status` from the JSON response. Extra headers: `'headers' => ['Authorization' => '$TICKETS_TOKEN']`. |
| `['type' => MyProvider::class, ...]` | Any class implementing `TicketProviderInterface`. The other keys are passed to its constructor. |

### Handoff payload (`project-assistant.handoff/1`)

```jsonc
{
  "schema": "project-assistant.handoff/1",
  "ticket": {
    "title": "Ajouter un filtre par région aux événements",
    "category": "feature_request",        // feature_request | bug | configuration_change | content_help | question
    "priority": "high",                   // low | normal | high | urgent
    "request": "What the user needs, in their words",
    "finding": "Why the CMS can't do it today",
    "evidence": ["What the assistant checked", "..."],
    "suggestedApproach": "How a developer could address it",
    "userNotes": "Anything the user added when confirming"
  },
  "requester": { "id": 1654, "name": "…", "email": "…", "groups": ["admin"] },
  "project": {
    "name": "Vital", "siteUrl": "https://…", "cpUrl": "https://…/admin",
    "environment": "production", "craftVersion": "5.11.5.1", "pluginVersion": "1.0.0"
  },
  "conversation": {
    "id": 2,
    "url": "https://…/admin/project-assistant/c/2",
    "title": "…",
    "startedAt": "2026-11-06T08:55:00-05:00",
    "transcript": [{ "role": "user|assistant", "text": "…", "time": "…" }],
    "steps": ["Looking at the “events” content model", "…"]
  },
  "createdAt": "2026-11-06T08:55:02-05:00"
}
```

The transcript contains only what was said: questions and answers. Raw tool results (database rows, file contents) are never included. The full conversation stays in the CP at `conversation.url`, for users with the usage permission.

## Developer chat

A second chat, **Assistant → Developer chat**, for the agency's developers. It can **change content**, not just read it. Only the user IDs in `developerUserIds` can open it; everyone else gets a 403, and that includes other admins. It is off when the list is empty.

```php
'developerUserIds' => [1],
'developerConfirmWrites' => true,                       // default: every change waits for approval
'developerApiKey' => '$PROJECT_ASSISTANT_DEVELOPER_API_KEY', // optional: keep developer usage off the client's bill
'developerModel' => 'claude-opus-5-5',                  // optional
'developerInstructions' => '',                           // optional extra prompt for this chat
```

**Tools:** the read tools above, plus Craft's own MCP tools (Craft 5.12+, offered to the model as `craft_*`). They're called in-process and act as the signed-in developer, so Craft checks that user's permissions on every call. You get every read-only Craft tool, and the write tools listed in `developerWriteTools`:
- entries and elements: create, update, duplicate, delete, restore
- drafts and revisions
- assets
- structure order

Project config, plugins, user permissions and system settings are left out on purpose: structural changes keep going through project config YAML in git. The MCP server doesn't need to be enabled for the developer chat.

**Approvals:** when the model calls a tool that can change something, the run pauses. The chat shows the tool and its exact arguments, with **Approve** and **Reject** buttons:
- **Approve** runs the call as you, then resumes the run.
- **Reject** tells the model it wasn't run.
- **Sending a new message** rejects whatever is still pending.

Content written by the public can't trigger a change on its own: it would still need a click. Every call, its arguments and the decision are logged in the audit log.

**Billing:** if `developerApiKey` is set, the developer chat uses it, and its spend isn't counted against the client's budgets.

**Console:** to test the developer mode from the command line:

```bash
php craft project-assistant/assistant/tools --mode=developer --user=1
php craft project-assistant/assistant/ask "Create a draft of entry 123" --mode=developer --user=1 --approve
```

## Project tools

A project module can register its own tools, for example a scoped view of renewals:

```php
use cossette\projectassistant\events\RegisterToolsEvent;
use cossette\projectassistant\services\Tools;
use yii\base\Event;

Event::on(Tools::class, Tools::EVENT_REGISTER_TOOLS, function (RegisterToolsEvent $event) {
    $event->tools[] = new \modules\mymodule\assistant\RenewalsTool();
});
```

A tool implements `ToolInterface` (or extends `BaseTool`). It has a name, a description written for the model, a JSON Schema, a progress label for the UI, and `execute(array $input, ToolContext $context): ToolResult`. **Keep tools read-only**, and use `$context->user` to apply the same permissions the CP does.

## Costs and billing

Each question is a loop of model calls. Most of the input, meaning the prompt, the context files and the earlier turns, is cached between calls and billed at the cache-read rate.

- The usage page (**Assistant → Usage**) shows estimated spend per month and per user, and every conversation.
- Budgets (`monthlyBudget`, `userMonthlyBudget`) block new questions once reached.
- Estimates come from the `prices` table in the settings; update it if prices change. The provider's invoice is what counts.

For clients who pay their own usage, the simplest setup is an Anthropic account in the client's name with their card. Put its API key in the project's environment and set a spend limit in the Anthropic Console as well.

## Developer commands

```bash
php craft project-assistant/assistant/tools                       # list tools and descriptions
php craft project-assistant/assistant/run-tool query_entries '{"section":"news","countOnly":true}' --user=admin
php craft project-assistant/assistant/system-prompt --user=admin  # the prompt as sent, with its size
php craft project-assistant/assistant/ask "How many events are published?" --user=admin   # real model calls
```

For local testing without real model calls, point `PROJECT_ASSISTANT_BASE_URL` at a mock of the Messages API.

## Data stored

| Table | Contents |
|---|---|
| `projectassistant_conversations` | One row per conversation: model history (API format), chat transcript, ticket draft and handoff, token totals and cost. Deleted with its user. |
| `projectassistant_events` | Audit and usage log: questions, model calls (tokens, cost, model), tool calls (input, truncated output), tickets, errors. Kept when a conversation is deleted. |

## Not done yet

- Amazon Bedrock / Google Vertex providers, for Canadian data residency. The Anthropic SDK supports both, but they aren't wired in yet.
- Ticket status coming back from the ticket system (webhook into Craft).
- Deleting a conversation from the UI.
