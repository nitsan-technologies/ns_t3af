.. include:: ../../Includes.txt

.. _release-notes-2-0-0:

========================
2.0.0  30 September 2026
========================

Here is the list of features and updates introduced in this release:

.. code-block:: none

   30-09-2026 [BREAKING] Drop TYPO3 12 support; raise floor to TYPO3 13.4 / 14.3
   30-09-2026 [BREAKING] symfony/ai-agent pinned to ~0.13.0 (experimental, no BC promise)
   30-09-2026 [TASK] Remove TYPO3-12-only dual code paths (StandaloneView Fluid views,
              legacy icon size strings, page-tree v12 component id, TYPO3-11 mailer branch)
   06-10-2026 [FEATURE] MCP records_apply: create, update, delete and move many records across tables in one atomic call
   06-10-2026 [FEATURE] MCP records_apply: bulk shorthand, dryRun, strict/non-strict, append ordering, up to 500 records
   06-10-2026 [FEATURE] MCP records_apply: requestId makes a retried call safe (new table tx_nst3af_mcp_idempotency)
   06-10-2026 [FEATURE] MCP records_apply: fromFile reads the batch from an uploaded .json file (up to 10 MB)
   06-10-2026 [FEATURE] MCP records_undo: take a whole batch back by its batchId
   06-10-2026 [FEATURE] MCP: write_table and the generated *_delete_batch / *_update_batch / *_move_batch tools run
              on the records_apply engine (one transaction, all or nothing)
   06-10-2026 [FEATURE] MCP: records written by an MCP client are recorded in the AI Label module (setting mcpMarkWritesAsAi)
   06-10-2026 [TASK] t3af:mcp:cleanup also removes expired records_apply request ids
   06-10-2026 [TASK] New settings: mcpMarkWritesAsAi, mcpIdempotencyTtlHours
   30-09-2026 [RELEASE] Release major version 2.0.0

Upgrade notes
=============

TYPO3 12 is no longer supported. TYPO3 12 is ELTS-only since April 2026, and
this extension's AI Agent feature depends on ``symfony/ai-agent`` (requires
PHP 8.2+ / Symfony 7.3+), which does not fit typical TYPO3-12-era stacks.

- If you run TYPO3 12, stay on the ``1.2.x`` line (``composer require
  "nitsan/ns-t3af:^1.2"``). It keeps receiving bugfixes and security updates.
- If you run TYPO3 13.4 or 14.x, upgrade normally via Composer. No configuration
  migration is required — only the supported TYPO3/PHP version floor changed.
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
* The generated ``*_delete_batch``, ``*_update_batch`` and ``*_move_batch`` tools are now all-or-nothing and take at
  most 500 records per call.
* Records created or changed by an MCP client are marked as AI-involved in the AI Label module. Disable this with
  the setting ``mcpMarkWritesAsAi``.
* ``records_apply`` and ``records_undo`` are not offered to the AI Agent (its approval flow handles one record at a
  time). Changes the Agent applies after your approval are still written by the Agent itself and are not part of
  these batches.
