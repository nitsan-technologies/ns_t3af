.. include:: ../Includes.txt


.. _installation:

============
Installation
============

Follow these steps in order. Install the license extension first, then
AI Foundation (``EXT:ns_t3af``).

Composer is the recommended method. Classic sites can use the
`TYPO3 Extension Repository (TER) <https://extensions.typo3.org/extension/ns_t3af>`__.

.. _system-requirements:

Step 1 — Verify Prerequisites
=============================

Confirm your environment before you install:

* **TYPO3** — 12.4 LTS, 13.4 LTS, or 14.x
* **PHP** — 8.2 or higher (8.3 recommended)
* **PHP extensions** — ``ext-sodium`` (required for secure storage)
* **Composer** — 2.x (recommended installation path)
* **Database** — MySQL 8.0+ or MariaDB 10.3+
* **Network** — Outbound HTTPS for AI provider API calls

Step 2 — Install Required Extensions
====================================

Install and activate these extensions before AI Foundation:

* **ns_license** — License activation and premium feature validation
* **scheduler** — Background AI jobs and scheduled tasks
* **workspaces** — Draft workspaces, MCP workflows, and safe content editing

``scheduler`` and ``workspaces`` ship with TYPO3. Activate them if they are
not already enabled. Install ``ns_license`` as described in the next step.

Step 3 — Install the License Extension
======================================

``EXT:ns_license`` must be installed first. AI Foundation depends on it for
license checks.

The extension is free and available from the
`TYPO3 Extension Repository <https://extensions.typo3.org/extension/ns_license>`__.

**Composer:**

.. code-block:: bash

   composer require nitsan/ns-license

**Extension Manager:**

1. Open **Admin Tools** → **Extensions** → **Get Extensions**.
2. Search for ``ns_license``.
3. Install and activate the extension.
4. Flush caches.

Step 4 — Install AI Foundation
==============================

**Composer (recommended):**

1. Install the package:

   .. code-block:: bash

      composer require nitsan/ns-t3af

2. Set up the extension and flush caches:

   .. code-block:: bash

      ./vendor/bin/typo3 extension:setup
      ./vendor/bin/typo3 cache:flush

3. Confirm ``ns_t3af`` is active in **Admin Tools** → **Extensions**.

**Extension Manager:**

1. Open **Admin Tools** → **Extensions** → **Get Extensions**.
2. Search for ``ns_t3af`` or **AI Foundation**.
3. Install and activate the extension.
4. Run **Analyze Database Structure**.
5. Flush caches.

Step 5 — Verify the Installation
================================

Confirm that:

* ``ns_license`` and ``ns_t3af`` are listed as active in **Admin Tools** → **Extensions**
* The **AI Foundation** module group appears in the backend sidebar
* **Analyze Database Structure** reports no pending changes for ``ns_t3af``

If the module is missing, flush caches and run
``./vendor/bin/typo3 extension:setup`` again.

Step 6 — Continue with Configuration
====================================

Next, connect AI providers and complete first-time setup in
:ref:`Configuration <configuration>`.
