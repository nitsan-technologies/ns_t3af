# Feature — AI Agent (backend modal)

**Status:** Done (structural + NL routing, embedding shortlist, DualMode preview→apply, BE i18n)  
**UI:** Global toolbar **Ask AI Agent** (`AgentToolbarItem`), modal JS `@nitsan/nst3af/agent.js`  
**Settings route:** `t3af_dashboard.ai_agent`  
**Verify:** `Documentation/Agent/VerifySuite.md`, `Tests/Unit/Agent/` · routing docs: `Documentation/Agent/Routing.md`, `PreviewApply.md`, `Eval.md`

---

## What it does

- **Global backend assistant** — orange launch bar + **Ctrl/Cmd+Shift+K** (optional Ctrl/Cmd+K via `localStorage`; see `Documentation/Agent/HotkeyConflictMatrix.md`).
- **Turn inputs:** natural language, `/tool_name` slash commands, `@table:uid` record attachments.
- **Tool catalog** — executable vs locked tools from `PermittedActionProvider` (entitlements, severity, plan support).
- **Read tools** — auto-run; results shown as **editor-facing prose** (not raw snake_case ids).
- **Write / destructive tools** — DualMode + Previewable: native preview → suggestions card → context apply (`AgentWriteService::applySuggestions`). Other writes: elicitation draft → DataHandler / confirm invoke.
- **NL orchestration** — `AgentTurnOrchestrator` + `AiToolCallingServiceInterface` loop with budgets and brand context.
- **Structural routing** — `AgentTurnRouter` (slash / `@` / UI tool chips); free-text NL goes only to the orchestrator (no keyword workflows).
- **Semantic shortlist** — `AgentToolShortlistService` + `t3af:agent:index-tools`; eval fixtures via `t3af:agent:eval`.
- **Conversation persistence** — `tx_nst3af_agent_conversation` via `AgentConversationRepository` / session API.

---

## Turn resolution order

Non-stream `turnAction` and stream turns both call `AgentTurnRouter::route()`:

| Step | Service | Notes |
|---|---|---|
| 1 | Structural | Slash commands, UI `tool`/`action` (legacy SEO/file-metadata action ids mapped once to MCP tools), `@` attachment reads |
| 2 | `AgentTurnOrchestrator` | Free-text NL only — embedding shortlist + LLM tool-calling; **no** keyword workflows / SEO flow / read fast-path |

Slash/`@` / starter chips go to `AgentToolTurnProcessor::execute()`. Meaning for free-text lives in the LLM + `AgentToolShortlistService`.

---

## Backend context

Resolved server-side in `AgentContextResolver` + client hints in `agent.js`.

| Module | `pageId` | Extra |
|---|---|---|
| Page / List | current page | `languageId` from iframe `languages[n]` when one non-default column selected |
| File (`media_management`) | **0** (never stale web-tree page) | `storageUid`, `folderIdentifier` from iframe `id=1:/path/` |
| Other | client `pageId` when readable | record attachment, workspace |

`AgentToolTurnProcessor::mergeContextArguments()` injects `pageId`/`pid`/`uid`, `storageUid`, `workspaceId`, `targetLanguageUid`.

---

## Editor-facing tool answers

| Piece | Path |
|---|---|
| Human tool title | `AgentToolEditorLabelService` → catalog `editorLabel`, turn meta `toolCallLabel` |
| Result prose | `AgentToolResultPresenter` — list leads, action success, file metadata facts |
| UI header | `agent.js` `resolveToolDisplayLabel()` — friendly label in card; technical id only under **Details** |
| Facts block | Hidden in UI when `message.content` already has prose (facts remain in meta for debug) |

LLM summaries skipped for structured list results (count + examples) to avoid redundant text.

Label priority: translated `LABEL_KEYS` → editor-friendly MCP description first sentence → humanized name (`t3aa_*` prefix stripped).

---

## Work trace UX (`agent.js`)

- While running: compact **Working…** (expandable trace).
- On complete: **Worked for Xs** (live only — stripped before session save via `stripEphemeralWorkTraceMeta()`).
- Result card renders immediately below trace (not hidden inside collapsed details).

