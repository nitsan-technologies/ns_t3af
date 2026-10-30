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
- **Conversations** — one row per conversation in `tx_nst3af_agent_conversation` (session uuid, home module + page, title, locked provider). `AgentConversationSession` opens the requested or latest one for `agentConversationScope`; conversation list, rename and delete in the window. See `Documentation/Agent/Conversations.md`. A reload does not cancel a running turn. Stream writes stop once the browser is gone, the answer is stored, and reopening shows a spinner until that answer appears. If the turn has ended with no answer, the panel says the connection was interrupted. A second message is still refused while the first turn holds the lock.
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

Resolved server-side in `AgentContextResolver` (permission checks) and presented by `AgentContextPresenter`: chips for the window and `details` (readable module, page with slug and parent, language with the site's languages, the page's translations with their page uid and hidden state, record label, workspace, folder). `AgentContextPresenter::promptBlock()` puts them into the system prompt as "Current context". The block tells the model to read it before deciding what a name in the request refers to: this page, a site language, a translation of this page, or another page. Translations are listed by language and uid, not by their translated title.

| Module | `pageId` | Extra |
|---|---|---|
| Page / List | current page | `languageId` from iframe `languages[n]` when one non-default column selected |
| File (`media_management`) | **0** (never stale web-tree page) | `storageUid`, `folderIdentifier` from iframe `id=1:/path/` |
| Other | client `pageId` when readable | record attachment, workspace |

`AgentToolTurnProcessor::mergeContextArguments()` injects `pageId`/`pid`/`uid`, `storageUid`, `workspaceId`, `targetLanguageUid`.

`site_languages_list` without a page (or for a page that belongs to no site) returns the languages grouped per site (`sites[]` with `siteIdentifier`, `rootPageId`, `languages`), because one language id can mean different languages on different sites.

---

## Editor-facing tool answers

| Piece | Path |
|---|---|
| Human tool title | `AgentToolEditorLabelService` → catalog `editorLabel`, turn meta `toolCallLabel` |
| Result prose | `AgentToolResultPresenter` — list leads, action success, file metadata facts. A tool's own `summary` is shown before the "Found N items" list lead |
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
| Suggestions apply | `AgentWriteService::applySuggestions` (context mode content params). A tool `{"error": ...}` (for example an editor without SEO field rights) is a failed apply, not "Applied". Undo writes the preview field `column` (pages `seo_title`, not the suggestion key `metaTitle`). |
| Plan + draft card | `AgentToolPlanResolver`, `AgentDraftService`, `SatelliteToolPlanService` |
| Apply (non-preview) | `AgentWriteService::apply` → DataHandler or tool confirmation invoke |
| Tool confirmation kind | `PLAN_KIND_TOOL_CONFIRMATION` — run-after-confirm for non-DataHandler tools |
| Classification | `Documentation/Agent/NonDataHandlerToolClassification.md` |

Draft cards carry `editorLabel` for UI; destructive = two-step confirm.

A second identical `action=create` card is dropped while the first is still pending (`AgentRunner::withoutRepeatedDeclinedDrafts()`). Execute all skips that duplicate as well. An already applied create can be requested again. A declined card of any action is still not offered again.

`/file_rename` and `/directory_rename` (alias `/folder_rename`) ask for the file or folder and the new name when either is missing, when the new name contains a slash, or when the target does not exist. No preview card is built in those cases. A complete rename shows the real file path (and file uid) or the folder name, with the field label "Rename". Apply stays disabled when a kept field has an empty proposed value.

A create, move, or copy under a page id greater than 0 is refused when that page is missing or deleted, including for an admin (`RecordService::assertParentPageExists()`, used again when the draft is applied). A deleted page is not kept as the current page.

A new `tt_content` row gets `colPos` 0 when the plan omitted it (`DataHandlerService::withContentColumn()`), so content_defender can read the column. An explicit colPos is kept. A create `beforeUid` places the new record directly before that page and is used instead of a parent pid. A create `afterUid` places the new record directly after that page and is used instead of a positive pid of that page. When the editor asked to create a page before or after a named page, that page is used even if the plan sent the parent pid or the wrong sign (`PageCreateBefore`, `PageCreateAfter`). The page name stops before "named", "called", or "titled". "Last under" or "inside … at the end" uses `afterUid` of that page's last child. A positive create `pid` is the first child of that page. A negative `pid` (`-uid`) places the new record directly after that record, including on apply (`DataHandlerService::applyPlanCreate()`). The preview card and the success sentence show that place (`AgentCreatePlacement`). "After Page 1" is `afterUid` of Page 1, "before Page 2" is `beforeUid` of Page 2, "last under Home" is `afterUid` of Home's last subpage, and "subpage of Page 1" stays a positive pid.

