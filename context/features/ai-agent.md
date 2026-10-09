# Feature — AI Agent (backend right-edge panel)

**Status:** Done (structural + NL routing on Symfony AI Agent, core tool set + find_tools, DualMode preview→apply, BE i18n)  
**UI:** Global toolbar **Ask AI Agent** (`AgentToolbarItem`), right-edge sliding panel JS `@nitsan/nst3af/agent.js` (persistent sessions rail, remembered last conversation via Persistent)  
**Settings route:** `t3af_dashboard.ai_agent`  
**Verify:** `Documentation/Agent/VerifySuite.md`, `Tests/Unit/Agent/` · routing docs: `Documentation/Agent/Routing.md`, `PreviewApply.md`, `Eval.md`

---

## What it does

- **Global backend assistant** — orange launch bar + **Ctrl/Cmd+Shift+K** (optional Ctrl/Cmd+K via `localStorage`; see `Documentation/Agent/HotkeyConflictMatrix.md`).
- **Turn inputs:** natural language, `/tool_name` slash commands, `@table:uid` record attachments.
- **Tool catalog** — executable vs locked tools from `PermittedActionProvider` (entitlements, severity, plan support).
- **Read tools** — auto-run; results shown as **editor-facing prose** (not raw snake_case ids).
- **Write / destructive tools** — DualMode + Previewable: native preview → suggestions card → context apply (`AgentWriteService::applySuggestions`). Other writes: elicitation draft → DataHandler / confirm invoke.
- **NL turns** — `AgentRunner`: Symfony AI `Agent` loop; `GovernedPlatform` sends every round through `AiToolCallingServiceInterface` (governance, logs). In T3Planet Credits mode that path uses `/API/AI/v1/chat/completions` via `symfony/ai-generic-platform` (`T3PlanetCreditsChatExecutor`); own-key sites keep provider adapters. `T3afToolbox` runs tools with argument validation, budgets and pauses.
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
| Links after a change | `applyDraftAction` returns `links` (Open page / Edit / View on website). Backend links close the panel, select the page in the tree (`selectWebPage()` → `ModuleStateStorage.update('web', pageId)`), then `ContentContainer.setUrl(href, …, module)` (`web_layout` or `record_edit`, from `AgentRecordLabeler`). View stays a new tab. After Undo the links are not rendered (`meta.undone`). The backdrop dims without `backdrop-filter`. |
| Credits | `AgentCreditsStatus` → header badge (ok/low/critical); at zero the composer is locked with a top-up hint. NL tool-calling uses Credits **v1 chat** (per completions call). Tool-result LLM summaries are skipped in credits mode (deterministic prose only). |

In the agent loop read tools get no extra LLM summary (`present(..., allowLlmSummary: false)`); the model reads the deterministic summary plus the data (JSON, 6000 characters).

Reasoning models (0.13 `MultiPartResult`: thinking + text / tool calls) are read by `SymfonyAiResultReader`; before this, a plain "Hi" returned "I could not produce a reply".

---

## Write path

| Piece | Path |
|---|---|
| Preview DualMode | `McpPlaygroundService::preview` + `McpModeOverride` → suggestions meta |
| Suggestions apply | `AgentWriteService::applySuggestions` (context mode content params). A tool `{"error": ...}` (for example an editor without SEO field rights) is a failed apply, not "Applied". |
| Plan + draft card | `AgentToolPlanResolver`, `AgentDraftService`, `SatelliteToolPlanService` |
| Apply (non-preview) | `AgentWriteService::apply` → DataHandler or tool confirmation invoke |
| Tool confirmation kind | `PLAN_KIND_TOOL_CONFIRMATION` — run-after-confirm for non-DataHandler tools |
| Classification | `Documentation/Agent/NonDataHandlerToolClassification.md` |

Draft cards carry `editorLabel` for UI; destructive = two-step confirm.

A second identical `action=create` card is dropped while the first is still pending (`AgentRunner::withoutRepeatedDeclinedDrafts()`). Execute all skips that duplicate as well. An already applied create can be requested again. A declined card of any action is still not offered again.

`/file_rename` and `/directory_rename` (alias `/folder_rename`) ask for the file or folder and the new name when either is missing, when the new name contains a slash, or when the target does not exist. No preview card is built in those cases. A complete rename shows the real file path (and file uid) or the folder name, with the field label "Rename". Apply stays disabled when a kept field has an empty proposed value.

A create, move, or copy under a page id greater than 0 is refused when that page is missing or deleted, including for an admin (`RecordService::assertParentPageExists()`, used again when the draft is applied). A deleted page is not kept as the current page.

A new `tt_content` row gets `colPos` 0 when the plan omitted it (`DataHandlerService::withContentColumn()`), so content_defender can read the column. An explicit colPos is kept. A create `beforeUid` places the new record directly before that page and is used instead of a parent pid. A create `afterUid` places the new record directly after that page and is used instead of a positive pid of that page. When the editor asked to create a page before or after a named page, that page is used even if the plan sent the parent pid or the wrong sign (`PageCreateBefore`, `PageCreateAfter`). The page name stops before "named", "called", or "titled". "Last under" or "inside … at the end" uses `afterUid` of that page's last child. A positive create `pid` is the first child of that page. A negative `pid` (`-uid`) places the new record directly after that record, including on apply (`DataHandlerService::applyPlanCreate()`). The preview card and the success sentence show that place (`AgentCreatePlacement`). "After Page 1" is `afterUid` of Page 1, "before Page 2" is `beforeUid` of Page 2, "last under Home" is `afterUid` of Home's last subpage, and "subpage of Page 1" stays a positive pid.

