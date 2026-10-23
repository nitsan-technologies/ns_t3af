# AI Logs (central sys_log, AI Usage log, cleanup) — cases

**Prefix:** `T3AF-LOG`
**How it works:** `context/features/ai-logs.md`
**Main code:** `Classes/Controller/Backend/ModuleController.php` (`aiLogsAction`, `aiLogsExportAction`, `aiLogsDeleteAction`, `aiUsage*Action`), `Classes/Domain/Repository/AiSysLogRepository.php`, `Classes/Domain/Repository/RequestLogRepository.php`, `Classes/Domain/Repository/RequestLogProviderScope.php`, `Classes/Service/AiLogService.php`, `Classes/Service/AiLogChannelCatalog.php`, `Classes/Service/AiLogsStatisticsService.php`, `Classes/Service/AiUniverseActivityLogService.php`, `Classes/Utility/SysLogWriterUtility.php`, `Classes/Utility/ExportLimits.php`, `Classes/Utility/BackendModuleLinkUtility.php`, `Classes/Command/CleanAiLogsCommand.php`, `Resources/Private/Partials/AiLogs/*`, `Resources/Public/JavaScript/ai-logs.js`, `Resources/Public/JavaScript/module-navigation.js`
**Existing tests:** `Tests/Functional/Domain/Repository/AiSysLogRepositoryFunctionalTest.php`, `Tests/Functional/Domain/Repository/RequestLogRepositoryFunctionalTest.php`, `Tests/Unit/Domain/Repository/RequestLogRepositorySearchTest.php`, `Tests/Unit/Service/AiLogChannelCatalogTest.php`, `Tests/Unit/Service/SchedulerCliCommandCatalogServiceTest.php`, `Tests/Unit/Utility/ModuleTabUtilityTest.php`

---

## Not applicable

| Row | Reason |
|---|---|
| 11 AI output | AI Logs only stores and shows admin events. It never calls a provider and never shows generated text. |

---

## Positive

### T3AF-LOG-001 Channel and period filter list only matching rows
P1 · positive · smoke, release · auto
Rows: 1
Test: `Tests/Functional/Domain/Repository/AiSysLogRepositoryFunctionalTest.php::insertFilterCountAndExportRespectChannelAndPeriod`
Given "Provider saved" (providers, now), "MCP tool invoked" (MCP, now) and "Older provider event" (providers, 40 days ago)
When AI Logs is filtered to channel `nst3af.providers` for the last hour
Then only "Provider saved" is listed · summary shows total 1, info 1 · export has the same single row

### T3AF-LOG-002 Saving a provider shows up in AI Logs
P1 · positive · smoke, release · manual
Rows: 1, 13
Test: none
Given an admin on AI Foundation → Providers
When they save provider "OpenAI Test" and open AI Logs with extension filter `ns_t3af`
Then the newest row reads `AI provider updated: "OpenAI Test" (…, uid N).` (or `created`) · level info · user is the admin · no API key in the text

### T3AF-LOG-003 Child extension button opens AI Logs pre-filtered
P2 · positive · release · manual
Rows: 1
Test: none
Given T3AI installed and page uid 11 selected in the page tree
When the editor clicks **AI Foundation Logs** in the T3AI module toolbar
Then AI Logs opens with `id=11`, `extension=ns_t3ai`, period 30 days · only T3AI channels are listed · the page tree selection is unchanged

### T3AF-LOG-004 Filter and pagination stay inside the module frame
P2 · positive · release · manual
Rows: 1, 12
Test: none
Given more than 20 AI log rows
When the user changes level, search and page, on TYPO3 13.4 and on 14.3
Then only the AI Logs content area reloads · no second backend top bar appears inside the frame · the route `token` and `id` stay in the URL

### T3AF-LOG-005 CSV export downloads the filtered rows
P1 · positive · release · manual
Rows: 1
Test: none
Given a level filter `error` with 3 matching rows
When the user clicks **Export CSV**
Then a file `ai-logs-YYYYMMDD-HHMMSS.csv` downloads · header `Time,Level,User,Details` · exactly 3 data rows · quotes inside details are doubled

### T3AF-LOG-006 Delete selected rows
P2 · positive · release · manual
Rows: 1, 7
Test: none
Given three AI log rows in the list
When the admin ticks two and deletes them
Then a "bulk deleted" message shows · those two rows are gone from `sys_log` · the third row is still there

