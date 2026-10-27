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
4. If balance is ``0``, :ref:`buy credits <t3planet-credits-buy>` or switch to
   :guilabel:`Your Own API Keys` if you need AI without a credits balance

No ``t3planet_credits`` in AI Usage
===================================

1. Is Credits still active?
2. Did the action call AI Foundation AI?
3. Check the date filter

.. _t3planet-credits-troubleshooting-claim:

Claim code not accepted
=======================

1. Paste the code again. Spaces and line breaks are removed for you.
2. Request a new code with :guilabel:`Email me a code` in the same dialog. Use
   the email address of your T3Planet credits account.
3. If the dialog shows an error from T3Planet, copy the text and open
   :ref:`Support <support>`. Do not send the claim code itself.

Full guide: :ref:`Use a claim code <t3planet-credits-claim-code>`.

Claim link is missing
=====================

The link :guilabel:`Use a claim code` sits at the bottom of the
:guilabel:`T3Planet Credits` card on :guilabel:`AI Foundation > AI Providers`
(text: *Bought credits on t3planet.de?*). It is shown both before and after
Credits is active. If you do not see it:

1. Select a page in the page tree. Without a selected page the module only
   asks you to select a site.
2. Reload the module and look at the card on the right of
   :guilabel:`AI Provider Mode`.
3. Check that your backend user may manage AI Providers.

See :ref:`Use a claim code <t3planet-credits-claim-code>`.

This server IP already belongs to a paying account
==================================================

T3Planet allows one free trial per public server IP address. If that IP is
already linked to a paid account, :guilabel:`Activate` is refused. Use the
claim code for that account instead.

Token does not match this server
================================

*This T3Planet Credits token is registered to a different server IP.* Your
server IP changed (new host, proxy or hosting move). Click :guilabel:`Activate`
again from this server, or connect with a :ref:`claim code
<t3planet-credits-claim-code>`.

Checkout does not open or purchase not visible
==============================================

* Use :guilabel:`Open in new tab` in the checkout window if the embedded page is
  blank.
* Bank transfers show *Payment pending*. Credits arrive when the payment
  clears.
* After a payment, wait a minute and reload :guilabel:`AI Providers`. Purchases
  show in :guilabel:`Recent AI Usage` after the next sync.
* :guilabel:`Checkout unavailable` on a bundle: reload, or buy on
  https://t3planet.de/ki-credits and use a claim code.

See :ref:`Buy credits <t3planet-credits-buy>`.

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
