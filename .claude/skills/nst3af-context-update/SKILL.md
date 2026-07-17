---
name: nst3af-context-update
description: Update agent context files in ns_t3af after a feature lands or architecture changes.
---

Read `tasks/context-update.md` for the file map.

Workflow:

1. Update the relevant `context/features/<feature>.md` (status, key paths, decisions).
2. If cross-cutting, update `context/core.md` or `context/architecture.md`.
3. Append a short note to `context/session-state.md` (date + what changed).
4. Do **not** bloat `AGENTS.md` — keep it a router only.
5. Link to `context/specs/FEATURE_*.md` for deep detail; do not duplicate full specs.

Principles: `context/principles.md` (DRY, load-on-demand).
