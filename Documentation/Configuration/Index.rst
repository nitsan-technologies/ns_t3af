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
   AIPermissions/Index

Two configuration areas
-----------------------

**AI Providers** — **Path:** :guilabel:`AI Foundation > AI Providers`. API keys,
models, and defaults.

**Extension settings** — Translation APIs, Basic Auth, notifications, and MCP
switches (including ``enableMcpServer``). Prefer
:guilabel:`AI Foundation > MCP Server > Advanced` for MCP options. These keys
live in AI Foundation settings, not the classic TYPO3
:guilabel:`Admin Tools > Settings > Extension Configuration` form.

Minimum working setup
---------------------

1. One AI provider with a valid key and model
2. Test connection passes
3. Provider marked **Default**
4. (Optional) DeepL or Google keys for translation APIs
5. (Optional) MCP enabled if you use AI agents

AI Providers (primary)
----------------------

**Path:** :guilabel:`AI Foundation > AI Providers`

Connect at least one provider, set a model, run :guilabel:`Test connection`,
and mark exactly one row as :guilabel:`Default`.

Full field reference: :ref:`Provider fields <provider-fields>`. Guide:
:ref:`AI Providers <ai-providers>`

Extension settings
------------------

AI Foundation stores translation helpers, Basic Auth, notifications, and MCP
switches in extension settings (including ``enableMcpServer``). Prefer
:guilabel:`AI Foundation > MCP Server > Advanced` for MCP options.

Where a classic Extension Configuration form is still used for optional
keys, open :guilabel:`Admin Tools > Settings > Extension Configuration` and
select ``ns_t3af``.

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

* ``enableMcpServer`` — Master switch (default: on)
* ``mcpBasePath`` — HTTP endpoint (default: ``/mcp``)
* ``requireAuth`` — Require login (default: on)
* ``accessTokenLifetime`` — OAuth token TTL

Full guide: :ref:`MCP Server <mcp-server>`

Per-feature providers
---------------------

**Path:** :guilabel:`AI Foundation > AI Features`

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
