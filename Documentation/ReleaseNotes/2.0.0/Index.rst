.. include:: ../../Includes.txt

.. _release-notes-2-0-0:

=====================
2.0.0  9 October 2026
=====================

Here is the list of features and updates introduced in this release:

.. code-block:: none

   30-09-2026 [BREAKING] Drop TYPO3 12 support; raise floor to TYPO3 13.4 / 14.3
   30-09-2026 [BREAKING] symfony/ai-agent pinned to ~0.13.0 (experimental, no BC promise)
   06-10-2026 [BREAKING] MCP write_table appends a new record after the existing ones (it used to put it on top); the generated
              *_delete_batch / *_update_batch / *_move_batch tools are all-or-nothing and take at most 500 records (see Behaviour changes)
   30-09-2026 [TASK] Remove TYPO3-12-only dual code paths (StandaloneView Fluid views,
              legacy icon size strings, page-tree v12 component id, TYPO3-11 mailer branch)
   01-09-2026 [FEATURE] AI Agent: backend assistant in a docked right-edge panel (Ctrl/Cmd+Shift+K), with chat,
              conversations list, starter chips and plain-language answers in the editor's backend language
   01-09-2026 [FEATURE] AI Agent: ask in natural language, use /tool commands or attach records with @table:uid
   18-09-2026 [FEATURE] AI Agent: changes are shown as a card first (preview) and written only after you apply them;
              applied changes can be undone and a stale change is not written over newer edits
   18-09-2026 [FEATURE] AI Agent: finds the right tool for a request from the tools the editor's group may use
   24-09-2026 [FEATURE] AI Agent: conversations are stored on the server, one running turn per backend user,
              provider fixed per conversation
   25-09-2026 [FEATURE] AI Agent: uses the workspace selected in the MCP Server module (Live is allowed unless the
              group's workspace enforcement blocks it); the panel shows which workspace a change goes to
   28-09-2026 [FEATURE] AI Agent: plan progress shown while a request has several steps
   29-09-2026 [FEATURE] AI Agent: runs on T3Planet Credits through the chat completions endpoint
   06-10-2026 [FEATURE] MCP records_apply: create, update, delete and move many records across tables in one atomic call
   06-10-2026 [FEATURE] MCP records_apply: bulk shorthand, dryRun, strict/non-strict, append ordering, up to 500 records
   06-10-2026 [FEATURE] MCP records_apply: requestId makes a retried call safe (new table tx_nst3af_mcp_idempotency)
   06-10-2026 [FEATURE] MCP records_apply: fromFile reads the batch from an uploaded .json file (up to 10 MB)
   06-10-2026 [FEATURE] MCP records_undo: take a whole batch back by its batchId
   06-10-2026 [FEATURE] MCP: write_table and the generated *_delete_batch / *_update_batch / *_move_batch tools run
              on the records_apply engine (batch tools all-or-nothing; write_table file fields after commit)
   06-10-2026 [FEATURE] MCP: records written by an MCP client are recorded in the AI Label module (setting mcpMarkWritesAsAi)
   06-10-2026 [TASK] t3af:mcp:cleanup also removes expired records_apply request ids
   06-10-2026 [TASK] New settings: mcpMarkWritesAsAi, mcpIdempotencyTtlHours
   07-10-2026 [FEATURE] Credits: clear messages for exhausted credits and other credit errors (EN/DE, with model and parameter);
              one retry after a temporary upstream timeout or 5xx; a redacted (content_removed) reply shows an info message
              in the AI Agent instead of an error
   07-10-2026 [BUGFIX] Credits: recover from token_ip_conflict on Activate and Refresh token
   08-10-2026 [FEATURE] AI Agent: create a page before or after a named page, and move a page after another one
   08-10-2026 [FEATURE] AI Agent: show or hide the conversations list
   08-10-2026 [FEATURE] AI Agent: only offers what the editor may do; the page tree, tables, fields, files and
              workspaces follow the editor's own permissions, and a refusal says why in plain words
   08-10-2026 [FEATURE] AI Agent: every refused, hidden or failed request is written to the AI logs
   08-10-2026 [FEATURE] MCP: tabs of the MCP module are grouped in a navigation group
   08-10-2026 [BUGFIX] MCP: the stdio transport no longer uses STDIN/STDOUT as buffered defaults
   08-10-2026 [BUGFIX] MCP: stdio uses the workspace chosen in the MCP Server module when -w is not given (it used to start in
              Live), and no longer saves the workspace to the backend user, so the editor's own session is not moved
   09-10-2026 [FEATURE] AI Agent: new content elements get a column, so content_defender does not warn on save
   09-10-2026 [FEATURE] MCP records_apply: a text field with one run of more than 256 KB without a space is refused
              with a clear message, because TYPO3 cannot index it
   09-10-2026 [SECURITY] MCP: write_table and records_apply refuse a table or field the backend user may not edit
   09-10-2026 [SECURITY] MCP Server settings (mode, advanced settings, scopes, IP allowlist, mTLS, analytics export,
              health ping) need an administrator, or the AI Foundation module with the MCP Server tab visible
   09-10-2026 [SECURITY] MCP Server module page no longer issues a personal token to a user without the MCP Server tab
   09-10-2026 [SECURITY] cache_clear: scope "pages" (all page caches) needs options.clearCache.pages = 1 for non-admins,
              as in TYPO3 core
   09-10-2026 [RELEASE] Release major version 2.0.0

Upgrade notes
=============

TYPO3 12 is no longer supported. TYPO3 12 is ELTS-only since April 2026, and
this extension's AI Agent feature depends on ``symfony/ai-agent`` (requires
PHP 8.2+ / Symfony 7.3+), which does not fit typical TYPO3-12-era stacks.

