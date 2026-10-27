.. include:: ../Includes.txt


.. _t3planet-credit-system:

================
T3Planet Credits
================

Purpose
=======

**T3Planet Credits** is T3Planet’s managed AI access for AI Foundation.
It lets your TYPO3 site use AI features **without storing or managing your own
vendor API keys**.

When Credits is active, AI Foundation sends billable AI requests to T3Planet.
T3Planet runs the AI work and reduces your **credit** balance. In
:guilabel:`AI Usage`, those requests are stored with provider id
``t3planet_credits``.

**Path:** :guilabel:`AI Foundation > AI Providers`

.. note::

   On :guilabel:`AI Providers` you can choose between :guilabel:`Your Own API Keys`
   and :guilabel:`T3Planet Credits`.

.. tip::

   **You can buy credits from the TYPO3 backend too.** No website visit is
   needed. See :ref:`Buy credits <t3planet-credits-buy>`.

Three ways to get credits
=========================

1. **Start free.** Select :guilabel:`T3Planet Credits` and click
   :guilabel:`Activate`. You receive 50 free trial credits once. No card needed.
   See :ref:`Configuration <t3planet-credits-configuration>`.
2. **Buy in the TYPO3 backend.** Pick a plan or a top-up under
   :guilabel:`AI Credit Bundles`. See :ref:`Buy credits <t3planet-credits-buy>`.
3. **Buy on t3planet.de/ki-credits.** Pay on the website, then paste the
   **claim code** from your email into TYPO3. See
   :ref:`Use a claim code <t3planet-credits-claim-code>`.

What you get
============

* 50 free trial credits once (the amount is set by T3Planet)
* AI features without configuring OpenAI, Anthropic, Gemini, Mistral, or similar
  keys
* One shared credit balance for this installation
* Balance on the Dashboard and AI Providers Credits panel
* Clear usage history in :guilabel:`AI Usage` (provider ``t3planet_credits``)

What's covered
==============

Credits can be used for these AI Foundation capabilities when Credits is active:

* Text completion
* Streaming
* Embeddings
* Text-to-speech (TTS)
* Image generation

When to use it
==============

Use Credits when you want editors to use AI quickly without key setup.

Use Own API Keys when you already manage vendor accounts and keys yourself.

Default is **Own API Keys**. Credits stays off until you select it and complete
:guilabel:`Activate`. Your saved providers stay in the database and are available
again when you switch back to :guilabel:`Your Own API Keys`.

When Credits is active, billable AI calls go to T3Planet. Prompts and inputs
may be stored in T3Planet billing records. For processors, retention, and
minimisation, see :ref:`DPA & GDPR <data-processing-agreement>`.

..  toctree::
   :maxdepth: 2
   :titlesonly:

   Overview/Index
   Configuration/Index
   BuyCredits/Index
   ClaimCode/Index
   AIUsage/Index
   MCPIntegration/Index
   Troubleshooting/Index