Moving an existing page uses `pages_move` (`beforeUid` places it directly before that page, `afterUid` places it directly after that page, `targetPid` makes it the first child). The previous sibling is a default-language page, and the page being moved is not used as its own anchor. Execute checks that sibling again. A request that already names both pages offers `pages_move` so the turn does not keep reading the tree. A hidden page can be moved, and `pages_search` still returns it when a name search was limited to the current page or to visible pages. `content_move` is only for a content element; a page uid sent there is planned as `pages_move` instead. Renaming a page, or changing its title, is a `write_table` update of `pages.title`. `pages_move` is refused for that request unless the editor also asked to move the page.

A request that names an existing page title ("the Contact page") uses that page for the turn. A name that matches no page title is no longer answered by the router with a "which page?" question. The model decides from the current context whether the name is this page, a site language ("the Hindi page" is the Hindi translation of the open page), a translation, or another page. It searches with `pages_search` (name only, no pid; a hidden match is a real page) only when the context does not explain the name, and asks when nothing matches. It must not fall back to the open page for a page the editor named. A page the editor picked from an earlier question ("the first one", a clicked title, old `missingPage` questions) still locks that page for the next turn (`lockedPageId`), including for SEO. Creating a page is unchanged.

A model reply that leaks a tool call (`to=tool_name`, including the JSON in front of it) is stripped before the editor sees it (`AgentPromptBuilder::stripLeakedToolCall`). When that leak is about a page uid that does not exist, the reply is `agent.page.missingUid` ("Page 99999 doesn't exist."). A normal reply is unchanged.

Removing a named page from the SEO queue uses that page's uid as `pageIds` on `t3ai_mass_seo_queue_remove`. "Remove page 138" and `/t3ai_mass_seo_queue_remove 138` use 138, including when another page is open. A title with no number, such as "the Contact page", is still looked up by name. The queue list uses the same uid. A page that is still queued gets an Apply card. When none of the named pages are in the queue, the reply says so and no card is prepared. "Take this page out of that list" uses the open page as `pageIds` when the call omitted the list. A reply that only describes a prepared change (`[Prepare change` or `[Prepared change`) is treated as a history note and retried as a real tool call. Adding several named pages uses `pageIds` on `t3ai_mass_seo_queue_add` in one call, so the open page is not substituted for that list. SEO for one language version passes that language's id as `sysLanguageUid`.

Saving a glossary word when the editor names the source, the translation, and the language calls `t3ai_glossary_save` at once. "We say Layoutansicht not Layout" and "Add Layout -> Layoutansicht to the glossary" are read as the source word and the translation before a card is prepared, including when the model does not call the tool. When that pair is already in the word list, the reply says so and no card is opened. The open page is `pageId` and the named site language id is `languageUid`. The reply does not ask which page, does not ask for a language that was named, and does not start with “Done”. When the language was not named and the site has more than one language besides the default, `GlossarySaveLanguage` withholds the save card and asks with those languages as options, unless that pair is already stored. A guessed language id, including German, is not used. A request that names one of those languages uses that language. One other site language is still used without asking. A new pair such as Pagetree → Seitenbaum still gets the save card.

Asking when queued SEO or translation texts will be written follows `QueueAutomationStatus`: an enabled, not-deleted scheduler task (`tx_scheduler_task.tasktype`) counts as automatic processing on. A disabled task counts as off, and the reply says an admin has to enable that scheduler task, and offers to write the texts now. It does not say a scheduled job will run. Showing the SEO or translation queue calls the list tool again, because a scheduler run can empty the queue between questions.

“Make this page look better in Google” or “write SEO texts for this page” calls `t3ai_generate_all_seo` and prepares suggestions. Nothing is written until Apply. Apply skips a suggestion that matches the text already stored, including a custom text that the SEO save would store as that same text. When every chosen text matches, the card stays and the reply says nothing was changed. A text that differs is still saved, and the follow-up after that save stays. That request does not add the page to the SEO queue. The queue is only for an explicit add, list, or “list for Google” request.

