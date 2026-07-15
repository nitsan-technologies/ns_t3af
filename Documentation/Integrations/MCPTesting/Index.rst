.. include:: ../../Includes.txt

.. _mcp-testing:

==========================
MCP Server — Testing guide
==========================

This guide walks through **end-to-end testing** of all three connection methods and every client tab in the backend module (:guilabel:`AI Foundation > MCP Server`).

Use it for QA, demos, or first-time setup on a local DDEV instance.

Prerequisites
-------------

Environment
   TYPO3 **^13.4 || ^14.3**, PHP **>= 8.2**, ``typo3/cms-workspaces`` installed, ``ns_t3af`` enabled.

Local stack (example)

   .. code-block:: bash

      ddev start
      ddev composer install
      ddev exec typo3 cache:flush

Backend access
   Log in as an admin backend user (for example ``admin``).

Site URL

   .. code-block:: text

      https://<project>.ddev.site/mcp
   The MCP module derives URLs from your first site configuration. On DDEV this is typically: Replace ``<project>`` with your DDEV project name throughout this guide.

Optional tools
   **Node.js 18+** — MCP Inspector and ``mcp-remote`` **Claude Desktop** — OAuth remote setup **n8n** — MCP Client node (self-hosted or cloud) **Cursor / VS Code MCP** — for CLI or ``mcp-remote`` configs

Step 0 — Pre-flight checks (backend module)
-------------------------------------------

Open :guilabel:`AI Foundation > MCP Server`.

1. **Workspace** - Use the **WORKSPACE** dropdown (top right). - If you see a yellow “create workspace” notice, click **Create MCP workspace** (requires permission) or pick **Live** for read-only smoke tests.
2. **Status bar** (top card) Verify:

   * **Server Status** → **Online**
   * **OAuth Endpoints** → both ``oauth-authorization-server`` and ``oauth-protected-resource`` show green checks
   * **Server URL** → copy your ``https://…/mcp`` URL

3. **Endpoint health** (Remote MCP Setup tab) Under **MCP endpoint status**, all three rows should be green:

   * MCP endpoint (``/mcp`` returns **401 without auth** — that is expected and counts as online)
   * ``/.well-known/oauth-authorization-server/mcp``
   * ``/.well-known/oauth-protected-resource/mcp``

4. **Quick curl smoke test**

   .. code-block:: bash

      # Replace with your site URL
      BASE=https://t3af.ddev.site

      curl -sS -o /dev/null -w "%{http_code}\n" "$BASE/mcp"
      # Expected: 401

      curl -sS "$BASE/.well-known/oauth-authorization-server/mcp" | head -c 200
      curl -sS "$BASE/.well-known/oauth-protected-resource/mcp" | head -c 200
      # Expected: JSON metadata (HTTP 200)

5. **Enable MCP** (if offline) Expand **Show advanced** → ensure **Enable MCP Server** is checked → **Save**.

Cursor IDE — two connection methods
-----------------------------------

Cursor can connect to the TYPO3 MCP server in two ways. Use **one** method per server entry — do not mix stdio and URL for the same logical connection.

**Project stdio (DDEV)**

* Config file: ``.cursor/mcp.json`` in the project root
* Transport: stdio via DDEV
* Auth: backend user and workspace
* Best for: local development in this repository

**Global remote URL (HTTP)**

* Config file: ``~/.cursor/mcp.json`` in the user home directory
* Transport: HTTP Streamable at ``/mcp``
* Auth: OAuth 2.1 with PKCE (browser)
* Best for: any Cursor workspace, including production

Method A — Project stdio via DDEV
---------------------------------

Create or edit **``.cursor/mcp.json``** in the **project root** (same directory as ``.ddev/``). Cursor spawns ``ddev exec … nst3af:mcp:serve`` when you open this project.

**Important:**