Moving an existing page uses `pages_move` (`beforeUid` places it directly before that page, `afterUid` places it directly after that page, `targetPid` makes it the first child). The previous sibling is a default-language page, and the page being moved is not used as its own anchor. Execute checks that sibling again. A request that already names both pages offers `pages_move` so the turn does not keep reading the tree. A hidden page can be moved, and `pages_search` still returns it when a name search was limited to the current page or to visible pages. `content_move` is only for a content element; a page uid sent there is planned as `pages_move` instead.

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
| Runner | `Classes/Agent/Service/AgentRunner.php`, `Classes/Agent/Runtime/{GovernedPlatform,T3afToolbox,AgentTurnState}.php`, credits chat: `Classes/Service/T3PlanetCreditsChatExecutor.php` + `Classes/Credits/Platform/T3PlanetCreditsPlatformFactory.php` |
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

- `agentMaxReadToolsPerTurn` (default 10)
- `agentMaxWriteDraftsPerTurn` (default 10)
- `agentShowProviderThinking`
- `agentConversationRetentionDays` (default 90; soft delete, removed 7 days later by `t3af:agent:conversations:cleanup`)
- `agentContinueAfterConfirm` (default on)
- `agentHistoryTokenBudget` (default 6000 tokens of replayed history)
- Workspace: no agent setting; it follows **MCP Server > Workspace selection** (`WorkspacePreferenceService`, `be_users.uc['nst3af_mcp_workspace']`, 0 = Live chosen, absent = never chosen = Live). An editor already in a workspace keeps it; from Live the selected workspace gets the changes, Live means Live (a group policy with `workspaceEnforcement` still blocks that). A selected workspace the editor may not use is never replaced: Apply is refused (`agent.workspace.noAccess`). `AgentWorkspaceTarget`; the switch is per request (`setTemporaryWorkspace`), never the editor's backend workspace.
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
| ns_t3ai | `t3ai_generate_image` — one image into the fileadmin, T3AI Media permission; result card shows the image (`previewImageUrl`). Saving the provider URL stays in ns_t3ai `FileService`. |
| ns_t3af | `file_upload_from_url` — downloads a public URL into FAL. A hostname is pinned with `CURLOPT_RESOLVE` and is not streamed, because the stream handler rejects curl options. A failed download names the host. |
| ns_t3af | `file_reference_add` — no card for a file name instead of a uid, an unknown or unreadable file, a file missing on disk, or a field the record type does not show (`assets` on Image). The card lists file names. |
| ns_t3aa | `t3aa_alt_text_queue_folder`, `t3aa_alt_text_drafts_list`, `t3aa_alt_text_approve`, `t3aa_mark_image_decorative` (`McpAltTextService`) |
| ns_t3aa | `t3aa_accessibility_scan_run`, `t3aa_accessibility_issues` (`McpAccessibilityService`) |
| ns_t3aa | `t3aa_generate_voice_over` — text or page text to MP3, T3AA Media permission; result card plays it (`McpVoiceOverService`) |


- **MCP tools** — tag `mcp.tool`; optional `McpToolIntent` for agent retrieval (`ns_t3ai`, `ns_t3aa`, …).
- **Entitlements** — `EntitlementResolver` locks tools when owner extension inactive.
- **Satellite write tools** — implement planning in child or use `write_table` / confirmation flow per classification doc.

Permissions and refusals (2.0.0): the table is checked before the page; a field the editor may not change (`exclude` fields need the `non_exclude_fields` grant, structural fields such as CType, colPos, hidden, sorting stay unchanged on a create the user may not make) is left out of the card and the card is dropped when nothing is left (`WriteTableTool`, `McpRecordPlanService`, `RecordAccessGate::canModifyField()/withoutForbiddenFields()`; plan context `notAllowedFields`). Refused, hidden, not-permitted, preview-failed and failed-apply requests are logged with success 0 (`AgentToolTurnProcessor::logRefusedPlan()`, `AgentAjaxController`). The "[The editor confirmed …]" follow-up is ignored by `AgentRequestChecklist`, and so is a header named as a field of the requested element ("with the header X", including the value that follows it, which may contain the word "header"). Once the request is for Text & Media or Text & Images, a later "short text", "alt text", "short sentence" or "attach the existing images" stays a field of that element. A second element is added when the editor asks for one ("a separate text element", "two elements").

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
- Write tools can implement `McpArgumentCheckInterface::checkArguments()`: `AgentToolPlanResolver` calls it before a card is built; an `\InvalidArgumentException` goes back to the model (no card), e.g. unknown setting names in `t3ac_chatbot_settings` / `t3as_search_settings`.
