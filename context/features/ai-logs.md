# Feature — AI Logs (centralized sys_log)

**Status:** Done (AI Foundation tab + child extension links + in-module filter navigation)  
**Route:** `t3af_dashboard.ai_logs` (`Configuration/Backend/Modules.php`)

---

## What it does

- Single **AI Logs** utility tab in AI Foundation for operational `sys_log` entries across all AI extensions (sync, training, providers, scheduler, MCP, prompts, …).
- Replaces legacy per-extension log screens (e.g. deprecated `T3AiLogController` → redirect to AI Logs).
- **Filters:** date range, log channel, AI extension, level, search, pagination, export CSV.
- **Child extensions** (`ns_t3ai`, `ns_t3aa`, `ns_t3cs`) expose an **AI Foundation Logs** toolbar button that deep-links here with page-tree `id` + extension pre-filter.

---

## Key paths

| Area | Path |
|---|---|
| Module action | `Classes/Controller/Backend/ModuleController.php` → `aiLogsAction()` |
| Read/filter/delete | `Classes/Domain/Repository/AiSysLogRepository.php` |
| Channel catalog | `Classes/Service/AiLogChannelCatalog.php` (merges `t3af.ai_log_channel` providers) |
| Summary KPIs | `Classes/Service/AiLogsStatisticsService.php` |
| Activity events (providers, prompts, wizard, …) | `Classes/Service/AiUniverseActivityLogService.php` |
| Low-level write API | `Classes/Service/AiLogService.php` |
| Templates / partials | `Resources/Private/Templates/Module/AiLogs.html`, `Resources/Private/Partials/AiLogs/` |
| Filter JS | `Resources/Public/JavaScript/ai-logs.js` |
| Period dropdown JS | `Resources/Public/JavaScript/period-filter.js` |
| In-iframe navigation | `Resources/Public/JavaScript/module-navigation.js` |
| Child deep link helper | `Classes/Utility/BackendModuleLinkUtility.php` |
| Shared child button | `Resources/Private/Partials/Backend/AiLogsModuleLink.html` |
| Labels | `Resources/Private/Language/locallang_mod.xlf` (`module.link.aiUniverseLogs*`) |
| Cleanup CLI | `Classes/Command/CleanAiLogsCommand.php` → `t3af:ai-logs:cleanup --days=90` |

---

## Writing logs (extensions)

Use **`AiLogService::writeLog($message, $level, $channel)`** or domain helpers like **`AiUniverseActivityLogService`** (providers, prompts, scheduler CLI, MCP config, wizard).

Channels are normalized via **`AiLogChannelCatalog`** (`normalizeWriteChannel`, `resolveChannelValuesForExtension`). Child extensions register **`AiLogChannelProviderInterface`** (tag `t3af.ai_log_channel`) with channel keys and optional write inference so **extension filter** in AI Logs works.

**Do:** Log meaningful admin events (save/delete provider, sync finished, training error).  
**Don't:** Duplicate a separate log UI in child extensions — link to AI Foundation instead.

---

## In-module navigation (TYPO3 v13+ iframe)

Module content runs inside the backend content iframe. **Native form GET/POST or `<a>` navigation** to module routes sends `Sec-Fetch-Dest: document` → `BackendModuleValidator` redirects to `/typo3/main?redirect=…` → **nested full backend** inside the iframe.

**Fix (standard for AI Logs + period filter):**

1. Intercept filter submit / list links in JS (`ai-logs.js`, `period-filter.js`).
2. Build URL via **`module-navigation.js`**:
   - Preserve route **`token`** and page-tree **`id`** from the form action URL.
   - Prefer **`fetch()` + replace `[data-aiu-logs-root]`** inside the iframe (no full document navigation).
   - Fallback: set `endpoint` on **`typo3-backend-module-router`** in `top.document`.
3. **Never** use `window.location.assign()` from inside the module iframe as primary navigation.

Register JS modules in `Configuration/JavaScriptModules.php`. AI Logs template loads `period-filter.js` + `ai-logs.js` via `f:be.pageRenderer`.

**Do not** use `window.top.TYPO3.Viewport` — global is **`top.TYPO3.Backend`**. Prefer `@typo3/backend/viewport.js` import or the module-router approach above.

