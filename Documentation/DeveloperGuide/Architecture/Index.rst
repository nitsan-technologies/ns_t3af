.. include:: ../../Includes.txt

.. _architecture:

============
Architecture
============

Overview
--------

AI Foundation is a shared foundation layer:

.. code-block:: php

   Consuming Extension Code
           |
           v
   AiServiceInterface
           |
           v
   AdapterRegistry -> Provider adapters
           |
           v
      Provider APIs

Parallel support:

.. code-block:: php

   AiStatisticsService -> OpenAiOrganizationUsageService -> OpenAI Usage API
   HttpAuthUtility    -> Protected URL fetching with optional Basic Auth

Main components
---------------

* **Request orchestration**: ``AiServiceInterface`` and ``AiService``
* **Provider adapters**: ``AdapterRegistry`` and ``AdapterInterface`` implementations
* **Statistics processing**: ``AiStatisticsService`` and ``OpenAiOrganizationUsageService``
* **Engine configuration filtering**: ``AiEngineConfiguration``
* **Utility and environment helpers**: ``AiUniverseUtilityHelper``
* **HTTP auth helper**: ``HttpAuthUtility``

Configuration model
-------------------

Runtime behavior is mostly driven by extension configuration keys from ``ext_conf_template.txt``.

This includes:

* provider keys and models
* default engine selection
* token/temperature values
* basic auth settings

Caching
-------

The extension registers cache ``nst3af_statistics`` in ``ext_localconf.php``.

Statistics service stores processed data in this cache to reduce repeated usage API calls.

Constraints
-----------

* No native frontend plugin and no Fluid frontend output in this package.
* Primary role is reusable service infrastructure.
