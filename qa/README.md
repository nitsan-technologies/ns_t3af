# QA — ns_t3af

**Agents: load `INDEX.md` and run `php qa/scope.php`. Do not load this file or `cases/` unless INDEX sent you here.**

> How features work: `context/features/*.md`  
> Child QA index: `../ns_t3ai/qa/INDEX.md`

---

## Cheap loop (default)

1. `php qa/scope.php change` (or `smoke` / `feature <slug>`).
2. Run the printed `COMMANDS` (`composer test`, `stan`, `cs:check`; functional only if listed).
3. Stop. `OPEN: none` means do not read case files.
4. Manual, release, or `t3af:agent:eval --live` only if the user named that mode.

`smoke` is **auto + smoke tag only** (15 unit / functional cases). It is not a backend walk.

**Do not run `composer ci` during QA.** It ends with `cs:fix` and rewrites files.

---

## Files

| File | Who loads it |
|---|---|
| `INDEX.md` | Agent, first |
| `scope.php` | Agent runs, does not read |
| `README.md` | Human / this page |
| `impact.md` | `scope.php` only |
| `checklist.md` | Coverage edit, not a test run |
| `ai-checks.md` | Replay / live rules when asked |
| `known-issues.md` | Before reporting a fail |
| `cases/<feature>.md` | Only if `OPEN:` names it |

---

## Modes

| Mode | Command | What runs |
|---|---|---|
| `change` | `php qa/scope.php change` | Impact-mapped **auto** + smoke-auto |
| `smoke` | `php qa/scope.php smoke` | Auto cases tagged `smoke` |
| `feature` | `php qa/scope.php feature credits` | That feature's **auto** |
| `manual` | `php qa/scope.php feature credits --manual` | User asked to walk backend / CLI |
| `release` | User must ask | `release` tag on 13.4 and 14.3 + child smoke — expensive |

---

## Commands

From the package root (vendor in `.Build/vendor/`):

```bash
php qa/scope.php change
composer test
composer stan
composer cs:check
```

Replay (no LLM): `ddev exec typo3 t3af:agent:eval`  
Live eval: only with approval — `ai-checks.md`.

---

## Environments

TYPO3 13.4 / 14.3 · PHP 8.2+ · Composer or Classic · children: `ns_t3ai`, `ns_t3cs`, `ns_t3aa`, `ns_t3as`, `ns_t3ac`.

---

## Rules

- A case passes only on its **Then**. Suspected defects say `today …` in a Note — that is still a **fail**, not a known issue. Do not move them into `known-issues.md`.
- Negative **Then** also says what must not change.
- Blocked environment → `blocked`, not `pass`.
- Do not edit a case to match broken behaviour.
- New bug → new case (`gap` or `auto`) with the ClickUp link.

---

## Report

Raw output: `.Build/qa/<date>-<mode>/` (git-ignored). Summary on the ClickUp task or MR.

```
QA run: <mode> · ns_t3af · TYPO3 <x>
AUTO pass/fail from composer test / stan
MANUAL: not run (unless asked)
GAP: not verified
```

Severity: P0 data loss / security / credits / secrets · P1 no workaround or child broken · P2 workaround · P3 cosmetic.
