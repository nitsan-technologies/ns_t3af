# Governance (ACL, budgets, telemetry, alerts) — cases

**Prefix:** `T3AF-GOV`
**How it works:** `context/features/governance.md` (group caps from the wizard: `context/features/ai-access-roles.md`)
**Main code:** `Classes/Governance/AccessControlListener.php`, `Classes/Governance/BudgetService.php`, `Classes/Governance/BudgetCheckResult.php`, `Classes/Governance/GroupLimitsListener.php`, `Classes/Governance/PrivacyLevel.php`, `Classes/Domain/Repository/UsageBudgetRepository.php`, `Classes/EventListener/RecordBudgetUsageListener.php`, `Classes/Service/RequestTelemetryService.php`, `Classes/Service/AiApiAlertNotificationService.php`, `Configuration/Services.yaml` (listener order)
**Existing tests:** `Tests/Unit/Governance/*`, `Tests/Functional/Governance/*`, `Tests/Unit/Service/AiApiAlertNotificationServiceTest.php`, `Tests/Unit/Service/RequestTelemetryService*Test.php`, `Tests/Unit/Service/AiServiceTest.php`

---

## Not applicable

| Row | Reason |
|---|---|
| 11 AI output | Governance never produces content. A denied call returns empty content (see T3AF-GOV-014). |
| 12 Compatibility | No TYPO3-version branch in these classes (same event, TSconfig and mail API on 13.4 and 14.3). Covered by the normal `release` run on both versions. |

---

## Positive

### T3AF-GOV-001 Successful call records usage against the user budget
P1 · positive · smoke, release · auto
Rows: 1
Test: `Tests/Functional/Governance/UsageBudgetFunctionalTest.php::recordUsageIncrementsCountersAndZeroBudgetBlocks`
Given a backend user with no usage yet
When usage of 100 tokens and cost 0.05 is recorded for the period
Then `tx_nst3af_usage_budget` shows `requests_used = 1`, `tokens_used = 100`, `cost_used = 0.05`

### T3AF-GOV-002 Over-budget UserTSconfig cancels the next provider request
P0 · positive · smoke, release · auto
Rows: 1, 6
Test: `Tests/Functional/Governance/AccessControlListenerFunctionalTest.php::overBudgetUserTSconfigCancelsBeforeProviderRequest`
Given UserTSconfig `nst3af.budget.maxRequests = 1` and one request already used
When the next `BeforeProviderRequestEvent` is dispatched
Then the event is cancelled with a reason containing "Request budget exceeded"

### T3AF-GOV-003 Budget limit end to end in the backend
P1 · positive · release · manual
Rows: 1, 6
Test: none
Given a test editor with `nst3af.budget.maxRequests = 1` and a working provider
When the editor runs one AI action (e.g. T3AI content rewrite), then a second one
Then the first returns text and adds one row to `tx_nst3af_request_log` · the second shows a readable "Request budget exceeded" message · no second request-log row with `success = 1` · no second adapter call

### T3AF-GOV-004 Request-log row carries extension, feature and token data
P1 · positive · release · auto
Rows: 1, 13
Test: `Tests/Unit/Service/RequestTelemetryServiceTranslationTest.php::testLogTranslationPersistsTranslateRequestType`
Given a DeepL translation call from `ns_t3ai` with feature `translation.deepl`
When telemetry is written
Then the row has `request_type = translate`, `extension_key = ns_t3ai`, `feature_key = translation.deepl`, `provider_identifier = DeepL`, tokens 11 / 10 / 21, `latency_ms = 120`

### T3AF-GOV-005 Budget counters reset after the period ends
P1 · positive · release · auto
Rows: 1, 7
Test: `Tests/Functional/Governance/UsageBudgetFunctionalTest.php::expiredPeriodResetsCountersOnNextRead`
Given a usage row whose `period_start` is older than the period length
When usage is read
Then `requests_used`, `tokens_used` and `cost_used` are back to 0

### T3AF-GOV-006 Quota error sends one alert email per hour
P1 · positive · release · manual
Rows: 1, 5
Test: none
Given site settings `enableApiQuotaEmailNotification = 1`, a valid `apiQuotaNotificationEmail`, and a provider that answers "insufficient_quota"
When two AI calls fail within the same hour from the same extension
Then one email with subject "AI API data quota exceeded" arrives (Mailpit) with AI Engine, Error Message, Generated from, Time · the second failure sends no email (cache `nst3af_api_alert` cooldown)

