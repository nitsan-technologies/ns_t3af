@AGENTS.md

Claude-only notes:

- Prefer `context/features/<area>.md` (~100 lines) over `context/specs/FEATURE_*.md` unless you are implementing that feature.
- Update `context/session-state.md` at end of session (brief bullet summary).
- Skills live in `.agents/skills/` — symlinked to `.claude/skills/` via `link-skills.sh`.
