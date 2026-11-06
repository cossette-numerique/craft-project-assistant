# Release Notes for Project Assistant

## Unreleased

- The ticket card (draft and sent states) now follows the Cossette client portal style: ink and grey palette, Inter when installed locally, a soft colour field with the ticket system shown as a pill, pill buttons and key / value rows. Styles stay scoped to `.pa-ticket`, and the colours are `--pa-ticket-*` custom properties a project can override.

## 1.0.0-beta.1 - 2026-11-06

- Initial release, extracted from `vital-craft/plugins/craft-project-assistant`.
- Read-only chat in the control panel: content model, entries, code (limited to `allowedPaths`) and optional read-only SQL.
- Ticket drafts that the user edits and confirms, with an optional signed webhook handoff.
- Usage page with monthly budgets per user and an audit log.
