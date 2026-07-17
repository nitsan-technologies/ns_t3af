# Context Update Guide

> Before any edit, follow `context/principles.md`.

| What changed | Update this file |
|---|---|
| Operating principles | `context/principles.md` |
| PHP/TYPO3 floors, tooling, conventions | `context/core.md` |
| Service layers, tables, caches | `context/architecture.md` |
| Doc / FEATURE index | `context/docs-map.md` |
| Session / last touched | `context/session-state.md` |
| Provider / adapter | `context/features/providers.md` |
| `AiServiceInterface` / events | `context/features/public-api.md` |
| Credits / token auth | `context/features/credits.md` |
| MCP transport / OAuth / tools | `context/features/mcp-server.md` |
| ACL / logging / alerts | `context/features/governance.md` |
| Dashboard / AI Features drawer | `context/features/backend-module.md` |
| ns_t3ai / ns_t3cs / ns_t3aa wiring | `context/features/child-extensions.md` |
| AI Access / Roles / provider registry | `context/features/ai-access-roles.md` |
| Test / CI commands | `tasks/run-quality.md` |
| Provider implementation checklist | `tasks/implement-provider.md` |
| Deep implementation spec | `context/specs/FEATURE_*.md` |
| Spec index | `context/specs/README.md` |
| Router / feature table | `AGENTS.md` (minimal edits only) |

**Do not** paste full specs into `context/features/` — link to `context/specs/FEATURE_*.md` instead.

After landing a feature: append 3–5 lines to `context/session-state.md` with date + summary.
