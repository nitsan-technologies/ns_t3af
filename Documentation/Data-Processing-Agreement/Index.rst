.. include:: ../Includes.txt


.. _data-processing-agreement:
.. _dpa-gdpr:
.. _privacy:

===========================================================================
Data Processing Agreement (DPA) & General Data Protection Regulation (GDPR)
===========================================================================

This page describes GDPR-related questions about providers, prompts, usage,
and logs. It covers **technical data-management capabilities** in
**AI Foundation** (``EXT:ns_t3af``).

AI Foundation is the shared backend AI layer. It has **no frontend visitor
plugin**. Visitors only hit AI through child extensions (T3AS, T3AC, T3AA):

* **T3AS / T3AC** — yes, frontend search and chatbot
* **T3AA** — accessibility overlay; different processing than LLM chat

Related configuration:

* :ref:`AI Providers <ai-providers>`
* :ref:`AI Usage & Logs <ai-usage-and-logs>`
* :ref:`T3Planet Credits <t3planet-credit-system>`
* :ref:`MCP Server <mcp-server>`
* :ref:`AI Context <ai-context>`

.. _dpa-product-controls:

Product controls
================

* **Your Own API Keys (BYOK)** — default. AI traffic: customer server →
  configured provider. T3Planet is not on this path. See
  :ref:`AI Providers <ai-providers>`.