* **`cwd`** must be the **absolute path** to the DDEV project root. Without it, ``ddev`` may fail with *could not find a project*.
* Use the full command name **`nst3af:mcp:serve`** (alias ``mcp:server`` works only after TYPO3 caches are warm).
* **`–no-startup-message`** keeps diagnostics off stdout (stdio MCP reserves stdout for JSON-RPC).
* Adjust **`–user`** and **`–workspace``** to match your backend user and workspace UID from the MCP module dropdown.

.. code-block:: json

   {
     "mcpServers": {
       "TYPO3 DDEV": {
         "command": "ddev",
         "args": [
           "exec",
           "php",
           "vendor/bin/typo3",
           "nst3af:mcp:serve",
           "--no-startup-message",
           "--user=admin",
           "--workspace=3"
         ],
         "cwd": "/absolute/path/to/aiuniverse"
       }
     }
   }

Replace ``/absolute/path/to/aiuniverse`` with your checkout path (for example ``/Users/you/projects/aiuniverse``).

**Verify in Cursor:** Settings → MCP → **TYPO3 DDEV** shows **Connected** and
lists Core tools from AI Foundation (for example ``table_schema``, ``pages_get``,
``content_list``, ``write_table``, plus many others in the Core catalog).

Method B — Global remote URL (OAuth)
------------------------------------

For HTTP + OAuth without DDEV stdio, add the server URL to your **user-level** Cursor config: ``~/.cursor/mcp.json``. This works from any project; Cursor opens the OAuth flow in the browser on first connect.

.. code-block:: json

   {
     "mcpServers": {
       "typo3": {
         "url": "https://t3af.ddev.site/mcp"
       }
     }
   }

Replace the host with your site URL from the MCP module **Server URL** field. Complete OAuth when Cursor prompts. The backend **Active OAuth Tokens** table should show a new token with an updated **Last Used** timestamp after tool calls.

.. note::

   Project ``.cursor/mcp.json`` (Method A) and global ``~/.cursor/mcp.json`` (Method B) can coexist with **different server names** — for example ``TYPO3 DDEV`` (stdio) and ``typo3`` (URL).

Terminal verification (stdio / DDEV)
------------------------------------

Use these checks **before** relying on Cursor, or when debugging a broken stdio connection.

**1. DDEV and TYPO3 CLI**

.. code-block:: bash

   cd /absolute/path/to/aiuniverse
   ddev describe   # site should be running
   ddev exec php vendor/bin/typo3 list nst3af

Expect ``nst3af:mcp:cleanup`` and ``nst3af:mcp:serve`` (alias ``mcp:server``). If ``list nst3af`` fails, verify with:

.. code-block:: bash

   ddev exec php vendor/bin/typo3 help nst3af:mcp:serve

**2. One-shot initialize (pipe test — recommended)**

Sends a single JSON-RPC ``initialize`` request on stdin and prints the JSON response on stdout. This confirms the server speaks MCP without leaving a process running:

.. code-block:: bash

   printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}\n' \
     | ddev exec typo3 nst3af:mcp:serve --no-startup-message -u admin -w 3

**Expected output** (one line of JSON on stdout):

.. code-block:: json

   {"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"logging":{},"completions":{},"tools":{}},"serverInfo":{"name":"AI Foundation MCP Server","version":"1.0.0"}}}

Adjust ``-u`` / ``-w`` (short for ``--user`` / ``--workspace``) to match your config. If you see this JSON, the stdio transport is working; any Cursor issue is likely ``cwd``, command name, or MCP panel cache (restart Cursor).

**3. Interactive run (optional)**

.. code-block:: bash

   ddev exec php vendor/bin/typo3 nst3af:mcp:serve --no-startup-message --user=admin --workspace=3

The process **waits on stdin** after startup — that is normal. It is not “stuck”; it expects JSON-RPC from an MCP client. Diagnostics go to **stderr** (use ``-v`` or ``-vv`` for more detail). Stop with ``Ctrl+C``.

.. warning::

   A manual terminal session and Cursor **each spawn their own process**. A successful pipe test does not require a terminal server to stay open for Cursor — Cursor starts its own ``ddev exec …`` when the project loads.

Method 1 — Remote MCP Setup (HTTP + OAuth / Bearer)
---------------------------------------------------

Recommended for production-like clients. Transport: **HTTP Streamable** at ``{site}/mcp``.

Authentication options:

* **OAuth 2.1 + PKCE** — Claude Desktop, MCP Inspector, generic OAuth clients
* **Bearer token** — n8n, Manus (create token in the module UI)

Default scopes (advanced settings): ``mcp:read mcp:write mcp:tools``

Claude Desktop (OAuth)
----------------------

**In the module:** Remote MCP Setup → **Claude Desktop** tab.

**Steps:**

1. Open **Claude Desktop → Settings → Integrations**.
2. Click **Add Integration**.
3. Name it (for example ``TYPO3 DDEV``).
4. Paste **Server URL** from the module, e.g.:

   .. code-block:: text

      https://t3af.ddev.site/mcp

5. Save. Claude starts **OAuth** automatically (browser window / system prompt).
6. Approve access as your TYPO3 backend user.

**Verify:**

* Ask Claude to list MCP tools or call ``table_schema`` with ``tableName: pages``.
* In the module, **Active OAuth Tokens** shows a new row; **Last Used** updates.

**Example prompt:**

.. code-block:: text

   Use the TYPO3 MCP tool table_schema for table "pages" and summarize the fields.

n8n (Bearer token)
------------------

**In the module:** Remote MCP Setup → **n8n** tab.

**Steps:**

1. Click **Create n8n token** (if no active token is shown).
2. Copy the **Bearer token** immediately (full value is only shown once; the UI stores it in the browser session for copy).
3. In n8n, add an **MCP Client** node to a workflow.
4. Configure the MCP Client node:

   * **Endpoint** — ``https://t3af.ddev.site/mcp``
   * **Server Transport** — HTTP Streamable
   * **Authentication** — Bearer Auth
   * **Bearer Token** — paste the token from step 2

5. Save and **Execute workflow**.

**Verify:**

* Node connects without 401 errors.
* Tool list includes Core tools such as ``table_schema``, ``pages_get``,
  ``content_list``, and ``write_table`` (the Core catalog contains many more).
* **Active OAuth Tokens** table shows client **n8n token**.

Manus (Bearer token)
--------------------

**In the module:** Remote MCP Setup → **Manus** tab.

**Steps:**

1. Click **Create manus token** and copy the Bearer token.
2. In Manus, add a new **MCP server** connection.
3. Configure the connection:

   * **Server Name** — your TYPO3 site name
   * **Transport** — HTTP
   * **Server URL** — ``https://t3af.ddev.site/mcp``
   * **Authorization** — header ``Authorization: Bearer <token>``

4. Save the connection.

**Verify:** Same as n8n — tool calls succeed and token appears in **Active OAuth Tokens**.

MCP Inspector (OAuth)
---------------------

**In the module:** Remote MCP Setup → **MCP Inspector** tab.

**Steps:**

1. Copy the pre-filled command (requires Node.js):

   .. code-block:: bash

      npx @modelcontextprotocol/inspector --transport http --server-url https://t3af.ddev.site/mcp

2. Run it in your terminal.
3. Open the Inspector UI in the browser (URL printed in the terminal).
4. Complete **OAuth** when prompted.
5. Use the **Tools** panel to invoke ``table_schema``:

   .. code-block:: json

      {
        "tableName": "pages"
      }

**Verify:**

* ``tools/list`` returns the Core tool catalog (many tools, not a short fixed set).
* ``table_schema`` returns JSON with ``pages`` field metadata.

Other OAuth-capable clients
---------------------------

**In the module:** Remote MCP Setup → **Other** tab.

Generic checklist:

1. Add a **remote MCP server** in your client.
2. **Server URL:** ``https://t3af.ddev.site/mcp``
3. **Transport:** HTTP Streamable (when available).
4. **Auth:** OAuth 2.1 + PKCE if supported; otherwise create a Bearer token under the **n8n** or **Manus** tabs.

**Cursor:** see Cursor IDE — two connection methods for project stdio (DDEV) and global URL (OAuth) setup, plus terminal verification.

Method 2 — Local Setup (mcp-remote)
-----------------------------------

For MCP clients that **only speak stdio** (no native HTTP). The ``mcp-remote`` npm package bridges stdio ↔ your TYPO3 HTTP endpoint.

Token in URL
   After creating a token, the server accepts: ``https://<host>/mcp?token=<64-char-hex>`` **(recommended in UI)** ``https://<host>/mcp/r/<64-char-hex>`` *(legacy path form)*

.. warning::

   URL tokens are as sensitive as passwords. Do not commit them to git or share in screenshots.

**In the module:** click **Local Setup (mcp-remote)**.

Step-by-step
------------

1. Click **Create mcp-remote Token** (if none exists).
2. Copy **Token URL** (includes ``?token=…``).
3. Copy **Example mcp-remote configuration** or build manually:

   .. code-block:: json

      {
        "mcpServers": {
          "New TYPO3 site": {
            "command": "npx",
            "args": [
              "mcp-remote",
              "https://t3af.ddev.site/mcp?token=YOUR_64_CHAR_TOKEN"
            ]
          }
        }
      }

4. Paste into your client’s MCP config:

   * **Claude Desktop:** ``claude_desktop_config.json`` → ``mcpServers``
   * **Cursor:** ``.cursor/mcp.json``
   * **VS Code:** MCP extension settings

5. Restart the client so it spawns ``npx mcp-remote …``.

**Verify:**

.. code-block:: bash

   # Optional: run bridge manually to see logs
   npx mcp-remote "https://t3af.ddev.site/mcp?token=YOUR_TOKEN"

* Client lists TYPO3 tools.
* **Active OAuth Tokens** shows **mcp-remote token** with updated **Last Used**.

Method 3 — Local Setup (TYPO3 CLI)
----------------------------------

Direct **stdio** transport — no HTTP, no OAuth. The MCP client must run on the **same machine** as TYPO3 (or inside the DDEV web container).

**In the module:** click **Local Setup (TYPO3 CLI)**.

Example configuration
---------------------

Copy from the module or use:

.. code-block:: json

   {
     "mcpServers": {
       "New TYPO3 site": {
         "command": "php",
         "args": [
           "vendor/bin/typo3",
           "nst3af:mcp:serve",
           "--no-startup-message"
         ]
       }
     }
   }

The short alias ``mcp:server`` also maps to ``nst3af:mcp:serve`` when TYPO3 command caches are up to date.

Step-by-step (DDEV)
-------------------

1. For **Cursor**, prefer the full walkthrough in Cursor IDE — two connection methods.
2. For other MCP clients, use DDEV from the host with an absolute ``cwd``:

   .. code-block:: json

      {
        "mcpServers": {
          "TYPO3 DDEV": {
            "command": "ddev",
            "args": [
              "exec",
              "php",
              "vendor/bin/typo3",
              "nst3af:mcp:serve",
              "--no-startup-message",
              "--user=admin",
              "--workspace=3"
            ],
            "cwd": "/absolute/path/to/aiuniverse"
          }
        }
      }

3. **Terminal verification:** see Cursor IDE — two connection methods (pipe test and expected ``initialize`` JSON).
4. CLI options:

   * **``–user`` / ``-u``** — Backend username (default ``admin``).
   * **``–workspace`` / ``-w``** — Workspace UID (``0`` = live).
   * **``–no-startup-message``** — Suppress stderr banner (recommended for MCP).
   * **``-v`` / ``-vv``** — Verbose stderr diagnostics.

**Verify:**

* MCP client connects without HTTP/OAuth.
* Invoke ``pages_get`` with ``uid: 1`` (adjust to a page that exists).

.. note::

   Restart the CLI server after PHP code changes. Long-running processes may accumulate memory — restart periodically during heavy testing.

Step 4 — Test Core tools
------------------------

Use MCP Inspector, Claude, or any connected client. The **TYPO3 Core** tab
contains many tools — do not assume only four exist. Browse the full catalog in
:guilabel:`AI Foundation > MCP Tools`, then smoke-test a few representative calls.

Starter checks:

``table_schema``
   Input: ``{ "tableName": "pages" }`` Expect: JSON with field definitions.

``pages_get``
   Input: ``{ "uid": 1 }`` Expect: Page record (or error if uid missing).

``content_list``
   Input: ``{ "pid": 1, "limit": 5 }`` Expect: Array of ``tt_content`` rows for that page.

``write_table`` (workspace recommended)

   .. code-block:: json

      {
        "action": "create",
        "tableName": "tt_content",
        "data": "{\"pid\": 1, \"CType\": \"text\", \"header\": \"MCP test\"}"
      }
   Create (use a test page pid and workspace): Expect: JSON with ``uid``, ``fields``, and ``ignoredFields``. Verify with ``content_list`` on the same ``pid``. Use ``action: delete`` with the new ``uid`` to clean up.

Workspace testing
   Select a non-live workspace in the module dropdown before issuing tokens. Repeat ``content_list`` / ``pages_get`` — draft overlays should differ from live.

See :ref:`MCP Tools <mcp-tools>` for the full Core catalog and
:ref:`MCP Server <mcp-server>` for connection details.

Step 5 — Active OAuth Tokens table
----------------------------------

After each test, confirm in **Active OAuth Tokens**:

* **Client Name** — ``n8n token``, OAuth client name, and similar labels.
* **Created / Last Used** — Updates on successful calls.
* **Expires** — Reasonable future date.
* **Workspace** — Matches dropdown selection.
* **Token** — Preview plus **Copy Token**.
* **Action** — **Revoke** removes access.

Header actions:

* **Refresh** — reload table via AJAX
* **Revoke All** — invalidates every token for your backend user (confirm dialog)

Quick test matrix
-----------------

Use this checklist when regression-testing a release:

**Remote HTTP clients (Claude, n8n, Manus, MCP Inspector, other OAuth clients)**

* Endpoint health is green.
* ``tools/list`` works.
* ``table_schema`` works.
* Token appears in the connections table.

**mcp-remote bridge**

* Create token URL.
* ``npx mcp-remote`` bridge connects.
* Client stdio config lists tools.

**TYPO3 CLI / Cursor stdio**

* ``nst3af:mcp:serve`` starts.
* Pipe ``initialize`` test returns JSON.
* Cursor project ``cwd`` is set.
* ``--user`` and ``--workspace`` match your backend user.
* Tool invocation succeeds.

**Cursor URL (``~/.cursor/mcp.json``)**

* OAuth at ``/mcp`` completes.
* Token appears in the connections table.

Troubleshooting
---------------

Server Status **Offline**
   Enable MCP in **Show advanced**. Run ``ddev exec typo3 cache:flush``.

``/mcp`` returns **503**
   ``enableMcpServer`` is off (MCP Server → Advanced, or AI Foundation MCP settings).

``/mcp`` returns **401** without token
   **Expected** — proves middleware is reachable. Authenticate with OAuth or Bearer.

OAuth metadata checks **red**
   Verify site base URL, HTTPS, and that ``config/sites/*/config.yaml`` routes exist. Flush caches.

Bearer / URL token **Authentication failed**
   Token revoked, expired, or wrong workspace. Create a new token in the module.

``mcp-remote`` client shows no tools
   Confirm Node.js is installed, URL includes valid ``?token=``, restart client.

CLI **Backend user not found**
   Pass ``--user= <../existing-be-username>``.

Tool returns empty / wrong data
   Check **WORKSPACE** dropdown and token workspace pin.

Full token not copyable later
   Plaintext is only shown at issuance. Revoke and re-create, or use OAuth flow.

Cleanup after testing
---------------------

1. **Revoke** test tokens in **Active OAuth Tokens** (or **Revoke All**).
2. Remove MCP entries from ``claude_desktop_config.json`` / ``.cursor/mcp.json``.
3. Optional maintenance:

   .. code-block:: bash

      ddev exec vendor/bin/typo3 nst3af:mcp:cleanup

See also :ref:`Configuration <configuration>` and :ref:`MCP Server <mcp-server>`.
