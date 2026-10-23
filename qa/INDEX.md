# QA index — ns_t3af

**Load this file only.** Do not open `cases/`, `checklist.md`, or `impact.md`.

```bash
php qa/scope.php change          # default for a diff (auto + smoke-auto)
php qa/scope.php smoke           # 15 PHPUnit / functional cases, OPEN: none
php qa/scope.php feature credits # one feature, auto only
php qa/scope.php feature credits --manual   # only when the user asks
```

Then run the `COMMANDS` the script prints. Do not read a case file unless `OPEN:` names it.

---

## Token rules

1. Never load `qa/cases/`. ~286k characters.
2. Never load ns_t3ai `qa/` in the same turn unless the user asked and an EDGE printed.
3. Never walk `manual` / `release` / `t3af:agent:eval --live` unless the user names that mode.
4. `composer test` / `stan` are free. The model is not.

---

## Features (slug → file)

| Slug | File | auto | When |
|---|---|---|---|
| providers | `cases/providers.md` | 25 | adapters, cipher, connection test |
| public-api | `cases/public-api.md` | 34 | `AiServiceInterface` |
| backend-module | `cases/backend-module.md` | 14 | dashboard, drawer, scheduler CLI |
| wizard-settings | `cases/wizard-settings.md` | 20 | setup wizard, ext settings |
| ai-logs | `cases/ai-logs.md` | 8 | AI logs tab |
| ai-context | `cases/ai-context.md` | 22 | brand profiles |
| governance | `cases/governance.md` | 20 | ACL, budgets, telemetry |
| access-roles | `cases/access-roles.md` | 15 | AI Access & Roles |
| credits | `cases/credits.md` | 38 | T3Planet Credits |
| mcp-server | `cases/mcp-server.md` | 50 | MCP server + core tools |
| ai-label | `cases/ai-label.md` | 20 | AI Label |
| ai-agent | `cases/ai-agent.md` | 46 | backend agent modal |
| child-integration | `cases/child-integration.md` | 26 | child extension hooks |

Full rules: `README.md`. Replay / live eval: `ai-checks.md` (only if the user asked for live).
