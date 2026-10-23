# Backend module + AI Features drawer — cases

**Prefix:** `T3AF-MOD`
**How it works:** `context/features/backend-module.md`
**Main code:** `Classes/Controller/Backend/ModuleController.php`, `AbstractAiUniverseModuleController.php`, `FeatureSettingsController.php`, `Classes/Service/Dashboard*.php`, `ModuleStateService.php`, `FeatureExtensionHealthService.php`, `SetupChecklistService.php`, `SetupChecklistPresenter.php`, `EnvironmentRequirementService.php`, `SchedulerCliCommandCatalogService.php`, `SchedulerCliTaskService.php`, `ExtensionExtConfCategoryService.php`, `Classes/Access/ModuleTabAccessService.php`, `Classes/Backend/ToolbarItems/CreditBalanceToolbarItem.php`, `Classes/Utility/{ModuleTabUtility,BackendModuleLinkUtility,LicenseUtility}.php`, `Configuration/Backend/{Modules,AjaxRoutes}.php`
**Existing tests:** `Tests/Unit/Service/Dashboard*Test.php`, `Tests/Unit/Service/ModuleStateServiceTest.php`, `Tests/Unit/Service/SetupChecklist*Test.php`, `Tests/Unit/Service/EnvironmentRequirementServiceTest.php`, `Tests/Unit/Service/SchedulerCli*Test.php`, `Tests/Unit/Utility/{LicenseUtility,ModuleTabUtility}Test.php`, `Tests/Unit/Access/ModuleTabAccessServiceTest.php`, `Tests/Unit/Settings/ExtensionSettingsSecretServiceTest.php`

---

## Not applicable

| Row | Reason |
|---|---|
| 11 AI output | The module shell, dashboard and AI Features drawer render no AI-generated text. AI output is checked in `ai-context.md` (brand research), `ai-agent.md` and `ai-logs.md`. |

---

## Positive

### T3AF-MOD-001 Admin can open every AI Foundation tab
P1 · positive · smoke, release · manual
Rows: 1
Test: none
Given an admin and a page selected in the page tree that belongs to a configured site
When they open AI Foundation and click each tab: Dashboard, Providers, AI Context, MCP Server, MCP Tools, AI Features, AI Prompts, AI Usage, AI Logs, AI Label, Scheduler & CLI, AI Agent, For Developers, AI Permissions
Then every tab renders with its heading · the clicked tab is marked active · no PHP exception · no error in the browser console

