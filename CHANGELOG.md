# Release Notes for Project Assistant

## Unreleased

- New developer chat (**Assistant → Developer chat**), only for the user IDs in `developerUserIds`. Besides the read tools, it can use Craft's own MCP tools (Craft 5.12+) as the signed-in developer: every read-only tool, plus the content write tools in `developerWriteTools` (entries, drafts, revisions, assets, structure order). Project config, plugins, permissions and system settings are left out.
- Every tool call that can change something waits for **Approve** or **Reject** in the chat (`developerConfirmWrites`, on by default). A new message rejects pending changes.
- Optional `developerApiKey` and `developerModel`, so developer usage can be billed separately; it then doesn't count against the client's budgets.
- Console: `--mode=developer` and `--approve` options on the assistant commands; `--user` also accepts a user ID.
- Migration: conversations get a `mode` (`client` or `developer`) and a `pendingTools` column.
- The ticket card (draft and sent states) now follows the Cossette client portal style: ink and grey palette, Inter when installed locally, a soft colour field with the ticket system shown as a pill, pill buttons and key / value rows. Styles stay scoped to `.pa-ticket`, and the colours are `--pa-ticket-*` custom properties a project can override.

## 1.0.0-beta.1 - 2026-11-06

- Initial release, extracted from `vital-craft/plugins/craft-project-assistant`.
- Read-only chat in the control panel: content model, entries, code (limited to `allowedPaths`) and optional read-only SQL.
- Ticket drafts that the user edits and confirms, with an optional signed webhook handoff.
- Usage page with monthly budgets per user and an audit log.
