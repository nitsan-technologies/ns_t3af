# MCP 2.0.1 — QA batch 1 (dev handoff)

**Parent:** [MCP 2.0.1](https://app.clickup.com/t/90161069568/14zervyv6pg)  
**Package:** `ns_t3af` (uncommitted dev tree — **no commit SHA until dev is allowed to commit after your sign-off**)  
**Baseline for dev verification:** `packages/ns_t3af` @ `9b3bb05` + local batch-1 changes  

**Run model:** Execute **all five test cases in parallel** (separate MCP sessions or parallel tool runners). They do not depend on each other. Report pass/fail per ticket ID.

**Prerequisites (shared):**

- TYPO3 site up (e.g. DDEV `aiuniverse-14`), `ns_t3af` MCP enabled, OAuth or stdio MCP with a **backend admin** user (or a user with `tables_modify` on `tt_content` and read on test pages).
- At least one **real page** `pageId` with content and a known valid `CType` (e.g. `text`) and valid `colPos` (usually `0` or `1` — confirm via `table_schema` for `tt_content` field `colPos` on that page if needed).
- MCP tools available: `records_apply`, `file_upload_prepare`, `content_list`, `content_search`, `table_schema`.

---

## TC-x204 (high) — Invalid CType / colPos caught in preflight

**Ticket:** x204  

**Steps:**

1. Call `records_apply` with `dryRun=true`, `strict=true`, `data` creating one new `tt_content` on a valid `pageId`:
   - `CType`: `nonexistent_ctype_test`
   - `header`: `QA x204 strict`
   - `pid`: `<pageId>`
2. Call the same payload with `strict=false` (still `dryRun=true`).
3. Optional (if layout columns are known): repeat (1)–(2) with valid `CType` but `colPos`: `987` (or another value not in the page backend layout).

**Expected results:**

| Step | Expected |
|------|----------|
| 1 | **Fails validation** before write: `ok` is not true; problems mention disallowed field value(s); `CType` listed in `fields`. No row persisted. |
| 2 | **Succeeds dry-run** (`ok: true` or equivalent success) **without** saving invalid `CType`; response includes **`ignored`** (or equivalent) listing `CType` for that NEW id. Cleaned datamap must **not** contain `nonexistent_ctype_test`. |
| 3 (optional) | Same pattern for `colPos`: strict → refuse; non-strict → `colPos` in ignored, not in applied field set. |

---

## TC-x206 (high) — Private `records_apply` payload (not public fileadmin)

**Ticket:** x206  

**Steps:**

1. `file_upload_prepare` with `purpose=records_apply_payload` (ignore `storageUid` / `directoryPath` for this purpose).
2. HTTP PUT the JSON body to `uploadUrl` with `Authorization: Bearer <uploadToken>`. Use `?fileName=payload.json` if the prepare response includes it; bare PUT with only the bearer token is also valid (defaults to `payload.json`):
   ```json
   {"data":{"tt_content":{"NEWqa":{"pid":<pageId>,"header":"QA x206","CType":"text"}}}}
   ```
3. Note `payloadId` from the upload JSON response (not a `sys_file` `publicUrl`).
4. `records_apply` with `fromPayloadId=<payloadId>`, `data={}`, `cmd={}`, `bulk=[]`, `dryRun=true`, `strict=true`.
5. Try `records_apply` again with the **same** `fromPayloadId`.
6. Confirm the payload JSON is **not** served under fileadmin / public media URL (no guessable URL for the batch content).

**Expected results:**

| Step | Expected |
|------|----------|
| 1–2 | Upload succeeds; response includes `purpose: records_apply_payload`, `payloadId`, **no** public file URL for the JSON. |
| 4 | Dry-run succeeds; validation passes for valid `CType`. |
| 5 | **Second call fails** — payload not found or already used; nothing written. |
| 6 | No web-accessible copy of the payload JSON in fileadmin (private `var/transient` channel only). |

---

## TC-x1zx (normal) — `content_list` default fields are lightweight

**Ticket:** x1zx  

**Steps:**

1. `content_list` with `pid=<pageId>`, default `limit`/`offset`, **do not** pass `selectFields`.
2. Inspect each returned content row keys.

**Expected results:**

| Step | Expected |
|------|----------|
| 1–2 | Rows include structural fields: at least `uid`, `pid`, `CType`, `colPos`, `sorting`, `header` (when readable). |
| | Rows **do not** include full `bodytext` by default (unless the site has no `header` and dev regressed — flag if `bodytext` is the only label field returned). |
| | Response size reasonable for a page overview (no full RTE HTML for every element by default). |

---

## TC-x200 (normal) — `content_search` respects `limit` (MCP caller)

**Ticket:** x200  

**Steps:**

1. `content_search` with a search term that matches **multiple** elements on the site (or on `pid=<pageId>` if scoped), `limit=1`, `offset=0`.
2. Count items in the result array (e.g. `records` / `items` — use the tool’s actual JSON shape).

**Expected results:**

| Step | Expected |
|------|----------|
| 1–2 | **Exactly one** record returned (not forced to 5). |
| | Document actual `limit` used in metadata if the tool echoes it. |

**Note:** Backend **AI Agent** may still bump `content_search` limit to minimum 5; this case targets **direct MCP clients** only.

---

## TC-x203 (normal) — `table_schema` smaller, filtered, readable labels

**Ticket:** x203  

**Steps:**

1. `table_schema` with `tableName=tt_content` (full default).
2. `table_schema` with `tableName=tt_content`, `compact=true`.
3. `table_schema` with `tableName=tt_content`, `fields=header,CType,colPos`, `compact=true`.
4. `table_schema` with `tableName=tt_content`, `types=select`, `compact=true`.

**Expected results:**

| Step | Expected |
|------|----------|
| 1 | Schema returned; field labels are **translated human text** where possible, not raw `LLL:EXT:...` for common core fields. |
| 2 | Payload **smaller** than (1): no long per-field descriptions; compact flag reflected when present. |
| 3 | Only requested fields (plus any internal sort hint if documented); still valid JSON. |
| 4 | Only fields whose `type` is `select` (subset of tt_content). |
| | No regression: tool still usable to discover `CType` allowed values before writes. |

---

## Parallel execution

```text
Run TC-x204, TC-x206, TC-x1zx, TC-x200, TC-x203 in parallel.
Do not serialize unless a shared resource limit forces it (same OAuth token rate limit is OK).
Aggregate: PASS/FAIL per ticket + one-line evidence (HTTP snippet or tool JSON excerpt).
```

---

## QA agent prompt (copy for next batches too)

Use this as the **system or first user message** for the QA agent after dev handoff:

```text
You are QA for ns_t3af MCP 2.0.1. Read the batch file:
packages/ns_t3af/context/qa/MCP-2.0.1-batch-1.md

Execute every test case (TC-x204, TC-x206, TC-x1zx, TC-x200, TC-x203) in PARALLEL against the environment the dev team specifies (DDEV URL + MCP transport + test pageId).

Rules:
- One verdict per ClickUp subtask ticket id (x204, x206, x1zx, x200, x203): PASS or FAIL.
- FAIL requires: steps run, actual vs expected, minimal JSON/log excerpt (no secrets).
- Do not mark PASS on dry-run only for x206 step 4 if step 5 (reuse payloadId) was skipped.
- x200 applies to direct MCP only; if you only test via AI Agent, note "agent path not in scope" and still run MCP direct.
- Do not update ClickUp to done/release; report results back to the human for commit approval.

Output format:
## Batch 1 summary
| Ticket | Result | Evidence |
| x204 | PASS/FAIL | … |
…

## Blockers
…

## Suggested retest after commit
SHA: (wait for dev commit)
```

For **batch 2+**, duplicate this file as `MCP-2.0.1-batch-N.md`, keep the same parallel rule and agent prompt block (update batch number and ticket list).
