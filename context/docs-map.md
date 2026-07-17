# Docs Map — ns_t3af

*Index for fetch-on-demand. Store WHAT each source answers + path — not full page content.*

---

## Agent context (read first for coding)

| Need | Path |
|---|---|
| Router / what to load | `AGENTS.md` |
| Principles | `context/principles.md` |
| Backend UI (TYPO3 v12–v14, styleguide) | `context/Typo3CoreBackendDesign.md` |
| Tooling, compatibility | `context/core.md` |
| Runtime architecture | `context/architecture.md` |
| Feature summaries | `context/features/<name>.md` |
| Deep specs (on demand) | `context/specs/FEATURE_*.md` |
| Workflows | `tasks/*.md` |
| Session log | `context/session-state.md` |
| AI Permissions (wizard, matrix, enforcement) | `context/features/ai-access-roles.md`, `Documentation/Developer/CustomAiAccess.rst` |

---

## Deep implementation specs (`context/specs/`)

| Feature | File | Agent entry |
|---|---|---|
| AI Providers | `context/specs/FEATURE_AiProviderManagement.md` | `context/features/providers.md` |
| MCP Server | `context/specs/FEATURE_McpServer.md` | `context/features/mcp-server.md` |
| Credits (client) | `context/specs/FEATURE_T3PlanetCredits_Client.md` | `context/features/credits.md` |
| Credits (server) | `context/specs/FEATURE_T3PlanetCredits_Server.md` | `context/features/credits.md` |
| Credits rollout | `context/specs/FEATURE_T3PlanetCredits_NsAiuniverse_Rollout.md` | `context/features/credits.md` |
| Credits plan (legacy) | `context/specs/FEATURE_T3PlanetCreditsPlan.md` | `context/features/credits.md` |

See `context/specs/README.md` for the full index.

---

## User-facing Sphinx (`Documentation/`)

| Topic | Path |
|---|---|
| Introduction | `Documentation/Introduction/Index.rst` |
| Installation | `Documentation/Installation/Index.rst` |
| Configuration | `Documentation/Configuration/Index.rst` |
| Usage | `Documentation/Usage/Index.rst` |
| Public API | `Documentation/Api/PublicApi.rst` |
| Custom providers | `Documentation/Developer/CustomProviders.rst` |
| Custom AI prompts | `Documentation/Developer/CustomAiPrompts.rst` |
| Custom AI features | `Documentation/Developer/CustomAiFeatures.rst` |
| Custom AI access / permissions | `Documentation/Developer/CustomAiAccess.rst` |
| Custom MCP tools | `Documentation/Developer/CustomMcpTools.rst` |
| Child integration | `Documentation/Developer/ExtensionIntegration.rst` |
| T3Planet Credits | `Documentation/Developer/T3PlanetCredits.rst` |
| MCP Server | `Documentation/McpServer/Index.rst` |
| Governance | `Documentation/Governance/Index.rst` |
| Troubleshooting | `Documentation/Troubleshooting/Index.rst` |
| Privacy | `Documentation/Privacy.rst` |

Build locally: `composer doc-watch` from package root.

---

## Common developer questions → where to look

| Question | Load |
|---|---|
| How do I call AI from my extension? | `context/features/public-api.md`, `Documentation/Api/PublicApi.rst` |
| How do I add a custom provider? | `context/features/providers.md`, `Documentation/Developer/CustomProviders.rst` |
| Where are API keys stored? | Providers drawer + `tx_nst3af_provider` (not ext_conf) |
| Migrate old ext_conf keys? | Configure providers in **AI Foundation → Providers** (keys live in `tx_nst3af_provider`) |
| Credits mode / billing | `context/features/credits.md` |
| MCP OAuth / tools | `context/features/mcp-server.md`, `Documentation/McpServer/` |
| Run tests / CI | `tasks/run-quality.md` |
| Backend module markup / CSS conventions | `context/Typo3CoreBackendDesign.md` |
| Shared checklist in child ext (ns_t3aa, ns_t3ai, ns_t3cs) | `SetupChecklistPresenter`, `ChildSetupChecklistSlot.html`, design guide § Shared UI |
| AI Logs tab / filter navigation / child “AI Foundation Logs” button | `context/features/ai-logs.md`, `BackendModuleLinkUtility`, `module-navigation.js` |
| AI Permissions wizard / matrix / editor enforcement | `context/features/ai-access-roles.md`, `access-roles.js` |
| Register module/features/records ACL from child ext | `Documentation/Developer/CustomAiAccess.rst`, `AiAccessCatalogProviderInterface` |
| AI Context / brand profiles / dashboard bar / persona UI | `context/features/ai-context.md` |
| Brand Context override in AI Features (SEO, Pages, …) | `context/features/ai-context.md` § AI Features — profile override |
| Runtime `{brand_context}` placeholders | `context/features/ai-context.md`, `BrandContextPromptInjectionListener` |
