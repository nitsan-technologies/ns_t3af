.. include:: ../Includes.txt


.. _ask-ai-agent-tab-severity:

====================
Tab: Severity policy
====================

**Path:** :guilabel:`AI Foundation > AI Agent > Severity policy`

**What it shows:** how the AI agent treats each tool, based on the severity
the tool declares.

.. list-table::
   :header-rows: 1
   :widths: 20 80

   *  - Severity
      - Behaviour in the AI agent
   *  - **Read**
      - Runs automatically. Results appear as tool-call steps in the chat.
   *  - **Write**
      - Prepares a change card. The change is written through the TYPO3
        DataHandler only after you click :guilabel:`Apply`.
   *  - **Destructive**
      - Red change card with a two-step confirmation
        (:guilabel:`Confirm (1 of 2)`, then :guilabel:`Apply (2 of 2)`).
   *  - **Unclassified**
      - Locked. The tool cannot run until its extension declares a severity.

Below the table, one card per severity lists the tools and the extension
that provides them.

**What you do here:** check which tools can change data before you grant
the AI agent to editors. To block individual tools, use the
settings described in :ref:`ask-ai-agent-tab-governance`.

.. important::

   Write and destructive tools never write on their own. The AI agent
   always shows a change card and waits for your explicit approval.