---

## Child extension integration — AI Foundation Logs button

Same pattern as setup checklist: **markup lives in `ns_t3af` only**.

### Architecture

```
ns_t3af (master)
├── Classes/Utility/BackendModuleLinkUtility.php     ← buildAiLogsUri($pageId, $extensionKey)
├── Partials/Backend/AiLogsModuleLink.html           ← shared button
└── locallang_mod.xlf                                ← module.link.aiUniverseLogs*
```

| Layer | Owner | Child rule |
|-------|--------|------------|
| **Markup** | `AiLogsModuleLink.html` | Never copy into child extensions |
| **URL** | `BackendModuleLinkUtility::buildAiLogsUri()` | Pass current page `id` + own extension key |
| **Partial paths** | `SetupChecklistPresenter::configureViewPartials()` | Required before `f:render partial="Backend/AiLogsModuleLink"` |
| **Assign** | Child controller | `aiUniverseLogsUri` on ModuleTemplate view |

### Controller (when `ns_t3af` is loaded)

```php
use NITSAN\NsT3AF\Service\SetupChecklistPresenter;
use NITSAN\NsT3AF\Utility\BackendModuleLinkUtility;

$pageId = (int) ($request->getQueryParams()['id'] ?? 0);
$assign['aiUniverseLogsUri'] = BackendModuleLinkUtility::buildAiLogsUri($pageId, 'ns_t3cs');

// TYPO3 v12+ ModuleTemplate — register ns_t3af partial root:
GeneralUtility::makeInstance(SetupChecklistPresenter::class)->configureViewPartials($view);
```

**Recommended:** assign `aiUniverseLogsUri` in child `initializeModuleTemplate()` (see `ns_t3ai` / `ns_t3aa` `AbstractController`) so all submodule views get the link.

### Template (dashboard toolbar, right side)

```html
<f:render partial="Backend/AiLogsModuleLink" arguments="{aiUniverseLogsUri: aiUniverseLogsUri}" />
```

**Do not** pass `extensionName` on `f:render` — invalid on TYPO3 Fluid `RenderViewHelper` (v13+). Partial resolution relies on `configureViewPartials()`.

### Currently integrated

| Extension | Toolbar partial | Extension key in URI |
|-----------|-----------------|----------------------|
| **ns_t3cs** | `Partials/Dashboard/Tabs.html` | `ns_t3cs` |
| **ns_t3ai** | `Partials/Dashboard/Tabs.html`, `SubModuleTabs.html` | `ns_t3ai` |
| **ns_t3aa** | `Partials/Dashboard/Tabs.html`, `SubModuleTabs.html` | `ns_t3aa` |

Deep link example: `/typo3/module/t3af/dashboard/ai-logs?token=…&id=11&extension=ns_t3cs&period=7d`

---

## Routes (sub-actions)

| Route | Method | Purpose |
|---|---|---|
| `ai_logs` | GET (+ POST body merged in action) | List + filter |
| `ai_logs.delete` | POST | Delete selected / single entries |
| `ai_logs.export` | GET | CSV export |

---

## Do / Don't

**Do:**

- Use POST for delete actions only; use JS-navigated GET for filters/pagination inside the module iframe.
- Pass `id` in child deep links so page-tree selection is preserved.
- Pre-filter `extension` in child links so users see logs for that product only.

**Don't:**

- Add `extensionName` to `f:render` for cross-extension partials.
- Strip `token` from filter URLs when building query strings in JS.
- Reintroduce per-extension log list modules without redirecting to AI Logs.

---

## Verification

1. AI Foundation → **AI Logs** → apply extension/level filter → only content area updates (no double top bar).
2. Child extension (e.g. T3CS) → **AI Foundation Logs** → opens AI Logs with correct `id` + extension filter.
3. Save a provider → new row in AI Logs (providers channel, `ns_t3af` extension filter).
4. `vendor/bin/typo3 t3af:ai-logs:cleanup --days=90` runs without error.

---

## Related context

- Backend UI / shared child widgets: `context/Typo3CoreBackendDesign.md` § Shared UI in child extensions
- Child extension overview: `context/features/child-extensions.md`
- Backend module tabs: `context/features/backend-module.md`
