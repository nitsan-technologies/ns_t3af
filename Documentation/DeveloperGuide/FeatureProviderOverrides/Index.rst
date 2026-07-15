.. include:: ../../Includes.txt

.. _feature-provider-overrides:

==========================
Feature Provider Overrides
==========================

Feature provider overrides allow an extension to offer a dedicated default AI provider for a specific feature while still using AI Foundation’s shared provider registry.

Use this when one feature should use a different model or provider than the global default. Examples include SEO generation, page generation, content generation, and LLM-based translation.

How it works
------------

AI Foundation owns the provider rows. The child extension exposes feature-specific provider fields in **AI Foundation → AI Features**. At runtime, the feature resolves the provider in this order:

1. Provider selected in the runtime modal, if the request includes one.
2. Feature default saved in AI Features.
3. Global default provider fallback.

Supported implementation hook
-----------------------------

Child extensions register provider dropdowns by implementing:

``NITSAN\NsT3AF\Contract\FeatureProviderFormOptionsInterface``

Register the service with:

``t3af.feature_provider_form_options``

AI Foundation uses the registered service to render provider options in the feature settings drawer.

Known feature fields
--------------------

AI Assistant uses feature-specific default provider fields such as:

* ``defaultProviderForSeo``
* ``defaultProviderForPages``
* ``defaultProviderForContent``
* ``defaultProviderForTranslation``
* ``defaultModel``
* ``defaultEmbeddingsModel``

Each dropdown lists enabled providers from **AI Providers**. The **Default (inherit global provider)** option keeps the feature on the global provider path.

Translation note
----------------

``defaultModelForTranslation`` still selects the translation backend, such as DeepL, Google, or an AI-based path. ``defaultProviderForTranslation`` applies only to LLM completion paths that use the AI Foundation completion gateway.

Configuration workflow
----------------------

1. Register a feature card with :ref:`Custom AI Features <custom-ai-features>`.
2. Add the provider field to that feature’s settings schema.
3. Implement ``FeatureProviderFormOptionsInterface``.
4. Tag the service with ``t3af.feature_provider_form_options``.
5. Flush caches.
6. Open **AI Foundation → AI Features** and verify the provider dropdown.
7. Save a default provider and test the runtime feature.

Best practices
--------------

* Keep provider field names stable.
* Always include an inherit/default option.
* Use the same label, **AI Provider**, when the same choice appears in runtime modals.
* Resolve providers through the feature runtime path instead of reading provider rows directly.
* Do not hardcode provider IDs in feature logic.

Verification
------------

1. Open **AI Foundation → AI Providers** and confirm at least one provider is enabled.
2. Open **AI Foundation → AI Features**.
3. Open the feature settings drawer.
4. Confirm the provider dropdown contains enabled providers.
5. Save a feature default.
6. Open the feature runtime modal and confirm the saved provider is preselected.

Troubleshooting
---------------

**Dropdown is missing**

* Confirm the form-options service implements the correct interface.
* Confirm the service is tagged with ``t3af.feature_provider_form_options``.
* Confirm the related feature card and scope provider are active.

**Runtime uses the wrong provider**

* Confirm the request field name matches the saved setting.
* Confirm the feature handles ``default`` or empty values as fallback.
* Confirm the saved provider row still exists and is enabled.

Related documentation
---------------------

* :ref:`Custom AI Features <custom-ai-features>`
* :ref:`Extension Integration <extension-integration>`
* :ref:`AI Providers <ai-providers>`
* :ref:`AI Features <ai-features>`
