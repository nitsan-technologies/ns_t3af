.. include:: ../Includes.txt


.. _ask-ai-agent-feature-settings:

=====================
AI agent settings
=====================

**Path:** :guilabel:`AI Foundation > AI Features > AI Agent > Configure`

The :guilabel:`AI Agent` feature settings control how much work the AI agent
may do in one turn, how long conversations are kept, and which conversation
opens when an editor clicks :guilabel:`Ask AI Agent`.

.. contents:: On this page
   :local:
   :depth: 1

.. important::

   *  Only **administrators** can change these settings.
   *  The settings apply to the **whole installation** and to every backend
      user who works with the AI agent.
   *  **Nothing is stored until you click** :guilabel:`Save Changes`.

.. _ask-ai-agent-feature-settings-open:

Open the settings
=================

.. figure:: ../Images/ask-ai-agent-be-feature-settings.png
   :alt: TYPO3 backend with AI Universe > AI Foundation selected, the AI Features tab open, the Configure button of the AI Agent card highlighted, and the AI Agent settings drawer open on the right
   :class: with-border with-shadow

   :guilabel:`AI Foundation > AI Features > AI Agent > Configure` in the
   backend. The drawer on the right shows the first settings; scroll down in
   the drawer to see the rest. All 14 settings are described below.

.. rst-class:: bignums

#. **Open AI Foundation**

   In the module menu, click :guilabel:`AI Universe > AI Foundation`.

#. **Select AI Features**

   Click the :guilabel:`AI Features` tab in the module navigation.

#. **Find the AI Agent card**

   Scroll to the :guilabel:`AI Agent` card (subtitle
   *Conversations, workspace, limits*). You can also type *agent* in the
   search field above the cards.

#. **Click Configure**

   The :guilabel:`AI Agent` settings drawer opens on the right side.

#. **Change the fields and save**

   Adjust the fields described below, then click
   :guilabel:`Save Changes`. Click :guilabel:`Cancel` to close the drawer
   without saving.

.. _ask-ai-agent-feature-settings-overview:

All settings at a glance
========================

The numbers follow the order of the fields in the drawer, from top to
bottom.

.. list-table::
   :header-rows: 1
   :widths: 5 50 20 25

   *  - No.
      - Field
      - Default
      - Controls
   *  - 1
      - :guilabel:`Maximum read tools per agent turn`
      - ``10``
      - Work limit per turn
   *  - 2
      - :guilabel:`Maximum write drafts per agent turn`
      - ``10``
      - Work limit per turn
   *  - 3
      - :guilabel:`Show provider thinking in agent responses`
      - On
      - Answer display
   *  - 4
      - :guilabel:`Agent conversation retention (days)`
      - ``90``
      - Clean-up
   *  - 5
      - :guilabel:`Which conversation opens automatically (page, module or one per user)`
      - ``page``
      - Conversations
   *  - 6
      - :guilabel:`Show the conversation list in the agent window`
      - On
      - Conversations
   *  - 7
      - :guilabel:`Initial filter of the conversation list`
      - ``current``
      - Conversations
   *  - 8
      - :guilabel:`Maximum conversations per page / module and user (0 = unlimited)`
      - ``20``
      - Conversation limits
   *  - 9
      - :guilabel:`Maximum conversations per user (0 = unlimited)`
      - ``200``
      - Conversation limits
   *  - 10
      - :guilabel:`Continue the task after the editor confirms or declines a card`
      - On
      - Multi-step tasks
   *  - 11
      - :guilabel:`Earlier messages sent to the AI per turn, in tokens`
      - ``6000``
      - History size
   *  - 12
      - :guilabel:`Tool search for the AI Agent`
      - ``Auto``
      - Tool search
   *  - 13
      - :guilabel:`Embedding provider identifier`
      - *empty*
      - Tool search
   *  - 14
      - :guilabel:`Minimum embedding similarity for tool search results`
      - ``0.0``
      - Tool search

.. tip::

   The defaults work well for most installations. Change a value only when
   you have a concrete reason, for example to reduce AI usage or to keep
   conversations for a shorter time.

.. _ask-ai-agent-feature-settings-limits:

Limits per turn (1–2)
=====================

A **turn** is one request of the editor and the AI agent's answer to it.

.. confval:: Maximum read tools per agent turn
   :name: ask-ai-agent-max-read-tools
   :type: integer
   :default: 10

   How many **read tools** the AI agent may call in one turn, for example to
   load the page, list its content elements, or search the page tree.
   The minimum is ``1``.

   When the limit is reached, the AI agent stops and shows
   :guilabel:`Read tool budget reached (10 per turn). Send another message
   to continue.` Send a new message to let it continue.

.. confval:: Maximum write drafts per agent turn
   :name: ask-ai-agent-max-write-drafts
   :type: integer
   :default: 10

   How many **change drafts** (change cards) the AI agent may prepare in one
   turn. Drafts are only proposals: nothing is saved until the editor clicks
   :guilabel:`Apply`. Set ``0`` to stop the AI agent from preparing any
   change.

   When the limit is reached, the AI agent shows :guilabel:`Write draft
   budget reached (10 per turn). Apply or discard drafts, then continue.`

.. note::

   Lower limits reduce AI usage and credits per request. Higher limits help
   with larger tasks, for example *"Add a header and a text element to each
   subpage"*.

