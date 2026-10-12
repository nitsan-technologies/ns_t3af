.. include:: ../Includes.txt


.. _ask-ai-agent-tab-governance:

===============
Tab: Governance
===============

**Path:** :guilabel:`AI Foundation > AI Agent > Governance`

**What it shows:** the limits that apply to AI agent turns, and where each
limit is configured.

.. list-table::
   :header-rows: 1
   :widths: 25 75

   *  - Control
      - Effect on the AI agent
   *  - **Monthly credit cap**
      - Blocks turns when the group's monthly credits are used up.
   *  - **Daily request cap**
      - Blocks turns after the daily limit per backend user.
   *  - **Provider allowlist**
      - Rejects turns that request a provider that is not allowed.
   *  - **Model override**
      - Rejects a requested model when model override is not permitted.
   *  - **PII masking**
      - Redacts email addresses and phone numbers in answers.
   *  - **Turn guard**
      - Built into the AI agent: warns at 20 tool calls and stops the turn
        at 40 tool calls.

**What you do here:** the tab is read-only. To change a limit:

#. Open :guilabel:`AI Foundation > AI Permissions`.
#. Select the backend user group.
#. In step :guilabel:`Limits`, set the caps, the provider allowlist, model
   override, and PII masking.
#. Under :guilabel:`AI Agent tools`, optionally enable
   :guilabel:`Read-only agent` (answers questions, prepares no changes) or
   enter :guilabel:`Blocked tools` as a comma-separated list, for example
   ``file_delete,cache_clear``. The wildcard ``*`` is supported.
#. Save the group.

.. note::

   When a user belongs to several groups, the strictest setting applies.
   See :ref:`AI Permissions <ai-permissions>`.