---

## Write path

| Piece | Path |
|---|---|
| Preview DualMode | `McpPlaygroundService::preview` + `McpModeOverride` → suggestions meta |
| Suggestions apply | `AgentWriteService::applySuggestions` (context mode content params) |
| Plan + draft card | `AgentToolPlanResolver`, `AgentDraftService`, `SatelliteToolPlanService` |
| Apply (non-preview) | `AgentWriteService::apply` → DataHandler or tool confirmation invoke |
| Tool confirmation kind | `PLAN_KIND_TOOL_CONFIRMATION` — run-after-confirm for non-DataHandler tools |
| Classification | `Documentation/Agent/NonDataHandlerToolClassification.md` |

Draft cards carry `editorLabel` for UI; destructive = two-step confirm.

---

## NL tool selection

- Child tools declare `#[McpToolIntent(verbs, nouns, modules)]` on tool classes (`ns_t3af` attribute; consumed by introspector / embedding index).
- `AgentToolShortlistService` ranks catalog tools via embeddings (with category/module fallback); orchestrator uses that shortlist before tool-calling.
- Starter chips emit MCP tool names + args (`t3ai_generate_all_seo`, `t3aa_update_file_metadata`, …) — not legacy NL flow action ids.

---

## Key paths

| Area | Path |
|---|---|
| AJAX controller | `Classes/Agent/Controller/AgentAjaxController.php` |
| Routes | `Configuration/Backend/AjaxRoutes.php` (`nst3af_agent_*`) |
| Context | `Classes/Agent/Context/{AgentContext,AgentContextResolver}.php` |
| Turn router | `Classes/Agent/Service/AgentTurnRouter.php` |
| Turn processor | `Classes/Agent/Service/AgentToolTurnProcessor.php` |
| Orchestrator | `Classes/Agent/Service/AgentTurnOrchestrator.php` |
| Tool shortlist | `Classes/Agent/Service/AgentToolShortlistService.php` |
| Tool catalog | `Classes/Agent/Service/PermittedActionProvider.php` |
| Editor labels | `Classes/Agent/Service/AgentToolEditorLabelService.php` |
| Result presenter | `Classes/Agent/Service/AgentToolResultPresenter.php` |
| Starters | `Classes/Agent/Service/AgentStarterBuilder.php` |
| Governance | `Classes/Agent/Service/AgentGovernanceGuard.php`, `AgentTurnRepository` |
| Frontend | `Resources/Public/JavaScript/agent.js`, `Resources/Public/Css/module/agent.css` |
| Labels | `Resources/Private/Language/locallang_be.xlf` (`agent.*`) |

---

## ext_conf (Extension Configuration)

- `agentMaxReadToolsPerTurn` (default 5)
- `agentMaxWriteDraftsPerTurn` (default 2)
- `agentShowProviderThinking`
- `agentConversationRetentionDays` (default 90)

---

## Child extensions

- **MCP tools** — tag `mcp.tool`; optional `McpToolIntent` for agent retrieval (`ns_t3ai`, `ns_t3aa`, …).
- **Entitlements** — `EntitlementResolver` locks tools when owner extension inactive.
- **Satellite write tools** — implement planning in child or use `write_table` / confirmation flow per classification doc.

---

## Do / Don't

**Do:** Keep structural router before NL orchestrator on stream and non-stream paths.

**Do:** Pass `storageUid` / `folderIdentifier` from file module; clear `pageId` there.

**Do:** Use `editorLabel` / `toolCallLabel` in any new turn meta or draft payloads.

**Don't:** Show raw tool names or duplicate fact tables when presenter already returned prose.

**Don't:** Persist `workDurationMs`, `workSummary`, `fromDraftApply` in conversation rows.

---

## Verification

```bash
cd packages/ns_t3af
composer test -- Tests/Unit/Agent/
composer stan
```

Manual: File module → “list images missing alt text” → **List images missing alt text** header, count + examples, no duplicate facts block. Hard-refresh backend JS after `agent.js` changes.
