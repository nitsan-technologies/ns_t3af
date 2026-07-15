# ns_t3af — Operating Principles

*The common rule. Every change to context, code, or docs must serve the three goals below.*

---

## Goal 1 — Correct, maintainable code

Ship features that match locked decisions in `context/specs/FEATURE_*.md` and `context/features/*.md`.

- Prefer `AiServiceInterface` over deprecated `AiRequestService` / `BaseClient`.
- Child extensions integrate via the public API — never fork provider clients.
- Tests and PHPStan must pass before claiming done.

## Goal 2 — Token-cost efficiency (agent context)

Spend the fewest tokens that still give accurate guidance.

- **DRY context:** each fact lives in exactly ONE file. Reference, don't copy.
- **Load-on-demand:** read `context/features/<area>.md` for the task — not all `context/specs/` files.
- **Prune as you go:** remove stale session noise; update `context/session-state.md` briefly.

## Goal 3 — Accuracy and trust

A confident wrong path is worse than asking.

- **Answer from context + code**, not invented APIs or table names.
- **Link deep specs** (`context/specs/`, `Documentation/`) instead of paraphrasing 400 lines.
- Conflicting facts across files = fix the source.

---

## Guardrails (non-negotiable)

- API keys in provider rows only — encrypted via `CredentialCipher` (`enc:v1:`). Never log or echo secrets.
- GPL attribution when porting from `aim`, `nr_llm`, `typo3_mcp_server`, `ms_mcp_server` — see `context/core.md`.
- Controllers must not import adapters directly (phpat architecture tests).
- Custom adapters: tag `nst3af.adapter` in the **child** extension's `Services.yaml`.

---

## Apply this whenever you

- Edit `context/` or `AGENTS.md`
- Land a feature (update `context/features/*.md` + `session-state.md`)
- Port code from sibling packages in the monorepo
