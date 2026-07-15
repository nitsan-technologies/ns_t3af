.. include:: ../../Includes.txt

.. _ai-permissions:

=====================
Access and Governance
=====================

Purpose
-------

**Guardrails** for AI on multi-user TYPO3 instances. Governance answers who may use AI, what they may do, how much they may consume, and what gets logged.

**Path:** AI Foundation → AI Permissions

`AI Foundation AI Permissions Demo <https://app.supademo.com/embed/cmrbpvc5y0g0vqmo5l30iq6mc?utm_source=link>`__

Governance is **off by default**. Enable when your team grows beyond a few admins.

Overview
--------

* **Who may use a provider?** — Backend group restriction per provider
* **What capabilities are allowed?** — Permission flags per group
* **How much may a user consume?** — Monthly budgets
* **How fast may they request?** — Rate limits

1. Provider access
------------------

On each provider → **Access** tab → select allowed backend groups.

* Empty list = all groups may use the provider
* Admins always bypass group restrictions

2. Capabilities
---------------

Per-group permissions: chat, streaming, embeddings, vision, tool use.

Admins bypass capability checks. Configure in backend group settings.

3. Budgets (UserTSconfig)
-------------------------

Example:

.. code-block:: python

   ns_t3af.budget.monthly.tokens = 500000
   ns_t3af.budget.monthly.cost = 50

**No admin bypass** — budgets apply to everyone including admins.

4. Rate limits
--------------

Example:

.. code-block:: python

   ns_t3af.rateLimit.requestsPerMinute = 10

Prevents accidental bulk loops from scripts or misconfigured extensions.

Privacy levels
--------------

* **Minimal** — Metadata only (timestamp, user, extension)
* **Standard** — Metadata plus summary
* **Full** — Full prompt and response text (use with GDPR care)

Choose **Minimal** or **Standard** for production unless legal requires full audit trails.

CLI and scheduler note
----------------------

When no backend user is logged in (cron jobs, CLI), governance user checks are skipped. Plan scheduler tasks accordingly. Do not expose unrestricted CLI on shared servers.

When to enable governance
-------------------------

* More than five backend users use AI features
* Editors share one TYPO3 instance across departments
* Compliance requires audit trails
* Monthly AI spend must stay within a fixed budget

Scenario: editor group with SEO only
------------------------------------

1. Restrict premium provider to admin group on provider **Access** tab
2. Allow editor group chat capability only (no tool use for MCP)
3. Set monthly token budget for editors
4. Use **Standard** privacy level for GDPR-friendly logs

See :ref:`AI Usage & Logs <ai-usage-and-logs>` to monitor after changes.
