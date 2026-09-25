# Feature — AI Agent (backend modal)

**Status:** Done (structural + NL routing on Symfony AI Agent, core tool set + find_tools, DualMode preview→apply, BE i18n)  
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
- **NL turns** — `AgentRunner`: Symfony AI `Agent` loop; `GovernedPlatform` sends every round through `AiToolCallingServiceInterface` (governance, credits, logs); `T3afToolbox` runs tools with argument validation, budgets and pauses.
- **Structural routing** — `AgentTurnRouter` (slash / `@` / UI tool chips); free-text NL goes only to `AgentRunner` (no keyword workflows).
- **Tool selection** — `AgentCoreToolSet` (core + module + recent) and `find_tools` (`AgentToolSearch`: embeddings + BM25); index via `t3af:agent:index-tools`; eval via `t3af:agent:eval` (`--live` runs `Resources/Private/Agent/Eval/Scenarios/*.json` against providers, see `Documentation/Agent/Eval.md`).
- **Per-group tool access** — `LimitsConfig::agentReadOnly` / `blockedAgentTools` → `AgentGovernanceGuard::agentToolPolicy()` → locked in `PermittedActionProvider`.
- **Conversations** — one row per conversation in `tx_nst3af_agent_conversation` (session uuid, home module + page, title, locked provider). `AgentConversationSession` opens the requested or latest one for `agentConversationScope`; conversation list, rename and delete in the window. See `Documentation/Agent/Conversations.md`.
- **Provider select** — `AgentProviderOptions` (tool-calling providers the editor's groups may use); fixed per conversation after the first answer.
- **Info window** — "How the AI Agent works" with a live "Where you are" section (`renderInfo()` in `agent.js`).

---

## Turn resolution order

Non-stream `turnAction` and stream turns both call `AgentTurnRouter::route()`:

| Step | Service | Notes |
|---|---|---|
| 1 | Structural | Slash commands, UI `tool`/`action` (legacy SEO/file-metadata action ids mapped once to MCP tools), `@` attachment reads |
| 2 | `AgentRunner` | Free-text NL only — core tool set + `find_tools` + LLM tool-calling; **no** keyword workflows / SEO flow / read fast-path |

Slash/`@` / starter chips go to `AgentToolTurnProcessor::execute()`. Meaning for free-text lives in the LLM; `find_tools` only helps it discover tools.

---

## Backend context

Resolved server-side in `AgentContextResolver` (permission checks) and presented by `AgentContextPresenter`: chips for the window and `details` (readable module, page with slug and parent, language with the site's languages, record label, workspace, folder). `AgentContextPresenter::promptBlock()` puts them into the system prompt as "Current context".

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

Label priority: `agent.tool.label.<tool_name>` (EN + DE for every tool; `AgentLabelCoverageTest` fails when a core tool has none) → legacy `LABEL_KEYS` → editor-friendly MCP description first sentence → humanized name (`t3aa_*` prefix stripped). New child tools add their `agent.tool.label.*` key to ns_t3af `locallang_be.xlf`.

Lock reasons (`agent.tool.blockedForGroup`, `extensionUnavailable`, `planUnsupported`, `agent.entitlement.*`) are plain language with the next step and use the editor label, never the tool id.

---

## Work trace UX (`agent.js`)

- While running: compact **Working…** (expandable trace).
- On complete: **Worked for Xs** (live only — stripped before session save via `stripEphemeralWorkTraceMeta()`).
- Result card renders immediately below trace (not hidden inside collapsed details).
- Progress events carry `label` (editor label); `find_tools` shows "Looking for the right tool…".
- Successful read steps followed by an answer in the same turn collapse to `✓ <label>` (`.nst3af-agent-step`).
- Tool id, trace and JSON live only under **Technical details**.

## Editor UX rules (Phase 4)

| Rule | Implementation |
|---|---|
| Records and fields by name | `AgentRecordLabeler` → draft fields `recordLabel`/`fieldLabel`, readback `recordLabel`/`fieldLabels` |
| Where a change goes | `renderDraftTarget()` — live website vs. workspace „X“, destructive adds "cannot be undone" |
| Execute / Decline | Draft and tool-confirmation cards; **Execute all (n)** for ≥ 2 pending non-destructive cards |
| Continue after confirm | `agentContinueAfterConfirm`; client sends `continuation {outcome,label,result}` → hidden user message, `continuationMessage()` tells the model; only for cards the runner made (`meta.fromRunner`) |
| Ask instead of guess | `ask_clarification(question, options[])` pauses the turn; options render as answer buttons |
| Links after a change | `applyDraftAction` returns `links` (Open page / Edit / View on website); backend links open in the content frame |
| Credits | `AgentCreditsStatus` → header badge (ok/low/critical); at zero the composer is locked with a top-up hint |

In the agent loop read tools get no extra LLM summary (`present(..., allowLlmSummary: false)`); the model reads the deterministic summary plus the data (JSON, 6000 characters).

Reasoning models (0.13 `MultiPartResult`: thinking + text / tool calls) are read by `SymfonyAiResultReader`; before this, a plain "Hi" returned "I could not produce a reply".

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

- Child tools declare `#[McpToolIntent(modules, summary, examples (EN + DE), category)]`; core tools get `searchTerms` (EN + DE) in `Configuration/McpToolMetadata.yaml`.
- `AgentCoreToolSet` picks the start set; `find_tools` (`AgentToolSearch`) ranks the rest via embeddings + BM25. See `Documentation/Agent/Routing.md`.
- Starter chips are context requests in the editor's language (`AgentStarterBuilder::choose`: page → SEO / translate / add content / accessibility; open record → improve / translate; file → alt text / missing alt text / generate image; draft workspace → changes). A click sends the text as a normal message; a chip only shows when a permitted tool can do it.
- Image previews: `AgentMediaPreviewService` adds `meta.previews` (processed thumbnails, read permission checked) to tool results, suggestion cards and drafts about a file; `agent.js` renders them as a gallery.

---

## Key paths

| Area | Path |
|---|---|
| AJAX controller | `Classes/Agent/Controller/AgentAjaxController.php` |
| Routes | `Configuration/Backend/AjaxRoutes.php` (`nst3af_agent_*`) |
| Context | `Classes/Agent/Context/{AgentContext,AgentContextResolver}.php` |
| Turn router | `Classes/Agent/Service/AgentTurnRouter.php` |
| Turn processor | `Classes/Agent/Service/AgentToolTurnProcessor.php` |
| Runner | `Classes/Agent/Service/AgentRunner.php`, `Classes/Agent/Runtime/{GovernedPlatform,T3afToolbox,AgentTurnState}.php` |
| Tool selection | `Classes/Agent/Service/{AgentCoreToolSet,AgentToolSearch,AgentToolDocumentBuilder,AgentToolArgumentValidator}.php` |
| Tool catalog | `Classes/Agent/Service/PermittedActionProvider.php` |
| Editor labels | `Classes/Agent/Service/AgentToolEditorLabelService.php`, `AgentRecordLabeler.php` |
| Credits badge | `Classes/Agent/Service/AgentCreditsStatus.php` |
| Conversation storage | `AgentConversationSession` (load/save), `AgentConversationRecorder` (card actions), `AgentConversationSummarizer` (summary); see `Documentation/Agent/Conversations.md` |
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
- `agentConversationRetentionDays` (default 90; soft delete, removed 7 days later by `t3af:agent:conversations:cleanup`)
- `agentContinueAfterConfirm` (default on)
- `agentHistoryTokenBudget` (default 6000 tokens of replayed history)
- `agentWorkspaceMode` (`draft` default: from Live, changes go into `agentDraftWorkspaceUid` or the first workspace the editor may use; `current`: the editor's workspace). `AgentWorkspaceTarget`; the workspace switch is per request (`setTemporaryWorkspace`), never the editor's backend workspace.
- All `AI Agent` settings also appear in the **AI Agent** card of AI Features (scope `ai agent`).
- `agentConversationScope` (`page` | `module` | `user`), `agentSessionListEnabled`, `agentSessionListDefaultFilter`, `agentMaxSessionsPerScope` (20), `agentMaxSessionsPerUser` (200)

---

## Child extensions

Phase 4 child tools (all with `McpToolSeverity`, `McpToolIntent` EN/DE, `agent.tool.label.*`; write tools get a confirmation card through `SatelliteToolPlanService`, which shows page/language names and `agent.arg.*` labels):

| Extension | Tools |
|---|---|
| ns_t3ai | `t3ai_mass_seo_queue_remove`, `t3ai_mass_translation_queue_remove`, `…_requeue`, `…_clear_completed` (`McpQueueManagementService`, catalog ids bulkSeo/bulkTranslation) |
| ns_t3ai | `t3ai_translate_page` — page + all content, several languages, always native generation (`McpPageTranslationService`) |
| ns_t3ai | `t3ai_glossary_list` / `_save` / `_delete` (`McpGlossaryService`, catalog id translationGlossary) |
| ns_t3ai | `t3ai_generate_image` — one image into the fileadmin, T3AI Media permission; result card shows the image (`previewImageUrl`) |
| ns_t3aa | `t3aa_alt_text_queue_folder`, `t3aa_alt_text_drafts_list`, `t3aa_alt_text_approve`, `t3aa_mark_image_decorative` (`McpAltTextService`) |
| ns_t3aa | `t3aa_accessibility_scan_run`, `t3aa_accessibility_issues` (`McpAccessibilityService`) |
| ns_t3aa | `t3aa_generate_voice_over` — text or page text to MP3, T3AA Media permission; result card plays it (`McpVoiceOverService`) |


- **MCP tools** — tag `mcp.tool`; optional `McpToolIntent` for agent retrieval (`ns_t3ai`, `ns_t3aa`, …).
- **Entitlements** — `EntitlementResolver` locks tools when owner extension inactive.
- **Satellite write tools** — implement planning in child or use `write_table` / confirmation flow per classification doc.

---

## Do / Don't

**Do:** Keep structural router before `AgentRunner` on stream and non-stream paths.

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
