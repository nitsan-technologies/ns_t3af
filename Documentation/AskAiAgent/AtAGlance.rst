.. include:: ../Includes.txt


.. _ask-ai-agent-at-a-glance:

===========
At a glance
===========

The essential path, the key warnings, and where to find the settings. The AI
agent always works on **the page you are editing**.

.. list-table::
   :header-rows: 0
   :widths: 25 75

   *  - **1. Select the page**
      - Open the page you want to work on, for example in
        :guilabel:`Content > Layout`, and select it in the page tree.
   *  - **2. Open**
      - Click :guilabel:`Ask AI Agent` in the backend top bar, or press
        :kbd:`Ctrl+Shift+K` (Windows, Linux) / :kbd:`Cmd+Shift+K` (macOS).
        The context line shows the page the AI agent works on.
        See :ref:`ask-ai-agent-where`.
   *  - **3. Ask**
      - Describe the task for this page in your own words and press
        :kbd:`Enter`. Read tools run immediately and answer.
   *  - **4. Approve**
      - Every change to the page is shown as a card marked
        :guilabel:`Preview — nothing changed yet`. Click :guilabel:`Apply`
        to write it or :guilabel:`Cancel` to discard it.
   *  - **5. Check**
      - The card shows :guilabel:`Changes applied` with links and
        :guilabel:`Undo`.
   *  - **Tools**
      - AI Foundation ships 62 tools, for example ``pages_get``,
        ``content_list``, ``pages_search``, ``pages_tree``,
        ``explain_capabilities``, ``ask_clarification`` (read) and
        ``write_table`` (write). Type ``/`` to pick one directly.
   *  - **Settings**
      - :guilabel:`AI Foundation > AI Agent` with five tabs:
        :guilabel:`Overview`, :guilabel:`Severity policy`,
        :guilabel:`Governance`, :guilabel:`Audit`,
        :guilabel:`Demand signals`. See :ref:`ask-ai-agent-tabs`.

.. important::

   *  **Nothing is saved until you click** :guilabel:`Apply`.
   *  An applied change is saved in the **selected workspace**: in
      :guilabel:`Live` it is public immediately; in a draft workspace it
      stays in that workspace until it is published.
   *  **Delete needs two clicks** and cannot be undone.
   *  The AI agent has **your** permissions — never more.
   *  **Page context:** the AI agent works on the page shown in its context
      line, not on the backend as a whole.