.. _ask-ai-agent-feature-settings-answers:

Answers and history (3–4)
=========================

.. confval:: Show provider thinking in agent responses
   :name: ask-ai-agent-show-thinking
   :type: boolean
   :default: on

   Some AI providers return their reasoning ("thinking") together with the
   answer. When enabled, the AI agent shows this reasoning in its response so
   editors can follow how it reached the result. Disable it for shorter,
   cleaner answers.

.. confval:: Agent conversation retention (days)
   :name: ask-ai-agent-retention-days
   :type: integer
   :default: 90

   Conversations without activity for this number of days are moved to the
   trash and removed **7 days later**. The clean-up runs with the console
   command ``t3af:agent:conversations:cleanup``; schedule it in the
   :guilabel:`Scheduler` module (task *Execute console commands*).

   .. code-block:: bash
      :caption: Run the clean-up manually

      vendor/bin/typo3 t3af:agent:conversations:cleanup

.. _ask-ai-agent-feature-settings-conversations:

Conversations (5–9)
===================

These settings control the conversation list in the AI agent window. See
also :ref:`ask-ai-agent-conversations`.

.. confval:: Which conversation opens automatically (page, module or one per user)
   :name: ask-ai-agent-conversation-scope
   :type: string
   :default: page

   Decides which conversation the AI agent shows when an editor opens it:

   ``page``
      The latest conversation of this **page in this module**. Each page has
      its own conversations. Recommended, because the AI agent works on the
      page you are editing.

   ``module``
      The latest conversation of this **backend module**, for every page.

   ``user``
      **One conversation per user**: the latest conversation, wherever it
      was started.

.. confval:: Show the conversation list in the agent window
   :name: ask-ai-agent-session-list
   :type: boolean
   :default: on

   Shows the conversation list in the AI agent window, so editors can
   switch, rename, and delete conversations. When disabled, editors only see
   the conversation that opens automatically.

.. confval:: Initial filter of the conversation list
   :name: ask-ai-agent-session-list-filter
   :type: string
   :default: current

   The filter that is active when the conversation list opens:

   ``current``
      Only the conversations of **this page** (:guilabel:`This page`).

   ``all``
      The conversations of **all pages** (:guilabel:`All pages`).

   Editors can always change the filter in the list.

.. confval:: Maximum conversations per page / module and user (0 = unlimited)
   :name: ask-ai-agent-max-sessions-scope
   :type: integer
   :default: 20

   How many conversations one user keeps for one page or module (depending on
   setting 5). When a user starts a new conversation beyond this limit, the
   **oldest** conversations are moved to the trash. ``0`` means no limit.

.. confval:: Maximum conversations per user (0 = unlimited)
   :name: ask-ai-agent-max-sessions-user
   :type: integer
   :default: 200

   How many conversations one user keeps in total, across all pages and
   modules. Beyond this limit, the **oldest** conversations are moved to the
   trash. ``0`` means no limit.

.. warning::

   Conversations removed by a limit cannot be restored by the editor. Keep
   the limits high enough for your editors' daily work.

.. _ask-ai-agent-feature-settings-more:

More settings (10–14)
=====================

Scroll down in the drawer to see these settings.

.. confval:: Continue the task after the editor confirms or declines a card
   :name: ask-ai-agent-continue-after-confirm
   :type: boolean
   :default: on

   After the editor clicks :guilabel:`Apply` or :guilabel:`Cancel` on a
   change card, the AI agent continues with the next step of the request
   without a new message. This makes multi-step requests work, for example
   *"Create the page, then add two headers"*. When disabled, the editor must
   send a new message for each step.

.. confval:: Earlier messages sent to the AI per turn, in tokens
   :name: ask-ai-agent-history-token-budget
   :type: integer
   :default: 6000

   How much of the earlier conversation is sent to the AI provider with each
   turn (1 token is about 4 characters). Older messages are left out. A
   higher value gives the AI agent more memory of the conversation but uses
   more credits. Allowed range: ``500`` to ``100000``.

.. confval:: Tool search for the AI Agent
   :name: ask-ai-agent-embedding-source
   :type: string
   :default: auto

   How the AI agent finds the right tool for a request:

   ``auto``
      Uses embeddings of the AI provider when available, otherwise keyword
      search.

   ``provider``
      Always uses embeddings of the AI provider.

   ``none``
      Keyword search only, no embedding requests.

.. confval:: Embedding provider identifier
   :name: ask-ai-agent-embedding-provider
   :type: string
   :default: (empty)

   The AI provider used for tool search embeddings. Leave empty to use the
   default provider from :guilabel:`AI Foundation > AI Providers`.

.. confval:: Minimum embedding similarity for tool search results
   :name: ask-ai-agent-min-similarity
   :type: float
   :default: 0.0

   Tools with a lower similarity score (``0.0`` to ``1.0``) are not offered
   to the AI agent. ``0.0`` keeps all results. Raise it carefully: a high
   value can hide tools the AI agent needs.

.. _ask-ai-agent-feature-settings-next:

Related
=======

*  :ref:`ask-ai-agent-conversations` — start, switch, rename, and delete
   conversations.
*  :ref:`ask-ai-agent-tabs` — status, tools, and governance of the AI agent.
*  :ref:`ask-ai-agent-where` — where the AI agent opens.