### T3AF-MOD-002 Last visited tab is stored per backend user
P2 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Service/ModuleStateServiceTest.php::testSetLastTabPersistsNewValue`
Given a backend user with no stored module state
When they open the AI Usage tab
Then the user's module state stores `lastTab = aiUsage`

### T3AF-MOD-003 Sidebar link reopens the last visited tab
P2 · positive · — · manual
Rows: 1, 3
Test: none
Given an editor whose last visited tab was AI Prompts, then AI Prompts was removed from their group (`nst3af_tab:ai_prompts` not granted)
When they click AI Foundation in the module menu (`t3af_dashboard` `_default` → `indexAction`)
Then they are redirected to the first tab they may see, not to AI Prompts · an admin with the same last tab lands on AI Prompts

### T3AF-MOD-004 Dashboard period choice is remembered
P3 · positive · — · auto
Rows: 1
Test: `Tests/Unit/Service/ModuleStateServiceTest.php::testSetPeriodPersistsAllThreeFields`, `Tests/Unit/Service/DashboardPeriodResolverTest.php::testResolveFromQueryParamsUsesCustomRange`
Given the Dashboard tab
When the user picks a custom range and later opens the Dashboard without a `period` parameter
Then the custom range (`period`, `from`, `to`) is restored · `lastTab` is unchanged

### T3AF-MOD-005 Dashboard counts only the current site's own-key providers
P1 · positive · release · auto
Rows: 1, 8
Test: `Tests/Unit/Service/DashboardAnalyticsServiceTest.php::testResolveOwnKeysProviderUidsReturnsSiteProviderUids`, `::testResolveOwnKeysProviderUidsSkipsProvidersExcludedFromDashboard`
Given two sites, each with its own providers, one provider with "show on dashboard" off
When the Dashboard is opened from a page in site A
Then provider cards, cost and request KPIs use only site A providers shown on the dashboard · the credits provider and site B providers are not counted

### T3AF-MOD-006 Setup checklist matches the active mode
P2 · positive · — · auto
Rows: 1, 6
Test: `Tests/Unit/Service/SetupChecklistServiceTest.php::testOwnKeysModeBuildsEightItemsIncludingBudget`, `::testCreditsModeBuildsSevenItems`
Given the Dashboard checklist
When credits mode is off, then on
Then own-keys mode shows 8 items including the budget item · credits mode shows 7 items

### T3AF-MOD-007 AI Features drawer saves settings for the current site
P1 · positive · smoke, release · gap
Rows: 1, 8
Test: none
Given `ns_t3ai` loaded, an admin, a page in site A selected
When they open the AI SEO card, change one field and click Save (`ajax_nst3af_feature_settings_save` with `extension=ns_t3ai`, `scope=seo`, `id=<page>`)
Then the response is `success: true` with the `saveSuccessTitle` toast · `tx_nst3af_extension_setting` row (`extension_key=ns_t3ai`, `pid=<site A root>`) holds the new value · the site B row is unchanged

### T3AF-MOD-008 Child "AI Foundation Logs" button opens pre-filtered AI Logs
P2 · positive · — · manual
Rows: 1
Test: none
Given `ns_t3ai` and `ns_t3af` loaded and page 12 selected in the T3AI module
When the user clicks **AI Foundation Logs** in the T3AI docheader (`BackendModuleLinkUtility::buildAiLogsUri()`)
Then AI Foundation → AI Logs opens with `id=12` and the extension filter set to `ns_t3ai`

### T3AF-MOD-009 Feature health card warns above 2 % error rate
P2 · positive · — · gap
Rows: 1, 13
Test: none
Given request-log rows for a feature area in the last 7 days where more than 2 % failed
When the Dashboard is opened
Then that area's card in the health footer has status `warning` · areas whose parent extension is not loaded are not shown

### T3AF-MOD-010 Scheduler & CLI lists AI commands and legacy aliases
P2 · positive · — · auto
Rows: 9
Test: `Tests/Unit/Service/SchedulerCliCommandCatalogServiceTest.php::testAllDiscoversAiuniverseCommandsFromRegistry`, `::testFindByCommandResolvesLegacyAlias`
Given `t3af:*` commands registered, some reachable through a legacy alias
When the Scheduler & CLI tab opens
Then every AI command is listed once, grouped by extension · a legacy alias resolves to the current command

### T3AF-MOD-011 Running a command from Scheduler & CLI shows output and logs it
P2 · positive · — · gap
Rows: 9, 13
Test: none
Given an admin on Scheduler & CLI
When they run a `t3af:*` command that exits with 0 (`t3af_dashboard.scheduler_cli.run`)
Then a `scheduler-run-ok` flash shows the command output · a `sys_log` row in channel `nst3af.scheduler` says `Scheduler CLI command <command>: completed.`

---

## Negative

### T3AF-MOD-012 Restricted editor sees only granted tabs
P1 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Access/ModuleTabAccessServiceTest.php::testGatedTabRequiresMatchingPermission`, `Tests/Unit/Utility/ModuleTabUtilityTest.php::testMcpEntryIsOmittedWhenNoMcpTabIsGranted`
Given an editor group with `custom_options` containing only `nst3af_tab:ai_usage`
When the editor opens AI Foundation
Then only Dashboard and AI Usage appear in the navigation · Providers, MCP, AI Features, AI Prompts, AI Logs, Scheduler & CLI are absent

### T3AF-MOD-013 Direct URL to a hidden tab shows no tab data
P1 · negative · release · manual
Rows: 3
Test: none
Given the editor from T3AF-MOD-012
When they paste the URL of `t3af_dashboard.ai_prompts`
Then the tab content is replaced by the access-denied state (`tabAccessDenied = 1`) · no prompt rows are rendered · the Quick Setup wizard does not auto-open

### T3AF-MOD-014 Group without module access cannot open AI Foundation
P1 · negative · release · manual
Rows: 3, 4
Test: none
Given a backend group whose `modules` field does not include `t3af_dashboard`
When a member logs in and calls the module URL directly
Then AI Foundation is not in the module menu · TYPO3 denies the URL · no AI Foundation data is shown

### T3AF-MOD-015 Editor without settings write access cannot save AI Features
P1 · negative · release · gap
Rows: 3, 7
Test: none
Given an editor without `tables_modify` on `tx_nst3af_extension_setting` (catalog `extensionSettings`)
When they POST `ajax_nst3af_feature_settings_save`
Then HTTP 403 with `ok: false` and `Record modification is not permitted for your backend user group. (catalog: extensionSettings)` · `settings_json` is unchanged

### T3AF-MOD-016 Editor without usage-log write access cannot delete request-log rows
P1 · negative · — · gap
Rows: 3, 7
Test: none
Given an editor without write access to `tx_nst3af_request_log` (catalog `usageRequestLog`)
When they POST `t3af_dashboard.ai_usage.delete` or `.bulk_delete` with valid uids
Then they are redirected to AI Usage with `flash=access-denied` · every targeted row still has `deleted = 0`

