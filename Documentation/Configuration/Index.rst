.. include:: ../Includes.txt


.. _configuration-section:
.. _configuration:

=============
Configuration
=============

Configure **AI Foundation** after installation. You need a minimum working setup
before connected extensions can use AI.

This section also covers the AI Foundation backend modules used day to day:
providers, context, prompts, features, usage, and access control.

..  toctree::
   :maxdepth: 2
   :titlesonly:

   Dashboard/Index
   AIProviders/Index
   AIContext/Index
   AIPrompts/Index
   AIFeatures/Index
   AIUsageAndLogs/Index
   AccessAndGovernance/Index

Two configuration areas
-----------------------

**AI Providers** — **Path:** AI Foundation → AI Providers. API keys, models, and defaults.

**Extension Configuration** — **Path:** Admin Tools → Settings → Extension Configuration → ``ns_t3af``.
Translation APIs, MCP switches, basic auth, and notifications.

Minimum working setup
---------------------

1. One AI provider with a valid key and model
2. Test connection passes
3. Provider marked **Default**
4. (Optional) DeepL or Google keys for translation APIs
5. (Optional) MCP enabled if you use AI agents

AI Providers (primary)
----------------------

**Path:** AI Foundation → AI Providers

* **Title** — Friendly label for your team
* **Vendor** — OpenAI, Anthropic, Gemini, Azure, Mistral, and others
* **API key** — Encrypted after save
* **Model** — For example ``gpt-4o-mini``, ``claude-sonnet-4``
* **Default** — Exactly one active default provider
* **Enabled** — On/off switch per provider

Full guide: :ref:`AI Providers <ai-providers>`

Extension Configuration keys
----------------------------

**Path:** Admin Tools → Settings → Extension Configuration → ``ns_t3af``

Translation (optional)
~~~~~~~~~~~~~~~~~~~~~~

* ``deepl_api_key`` — DeepL translation
* ``google_api_key`` — Google translation
* ``defaultModelForTranslation`` — Default translation model

OpenAI usage statistics (optional)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

* ``openai_admin_api_key`` — Organization usage charts (not the chat API key)

HTTP Basic Auth (optional)
~~~~~~~~~~~~~~~~~~~~~~~~~~

* ``basicAuthEnabled`` — Enable helper
* ``basicAuthUsername`` — Username
* ``basicAuthPassword`` — Password

MCP Server
~~~~~~~~~~

* ``enableMcpServer`` — Master switch (default: off)
* ``mcpBasePath`` — HTTP endpoint (default: ``/mcp``)
* ``requireAuth`` — Require login (default: on)
* ``accessTokenLifetime`` — OAuth token TTL

Full guide: :ref:`MCP Server <mcp-server>`

Per-feature providers
---------------------

**Path:** AI Foundation → AI Features

Override the default provider per task: SEO, Pages, Content, Translation.

See :ref:`AI Features <ai-features>`.

Security checklist
------------------

* Limit backend admin access
* Use HTTPS in production
* Rotate API keys every 90 days
* Enable :ref:`AI Permissions <ai-permissions>` for large teams
* Never store keys in Git or email

When to reconfigure
-------------------

* After key rotation — run **Test connection** again
* When adding a new child extension — check :ref:`AI Features <ai-features>`
* Before enabling MCP in production — read :ref:`MCP Server <mcp-server>` security section
* After license renewal — confirm the extension license is still valid
