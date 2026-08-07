.. include:: ../../Includes.txt


.. _t3planet-credits-dashboard:
.. _t3planet-credits-ai-usage:

====================
Dashboard & AI Usage
====================

Monitor credit balance on the Dashboard and verify Credits traffic in AI Usage.

Dashboard
=========

**Path:** :guilabel:`AI Foundation > Dashboard`

.. figure:: ../../Images/t3planet-credits-dashboard.png
   :alt: AI Foundation Dashboard in T3Planet Credits mode with balance, credit burn chart, and spend by extension
   :class: with-border with-shadow

   Credits Dashboard — remaining balance, credit burn over time, spend by
   extension, and period KPIs.

What it shows
-------------

* **Credit balance** — remaining credits from T3Planet
* **Usage charts** — credit use and requests for the selected period
* **KPI cards** — requests, tokens, credits used, success rate
* **Recent requests** — the latest AI requests from the local request log
  for the current period filter (not a fixed “last hour” window). See
  :ref:`AI Usage <t3planet-credits-ai-usage-section>` below for the full
  filterable history.

.. tip::

   Remaining balance comes from T3Planet. Local charts and recent requests come
   from the request log. They are related, but not always identical.

Period filter
-------------

Use Today, Yesterday, 7 / 14 / 30 days, or a custom range. Charts, KPIs, and
the recent-requests list follow this filter.

.. _t3planet-credits-ai-usage-section:

AI Usage
========

**Path:** :guilabel:`AI Foundation > AI Usage`

Use AI Usage to confirm that AI calls ran through T3Planet Credits and to
inspect individual requests.

What to look for
----------------

For Credits traffic, the **provider** value is:

``t3planet_credits``

Column glossary
---------------

* **Module / extension** — which product triggered the call (for example
  T3 AI, AI Search, AI Chatbot)
* **Model** — model used for that request
* **Tokens** — input and output token count
* **Credits** — credits charged for a successful Credits request
* **Status** — success or error for that call
* **Provider** — ``t3planet_credits`` when Credits handled the request;
  otherwise your own API provider id

Filter and search
-----------------

In :guilabel:`AI Usage`, narrow the log by:

* **Date range** — same period idea as the Dashboard (today, last N days,
  or custom)
* **Provider** — filter to ``t3planet_credits`` to see only Credits traffic
* **Status** — success vs failed/error rows
* **Module / extension** — when you need traffic from one product only

Use these filters when the recent list on the Dashboard is not enough.

How to verify Credits
---------------------

1. Activate Credits.
2. Run one AI action that uses AI Foundation — for example, generate a meta
   description in **T3 AI**.
3. Open :guilabel:`AI Usage`.
4. Filter by provider ``t3planet_credits`` (or scan the latest rows).
5. Confirm a new row for that action.

From Dashboard, open a recent request or go to :guilabel:`AI Usage` when you
need filters, older history, or failed-request details.