### T3AF-MOD-017 Site-scoped tab without a page asks for one
P2 · negative · — · manual
Rows: 2, 8
Test: none
Given no page selected in the page tree
When the user opens Providers, AI Context, AI Features or AI Prompts
Then the `Module/PageSelectionRequired` view is shown · no form can be saved · non-scoped tabs (AI Usage, AI Logs) still render

### T3AF-MOD-018 AI Features save for a page outside any site is rejected
P2 · negative · — · gap
Rows: 2, 8
Test: none
Given a page that belongs to no site configuration
When `ajax_nst3af_feature_settings_save` is posted with that `id`
Then HTTP 400 with `The selected page is not part of a configured site.` (`module.siteStorage.pageNotInSite`) · no `tx_nst3af_extension_setting` row is created

### T3AF-MOD-019 Invalid settings scope is rejected
P2 · negative · — · gap
Rows: 2
Test: none
Given `ns_t3ai` loaded
When `ajax_nst3af_feature_settings_get` or `_save` is called with `scope=does-not-exist`
Then HTTP 400 with `Invalid settings scope.` · no form HTML is returned · nothing is saved

### T3AF-MOD-020 Invalid field value is rejected and nothing is saved
P2 · negative · — · gap
Rows: 2, 7
Test: none
Given a scope with an integer field
When the drawer is saved with `abc` in that field and a valid change in another field
Then HTTP 400 with `success: false` and the validation message · neither field is written to `settings_json`

### T3AF-MOD-021 Missing licence does not block the module
P1 · negative · smoke, release · auto
Rows: 4
Test: `Tests/Unit/Utility/LicenseUtilityTest.php::testModuleLicenseGateAlwaysAllows`
Given no `ns_license` extension and no licence key
When an admin opens AI Foundation
Then the module and all tabs open · no licence banner blocks the UI

### T3AF-MOD-022 Credits purchase pages redirect when credits are not public
P2 · negative · — · manual
Rows: 4, 6
Test: none
Given `CreditsReleaseGate` reports credits as not publicly available
When the user opens `t3af_dashboard.buy_credits` directly
Then they are redirected with the credits-unavailable message · no checkout URL is built · no credits API purchase call is made

### T3AF-MOD-023 Unknown CLI command returns an error and runs nothing
P2 · negative · — · auto
Rows: 2, 9
Test: `Tests/Unit/Service/SchedulerCliTaskServiceTest.php::testRunCommandReturnsErrorPayloadWhenCommandMissing`
Given Scheduler & CLI
When `scheduler_cli.run` is posted with `command=t3af:does-not-exist`
Then the result has `ok = 0` and an error text · the page shows `scheduler-run-fail` · no command runs

### T3AF-MOD-024 Command with non-zero exit code is reported as failed
P2 · negative · — · gap
Rows: 9, 13
Test: none
Given a `t3af:*` command that exits with code 1
When it is run from Scheduler & CLI
Then the `scheduler-run-fail` flash shows `Exit code: 1` · a `sys_log` error row in channel `nst3af.scheduler` says `Scheduler CLI command <command>: failed.`

### T3AF-MOD-025 Missing encryption key is shown as a setup error
P1 · negative · release · auto
Rows: 10
Test: `Tests/Unit/Service/EnvironmentRequirementServiceTest.php::testMissingEncryptionKeyProducesChecklistError`, `Tests/Unit/Service/SetupChecklistServiceTest.php::testFailingEnvironmentRequirementsArePrepended`
Given `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']` is empty
When the Dashboard checklist is built
Then a failing environment item is listed first in the checklist · the other checklist items are still shown

### T3AF-MOD-026 Credit badge is hidden when credits are off or the balance call fails
P2 · negative · — · gap
Rows: 5, 6
Test: none
Given credits mode off, or credits mode on and the balance API times out
When any backend page loads
Then `CreditBalanceToolbarItem::checkAccess()` is false and no credit badge is in the toolbar · the backend renders without error

---

## Edge

### T3AF-MOD-027 KPI strip hides token metrics in credits mode
P3 · edge · — · auto
Rows: 6
Test: `Tests/Unit/Service/DashboardViewModelBuilderTest.php::testBuildKpiStripOmitsTokensInCreditsMode`
Given credits mode on
When the Dashboard KPI strip is built
Then no token count KPI is shown · credit KPIs are shown

### T3AF-MOD-028 Unchanged module state is not rewritten
P3 · edge · — · auto
Rows: 7
Test: `Tests/Unit/Service/ModuleStateServiceTest.php::testSetLastTabIsNoOpWhenValueUnchanged`, `::testSetPeriodIsNoOpWhenAllFieldsUnchanged`
Given a stored last tab and period
When the same tab and period are set again
Then the backend user's `uc` is not written

