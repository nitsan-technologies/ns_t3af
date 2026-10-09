.. include:: ../Includes.txt

.. _ai-agent:

========
AI Agent
========

Purpose
-------

The **AI Agent** is a docked right-edge assistant in the TYPO3 backend. Editors ask
in plain language, review proposed changes as cards, and apply them only when they
confirm. Reads run automatically; writes never go live without approval.

**Path (settings):** :guilabel:`AI Foundation > AI Agent`

**Open the panel:** toolbar :guilabel:`Ask AI Agent`, or **Ctrl/Cmd+Shift+K**
(optional **Ctrl/Cmd+K** in the agent preferences stored in the browser).

Prerequisites
-------------

* TYPO3 **13.4** or **^14.3**, PHP **8.2+**
* A tool-calling :ref:`AI provider <ai-providers>` marked Default, **or**
  :ref:`T3Planet Credits <t3planet-credit-system>` mode
* Backend user must be allowed to use the AI Agent (see :ref:`AI Permissions <ai-permissions>`)

How to use it
-------------

1. Open a backend module (Page, List, Filelist, …). The agent uses that module and
   page (or folder) as context.
2. Open the panel with :guilabel:`Ask AI Agent` or the keyboard shortcut.
3. Ask in the editor's backend language, or use:

   * ``/tool_name`` — run a known tool by name
   * ``@table:uid`` — attach a record to the request
   * starter chips — common tasks for the current module

4. Read tools answer in plain words. Write tools show a **preview card** first.
5. Review the card (fields, target workspace), then :guilabel:`Execute` or
   :guilabel:`Decline`. Applied changes can be undone from the card when the
   agent offers Undo. A stale card is not written over newer edits.

Conversations
-------------

Conversations are stored on the server for each backend user. Opening the agent
resumes the last-viewed conversation for the configured scope (page, module, or
user). Use the conversations list in the panel header to switch, rename, or remove
conversations. The AI provider is fixed for a conversation after the first answer.

Workspace
---------

Confirmed changes follow the workspace selected under
:guilabel:`AI Foundation > MCP Server` (same preference as the MCP server).
**Live** is allowed unless the editor's group has workspace enforcement that
blocks it. The panel shows which workspace a change will go to before you apply.

Permissions and logs
--------------------

The agent only offers tools and fields the editor may use: page tree, tables,
fields, files, and workspaces follow the editor's own backend rights and
:ref:`AI Permissions <ai-permissions>`. A refusal explains why in plain words.
Every refused, hidden, or failed request is written to
:ref:`AI Usage & Logs <ai-usage-and-logs>`.

Credits
-------

In T3Planet Credits mode, the agent calls the chat completions endpoint and
shows remaining credits in the panel. Exhausted credits lock the composer until
you top up. See :ref:`T3Planet Credits <t3planet-credit-system>`.

Settings administrators usually change
--------------------------------------

Configure these under :guilabel:`AI Foundation > AI Agent`:

* ``agentConversationScope`` — which conversation opens automatically
  (``page`` / ``module`` / ``user``)
* ``agentSessionListEnabled`` — show the conversations list
* ``agentSessionListDefaultFilter`` — initial list filter
* ``agentMaxSessionsPerScope`` / ``agentMaxSessionsPerUser`` — retention caps
  (``0`` = unlimited)
* ``agentConversationRetentionDays`` — inactive conversations → trash
* ``agentHistoryTokenBudget`` — how much earlier chat is replayed to the model
* ``agentContinueAfterConfirm`` — continue a multi-step plan after Execute / Decline
* ``agentMaxReadToolsPerTurn`` / ``agentMaxWriteDraftsPerTurn`` — per-turn budgets

Cleanup of trashed conversations and related turn rows:
``t3af:agent:conversations:cleanup``.

Limits
------

* Batch MCP tools ``records_apply`` and ``records_undo`` are **not** offered in
  the AI Agent (one-record approval cards). External MCP clients still use them;
  see :ref:`Batch writes <mcp-tools-batch-writes>`.
* New content elements get a default column when the plan omits one, so layout
  constraints (for example content_defender) can resolve the column on save.

Related
-------

* :ref:`MCP Server <mcp-server>` — workspace selection and external clients
* :ref:`MCP Tools <mcp-tools>` — tool catalogue shared with the agent
* :ref:`AI Permissions <ai-permissions>` — group policy for the agent
* :ref:`Release notes 2.0.0 <release-notes-2-0-0>` — when the agent was introduced
