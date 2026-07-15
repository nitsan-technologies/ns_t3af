.. include:: ../../Includes.txt

.. _custom-ai-prompts:

======================
Custom Prompt Catalogs
======================

Use a custom prompt catalog when your extension needs editable LLM instruction templates in **AI Foundation → AI Prompts**. Prompt catalogs let your extension ship built-in defaults while allowing editors to create project-specific overrides.

Purpose
-------

AI prompts are feature prompts, not MCP workflow templates. Use them for extension features such as SEO generation, content generation, chat answers, product summaries, or support replies.

AI Foundation provides the backend module and shared storage. Your extension provides the prompt contracts, category metadata, and runtime resolver.

Architecture
------------

``PromptContractRegistry``
   Your extension’s PHP source of truth for built-in prompt types, labels, default text, scopes, and required variables.

``PromptCatalogProviderInterface``
   Connects your prompt contracts to **AI Foundation → AI Prompts**.

``tx_nst3af_ai_prompt``
   Shared table owned by AI Foundation for editor-created custom prompts.

Runtime resolver
   Your feature code decides which text to use: explicit request text, saved custom prompt, or built-in default.

Implementation steps
--------------------

1. Define prompt contracts in your extension.
2. Implement ``NITSAN\NsT3AF\Contract\PromptCatalogProviderInterface``.
3. Tag the provider with ``t3af.prompt_catalog_provider``.
4. Add a runtime resolver that reads custom prompt rows through AI Foundation services.
5. Use the resolved prompt when calling ``AiServiceInterface``.
6. Flush caches and verify the category in **AI Foundation → AI Prompts**.

Prompt contract rules
---------------------

* Use stable ``prompt_type`` values, for example ``product_summary``.
* Use a unique ``category_id`` prefixed with your extension key.
* Use ``[variable]`` placeholders for required values.
* Do not seed built-in prompts into the database. Keep built-ins in PHP.

Minimal contract idea
---------------------

.. code-block:: php

   private const CONTRACTS = [
       'product_summary' => [
           'scope' => 'catalog',
           'label' => 'Product summary',
           'defaultText' => 'Summarize [productName] for a [language] product page.',
           'requiredVariables' => ['productName', 'language'],
       ],
   ];

Service registration
--------------------

Register prompt providers in your extension.

.. code-block:: yaml

   services:
     _defaults:
       autowire: true
       autoconfigure: true

     _instanceof:
       NITSAN\NsT3AF\Contract\PromptCatalogProviderInterface:
         tags: ['t3af.prompt_catalog_provider']

     MyVendor\MyExt\Prompt\:
       resource: '../Classes/Prompt/'

Runtime usage
-------------

At runtime, resolve prompt text before making the AI request. A common resolution order is:

1. Explicit prompt text passed by the current request.
2. Custom prompt selected by title/type from ``tx_nst3af_ai_prompt``.
3. Built-in default from your contract registry.

Then pass the resolved text to ``AiServiceInterface`` with a stable ``featureKey``.

Best practices
--------------

* Keep category IDs unique across the TYPO3 instance.
* Keep prompt types stable after release.
* Validate that custom prompt text still contains required variables.
* Do not create extension-specific prompt tables unless the implementation requires separate domain data.
* Keep prompts focused on one feature workflow.

Verification
------------

1. Flush TYPO3 caches.
2. Open **AI Foundation → AI Prompts**.
3. Confirm your category card appears.
4. Open the category and verify built-in prompt rows.
5. Add a custom prompt and save it.
6. Trigger your feature and confirm the resolver can use the custom prompt.

Troubleshooting
---------------

**Category is missing**

* Confirm the provider is tagged with ``t3af.prompt_catalog_provider``.
* Confirm ``isAvailable()`` returns ``true``.
* Flush caches.

**Custom prompt is not used**

* Confirm ``extension_key``, ``category_id``, ``scope``, and ``prompt_type`` match your resolver query.
* Confirm the selected prompt title is passed to the feature runtime.

Related documentation
---------------------

* :ref:`Extension Integration <extension-integration>`
* :ref:`Custom AI Features <custom-ai-features>`
* :ref:`AI Prompts <ai-prompts>`
