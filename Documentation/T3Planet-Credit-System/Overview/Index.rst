.. include:: ../../Includes.txt


.. _t3planet-credits-overview:

========
Overview
========

Purpose
=======

Explain what T3Planet Credits is and when to use it.

What it is
==========

T3Planet Credits is T3Planet’s managed AI access in AI Foundation (``ns_t3af``):

* You do **not** need your own OpenAI / Anthropic / similar keys for billable AI
* AI Foundation sends those calls to T3Planet
* Usage is counted in **credits**

Requirements
============

* AI Foundation (``EXT:ns_t3af``) installed and active
* Server can reach the T3Planet Credits API (for Credits mode)
* No product licence key is required for AI Foundation itself

What's covered
==============

When Credits is active, it can cover:

* Text completion
* Streaming
* Embeddings
* Text-to-speech (TTS)
* Image generation

Own API Keys vs Credits
=======================

+------------------+---------------------+---------------------------+
|                  | Own API Keys        | T3Planet Credits          |
+==================+=====================+===========================+
| Your API keys    | Required            | Not used for AI calls     |
+------------------+---------------------+---------------------------+
| Who runs AI      | Vendor API (direct) | T3Planet                  |
+------------------+---------------------+---------------------------+
| Providers UI     | Shows provider list | Hides list; shows Credits |
|                  |                     | panels                    |
+------------------+---------------------+---------------------------+
| AI Usage id      | Your provider id    | ``t3planet_credits``      |
+------------------+---------------------+---------------------------+

Selected vs Active
==================

* **Selected** — Credits card chosen; token not yet issued. Complete
  :guilabel:`Activate` to finish setup.
* **Active** — License available and token ready. AI can run through Credits.

When to use Credits
===================

* You want AI without managing vendor keys
* You want one shared credit pool

Use Own API Keys when you already manage vendor keys yourself.

What uses credits
=================

Credits are used when Credits is **active** and an AI Foundation AI call
completes successfully through T3Planet.

Opening modules or only switching the card does **not** use credits.

Switch modes
============

**Turn on**

1. Open :guilabel:`AI Foundation > AI Providers`
2. Choose :guilabel:`T3Planet Credits`
3. Click :guilabel:`Activate` if shown
4. Success message → page reload

**Turn off**

1. Choose :guilabel:`Your Own API Keys`
2. Confirm if asked
3. Provider list appears again

.. tip::

   Switching off does not delete the stored Credits token. It only turns Credits
   off.