Asking to put the old text back, to undo, or to revert restores the latest applied change that still has Undo. That uses the same restore as the Undo button and does not generate new SEO texts. When nothing in the conversation was applied, the reply says nothing was changed. When the latest applied change is a search setting, a chatbot setting, or a data source, the reply says that change can't be undone automatically. When an earlier change was already undone and nothing newer remains, the reply points at the Undo button. A request for better or new Google texts still generates suggestions.

Writing SEO texts for the subpages of the open page uses `t3ai_generate_seo_batch`. An empty `entries` list uses the subpages of the open page, including pages that do not have Mass SEO enabled and including hidden pages. A list that contains only the open page is dropped for that request, so the card is not filled with the open page. A page that still has no site is named and skipped, and the other subpages still get a card. The SEO queue is for background generation. A recursive add only includes pages with Mass SEO enabled, and the reply names any page that was skipped. When that add queues nothing, the Apply reply is that skip sentence and no follow-up turn says “Done.” The card does not show `pid`.

---

## NL tool selection

- Child tools declare `#[McpToolIntent(modules, summary, examples (EN + DE), category)]`; core tools get `searchTerms` (EN + DE) in `Configuration/McpToolMetadata.yaml`.
- `AgentCoreToolSet` picks the start set; `find_tools` (`AgentToolSearch`) ranks the rest via embeddings + BM25. See `Documentation/Agent/Routing.md`.
- Starter chips are context requests in the editor's language (`AgentStarterBuilder::choose`: page → SEO / translate / add content / accessibility; open record → improve / translate; file → alt text / missing alt text / generate image; draft workspace → changes). A click sends the text as a normal message; a chip only shows when a permitted tool can do it.
- Image previews: `AgentMediaPreviewService` adds `meta.previews` (processed thumbnails, read permission checked) to tool results, suggestion cards and drafts about a file; `agent.js` renders them as a gallery. Files of a `fileUids` list (attach cards) that are missing or no image show their name only.

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
| ns_t3af | `file_upload_from_url` — downloads a public URL into FAL. A hostname is pinned with `CURLOPT_RESOLVE` and is not streamed, because the stream handler rejects curl options. A failed download names the host. No card for a placeholder host such as example.com. |
| ns_t3af | `file_reference_add` — no card for a file name instead of a uid, an unknown or unreadable file, a file missing on disk, or a field the record type does not show (`assets` on Image). The card lists file names. sys_file uids named in the request are checked up front (`AgentRequestedFiles`): missing ones are reported in a `files_unavailable` message and the attach step ends as `failed`. |
| ns_t3aa | `t3aa_alt_text_queue_folder` (creates the drafts), `t3aa_alt_text_drafts_list` (read only; capped, with paging; an empty list says nothing was generated yet and points to the queue tool), `t3aa_alt_text_approve` (`all=true` for every pending draft; refused with a pointer to `t3aa_update_file_metadata` when a named file has no draft), `t3aa_mark_image_decorative` (`McpAltTextService`) |
| ns_t3aa | `t3aa_accessibility_scan_run` (write; only when the editor asks to run/start/check a scan), `t3aa_accessibility_issues`, `t3aa_accessibility_fix_suggestions` (`McpAccessibilityService`; one item per rule with summed occurrences, plain-text fix, ready `summary` with score and issue counts). "What issues" / "suggest fixes" must not open a scan confirmation card |
| ns_t3aa | `t3aa_get_file_metadata`, `t3aa_update_file_metadata` — see "Image texts" below |
| ns_t3aa | `t3aa_generate_voice_over` — text or page text to MP3, T3AA Media permission. For a page it stores the audio on the page and places the Voiceover content element (`nst3aa_voiceover`) in that language; it never creates Text & Media (`McpVoiceOverService`) |
| ns_t3aa | `t3aa_get_page_speed` — `pageId` of this site (frontend URL from the site) or a URL the editor wrote; `T3afToolbox` drops a URL whose host the editor never wrote |


### Image texts (alt text, title, description)

