# Coverage checklist — ns_t3af

Every feature answers every row. A feature is **covered** when each row has at least one case, or `n/a` with a reason in the feature file.

Same 13 rows as `../ns_t3ai/qa/checklist.md` so both extensions are judged the same way.

---

## Rows

| # | Type | What to check |
|---|---|---|
| 1 | Positive | Main flow works and saves the right data |
| 2 | Input | Empty, too long, wrong type, invalid uid / pid, missing record, malformed MCP / API JSON |
| 3 | Permission | No module access, no page / table write access, read-only user, other site, workspace |
| 4 | Toggle / license | Feature toggle off, license missing or expired, module hidden |
| 5 | Provider failure | No provider set, invalid key, timeout, rate limit, 5xx, empty or malformed response |
| 6 | Credits | Credits mode on / off, balance exhausted, correct `feature_key` charged, nothing charged on failure |
| 7 | Data safety | Preview writes nothing, failure leaves no partial records, re-run does not duplicate, undo where offered |
| 8 | Language / site | Default language, translated language, missing site config, multi-site, RTL where relevant |
| 9 | Queue / CLI | Duplicate entries, partial batch failure, re-queue, scheduler re-run, CLI exit codes |
| 10 | Security | XSS in AI output shown in backend, SSRF on URL inputs, CSRF on backend routes, secrets not in logs or responses |
| 11 | AI output | Right language, length limits, no HTML where plain text, injection ignored, no prompt leakage |
| 12 | Compatibility | Behaviour on TYPO3 13.4 and 14.3 where code paths differ |
| 13 | Logging | Request logged with correct `requestSource`, extension and feature key; errors logged readably |

---

## Status

Update this table when cases change. Counts are cases per status. A row number in **Missing** means no case and no `n/a` yet.

| Feature | File | Cases | auto | manual | gap | Missing rows |
|---|---|---|---|---|---|---|
| AI providers + adapters | `cases/providers.md` | 43 | 25 | 13 | 5 | — |
| Public API (`AiServiceInterface`) | `cases/public-api.md` | 46 | 34 | 2 | 10 | — |
| Backend module + AI Features drawer | `cases/backend-module.md` | 37 | 14 | 12 | 11 | — |
| Setup wizard + extension settings | `cases/wizard-settings.md` | 38 | 20 | 3 | 15 | — |
| AI logs | `cases/ai-logs.md` | 39 | 8 | 19 | 12 | — |
| AI Context (brand profiles) | `cases/ai-context.md` | 45 | 22 | 4 | 19 | — |
| Governance (ACL, budgets, telemetry) | `cases/governance.md` | 40 | 20 | 9 | 11 | — |
| AI Access & Roles | `cases/access-roles.md` | 38 | 15 | 17 | 6 | — |
| T3Planet Credits | `cases/credits.md` | 57 | 38 | 10 | 9 | — |
| MCP server + core tools | `cases/mcp-server.md` | 71 | 50 | 9 | 12 | — |
| AI Label | `cases/ai-label.md` | 44 | 20 | 16 | 8 | — |
| AI Agent (backend modal) | `cases/ai-agent.md` | 84 | 46 | 26 | 12 | — |
| Child-extension integration | `cases/child-integration.md` | 37 | 26 | 8 | 3 | — |
| **Total** | | **619** | **338** | **148** | **133** | |