* **T3Planet Credits** — optional. Billable calls go to T3Planet
  (``/API/AI/*``). Prompts and inputs may be stored in T3Planet billing
  records (``meta_json``) for support, fraud prevention, and cost
  reconciliation. See :ref:`T3Planet Credits <t3planet-credit-system>`.
* **AI Logs cleanup** — ``t3af:ai-logs:cleanup``, default **90 days**, not
  created automatically. See :ref:`AI Usage & Logs <ai-usage-and-logs>`.
* **MCP cleanup** — ``t3af:mcp:cleanup`` (expired OAuth tokens/codes and
  stale MCP sessions). Not created automatically. See
  :ref:`MCP Server <mcp-server>`.

.. _dpa-provider-mode:

AI provider mode (BYOK vs Credits)
==================================

Your Own API Keys (BYOK) — default
----------------------------------

AI requests go from the customer TYPO3 server to the AI provider you
configure, using your own API keys. **T3Planet is not in the AI data path.**

Configure keys in :ref:`AI Providers <ai-providers>`.

AI Foundation makes **no product licence call** and sends T3Planet nothing
for AI Foundation activation.

Premium extensions that depend on AI Foundation use ``ns_license`` for their
own licence management. That is separate from the AI request path.

T3Planet Credits — optional
---------------------------

When Credits is enabled, billable calls (``complete``, ``stream``,
``embed``, and related billed features) go to the T3Planet composer API
(``/API/AI/*``). Enable this only from
:ref:`T3Planet Credits configuration <t3planet-credits-configuration>`.

What may be transmitted in Credits mode:

* Site domain and optional contact name/email (trial token identity)
* ``feature_key``, ``request_uuid``, and billing metadata
* Prompts and model inputs **may be stored** in T3Planet billing records
  (``meta_json``) for support, fraud prevention, and cost reconciliation —
  see T3Planet terms and DPA

More details: :ref:`T3Planet Credits <t3planet-credit-system>`.

.. _dpa-logging-privacy:

Logging privacy level
=====================

On each AI provider record: **standard** / **reduced** / **none** (optional
UserTSconfig ``nst3af.privacyLevel``; the strictest of provider + user
applies). Configure the provider field in
:ref:`AI Providers <ai-providers>`.

Local logs never store prompt or response text. Privacy level controls
**local AI Usage telemetry only**. It does **not** redact, strip, or block
prompts, brand context, or documents sent to the AI provider.

..  list-table:: Local AI Usage (``tx_nst3af_request_log``)
   :header-rows: 1
   :widths: 22 78

   * - Level
     - What is stored
   * - **standard** (default)
     - Row written: tokens, timing, SHA-256 prompt fingerprint, technical
       ``raw_meta``. Prompt and response text are not stored.
   * - **reduced**
     - Row written: counters and identifiers only (tokens, timing, cost,
       provider, feature, user). Fingerprint and ``raw_meta`` stripped.
       Prompt and response text are not stored.
   * - **none**
     - No request-log row.

Inspect written rows in :ref:`AI Usage & Logs <ai-usage-and-logs>`.

.. _dpa-ai-logs-cleanup:

AI Logs cleanup
===============

..  code-block:: bash
    :caption: Delete AI Foundation sys_log entries older than 90 days

    vendor/bin/typo3 t3af:ai-logs:cleanup --days=90

* Cleans AI Foundation **system log** (``sys_log``) channels shown in
  :ref:`AI Usage & Logs <ai-usage-and-logs>`
* Default **90 days** when scheduled
* The task is **not created automatically**

**AI Usage** rows (``tx_nst3af_request_log``) have **no automatic purge** by
default (delete in the backend). Provider field ``retention_days_override``
is stored but **not wired** to cleanup.

MCP OAuth tokens, codes, and stale sessions use a separate command:

..  code-block:: bash
    :caption: Remove expired MCP OAuth tokens and stale sessions

    vendor/bin/typo3 t3af:mcp:cleanup

See :ref:`MCP Server <mcp-server>`.

.. _dpa-considerations:

Data Processing Agreement (DPA) considerations
==============================================

Administrators should consider and document:

* Local request logging vs outbound AI provider transfer (independent)
* BYOK vs Credits (who is a processor) — see
  :ref:`T3Planet Credits <t3planet-credit-system>`
* Retention of AI Logs vs AI Usage vs visitor history (three different
  stores) — see :ref:`AI Usage & Logs <ai-usage-and-logs>`
* Brand context and AI prompts stored in TYPO3 and injected into provider
  calls — see :ref:`AI Context <ai-context>` and
  :ref:`AI Prompts <ai-prompts>`
* Provider contract / DPA must cover no model training

.. _dpa-questions:

Data Processing Agreement (DPA) questions
=========================================

Does AI Foundation collect frontend visitor information?
--------------------------------------------------------

**No.** AI Foundation is a backend foundation. It does not provide a public
plugin and does not store visitor IP addresses, cookies, or frontend-user
accounts as dedicated fields.

What is stored in AI Usage?
---------------------------

Table ``tx_nst3af_request_log``, typically:

* Provider, extension, feature, request source
* Token counts, latency, success/failure
* Backend user (``0`` for anonymous frontend)
* **SHA-256 prompt fingerprint** — not the full prompt or response
* Technical ``raw_meta`` (adapter type, page id, error message, credits
  request uuid) at standard privacy

See :ref:`AI Usage & Logs <ai-usage-and-logs>` and, for Credits traffic,
:ref:`Credits AI Usage <t3planet-credits-ai-usage>`.

What is stored in AI Logs?
--------------------------

Operational ``sys_log`` lines (extension, channel, message, user). For
anonymous frontend the user is ``0``. See
:ref:`AI Usage & Logs <ai-usage-and-logs>`.

Are IP, session, cookies stored in AI Usage?
--------------------------------------------

**No dedicated visitor IP / session / cookie fields.**

Can logging be reduced?
-----------------------

**Yes.** Privacy level **reduced** = counters and identifiers only; no
fingerprint and no raw metadata. **none** = no AI Usage row.

A user may only tighten logging (UserTSconfig), never loosen a stricter
provider setting. This does not change what is sent to the AI provider.
See :ref:`AI Providers <ai-providers>`.

Retention — AI Logs?
--------------------

Cleanup command available; default **90 days** when scheduled. See
:ref:`AI Logs cleanup <dpa-ai-logs-cleanup>` above.

Retention — AI Usage?
---------------------

**No automatic purge** by default. Delete in the backend. Provider
``retention_days_override`` is stored but **not wired** to cleanup.

What is sent to the AI provider (BYOK)?
---------------------------------------

Prompt / editorial or search payload built by the child extension, plus
system/brand context from :ref:`AI Context <ai-context>`. T3Planet is not
in the path.

What is sent if Credits is on?
------------------------------

Feature key, request metadata, site domain, optional contact;
prompts/inputs may be stored by T3Planet for billing. Do not enable Credits
for a minimisation setup. See :ref:`T3Planet Credits <t3planet-credit-system>`.

MCP?
----

If enabled, MCP clients can access configured TYPO3 tools. Treat as a
separate access-control topic, not visitor Usage Analytics. See
:ref:`MCP Server <mcp-server>` and :ref:`MCP Tools <mcp-tools>`.