### T3AF-LOG-007 AI Usage and AI Logs sit in the utility tab group
P3 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Utility/ModuleTabUtilityTest.php::testBuildNavigationTabGroupsSeparatesUtilityTabs`
Given a user with all tabs while on AI Logs
When the navigation tabs are built
Then `aiUsage` and `aiLogs` are in the utility group, not the primary group · `aiLogs` is marked active

### T3AF-LOG-008 Request log list paginates and groups usage by extension
P2 · positive · release · auto
Rows: 1
Test: `Tests/Functional/Domain/Repository/RequestLogRepositoryFunctionalTest.php::findFilteredPaginationAndUsageByExtensionMatchFixtures`
Given request-log fixture rows
When page size is 2
Then the page has 2 rows · usage by extension shows 2 requests for `ns_t3af`

### T3AF-LOG-009 Cleanup command removes old AI rows
P1 · positive · smoke, release · manual
Rows: 1, 9
Test: none
Given AI log rows aged 10 days and 40 days
When `vendor/bin/typo3 t3af:ai-logs:cleanup --days=30` runs
Then it prints "Deleted 1 AI log entries older than 30 days (before …)." and exits 0 · the 10-day row is still listed

---

## Negative

### T3AF-LOG-010 Unsupported page size falls back to 20
P3 · negative · — · gap
Rows: 2
Test: none
Given the AI Logs URL with `max=7` (or `max=abc`)
When the list loads
Then 20 rows per page are shown · no error · no query with limit 7

### T3AF-LOG-011 Page number past the end shows the last page
P3 · negative · — · gap
Rows: 2
Test: none
Given 45 rows and page size 20
When the URL has `currentPage=99`
Then page 3 is shown with 5 rows · no empty page and no error

### T3AF-LOG-012 Unknown channel or extension filter shows only AI channels
P2 · negative · release · gap
Rows: 2, 7
Test: none
Given the URL `logChannel=core` and `extension=not_an_ext`
When the list loads
Then both filters fall back to "all" · only AI channels are listed · core rows such as backend logins are not shown

### T3AF-LOG-013 Delete with nothing selected
P3 · negative · — · manual
Rows: 2
Test: none
Given the AI Logs list
When the user submits delete with no row ticked
Then a "none selected" message shows · no row is removed from `sys_log`

### T3AF-LOG-014 Editor without the AI Logs tab cannot read logs
P1 · negative · release · manual
Rows: 3
Test: none
Given an editor whose group lacks `nst3af_tab:ai_logs`
When they open the AI Logs URL directly
Then an access message is shown instead of the list · no log rows, summary or export button appear · the tab is not in the navigation

### T3AF-LOG-015 AI Usage delete and export need modify rights on the usage log
P1 · negative · release · manual
Rows: 3
Test: none
Given an editor with the AI Usage tab but no modify right for `tx_nst3af_request_log` (catalog `usageRequestLog`)
When they post delete, bulk delete or open export
Then they are sent back to AI Usage with an "access denied" message · no request-log row gets `deleted = 1` · no file downloads

### T3AF-LOG-016 Child extension not installed
P3 · negative · — · manual
Rows: 4
Test: none
Given `ns_t3cs` is not installed
When AI Logs is opened with `extension=ns_t3cs`
Then the filter shows "all" · `ns_t3cs` is not in the extension dropdown · no error

### T3AF-LOG-017 Cleanup with zero or text days never wipes all logs
P2 · negative · release · gap
Rows: 2, 9
Test: none
Given AI log rows aged 1 day and 100 days
When `t3af:ai-logs:cleanup --days=0` (or `--days=abc`) runs
Then the 1-day row is kept · the output names the retention actually used (90 days today) · exit code 0

### T3AF-LOG-018 A failed provider call is logged as an error
P1 · negative · release · manual
Rows: 5, 13
Test: none
Given T3AI with a provider whose API key is wrong
When the editor runs an AI action and it fails
Then AI Logs has a new row with level error starting with "Error " · the `sys_log` row has `error = 1` · the error level filter finds it · the editor's content is not changed

---

## Edge

### T3AF-LOG-019 Search treats % and _ as plain text
P3 · edge · — · gap
Rows: 2
Test: none
Given rows "rate 100%" and "rate 1000"
When the user searches `100%`
Then only "rate 100%" is listed

### T3AF-LOG-020 Percent signs show once in the list and the CSV
P3 · edge · — · manual
Rows: 2, 13
Test: none
Given a log message "Quota at 100%"
When the row is shown in AI Logs and exported
Then both show "Quota at 100%" · not "100%%"

### T3AF-LOG-021 Built-in channel names fit the sys_log column
P3 · edge · — · auto
Rows: 2
Test: `Tests/Unit/Service/AiLogChannelCatalogTest.php::canonicalChannelsFitSysLogColumn`
Given all channel names from `AiLogChannelCatalog`
When their length is checked
Then each is at most 20 characters, so none is cut when written

### T3AF-LOG-022 Legacy MCP config channel maps to the current one
P3 · edge · — · auto
Rows: 2, 12
Test: `Tests/Unit/Service/AiLogChannelCatalogTest.php::legacyMcpConfigChannelMapsToCurrentValue`
Given the old filter value `nst3af.mcp_config`
When it is normalized
Then it becomes `nst3af.mcp_cfg`

### T3AF-LOG-023 Old cleanup command names still resolve
P3 · edge · — · auto
Rows: 9, 12
Test: `Tests/Unit/Service/SchedulerCliCommandCatalogServiceTest.php::testFindByCommandResolvesDeprecatedLogClearAlias`
Given a scheduler task still using `t3af:log:clear`
When the scheduler CLI catalog looks it up
Then it resolves to `t3af:ai-logs:cleanup` owned by `ns_t3af`

### T3AF-LOG-024 Running cleanup twice deletes nothing the second time
P3 · edge · — · manual
Rows: 9
Test: none
Given cleanup with `--days=30` just ran
When it runs again with the same option
Then it prints "Deleted 0 AI log entries …" and exits 0

### T3AF-LOG-025 Request-log export stops at the export limit
P2 · edge · release · auto
Rows: 7
Test: `Tests/Functional/Domain/Repository/RequestLogRepositoryFunctionalTest.php::findForExportIsCappedAtExportLimit`
Given `ExportLimits::MAX_ROWS + 5` request-log rows
When they are exported
Then exactly `ExportLimits::MAX_ROWS` (5000) rows are returned

### T3AF-LOG-026 AI Logs CSV stops at the export limit
P2 · edge · — · gap
Rows: 7
Test: none
Given 5,100 AI log rows in the period
When the CSV is exported
Then the file has 5,000 data rows · the request does not time out

### T3AF-LOG-027 Usage export columns follow credits mode
P2 · edge · release · manual
Rows: 6
Test: none
Given request-log rows in the period
When AI Usage is exported as CSV with credits mode on, then off
Then credits mode has columns ending in `Credits` · own-key mode has `Tokens` and `Cost` · no row is lost or duplicated

### T3AF-LOG-028 German backend and page context
P3 · edge · — · manual
Rows: 8
Test: none
Given a backend user with language German and page uid 11 selected
When they open AI Logs
Then labels are German · rows from all pages and sites are listed (the page `id` only keeps the tree selection) · timestamps are readable

### T3AF-LOG-029 Switch-user actions keep the real admin
P2 · edge · — · manual
Rows: 3, 13
Test: none
Given an admin switched to editor "anna"
When anna saves a provider
Then the log row user is anna · its data holds `originalUser` with the admin uid

### T3AF-LOG-030 Scheduler CLI run is logged
P3 · edge · — · manual
Rows: 9, 13
Test: none
Given the Scheduler CLI tab in AI Foundation
When an admin runs `t3af:ai-logs:cleanup` there
Then AI Logs has "Scheduler CLI command t3af:ai-logs:cleanup: completed." · a failed run is logged with level error

### T3AF-LOG-031 Rows readable on both sys_log schemas
P1 · edge · release · auto
Rows: 12
Test: `Tests/Functional/Domain/Repository/AiSysLogRepositoryFunctionalTest.php::insertFilterCountAndExportRespectChannelAndPeriod`
Given TYPO3 13.4 (`details` column) and 14.3 (`message` column)
When a row is written with `SysLogWriterUtility::insert` and read back
Then the text is the same on both versions · run the test on both versions

---

## Security

### T3AF-LOG-032 Export URL without AI Logs tab access
P0 · security · release · gap
Rows: 3, 10
Test: none
Given an editor whose group lacks `nst3af_tab:ai_logs`
When they open the `ai_logs.export` route directly with a valid token
Then access is denied · no CSV with log details downloads

### T3AF-LOG-033 Delete post without AI Logs tab access
P0 · security · release · gap
Rows: 3, 7, 10
Test: none
Given an editor whose group lacks `nst3af_tab:ai_logs`
When they post `uids[]` to the `ai_logs.delete` route
Then access is denied · no `sys_log` row is removed

### T3AF-LOG-034 A crafted uid cannot delete non-AI log rows
P1 · security · release · gap
Rows: 7, 10
Test: none
Given a core `sys_log` row (e.g. a backend login) with uid 500
When `uids[]=500` is posted to AI Logs delete
Then the row is still in `sys_log` · a "none selected" message shows

### T3AF-LOG-035 Cleanup leaves non-AI rows alone
P1 · security · release · gap
Rows: 7, 9
Test: none
Given core `sys_log` rows and AI rows, all 200 days old
When `t3af:ai-logs:cleanup --days=90` runs
Then only AI channel rows are removed · core rows are still there

### T3AF-LOG-036 HTML in a log message is escaped
P1 · security · release · manual
Rows: 10
Test: none
Given a provider titled `<img src=x onerror=alert(1)>` is saved
When AI Logs is opened
Then the title shows as plain text · no script runs

### T3AF-LOG-037 CSV cells cannot start a spreadsheet formula
P2 · security · — · gap
Rows: 10
Test: none
Given a log message starting with `=HYPERLINK(…)`
When the CSV is opened in a spreadsheet
Then the cell shows as text · no formula runs

### T3AF-LOG-038 API keys never appear in AI log rows
P0 · security · release · gap
Rows: 7, 10, 13
Test: none
Given a provider error whose text echoes the API key (e.g. `Incorrect API key provided: sk-…`)
When it is written through `AiLogService::reportApiError`
Then the stored row, the list and the CSV show at most a masked key · the full key is in none of them

### T3AF-LOG-039 Delete only works as POST with a valid token
P1 · security · — · manual
Rows: 10
Test: none
Given a delete link copied as a GET URL, or a POST without the route token
When it is opened or sent
Then the request is refused · no `sys_log` row is removed