- If you run TYPO3 12, stay on the ``1.2.x`` line (``composer require
  "nitsan/ns-t3af:^1.2"``). It keeps receiving bugfixes and security updates.
- If you run TYPO3 13.4 or 14.3 and newer, upgrade normally via Composer. No settings migration is
  required — the supported TYPO3/PHP version floor changed. If you use MCP clients, read
  *Behaviour changes* below first: ``write_table`` and the generated batch tools behave differently.
- Run :guilabel:`Admin Tools > Maintenance > Analyze Database Structure` once: the new
  table ``tx_nst3af_mcp_idempotency`` stores the ``requestId`` of ``records_apply`` calls.
- If you schedule ``t3af:mcp:cleanup``, it now also deletes expired request ids. Nothing
  else to configure.

MCP batch writes
================

``records_apply`` writes many records across tables in one atomic call and ``records_undo`` takes a whole
batch back. See :ref:`Batch writes <mcp-tools-batch-writes>` for the full description.

Behaviour changes
-----------------

* ``write_table`` now appends a new record after the existing ones on its page (it used to put it on top). A
  negative ``pid`` still places it after that record.
* ``write_table`` refuses a ``pid`` that is not an existing page, and tables stored on a different database
  connection than the TYPO3 default.
* ``write_table`` and the generated batch tools write their audit entry under their own tool name, with the batch
  id, tables, operation counts and field names (never values).
* ``write_table`` scalar/relation writes share the engine transaction; file fields are attached after that commit
  and are not rolled back with it. ``write_table`` does not return a ``batchId``.
* The generated ``*_delete_batch``, ``*_update_batch`` and ``*_move_batch`` tools are now all-or-nothing, take at
  most 500 records per call, and return a ``batchId`` that ``records_undo`` accepts.
* Bulk and ``*_move_batch`` moves keep the order of the request on the target page (when the table has a sort
  field). Hand-written ``cmd`` moves are unchanged.
* Records created or changed by an MCP client are marked as AI-involved in the AI Label module. Disable this with
  the setting ``mcpMarkWritesAsAi``.
* ``records_apply`` and ``records_undo`` are not offered to the AI Agent (its approval flow handles one record at a
  time). Changes the Agent applies after your approval are still written by the Agent itself and are not part of
  these batches.
