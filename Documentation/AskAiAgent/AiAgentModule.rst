.. include:: ../Includes.txt


.. _ask-ai-agent-tabs:

====================
AI Agent module tabs
====================

**Path:** :guilabel:`AI Foundation > AI Agent`

The :guilabel:`AI Agent` module shows how the AI agent is configured on this
installation. It has five tabs. Most of the information is read-only; the
values come from the installed tools and from the backend user group
settings in :guilabel:`AI Foundation > AI Permissions`.

#. Open :guilabel:`AI Foundation` in the module menu.
#. Click :guilabel:`AI Agent` in the module navigation.
#. Select one of the five tabs (number 4 in the screenshot under
   :ref:`ask-ai-agent-where`). The summary below introduces each tab;
   each tab is described on its own page.

.. tabs::

   .. tab:: Overview

      The current state of the AI agent: tool counters
      (:guilabel:`Registered tools`, :guilabel:`Executable now`,
      :guilabel:`Locked`, :guilabel:`Contributing extensions`), the
      :guilabel:`Keyboard shortcut` setting, :guilabel:`Availability`,
      :guilabel:`How tools register`, and :guilabel:`Tools by owning
      extension`. See :ref:`ask-ai-agent-tab-overview`.

   .. tab:: Severity policy

      How the AI agent treats **read**, **write**, **destructive**, and
      **unclassified** tools, and one list of tools per severity.
      See :ref:`ask-ai-agent-tab-severity`.

   .. tab:: Governance

      The **control matrix**: monthly credit cap, daily request cap,
      provider allowlist, model override, PII masking, and turn guard,
      with the source of each limit and its effect on AI agent turns.
      See :ref:`ask-ai-agent-tab-governance`.

   .. tab:: Audit

      **What is recorded** and the **recent agent tool invocations**
      (time, tool, result, correlation, arguments hash).
      See :ref:`ask-ai-agent-tab-audit`.

   .. tab:: Demand signals

      **Locked tool activation signals** per extension and tool. Sharing
      with T3Planet is disabled; counts stay on this installation.
      See :ref:`ask-ai-agent-tab-demand`.

.. tip::

   The AI agent **settings** (limits per turn, conversation retention, and the
   conversation list) are not in this module. Open
   :guilabel:`AI Foundation > AI Features`, find the :guilabel:`AI Agent`
   card, and click :guilabel:`Configure`. See
   :ref:`ask-ai-agent-feature-settings`.

.. toctree::
   :maxdepth: 1
   :titlesonly:

   TabsOverview
   TabsSeverity
   TabsGovernance
   TabsAudit
   TabsDemand