---

## Negative

### T3AF-GOV-007 Provider restricted to other backend groups is denied
P0 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Governance/AccessControlListenerTest.php::testDeniesWhenProviderRestrictedToOtherBeGroups`
Given a provider with `be_groups` set to a group the editor is not in
When the editor's call reaches `BeforeProviderRequestEvent`
Then it is cancelled with 'Provider "…" is restricted to other backend groups.'

### T3AF-GOV-008 Missing capability permission is denied
P0 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Governance/AccessControlListenerTest.php::testDeniesWhenCapabilityPermissionMissing`
Given an editor whose groups grant `nst3af:capability_chat` only
When an `embed` call is made
Then it is cancelled with 'Missing AI capability permission "nst3af:capability_embeddings" for this request.'

### T3AF-GOV-009 Admin bypasses group checks but not budgets
P1 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Governance/AccessControlListenerTest.php::testAdminIsStillSubjectToBudgetLimits`
Given an admin with `nst3af.budget.maxRequests = 1` already used
When the admin makes a call
Then it is cancelled with "Request budget exceeded"

### T3AF-GOV-010 Rate limit per minute is enforced
P1 · negative · — · auto
Rows: 3
Test: `Tests/Unit/Governance/AccessControlListenerTest.php::testDeniesWhenRateLimitExceeded`
Given `nst3af.rateLimit.requestsPerMinute = 2` and 2 request-log rows in the last 60 s
When a third call is made
Then it is cancelled with a reason containing "Rate limit exceeded"

### T3AF-GOV-011 Group daily request cap is enforced
P1 · negative · release · auto
Rows: 3, 6
Test: `Tests/Unit/Governance/GroupLimitsListenerTest.php::testDeniesWhenDailyRequestCapReached`
Given a configured `tx_nst3af_group_settings` row with `daily_request_cap` reached today
When the member calls a provider
Then it is cancelled with "Daily AI request limit reached for your backend user group."

### T3AF-GOV-012 Group monthly credit cap is enforced
P0 · negative · release · auto
Rows: 6
Test: `Tests/Unit/Governance/GroupLimitsListenerTest.php::testDeniesWhenMonthlyCreditCapReached`
Given `credit_cap_monthly` reached by the user's `credits_used` this month
When the member calls a provider
Then it is cancelled with "Monthly AI credit cap reached for your backend user group."

### T3AF-GOV-013 Provider outside the group allowlist is denied
P1 · negative · release · auto
Rows: 3
Test: `Tests/Unit/Governance/GroupLimitsListenerTest.php::testDeniesProviderNotOnAllowlist`
Given `limits_json` with `providerAllowlistEnabled = true`, `allowedProviders = ["other-provider"]`
When the member calls a different provider
Then it is cancelled with "Group policy blocks this AI provider."

### T3AF-GOV-014 A cancelled call never reaches the provider
P0 · negative · release · auto
Rows: 5, 7
Test: `Tests/Unit/Service/AiServiceTest.php::testCompleteCancelsWhenListenerSetsReason`
Given a listener that cancels `BeforeProviderRequestEvent` with "blocked by ACL"
When `AiService::complete()` runs
Then the response content is `''` and `raw = { cancelled: "blocked by ACL" }`

### T3AF-GOV-015 Denied call charges nothing and records no usage
P0 · negative · release · manual
Rows: 6, 7
Test: none
Given credits mode on, a test license, and an editor over a group daily cap
When the editor triggers an AI action
Then the T3Planet credit balance is unchanged · `tx_nst3af_usage_budget` counters are unchanged · no `tx_nst3af_request_log` row with `success = 1` is added

### T3AF-GOV-016 Provider failure does not count against the budget
P1 · negative · release · gap
Rows: 5, 7
Test: none
Given an editor with a budget and a provider that returns 500 / times out
When the call fails
Then `tx_nst3af_usage_budget` counters are unchanged (`RecordBudgetUsageListener` only runs after success) · one request-log row with `success = 0`, `error_class` and `error_code` is written

### T3AF-GOV-017 Alerts off or invalid recipient sends no email
P2 · negative · — · gap
Rows: 4, 5
Test: none
Given `enableApiQuotaEmailNotification = 0`, or `apiQuotaNotificationEmail = "not-an-email"`
When a quota or API-key error is logged at level `error`
Then no email is sent · no cooldown cache entry is set · the sys_log row is still written

### T3AF-GOV-018 Error classification picks quota, API key or nothing
P2 · negative · — · auto
Rows: 5
Test: `Tests/Unit/Service/AiApiAlertNotificationServiceTest.php::testClassifyErrorDetectsQuota`
Given error texts "insufficient_quota", "Incorrect API key provided" and an unrelated error
When `classifyError()` runs
Then they map to `quota`, `api_key` and `null` (see also `::testClassifyErrorDetectsApiKey`, `::testClassifyErrorReturnsNullForUnrelatedMessage`)

---

## Edge

### T3AF-GOV-019 No TSconfig means no limits
P1 · edge · — · auto
Rows: 2, 4
Test: `Tests/Unit/Governance/BudgetServiceTest.php::testAllLimitsMissingMeansUnlimited`
Given no `nst3af.budget.*` keys
When the budget is checked
Then the call is allowed

### T3AF-GOV-020 Negative limit means unlimited, zero blocks all
P1 · edge · — · auto
Rows: 2
Test: `Tests/Unit/Governance/BudgetServiceTest.php::testNegativeLimitMeansUnlimited`
Given `maxRequests = -1` (and, in `::testZeroRequestBudgetBlocksAll`, `maxRequests = 0`)
When the budget is checked
Then -1 allows the call · 0 denies every call

### T3AF-GOV-021 Limit N denies at exactly N
P2 · edge · — · auto
Rows: 2
Test: `Tests/Unit/Governance/BudgetServiceTest.php::testLimitOfNDeniesAtExactlyN`
Given `maxRequests = N` and N requests used
When the budget is checked
Then the call is denied

### T3AF-GOV-022 Unknown budget period falls back to monthly
P3 · edge · — · gap
Rows: 2
Test: none
Given `nst3af.budget.period = yearly`
When usage is recorded and checked
Then the `monthly` row of `tx_nst3af_usage_budget` is used · no row with `period_type = yearly` is created

### T3AF-GOV-023 Strictest daily cap wins across groups
P1 · edge · release · auto
Rows: 3
Test: `Tests/Unit/Governance/GroupLimitsListenerTest.php::testUsesStrictestDailyCapAcrossGroups`
Given a user in two configured groups with daily caps 10 and 2
When the third request of the day is made
Then it is denied (cap 2 applies)

### T3AF-GOV-024 Group limits never overwrite an earlier denial reason
P2 · edge · — · auto
Rows: 13
Test: `Tests/Unit/Governance/GroupLimitsListenerTest.php::testSkipsChecksWhenEventAlreadyCancelled`
Given `AccessControlListener` already cancelled the event
When `GroupLimitsListener` runs
Then the original reason is kept and `tx_nst3af_group_settings` is not read

### T3AF-GOV-025 CLI, scheduler and frontend calls pass the backend gates
P1 · edge · — · auto
Rows: 9
Test: `Tests/Unit/Governance/AccessControlListenerTest.php::testPassesThroughWhenNoBackendUser`
Given no `BackendUserAuthentication` in `$GLOBALS['BE_USER']`
When a provider call is made
Then the event is not cancelled

### T3AF-GOV-026 Scheduler task runs are not blocked by an editor's budget
P2 · edge · — · manual
Rows: 9
Test: none
Given an editor with `nst3af.budget.maxRequests = 0` and a scheduler task that calls AI (e.g. T3AI bulk translation)
When the scheduler runs the task twice via `typo3 scheduler:run`
Then both runs reach the provider · no `tx_nst3af_usage_budget` row is written for the editor · exit code 0

### T3AF-GOV-027 Credits mode goes through the same gates
P0 · edge · release · manual
Rows: 6
Test: none
Given credits mode on and an editor without `nst3af:capability_chat` (other `nst3af:` bits present)
When the editor runs a chat feature
Then the call is denied with the capability message before `ProxyAiExecutor` sends anything · credit balance unchanged

### T3AF-GOV-028 Credits-mode budget cost uses the charged credits
P1 · edge · — · gap
Rows: 6
Test: none
Given credits mode on and a response with `credits.charged = 3.5`, `credits.tokensTotal = 1200`
When `RecordBudgetUsageListener` runs
Then `cost_used` grows by 3.5 and `tokens_used` by 1200 (not the token price estimate)

### T3AF-GOV-029 Wizard-configured editor can use granted image and TTS features
P1 · edge · release · manual
Rows: 3
Test: none
Given an editor group applied by the wizard with T3AI (and T3AA) enabled, which writes `nst3af:capability_chat/streaming/embeddings` only
When the editor runs a granted image generation (T3AI media) and a TTS feature (T3AA)
Then both calls run · if they are denied with 'Missing AI capability permission "nst3af:capability_image_generation"' / `capability_tts`, report a bug

### T3AF-GOV-030 Alert settings follow the right site
P2 · edge · — · manual
Rows: 8
Test: none
Given two sites: site A alerts on to `a@example.test`, site B alerts off
When a quota error happens in a frontend chat on site B, then in a backend action
Then the frontend error sends no email (site B settings) · the backend error emails `a@example.test` (first site row with alerts on)

### T3AF-GOV-031 Unconfigured group settings are ignored
P2 · edge · — · gap
Rows: 3
Test: none
Given a `tx_nst3af_group_settings` row with `configured = 0` and `daily_request_cap = 1`
When a member makes several calls
Then none are denied by group limits

---

## Security

### T3AF-GOV-032 Disjoint allowlists across groups allow no provider
P0 · security · release · gap
Rows: 3, 10
Test: none
Given a user in group A (allowlist `["openai"]`) and group B (allowlist `["anthropic"]`), both configured
When the user calls `openai`, then `anthropic`, then a third provider
Then all three are denied with "Group policy blocks this AI provider." (same rule as `Tests/Unit/Agent/AgentGovernanceGuardTest.php::groupsWithDisjointAllowlistsAllowNoProvider`) · no adapter call

### T3AF-GOV-033 Member of any allowed group passes the provider restriction
P1 · security · — · gap
Rows: 3
Test: none
Given a provider with `be_groups = [A]` and a user in groups A and C
When the user calls the provider
Then the call is allowed · a user in C only is denied

### T3AF-GOV-034 Privacy level none writes no request-log row
P1 · security · release · gap
Rows: 10, 13
Test: none
Given a provider with `privacy_level = none`
When a call succeeds and another fails
Then no row is added to `tx_nst3af_request_log` for either

### T3AF-GOV-035 Privacy level reduced drops fingerprint and raw meta
P1 · security · — · gap
Rows: 10
Test: none
Given a provider with `privacy_level = reduced`
When a call succeeds
Then the row has tokens and cost · `prompt_fingerprint = ''` · `raw_meta = '{}'`

### T3AF-GOV-036 User TSconfig can tighten but not loosen privacy
P1 · security · — · gap
Rows: 10
Test: none
Given a provider with `privacy_level = reduced` and an editor with `nst3af.privacyLevel = standard`
When the editor's call is logged
Then the reduced rules apply (strictest wins) · with `nst3af.privacyLevel = none` no row is written

### T3AF-GOV-037 Request log stores no prompt text
P0 · security · release · manual
Rows: 10
Test: none
Given a provider with `privacy_level = standard`
When a call with prompt "secret-marker-123" succeeds
Then no column of the new `tx_nst3af_request_log` row contains "secret-marker-123" · `prompt_fingerprint` is a 64-char sha256 hash

### T3AF-GOV-038 API key echoed by the provider is not stored or mailed
P0 · security · release · gap
Rows: 10
Test: none
Given a provider error body "Incorrect API key provided: sk-test-ABCDEF1234567890" and alerts on
When the call fails
Then the full key does not appear in `tx_nst3af_request_log.raw_meta`, in `sys_log`, or in the alert email body

### T3AF-GOV-039 Privacy dropdown labels describe logging only
P3 · security · — · auto
Rows: 10
Test: `Tests/Unit/Governance/PrivacyLevelTest.php::testLabelsDescribeLoggingOnlyNotEgress`
Given the three privacy levels
When their labels are read
Then each label mentions logging (reduced mentions the fingerprint) · none mentions "egress" or "provider payload"

### T3AF-GOV-040 Denials are logged readably
P2 · security · — · manual
Rows: 13
Test: none
Given an editor denied by budget, capability or be_groups
When the call is cancelled
Then the TYPO3 log (`var/log/typo3_*.log`) has "ns_t3af governance denied a request." with `provider`, `callKind` and `reason` · no prompt text and no API key in the entry