- A file named in the request (`AgentToolTurnProcessor::requestedFileName`) is the target; `fileUrl` may be just the name and is found in any folder. Several named files: one `t3aa_update_file_metadata` call per file (`nextRequestedFileName`). **Keep current texts** continues with the next named file in the same turn. File-text writes are never treated as a file to attach. Missing-alt / decorative / named-file alt-text requests do not open a content-element checklist. Only files the editor may read count (`RecordsApplyFileAccess`); files outside their file mounts are neither used nor named.
- `t3aa_get_file_metadata` returns `public_url` (FAL path + `TYPO3_SITE_URL`). Empty when there is no real origin — never a placeholder host. The agent must quote that field, not invent a URL. `t3aa_get_file_for_metadata` stays hidden; if it is called, the turn points at `t3aa_get_file_metadata`.
- Several readable files with that name: the turn asks which one (`agent.fileMeta.whichFile`, meta `fileChoice`, `fileChoiceTool`). The click (`… [uid]`) goes back to that tool.
- Texts already stored: before generating, the turn shows them and asks (`agent.fileMeta.overwriteQuestion`, meta `fileMetaOverwrite {fileUid, languageUid, choices}`, `askedFor`). **Regenerate all** builds suggestions; **Fill empty fields only** passes `onlyFields`; **Keep current texts** leaves this file unchanged and, when more named or listed files remain, prepares the next one in the same turn.
- A click on one of those options is answered by `AgentTurnRouter` without the model (`AgentToolTurnProcessor::imageTextAnswer()`). `T3afToolbox::answeredImageTextQuestion()` does the same when the runner gets the click.
- Language: the model passes `isoCode` (`de`, `hi`). `languageUid` is `#[McpAgentHidden]` on the tool parameter, and `T3afToolbox` drops a `languageUid` the model sends anyway. ns_t3aa `MetadataLanguage` maps the code to the site language id.
- Text the editor wrote for alt text, title or description (`#[McpContentParam(editorText: true)]`) is shown as it is; text the model made up is dropped and generated from the image instead.
- A follow-up within 3 requests ("now the same in Hindi") keeps the image-text tool offered (`AgentRunner::usedRecently()` + `isImageTextFollowUpQuery()`); a new missing-alt / decorative / queue-folder request is not a follow-up of the previous image. `t3ai_generate_image` is refused on a true follow-up unless the editor asked for a new picture.
- `t3aa_mark_image_decorative` accepts `fileUrl` (a file name). `t3aa_list_files_missing_alt_text` omits decorative images; "pick the first N and generate" continues with listed `fileUid`s, one card at a time. "Do not approve yet" hides and refuses `t3aa_alt_text_approve`.
- After Apply, the result links point at the `sys_file_metadata` row (`AgentWriteService` `resultRecords`), and Undo resets that row.

### Runner safety nets

- `t3aa_accessibility_scan_run` is offered only for an explicit run/start scan (`AgentRunner::isAccessibilityScanRequest()`). "Check issues" is a read (`t3aa_accessibility_issues`), not a confirmation card. Combined "check issues and suggest fixes and page speed" offers the three read tools and hides the scan. "Suggest fixes" alone still hides the issues list so the model cannot invent the solutions. `T3afToolbox` refuses a scan detour on those requests.
- A sys_file uid or a named page uid is never a tt_content uid. "Delete all images that have no alt text" lists files (`t3aa_list_files_missing_alt_text`) and hides/refuses `content_delete`, `file_delete` and `directory_delete` (named-file delete still uses `file_delete`). After that list the turn stops so the model cannot plan a mass delete. "Summarize page 234" must pass `pageId`, not `contentUid`; the toolbox pins `pageId` and drops `contentUid`. After `t3aa_get_file_metadata`, "change it to …" keeps `t3aa_update_file_metadata` offered and refuses `write_table` on `sys_file`.
- A simplified / plain-language version in a named language is `t3aa_summarize_content` (pageId of this page, `language` German). Translate tools are hidden and refused. Native summarize passes that language into the simplify prompt instead of defaulting to "the source".
- `agent.turn.stepsStillOpen` ("Say continue") is only for work that already started (an image still to attach). A never-started text-element create uses `agent.turn.emptyModelReply` instead.
- A turn that ends on a failed tool with no reply gets `agent.turn.toolFailedNoReply`.
- A reply that claims a missing permission while no tool ran and nothing was refused (`AgentRunner::claimsPermissionRefusal()`) is retried once with a nudge to call the offered tool. English wording only; one extra model call.
- `write_table` is refused when the record is headed "Voice-over", or when the request is about a voice-over and the write attaches a file (`assets`, `media`, `image`), as long as `t3aa_generate_voice_over` is offered.
- A uid-keyed `write_table` update (`{"275": {"bodytext": "…"}}`) checks every record before writing any (`RecordPayloadNormalizer::uidKeyedFieldMaps()`).

