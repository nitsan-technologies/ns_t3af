.. include:: ../../Includes.txt

.. _extension-integration:

=====================
Extension Integration
=====================

Use ``AiServiceInterface`` when your TYPO3 extension needs AI completions, streaming, or embeddings through AI Foundation. Do not call provider adapters, provider repositories, or vendor SDKs directly from feature code.

Purpose
-------

AI Foundation acts as the shared AI gateway for child extensions such as AI Assistant, AI Search, AI Chatbot, AI Accessibility, and custom agency extensions. The child extension prepares the prompt, context, and feature metadata. AI Foundation resolves the provider, executes the request, and records usage attribution.

Request lifecycle
-----------------

1. Your extension builds the prompt and context.
2. Your service calls ``AiServiceInterface``.
3. AI Foundation resolves the requested provider or the default provider.
4. The matching adapter performs the completion, stream, or embedding request.
5. Request metadata is logged for usage, analytics, and troubleshooting.

Dependency injection
--------------------

Inject the interface into your own service.

.. code-block:: php

   use NITSAN\NsT3AF\Api\AiServiceInterface;

   final class MyAiService
   {
       public function __construct(
           private readonly AiServiceInterface $aiService,
       ) {}
   }

Minimal working example
-----------------------

Pass ``AiOptions`` with stable feature metadata. This makes logs and usage analytics useful.

.. code-block:: php

   use NITSAN\NsT3AF\Api\AiOptions;
   use NITSAN\NsT3AF\Api\AiServiceInterface;

   final class SeoDescriptionGenerator
   {
       public function __construct(
           private readonly AiServiceInterface $aiService,
       ) {}

       public function generate(string $prompt, int $pageUid): string
       {
           $response = $this->aiService->complete(
               $prompt,
               new AiOptions(
                   extensionKey: 'my_extension',
                   featureKey: 'seo.meta_description',
                   featureLabel: 'SEO meta description',
                   requestSource: 'backend_module',
                   contentEntityType: 'pages',
                   contentEntityUid: $pageUid,
               ),
           );

           return $response->content;
       }
   }

What to put in AiOptions
------------------------

``extensionKey``
   TYPO3 extension key that initiated the request.

``featureKey``
   Stable machine key for the feature. Keep it unchanged across releases.

``featureLabel``
   Human-readable label for logs and dashboards.

``requestSource``
   Source of the request, such as ``backend_module``, ``scheduler``, or ``cli``.

``contentEntityType`` and ``contentEntityUid``
   Optional record context used for drilldown and troubleshooting.

Best practices
--------------

* Use ``AiServiceInterface`` as the only runtime AI integration surface.
* Keep ``featureKey`` stable so analytics history remains meaningful.
* Treat AI output as untrusted content before rendering or saving it.
* Do not log API keys, provider secrets, or sensitive prompt payloads.
* Handle provider failures and empty responses in your feature code.
* For CLI or Scheduler usage, configure an absolute TYPO3 site base URL when required by your environment.

Troubleshooting
---------------

**No provider is resolved**

* Confirm a provider is connected in :guilabel:`AI Foundation > AI Providers`.
* Confirm your feature-level provider override, if used, points to an enabled provider.

**Request is missing in logs**

* Confirm ``extensionKey`` and ``featureKey`` are set in ``AiOptions``.
* Check :ref:`AI Usage & Logs <ai-usage-and-logs>`.

Related documentation
---------------------

* :ref:`Feature Provider Overrides <feature-provider-overrides>`
* :ref:`Custom AI Providers <custom-ai-providers>`
* :ref:`AI Providers <ai-providers>`
* :ref:`AI Usage & Logs <ai-usage-and-logs>`
