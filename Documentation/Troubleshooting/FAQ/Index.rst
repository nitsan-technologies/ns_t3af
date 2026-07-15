.. include:: ../../Includes.txt


.. _faq:

===
FAQ
===

Short answers about **AI Foundation** (``EXT:ns_t3af``).

General
-------

**What is AI Foundation?**

The shared AI foundation for T3Planet TYPO3 extensions. It manages providers,
MCP, brand context, prompts, access roles, and usage in one backend module.
See :ref:`Overview <introduction>`.

**Does it include a frontend plugin?**

No. AI Foundation is a backend foundation layer. Visitors see AI through
child extensions such as AI Assistant or AI Chatbot.

**Which TYPO3 and PHP versions are supported?**

TYPO3 12.4–14.x with PHP 8.2 or higher. See
:ref:`System Requirements <system-requirements>`.

Installation
------------

**How do I install it?**

With Composer (``composer require nitsan/ns-t3af``) or from the TYPO3 Extension
Repository. See :ref:`Installation <installation>`.

**Composer reports a conflict with another MCP package.**

Remove conflicting MCP server packages first, then install AI Foundation.
See :ref:`Known Problems <known-problems>`.

Providers and MCP
-----------------

**Can I use local models such as Ollama?**

Yes. Use the Ollama provider type or a custom OpenAI-compatible endpoint.
See :ref:`AI Providers <ai-providers>`.

**Test connection fails even with a valid key.**

Check the model ID, outbound HTTPS, and provider status. See the provider
checklist in :ref:`Known Problems <known-problems>`.

**What is MCP?**

Model Context Protocol connects AI clients such as Cursor to your TYPO3
instance. See :ref:`MCP Server <mcp-server>`.

Privacy
-------

**Where does request data go?**

AI Foundation is self-hosted. Prompts and responses go from your server to the
AI provider you configure, using your API keys. T3Planet is not in the AI data
path. License validation only sends the license key and domain.

Still stuck?
------------

Open :ref:`Support <support>` with your TYPO3, PHP, and ``ns_t3af`` versions
and the exact error text.
