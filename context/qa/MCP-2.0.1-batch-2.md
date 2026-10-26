# MCP 2.0.1 — QA batch 2 (dev handoff)

**Parent:** [MCP 2.0.1](https://app.clickup.com/t/90161069568/14zervyv6pg)  
**Package:** `ns_t3af` (uncommitted dev tree — **no commit SHA until dev is allowed to commit after your sign-off**)  
**Baseline:** batch 1 merged or same branch as batch 1 commit  

**Run model:** Execute **all four test cases in parallel**. They do not depend on each other.

**Out of scope (no code in this batch):** x207 `page_render_check` — tracked as suggestion on [MCP next / D5](https://app.clickup.com/t/90161069568/14zervywv1u).

**Prerequisites (shared):**

- TYPO3 + `ns_t3af` MCP enabled; backend user with read/search and `records_apply` where noted.
- At least one draft workspace (optional for x208 enum check).
- Tools: `workspace_list`, `content_search`, `pages_search`, `record_search`, `record_count`, `records_apply`.

---

## TC-x208 (medium) — `workspaceId` schema text stays short

**Ticket:** x208  

**Steps:**

1. In MCP tool schema (stdio `tools/list` or backend tool inspector), open any tool that exposes `workspaceId`.
2. Read the `workspaceId` parameter description (do not rely on copying the full enum list into chat).
3. Call `workspace_list` and confirm draft workspace UIDs/titles match the enum values on the same tool.

**Expected:**

| Step | Expected |
|------|----------|
| 1–2 | Description mentions omitting the param, MCP module default, **enum**, and **`workspace_list`**; it does **not** embed a long `N = Title, …` list. |
| 3 | Enum integers match `workspace_list` draft UIDs; titles come from `workspace_list`, not from the parameter description. |

---

## TC-x201 (medium) — Unknown search `op` is rejected

**Ticket:** x201  

**Steps:**

1. `content_search` with `search` = `{"header":{"op":"bogus","value":"x"}}` (valid page scope if required).
2. `pages_search` with `search` = `{"title":{"op":"nope","value":"x"}}`.
3. `record_search` on `tt_content` with `search` = `{"header":{"op":"??","value":"x"}}`.
4. `record_count` on `tt_content` with same bad `search` as (3).

**Expected:**

| Step | Expected |
|------|----------|
| 1–4 | **Error** (tool exception / MCP error), not an empty success result. Message mentions unknown operator or invalid search parameter. Code **1790600005** where the stack exposes MCP error codes. |

---

## TC-x202 (medium) — Invalid `orderBy` / `orderDirection`

**Ticket:** x202  

**Steps:**

1. `content_search` with a valid `search`, `orderBy` = `not_a_real_column`, `orderDirection` = `ASC`.
2. Same with `orderBy` = `header`, `orderDirection` = `UP`.
3. Repeat (1)–(2) on `pages_search` and `record_search` (`tt_content` or `pages`).

**Expected:**

| Step | Expected |
|------|----------|
| 1, 3 (bad orderBy) | Refused; code **1790600007** when exposed. |
| 2, 3 (bad direction) | Refused; code **1790600008** when exposed. |
| — | No silent fallback to ASC on invalid direction. |

---

## TC-x205 (medium) — `sys_category` + `dryRun` distinct MCP code

**Ticket:** x205  

**Steps:**

1. `records_apply` with `dryRun=true`, `data` creating `sys_category` only (e.g. `NEWcat` with `title`, `pid` 0).
2. `records_apply` with `dryRun=true`, invalid normal validation (e.g. new `tt_content` without `pid`).

**Expected:**

| Step | Expected |
|------|----------|
| 1 | Validation refusal; MCP/tool code **1790500032** (not **1790500006**). Message mentions `sys_category` and `dryRun`. Nothing written. |
| 2 | Validation refusal; code **1790500006**. |

---

## Parallel execution note

```text
Run TC-x208, TC-x201, TC-x202, TC-x205 in parallel.
Aggregate: PASS/FAIL per ticket + one-line evidence.
```

---

## QA agent prompt

```text
You are QA for ns_t3af MCP 2.0.1. Read:
packages/ns_t3af/context/qa/MCP-2.0.1-batch-2.md

Execute TC-x208, TC-x201, TC-x202, TC-x205 in PARALLEL.

Rules:
- One verdict per ticket id: PASS or FAIL.
- FAIL: steps, actual vs expected, minimal excerpt (no secrets).
- x207 is out of scope for this batch.

Output:
## Batch 2 summary
| Ticket | Result | Evidence |
…
SHA: (wait for dev commit)
```
