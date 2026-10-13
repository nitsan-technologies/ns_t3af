.. include:: ../../Includes.txt

.. _release-notes-2-0-1:

======================
2.0.1  13 October 2026
======================

Patch release on the 2.0 line. This package branch also carries AI Agent QA
fixes that land in the same release notes so the two workstreams stay
distinguishable.

Here is the list of features and updates introduced in this release:

.. code-block:: none

   12-10-2026 [FEATURE] MCP Tool pages_search / content_search / record_search: selectFields (same semantics as content_list)
   12-10-2026 [FEATURE] MCP Tool pages_get / content_get: selectFields may include tstamp, crdate, cruser_id
   12-10-2026 [SECURITY] MCP Tool record_search / record_count / table_schema: be_users, be_groups and be_sessions are admin-only
   12-10-2026 [TASK] MCP tool input schemas: additionalProperties false so unknown arguments are rejected
   12-10-2026 [TASK] MCP ErrorHandlingProxy: soft {"error"} tool results become isError true with a numeric code
   12-10-2026 [TASK] MCP initialize serverInfo.version reports 2.0.1
   12-10-2026 [BUGFIX] MCP Tool records_apply: defer DataHandler cache flush until after commit so TRUNCATE cannot end the transaction (sys_category / dryRun)
   12-10-2026 [BUGFIX] MCP Tool records_apply: refuse dryRun when the batch includes sys_category (interim until MariaDB proves deferred flush)
   12-10-2026 [BUGFIX] MCP Tool records_apply: broken TX after DataHandler returns ok:false with new ids (partial write); real runs flush deferred caches, dry runs discard them
   12-10-2026 [BUGFIX] MCP Tool records_apply / write_table: attach TYPO3 site on TYPO3_REQUEST for page writes; warn when a page has no site
   12-10-2026 [BUGFIX] MCP Tool file_upload_from_url: pinned-DNS downloads must not set Guzzle stream together with curl (regression guard + unit test; 2.0.0 still combined them)
   12-10-2026 [BUGFIX] MCP Tool file_upload / file_upload_from_url: allow woff/woff2; MIME + font magic; surface TYPO3 consistency errors; failures throw (isError)
   12-10-2026 [BUGFIX] MCP Tool uploads: SHA-1 dedupe scoped to same folder + matching extension
   12-10-2026 [BUGFIX] MCP /mcp_upload: refusals return numeric code alongside error
   12-10-2026 [BUGFIX] AI Agent: attachment handling (title/uid, alt text, undo, decline/re-offer, AI image vs stock URL, failed download)
   12-10-2026 [BUGFIX] AI Agent: SEO and page-structure (language/overlay, subpage SEO, custom outline, Progress for newest request only)
   12-10-2026 [BUGFIX] AI Agent: drafts in sys_registry so Apply after page reload keeps the next card
   12-10-2026 [BUGFIX] AI Agent: refuse news without EXT:news; image model for generate/variation; strip image_generation from chat-only providers (+ upgrade wizard)
   12-10-2026 [BUGFIX] AI Agent: editor UX (show/hide vs delete, element pick, provider fallback notice, file-mount refusals)
   13-10-2026 [RELEASE] Release patch version 2.0.1

Deferred (planned for 2.1+)
===========================

Not in 2.0.1 — track against the MCP 2.0 handoff checklist:

* D5 ``page_render_check`` (server-side only; edit rights; no preview URL in the reply)
* D8 ``pages_tree`` ``sysLanguageUid`` default 0
* D9 ``table_schema`` field / CType filters
* D10 colPos / CType vs content_defender — warn by default, refuse only in ``strict``
* D11 ``cmd localize`` page uid in ``copied`` / hidden state
* Remaining P2 handoff items (metadata, workspace stage, pages_move, file atomicity, …). Structured error codes and ``additionalProperties: false`` shipped in 2.0.1.

Ops (not code): D4 draft workspace for MCP users; D13 demo WAF GET vs PUT.
