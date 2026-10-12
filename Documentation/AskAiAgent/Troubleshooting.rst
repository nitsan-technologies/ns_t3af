.. include:: ../Includes.txt


.. _ask-ai-agent-troubleshooting:

===============
Troubleshooting
===============

**"AI provider is not configured"**
   No provider is available. Add one in :guilabel:`AI Foundation > AI
   Providers` and run :guilabel:`Test connection`.

**"Your current AI provider cannot run tools for natural-language requests"**
   The selected model does not support tool calling. Start a new conversation
   with a tools-capable provider, or use ``/`` and ``@`` to run a tool
   directly.

**"No executable tools are available for your account"**
   Your backend group has no permitted tools. Type ``/`` to see which tools are
   locked, and ask your administrator to check :guilabel:`AI Foundation > AI
   Permissions`.

**The** :guilabel:`Ask AI Agent` **button is missing**
   Your backend group does not have the option :guilabel:`AI Agent access`.
   Ask your administrator to grant it.

**The keyboard shortcut opens Live Search**
   :kbd:`Ctrl/Cmd+K` belongs to Live Search by default. Use
   :kbd:`Ctrl/Cmd+Shift+K`, or change the shortcut in :guilabel:`AI Foundation
   > AI Agent > Overview`. Reload other backend tabs afterwards.

**"The AI Agent is still working on your previous request"**
   Wait until the current answer is complete, or click :guilabel:`Stop`.

Administrators can review requests, errors, and costs in
:ref:`AI Usage & Logs <ai-usage-and-logs>`.