### T3AF-MOD-029 Corrupt stored module state falls back to defaults
P3 · edge · — · auto
Rows: 2, 7
Test: `Tests/Unit/Service/ModuleStateServiceTest.php::testReadReturnsDefaultsWhenStoredValueIsNotArray`, `::testReadFallsBackToDefaultsForInvalidScalarTypes`
Given the stored module state is a string or has wrong scalar types
When the module opens
Then `ModuleStateService::DEFAULTS` are used · no exception

### T3AF-MOD-030 Scheduler extension missing: tab still loads
P3 · edge · — · auto
Rows: 9, 12
Test: `Tests/Unit/Service/SchedulerCliTaskServiceTest.php::testListTasksReturnsEmptyArrayWhenSchedulerRepositoryUnavailable`, `Tests/Unit/Service/DashboardAnalyticsServiceTest.php::testScheduledTaskStatsReturnsZerosWhenNoCliTasks`
Given EXT:scheduler is not installed
When Scheduler & CLI and Dashboard are opened
Then the task list is empty and task KPIs are 0 · commands are still listed

### T3AF-MOD-031 Second site shows its own settings and providers
P1 · edge · release · manual
Rows: 8
Test: none
Given site A and site B with different providers and AI Features values
When the user switches the page tree from a site A page to a site B page on Providers and AI Features
Then site B values are shown · saving on site B never changes site A rows

### T3AF-MOD-032 German backend user sees translated labels
P3 · edge · — · manual
Rows: 8
Test: none
Given a backend user with language German
When they open Dashboard and AI Features
Then tab titles and AI Features card descriptions are German where `de.locallang_*.xlf` exists · no raw `LLL:` string is shown

### T3AF-MOD-033 Module renders correctly on 13.4 and 14.3
P1 · edge · release · manual
Rows: 12
Test: none
Given the same install on TYPO3 13.4 and 14.3
When AI Foundation and Scheduler & CLI are opened
Then 14.3 shows the `ModuleV14.svg` module icon and 13.4 the `ns-t3af-module13` icon · the "Manage in Scheduler" link opens route `scheduler` on 14.3 and `scheduler_manage` on 13.4 · the docheader shortcut works on 14.3 and its absence causes no error on 13.4

---

## Security

### T3AF-MOD-034 Scheduler CLI run is limited to permitted users and AI commands
P0 · security · release · gap
Rows: 3, 9, 10
Test: none
Given an editor with AI Foundation module access but without the Scheduler & CLI tab (or with tab gating inactive)
When they POST `t3af_dashboard.scheduler_cli.run` with `command=cache:flush` or any non-`t3af:*` command
Then the request is refused · no command runs · no output is returned · only commands accepted by `SchedulerCliCommandCatalogService::isSchedulerCliCommand()` may run, and only for users allowed on that tab

### T3AF-MOD-035 POST routes reject requests without a valid token
P1 · security · release · manual
Rows: 10
Test: none
Given a logged-in editor
When `t3af_dashboard.ai_usage.delete`, `t3af_dashboard.scheduler_cli.run` and `ajax_nst3af_feature_settings_save` are called without the TYPO3 route token, or with a token from another session
Then TYPO3 rejects each request · no row is deleted, no command runs, no setting is saved

### T3AF-MOD-036 Command output passed in the URL is escaped
P1 · security · — · manual
Rows: 10
Test: none
Given the Scheduler & CLI URL with `flash=scheduler-run-ok&runOutput=<script>alert(1)</script>`
When the page loads
Then the text is shown literally inside the `<pre>` block · no script runs

### T3AF-MOD-037 AI Features drawer never returns a stored secret
P0 · security · release · auto
Rows: 10
Test: `Tests/Unit/Settings/ExtensionSettingsSecretServiceTest.php::testMaskValuesForDisplayClearsSecretValues`, `Tests/Unit/Service/CredentialCipherTest.php::testMaskHidesMostOfKey`
Given a stored DeepL API key in `ns_t3af` settings
When the drawer HTML is loaded via `ajax_nst3af_feature_settings_get`
Then the field is empty with a "Configured" mask label · the plain key and the `enc:v1:` ciphertext are not in the response

---

## Field reference

| Field | Values |
|---|---|
| Priority | P0 · P1 · P2 · P3 (see `../README.md` § Severity) |
| Kind | positive · negative · edge · security · ai |
| Tags | `smoke`, `release` (zero, one or both) |
| Status | `auto` = a test covers it · `manual` = verify in backend / CLI · `gap` = should be automated, no test yet |
| Rows | Checklist rows this case answers (`../checklist.md`) |
| Test | `path::method` when `auto`; `none` otherwise |
| Ticket | Optional ClickUp link when the case came from a bug |

Rules:

- One behaviour per case. Split instead of writing "and also".
- **Then** must be observable: a saved value, a message, a log row, a credit count, an HTTP status.
- Negative **Then** always says what must **not** change.
- Never renumber. Retired cases stay with `~~strikethrough~~` title and a reason.
