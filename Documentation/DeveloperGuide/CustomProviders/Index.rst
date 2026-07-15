.. include:: ../../Includes.txt

.. _custom-ai-providers:

===================
Custom AI Providers
===================

Create a custom AI provider when your extension needs to connect AI Foundation to a proprietary gateway, an on-premise model, or a cloud provider that is not covered by an installed Symfony AI bridge.

Provider architecture
---------------------

AI Foundation discovers provider adapters from the Symfony service container. Each adapter describes its type, display name, default endpoint, supported capabilities, connection test, and runtime platform object.

Built-in bridge packages are auto-registered when available. AI Foundation also provides the built-in **Custom / Other** OpenAI-compatible adapter under ``nst3af.openai_compatible``. Use a custom adapter only when the provider is not OpenAI-compatible or needs a custom SDK/protocol.

When to create a provider
-------------------------

* You use a private or on-premise LLM.
* Your vendor has a custom API that is not OpenAI-compatible.
* Your company routes AI requests through an internal gateway.
* You need capabilities or connection behavior that the built-in adapters do not provide.

Implementation steps
--------------------

1. Add a Composer dependency or suggestion on ``nitsan/ns-t3af``.
2. Create an adapter class in your extension.
3. Implement ``NITSAN\NsT3AF\Provider\Contract\AdapterInterface``.
4. Tag the service with ``nst3af.adapter`` in your extension.
5. Flush TYPO3 caches.
6. Create a provider row in :guilabel:`AI Foundation > AI Providers` and select your adapter.
7. Run the connection test.

Minimal adapter shape
---------------------

.. code-block:: php

   use NITSAN\NsT3AF\Domain\Model\Provider;
   use NITSAN\NsT3AF\Provider\Capability;
   use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
   use NITSAN\NsT3AF\Provider\Contract\VerifyResult;

   final class AcmeAdapter implements AdapterInterface
   {
       public function getType(): string
       {
           return 'custom.acme';
       }

       public function getDisplayName(): string
       {
           return 'ACME AI Gateway';
       }

       public function getDefaultEndpoint(): string
       {
           return 'https://llm.example.internal/v1';
       }

       public function getDefaultCapabilities(): array
       {
           return [Capability::CHAT, Capability::STREAMING];
       }

       public function testConnection(Provider $provider): VerifyResult
       {
           // Return VerifyResult::ok() or VerifyResult::failure().
       }

       public function platform(Provider $provider): object
       {
           // Return the SDK/platform object used by the runtime adapter layer.
       }
   }

Service registration
--------------------

The ``_instanceof`` rules inside ``EXT:ns_t3af`` do not tag services from your extension. Register the adapter in your own ``Configuration/Services.yaml``.

.. code-block:: yaml

   services:
     _defaults:
       autowire: true
       public: false

     _instanceof:
       NITSAN\NsT3AF\Provider\Contract\AdapterInterface:
         tags: ['nst3af.adapter']

     MyVendor\MyExt\Provider\:
       resource: '../Classes/Provider/*'
       autoconfigure: true

Credentials
-----------

Provider API keys are stored encrypted on provider records. If your SDK needs the plaintext key, decrypt it through AI Foundation’s credential service and never write the value to logs, exceptions, or frontend output.

Best practices
--------------

* Use the ``custom.<vendor>`` prefix for custom adapter types.
* Keep adapter type values stable after release.
* Return connection failures through ``VerifyResult::failure()`` instead of throwing from ``testConnection()``.
* Advertise only the capabilities your adapter really supports.
* Reuse AI Foundation provider rows instead of adding your own provider configuration table.

Verification
------------

1. Flush caches.
2. Open :guilabel:`AI Foundation > AI Providers`.
3. Create or edit a provider row and select your adapter.
4. Save the row.
5. Run the connection test and confirm the status updates.
6. Trigger a feature that uses ``AiServiceInterface`` with that provider.

Related documentation
---------------------

* :ref:`Extension Integration <extension-integration>`
* :ref:`AI Providers <ai-providers>`
* :ref:`Configuration <configuration>`
