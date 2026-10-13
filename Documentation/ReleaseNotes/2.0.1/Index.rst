.. include:: ../../Includes.txt

.. _release-notes-2-0-1:

======================
2.0.1  12 October 2026
======================

Patch release on the 2.0 line. This package branch also carries AI Agent QA
fixes that land in the same release notes so the two workstreams stay
distinguishable.

Here is the list of features and updates introduced in this release:

.. code-block:: none

   12-10-2026 [BUGFIX] MCP records_apply: defer DataHandler cache flush until after commit so TRUNCATE cannot end the transaction (sys_category / dryRun)
   12-10-2026 [BUGFIX] MCP records_apply: refuse dryRun when the batch includes sys_category (interim: staging must prove deferred flush on MariaDB; remove the refusal in a follow-up once check #1 passes)
   12-10-2026 [BUGFIX] MCP records_apply: if the DB transaction is gone after DataHandler, return ok:false with new ids (partial write), do not pretend to roll back
   12-10-2026 [BUGFIX] MCP records_apply / write_table: attach the TYPO3 site on TYPO3_REQUEST for page writes (shared McpSiteRequestBootstrap); warn when a page has no site instead of failing the batch
   12-10-2026 [BUGFIX] MCP file_upload_from_url: pinned-DNS downloads must not set Guzzle stream together with curl (regression guard + unit test; 2.0.0 still combined them)
   12-10-2026 [FEATURE] MCP pages_search / content_search / record_search: selectFields (same semantics as content_list)
   12-10-2026 [FEATURE] MCP pages_get / content_get: selectFields may include tstamp, crdate, cruser_id
   12-10-2026 [BUGFIX] MCP uploads: allow woff/woff2 in mediafile_ext; map application/octet-stream and application/font-woff* to font/woff and font/woff2; surface TYPO3 consistency errors; file_upload_from_url failures throw (isError)
   12-10-2026 [TASK] MCP initialize serverInfo.version reports 2.0.1
   12-10-2026 [TASK] AI Agent QA (separate commits on feature/ai-agent): attachment handling, SEO/page-structure fixes, news guard, and related editor UX

Deferred (planned for 2.1+)
===========================

Not in 2.0.1 — track against the MCP 2.0 handoff checklist:

* D5 ``page_render_check`` (server-side only; edit rights; no preview URL in the reply)
* D8 ``pages_tree`` ``sysLanguageUid`` default 0
* D9 ``table_schema`` field / CType filters
* D10 colPos / CType vs content_defender — warn by default, refuse only in ``strict``
* D11 ``cmd localize`` page uid in ``copied`` / hidden state
* P2 handoff items 1–14 (metadata, workspace stage, pages_move, file atomicity, error codes, …)

Ops (not code): D4 draft workspace for MCP users; D13 demo WAF GET vs PUT.
