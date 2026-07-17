.. include:: ../Includes.txt

.. _developer-guide:

===============
Developer Guide
===============

Build TYPO3 extensions on top of AI Foundation. Use the public contracts for providers, prompts, features, MCP tools, and access catalogs.

Prerequisites
-------------

* AI Foundation is installed and configured — :ref:`installation`
* At least one AI provider is connected when your feature needs AI requests — :ref:`ai-providers`
* You understand TYPO3 extension development, Composer, and Symfony dependency injection

Reference extension
-------------------

* `nitsan-technologies/ns_t3af_extended <https://github.com/nitsan-technologies/ns_t3af_extended>`__

..  toctree::
   :maxdepth: 2
   :titlesonly:

   Architecture/Index
   ExtensionIntegration/Index
   CustomProviders/Index
   CustomPromptCatalogs/Index
   CustomFeatureCards/Index
   FeatureProviderOverrides/Index
   CustomMcpTools/Index
   CustomAccessCatalogs/Index
