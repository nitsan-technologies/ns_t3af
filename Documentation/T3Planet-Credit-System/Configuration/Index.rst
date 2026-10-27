.. include:: ../../Includes.txt


.. _t3planet-credits-configuration:

=============
Configuration
=============

Turn on T3Planet Credits from AI Foundation, set limits, and add credits.

**Path:** :guilabel:`AI Foundation > AI Providers`

Before you start
================

* AI Foundation (``EXT:ns_t3af``) installed and active
* Server can reach the T3Planet API

Two ways to connect
===================

* **Start free** — follow *Activate Credits* below. You get free trial credits.
  Each public server IP address can start one trial.
* **You already bought credits** — skip :guilabel:`Activate` and use a
  **claim code** instead. See :ref:`Use a claim code <t3planet-credits-claim-code>`.

.. _t3planet-credits-activate:

Activate Credits
================

1. Open :guilabel:`AI Foundation > AI Providers`.
2. Choose :guilabel:`T3Planet Credits`.
3. Confirm if asked.
4. Click :guilabel:`Activate` if shown.
5. If no AI Universe license contact is available, enter name and email in the
   free-credits form, accept the privacy notice, then continue. Credits
   processing (including optional prompt storage in billing records) is
   documented in :ref:`DPA & GDPR <data-processing-agreement>`.
6. Wait for success → page reloads.

.. figure:: ../../Images/t3planet-credits-activate.png
   :alt: AI Providers with T3Planet Credits selected and Activate button
   :class: with-border with-shadow

   Select :guilabel:`T3Planet Credits`, then click :guilabel:`Activate`.

.. figure:: ../../Images/t3planet-credits-providers.png
   :alt: AI Providers with T3Planet Credits active and credit balance panel
   :class: with-border with-shadow

   After activation — :guilabel:`T3Planet Credits` active, balance panel, and
   :guilabel:`Buy more credits`.

.. note::

   If Activate fails, check that the server can reach the T3Planet API and try
   again. If TYPO3 says the server IP already belongs to a paying account, use a
   :ref:`claim code <t3planet-credits-claim-code>` instead. When no license contact exists, complete the free-credits name/email
   form. See :ref:`Troubleshooting <t3planet-credits-troubleshooting>` for
   common fixes.

After activation
================

When Credits is active:

* Billable AI calls go through T3Planet and use your credit balance
* Usage is logged in :guilabel:`AI Usage` as ``t3planet_credits``
* Credits panels appear and the own provider list is hidden
* Balance is available on the Dashboard and Credits panel
* Switching back to :guilabel:`Your Own API Keys` restores your saved providers

Group limits
============

Set per-group usage caps in :guilabel:`AI Foundation > AI Permissions`.

1. Open :guilabel:`AI Permissions`.
2. Select the backend usergroup.
3. Configure credit-related limits for that group.

What can be capped:

* **Monthly credit limit** — Maximum credits the group may use per month
* **Daily request limit** — Maximum AI requests the group may send per day

Administrator users are exempt from these caps.

Buy more credits
================

When your balance is low, click :guilabel:`Buy more credits` in the
:guilabel:`Credit Balance` panel and pick a bundle. You pay on the T3Planet
checkout page; invoices stay in your T3Planet account. Step by step, including
buying on the website: :ref:`Buy credits <t3planet-credits-buy>`.

.. warning::

   If T3Planet returns a rate-limit message, wait for the shown cooldown before
   retrying.
