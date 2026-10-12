.. include:: ../Includes.txt


.. _ask-ai-agent-tab-audit:

==========
Tab: Audit
==========

**Path:** :guilabel:`AI Foundation > AI Agent > Audit`

**What it shows:** what the AI agent records, and the most recent tool
calls.

*  **What is recorded** — tool invocations (metadata) and turn correlation
   IDs are stored. The full conversation transcript and raw prompt and
   response bodies are **not** stored in the audit log.
*  **Recent agent tool invocations** — time, tool, result
   (:guilabel:`Success` or :guilabel:`Failed`), correlation ID, and a hash
   of the arguments. No payloads are stored.

**What you do here:**

#. Use the :guilabel:`Correlation` value to group all tool calls of one
   request.
#. Investigate rows with the result :guilabel:`Failed`.
#. For requests, errors, and costs across all AI features, open
   :ref:`AI Usage & Logs <ai-usage-and-logs>`.
