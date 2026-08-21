.. include:: ../../Includes.txt


.. _t3planet-credits-troubleshooting:

===============
Troubleshooting
===============

Quick smoke test
================

Before digging into individual symptoms:

1. Credits is **Selected** and **Activated**?
2. Balance loads on Dashboard / Providers?
3. One AI action → :guilabel:`AI Usage` shows ``t3planet_credits``?
4. Switching to :guilabel:`Your Own API Keys` restores the provider list?

Activation failed
=================

1. Confirm the server can reach the T3Planet Credits API
2. If prompted, complete the free-credits name/email form and consent checkbox
3. Flush caches → retry :guilabel:`Activate`

Balance missing
===============

1. Confirm Activate completed (Active, not only Selected)
2. Reload Providers / Dashboard
3. Check for a rate-limit message
4. If balance is ``0``, the balance widget is hidden by design — buy credits
   or switch to Own API Keys if you need AI without Credits balance

No ``t3planet_credits`` in AI Usage
===================================

1. Is Credits still active?
2. Did the action call AI Foundation AI?
3. Check the date filter

Provider list missing
=====================

Expected while Credits is on. Switch to :guilabel:`Your Own API Keys` to edit
providers again.

Module shows empty or an error
==============================

* Select the site root in the page tree, then reopen the module
* Flush caches and reload
* Confirm your backend user can access AI Foundation modules

Rate limited
============

T3Planet returned a temporary cooldown. Wait for the shown period, then retry.

Insufficient credits
====================

Balance is too low for the next request. Buy more credits, or switch to
:guilabel:`Your Own API Keys`.
