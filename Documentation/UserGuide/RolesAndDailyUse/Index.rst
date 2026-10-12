.. include:: ../../Includes.txt


.. _usage:

===================
Roles and Daily Use
===================

Practical guidance for editors, administrators, and stakeholders using
**AI Foundation** (``EXT:ns_t3af``) in daily workflows.

For administrators
------------------

Daily responsibilities:

* Keep provider API keys valid in :guilabel:`AI Foundation > AI Providers`.
* Maintain the default provider and model selections.
* Monitor usage statistics across all configured AI providers in
  :guilabel:`AI Foundation > AI Usage` and the Dashboard.
* Keep credentials and access permissions under control.

Admin checklist
~~~~~~~~~~~~~~~

1. Confirm a default provider is enabled in **AI Providers**.
2. Run **Test connection** on critical provider rows.
3. Test extension-dependent AI features in your connected modules.
4. Review provider usage statistics regularly (requests, tokens, and
   consumption) for cost and rate control.

For editors
-----------

Editors usually do not configure providers directly. They use the AI agent
of AI Foundation and the features of extensions that depend on AI Foundation.

Working with the AI agent:

* Open the agent with :guilabel:`Ask AI Agent` in the backend
  toolbar or with :kbd:`Ctrl/Cmd+Shift+K`, then describe the task in plain
  language.
* Read requests are answered right away. Changes appear as cards with the
  current and the new values — review them, then click :guilabel:`Apply` or
  :guilabel:`Cancel`. Deleting needs a second click.
* Use :guilabel:`Undo` on an applied card if the result is not what you
  wanted. See :ref:`Ask AI agent <ask-ai-agent>`.

When AI features fail in a backend module:

* Retry once.
* Capture exact error text.
* Inform the administrator with module and page context.

For non-technical stakeholders
------------------------------

AI Foundation helps organizations by:

* Reducing duplicated AI integration work across extensions.
* Centralizing provider and model governance.
* Improving consistency of AI capabilities across teams.

What to expect operationally
----------------------------

* Some providers have rate limits and temporary outages.
* Model behavior can differ between providers and versions.
* Usage statistics cover configured AI providers from a centralized view
  and may be cached (not always real-time).

Known boundaries
----------------

* No standalone frontend plugin is provided by this extension.
* The in-backend AI agent (:ref:`Ask AI agent <ask-ai-agent>`) is part of
  AI Foundation. Many content features (SEO, translation, page and content
  generation, alt texts) still come from dependent extensions and appear in
  the agent as additional tools.
