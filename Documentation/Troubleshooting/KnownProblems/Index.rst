.. include:: ../../Includes.txt


.. _known-problems:

==============
Known Problems
==============

Verified issues and checks for **AI Foundation** (``EXT:ns_t3af``).

Installation
------------

**Composer conflicts with another MCP package**

AI Foundation includes an MCP server and conflicts with other MCP server
packages such as ``marekskopal/typo3-mcp-server`` and ``hn/typo3-mcp-server``.
Remove those packages before installing ``nitsan/ns-t3af``.

**Backend module is missing**

1. Confirm ``ns_t3af`` is active.
2. Flush all caches.
3. Run:

   .. code-block:: bash

      ./vendor/bin/typo3 extension:setup
      ./vendor/bin/typo3 cache:flush

Also confirm ``scheduler`` and ``workspaces`` are available. See
:ref:`Installation <installation>`.

Providers
---------

**Provider request or Test connection fails**

* Confirm the provider row exists and is **enabled** in :guilabel:`AI Foundation > AI Providers`.
* Run **Test connection** from the provider drawer.
* Check the API key, model ID, and endpoint URL (for custom/OpenAI-compatible rows).
* Confirm outbound HTTPS to the provider API works from the server.
* Review backend logs for entries from AI Foundation request logging.

**Unexpected model or provider behavior**

* Confirm the **default** provider matches the feature you expect.
* Confirm the model ID on the provider row.
* Check feature-level provider overrides in :guilabel:`AI Foundation > AI Features`.

**No usage statistics shown**

* Confirm an OpenAI admin/organization key is set where org usage charts are required.
* Clear caches and open :guilabel:`AI Foundation > AI Usage` / :guilabel:`AI Foundation > AI Logs` again.
* Confirm the dashboard analytics cache is available after ``extension:setup``.

Configuration
-------------

**HTTP 401 / 403 when fetching a protected URL**

If you use the Basic Auth helper in Extension Configuration (``ns_t3af``):

* Enable ``basicAuthEnabled``.
* Set ``basicAuthUsername`` and ``basicAuthPassword``.
* Retry the protected URL fetch.

MCP
---

**MCP client cannot connect**

* Confirm the MCP server is enabled in Extension Configuration.
* Prefer HTTPS on the site base URL.
* For Cursor and similar clients, follow :ref:`MCP Testing <mcp-testing>`.
* For stdio setups, keep the working directory and user/workspace flags correct.

**MCP writes fail after a successful connect**

Confirm the backend user has the required module, table, and workspace rights.
See :ref:`AI Permissions <ai-permissions>`.

Report an issue
---------------

Include TYPO3 version, PHP version, ``ns_t3af`` version, exact error text, and
whether MCP is enabled. Submit via :ref:`Support <support>`.
