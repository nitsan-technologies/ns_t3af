# AGENTS.md — ns_t3af

Agent router for the master TYPO3 AI extension. Load only what your task needs.

> **Principles:** `context/principles.md`  
> **Session log:** `context/session-state.md`  
> **Doc index:** `context/docs-map.md`  
> **Deep specs:** `context/specs/` (load on demand)

---

## Always load

| File | Why |
|---|---|
| `context/principles.md` | DRY, guardrails, attribution |
| `context/core.md` | Compatibility, tooling, conventions |

---

## Task router

| Task | Load |
|---|---|
| Provider / adapter / cipher | `context/features/providers.md`, `tasks/implement-provider.md` |
| `AiServiceInterface` / events / streaming | `context/features/public-api.md` |
| Child ext (`ns_t3ai`, `ns_t3cs`, `ns_t3aa`, `ns_t3as`, `ns_t3ac`) | `context/features/child-extensions.md`, `context/features/public-api.md` |
| T3Planet Credits / billing | `context/features/credits.md` |
| MCP server / OAuth / tools | `context/features/mcp-server.md` |
| Dashboard / AI Features drawer | `context/features/backend-module.md` |
| AI Logs tab / child log links / iframe filters | `context/features/ai-logs.md` |
| AI Context / brand profiles / placeholders / dashboard bar | `context/features/ai-context.md` |
| ACL / logging / alerts | `context/features/governance.md` |
| AI Access / Roles wizard / matrix / enforcement | `context/features/ai-access-roles.md`, `Documentation/Developer/CustomAiAccess.rst` |
| Architecture / tables / caches | `context/architecture.md` |
| Run tests / CI | `tasks/run-quality.md` |
| Update agent context after a landing | `tasks/context-update.md` |

Prefer `context/features/*.md` over `context/specs/FEATURE_*.md` unless implementing.

---

## Feature status

| Feature | Status | Agent entry | Deep spec |
|---|---|---|---|
| AI Providers | Done | `context/features/providers.md` | `context/specs/FEATURE_AiProviderManagement.md` |
| Public API | Done | `context/features/public-api.md` | `Documentation/Api/PublicApi.rst` |
| Backend module | Done | `context/features/backend-module.md` | — |
| AI Logs (sys_log UI) | Done | `context/features/ai-logs.md` | — |
| AI Context (brand profiles) | Done | `context/features/ai-context.md` | `Documentation/Architecture/AiContextImplementationPlan.md` |
| Governance / telemetry | Done | `context/features/governance.md` | `Documentation/Governance/Index.rst` |
| Child integration | Ongoing | `context/features/child-extensions.md` | `Documentation/Developer/ExtensionIntegration.rst` |
| AI Access & Roles | Done | `context/features/ai-access-roles.md` | `Documentation/Developer/CustomAiAccess.rst` |
| T3Planet Credits | Implemented (Coming soon gate) | `context/features/credits.md` | `context/specs/FEATURE_T3PlanetCredits_Client.md` |
| MCP Server & tools | Done | `context/features/mcp-server.md` | `context/specs/FEATURE_McpServer.md` |

---

## Skills (`.agents/skills/`)

| Skill | Use |
|---|---|
| `nst3af-test` | PHPUnit, PHPStan, cs:check |
| `nst3af-provider` | Provider/adapter work |
| `nst3af-context-update` | Maintain `context/` after features |

Symlinks: run `.agents/scripts/link-skills.sh` after adding skills.

---

## Quick commands

```bash
cd packages/ns-t3af   # host path; extension key remains ns_t3af
composer test && composer stan
```

See `context/core.md` for DDEV and functional test commands.
