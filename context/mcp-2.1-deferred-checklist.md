# MCP deferred checklist (post-2.0.1)

From Sanjay’s 2026-10-12 handoff. **Not in 2.0.1.** Track for 2.1+.

## Product / security

| ID | Item | Notes |
|----|------|--------|
| D5 | `page_render_check` | Server-side fetch only. Edit rights on the page. Build URL from site config + uid — never caller URL. Return title, canonical, H1/H2, og:image, hreflang, HTTP status. **No preview link in the reply.** |
| D4 | Draft workspace | Ops/product: confirm draft workspace for MCP user on t3planet.de |
| D13 | Demo WAF | Hosting: GET blocked while PUT works for cloud agent |

## Engine / tools

| ID | Item | Notes |
|----|------|--------|
| D8 | `pages_tree` `sysLanguageUid` | Default 0; fix `totalNodes` |
| D9 | `table_schema` filters | `fields` and/or `type`/`CType` → showitem fields only |
| D10 | colPos / CType validation | Warn by default; refuse only when `strict` |
| D11 | `cmd localize` | Page translation uid in `copied`; report/control `hidden` |

## P2 (handoff items 1–14)

1. `write_table` / metadata: append wording (not “first child”)
2. `workspaceId` descriptions: module name, `0` ignored, silent Live fallback
3. `workspace_stage_set`: use cmd `version → setStage`; fix stage labels
4. Dynamic `*_create`/`_update`/…: route via engine or document limits
5. `pages_move beforeUid` skip self
6. Workspace file-reference counter sync on live row
7. Atomic file field replace / `file_reference_remove`
8. `file_list` shared offset for files+folders
9. Upload dedupe scope (mount/folder) — **partially fixed in 2.0.1:** same-folder only + extension/mime guard; revisit mount-wide policy if product wants storage-wide reuse with an explicit flag
10. Docs vs code base64 upload limit
11. Unknown search `op` → refuse, not silent LIKE
12. Structured error codes in tool replies
13. Dry-run auto-increment / undo batchId collision
14. Reject unknown args on `directory_create` / `file_list`

## Acceptance still for 2.1

Handoff check **#4** (hidden page metadata without backend) gates D5.
