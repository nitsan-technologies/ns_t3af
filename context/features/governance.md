# Feature — Governance & Observability

**Status:** Done  
**Deep spec:** `Documentation/Governance/Index.rst`  
**Related:** `context/specs/FEATURE_AiProviderManagement.md` §CC-2, CC-8 (provider access fields); wizard/group caps → `context/features/ai-access-roles.md`

---

## What it does

- **Access control** on `BeforeProviderRequestEvent`: `be_groups` restriction, capability permissions (`nst3af:capability_*`), UserTSconfig budgets, per-minute rate limits.
- **Budget recording** on `AfterProviderResponseEvent` via `RecordBudgetUsageListener`.
- **Logging privacy** (`standard` / `reduced` / `none`) on provider rows + UserTSconfig; strictest wins. **Affects request-log fidelity only** — does not change provider egress (prompts/brand still leave when the call is allowed).
- **Per-request telemetry** to `tx_nst3af_request_log` (tokens, cost, provider/model, optional applied brand profile uid).
- **Dashboard analytics** from `DashboardAnalyticsService` (AI Usage tab).
- **Operational sys_log UI** (AI Logs tab — filters, export, cleanup CLI). See `context/features/ai-logs.md` (distinct from request-log analytics).
- **API quota email alerts** via `AiApiAlertNotificationService` (ext_conf notifications category).

---

## Key paths

| Area | Path |
|---|---|
| ACL gate | `Classes/Governance/AccessControlListener.php` |
| Budget check/record | `Classes/Governance/BudgetService.php`, `Classes/EventListener/RecordBudgetUsageListener.php` |
| Privacy (logging) | `Classes/Governance/PrivacyLevel.php` |
| Telemetry | `Classes/Service/RequestTelemetryService.php` |
| Analytics | `Classes/Service/DashboardAnalyticsService.php` |
| Request log | `Classes/Domain/Repository/RequestLogRepository.php` |
| Alerts | `Classes/Service/AiApiAlertNotificationService.php` |
| Events | `Classes/Event/BeforeProviderRequestEvent.php`, `AfterProviderResponseEvent.php` |
| Permissions | `ext_localconf.php` → `customPermOptions['nst3af']` (capabilities); `AiAccessCustomOptionsBootstrap` merges `T3Ai:*` from `FeaturePermissionCatalog` on boot |
| Group limits (wizard) | `Classes/Governance/GroupLimitsListener.php`, `tx_nst3af_group_settings` |
| Tables | `tx_nst3af_request_log`, `tx_nst3af_usage_budget` |

---

## UserTSconfig keys

```text
nst3af.budget.period       = daily | weekly | monthly
nst3af.budget.maxCost      = float
nst3af.budget.maxTokens    = int
nst3af.budget.maxRequests  = int
nst3af.rateLimit.requestsPerMinute = int
nst3af.privacyLevel        = standard | reduced | none
```

Off by default — no TSconfig means no limits. Admins bypass `be_groups` and capability checks; budgets and rate limits apply to everyone.

---

## Deferred (stored, not enforced yet)

- **`no_rerouting`** on provider rows — flag is persisted; smart-routing layer that honours it is not shipped.

---

## ext_conf (non-provider)

- `enableApiQuotaEmailNotification`, `apiQuotaNotificationEmail`
- Basic auth: `basicAuthEnabled`, `basicAuthUsername`, `basicAuthPassword`

---

## Do / Don't

**Do:** Configure governance via provider Access tab + UserTSconfig; verify in AI Usage tab.

**Don't:** Log full API keys or prompt secrets in `sys_log` or request log metadata.

---

## Verification

1. Backend → AI Foundation → AI Usage — cards populate after a provider call.
2. Set `nst3af.budget.maxRequests = 1` on a test user → second call short-circuits.
3. Inspect `tx_nst3af_request_log` for token/cost rows.