- **MCP tools** — tag `mcp.tool`; optional `McpToolIntent` for agent retrieval (`ns_t3ai`, `ns_t3aa`, …).
- **Hidden parameters** — `#[McpAgentHidden]` on an `execute()` parameter hides it from the agent schema (`AgentToolDefinitionMapper`); the tool or runtime fills it. `#[McpContentParam(editorText: true)]` exposes a content parameter for text the editor wrote only. Child extensions using these need the ns_t3af release that contains them; older 2.0.x ignores them (parameter visible, editor text hidden).
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
- Write tools can implement `McpArgumentCheckInterface::checkArguments()`: `AgentToolPlanResolver` calls it before a card is built; an `\InvalidArgumentException` goes back to the model (no card), e.g. unknown setting names in `t3ac_chatbot_settings` / `t3as_search_settings`. A `t3as_search_settings` value the search form would not save (colour, limit, position, offset, domain, or switch) is refused the same way, and nothing is written. The prompt tells the model those limits.
- After a failed `t3cs_save_datasource`, "activate it" or "sync that one" is answered before the model runs: the source was not created, and no card is drafted. That must not become the Mass SEO scheduler. "All my data sources" on a sync is scope `all`. After `t3cs_list_datasources`, "the second one" is that row, not a translation of the open page. "No" or "nein" cancels an open card.
- A text data source is saved only from text the editor wrote. "Create a text source for beauty tips" with no text asks what to add and drafts no card. Quoted text is stored as given. After Apply, the history the next turn reads names that data source and its text, so the reply does not say nothing was saved.
- A data-source update and a chatbot-settings update store the current field values on the card. Apply is refused when one of those fields changed after the card was drafted, so an older rename cannot overwrite a newer one. A page or content card already has this check. SEO, schema, and file tools are not part of it.
- Links under the chatbot's answers are `enable_sources_links` and `max_source_links`. Answering in the visitor's language is `answer_in_visitor_language`. A rule for the website bot is `instructions`. Those call `t3ac_chatbot_settings`. "Make it 2" after a source-link request keeps that count. "Is that active now?" is answered no when that setting was not saved, and it is not a data-source sync. The reply does not invent a menu path.
- A search colour request keeps only the colour that was named. A main colour is `primaryColor`. Apply does not also save the switch, the instructions, another colour, the appearance, or the widget mode unless the editor asked for that.
- "Refresh", "learn again", "neu lernen", or "in the bot" syncs that data source. It does not search news. "Refresh everything" is scope all. After that card is cancelled, "just A and B" syncs only those sources, one at a time. "Fix the failed one" retries that source's failed training with `t3cs_reset_failed_queue_item`. "Rename the X source" updates that source's name with `t3cs_save_datasource`. A page rename stays a page title. A folder rename stays a file change.
- Setting the description of a named data source updates that exact name. The description is the text the editor gave. Another source's id is not used. If no source has that name, nothing is written. "The search should not use this source, only the chatbot" sets that source's usage to chatbot. "Neither should use it" deactivates that same source. "Turn on AI search" and "Turn off AI search" stay the global AI Search switch.
- A welcome or Begrüßung is an update of `first_message` with the quoted text. A chatbot get is a read and is not an Apply card. "Rename the chatbot" is a chatbot-settings card with the new title. A card with no fields is not shown. After Apply, the previous title or greeting stays in the history, and "change it back" writes that value. If there is no previous value, the agent asks and does not say it was restored.
- MCP cannot create or change `be_users`, `be_groups`, or `be_sessions`, including for an admin. `explain_capabilities` still answers when one extension's status check fails. A redirect is refused when the target is not a t3 link, a site path, or http(s), when the status is not 301/302/303/307/308, or when that host and path already exist.
- A scheduler description update keeps the task's stored start date. After Apply of a data source, the agent does not create page content. AI provider and API key settings cannot be changed from the AI Agent; the reply points at the Providers page, does not repeat the key, and stored conversation messages mask keys.
